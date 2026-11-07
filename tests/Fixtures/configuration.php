<?php
/** Portable, synthetic settings and administrator diagnostics fixtures. */
declare(strict_types=1);

namespace Piwik {
    final class Config
    {
        public array $SpeoReportPerformance = [];
        private static ?self $instance = null;
        public static function getInstance(): self { return self::$instance ??= new self(); }
    }
    final class Piwik
    {
        public static bool $administrator = true;
        public static function checkUserHasSuperUserAccess(): void
        {
            if (!self::$administrator) { throw new \RuntimeException('Administrator required'); }
        }
    }
}
namespace {
    use Piwik\Plugins\SpeoReportPerformance\Configuration;
    use Piwik\Plugins\SpeoReportPerformance\Diagnostics;
    use Piwik\Plugins\SpeoReportPerformance\Commands\DiagnoseReports;
    use Piwik\Plugins\SpeoReportPerformance\Diagnostic\WarmupCheck;
    use Symfony\Component\Console\Tester\CommandTester;

    require dirname(__DIR__) . '/bootstrap.php';
    speoFixtureBootstrap('configuration', true);
    mkdir(PIWIK_DOCUMENT_ROOT, 0700, true);
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
    $cases = [];
    function configurationExpect(bool $value, string $message): void
    {
        if (!$value) { throw new \RuntimeException($message); }
    }
    function configurationCheck(string $name, callable $test): void
    {
        global $cases;
        try { $test(); $cases[] = ['case' => $name, 'status' => 'pass']; }
        catch (\Throwable $error) { $cases[] = ['case' => $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()]; }
    }
    configurationCheck('unchanged-operational-defaults', function () {
        $config = new Configuration();
        configurationExpect($config->cacheTtl() === 86400 && $config->warmupEnabled()
            && $config->warmupMemoryLimit() === '2G' && $config->warmupMaxJobs() === 3
            && $config->warmupMaxSeconds() === 120 && $config->warmupMaxTargets() === 20
            && $config->warmupTargetMaxAge() === 604800 && $config->warmupRecordInterval() === 300
            && $config->issues() === [], 'Default behavior changed');
    });
    configurationCheck('public-configuration-api-read-and-validation', function () {
        \Piwik\Config::getInstance()->SpeoReportPerformance = ['cache_ttl' => '3600', 'warmup_memory_limit' => '768m', 'warmup_max_jobs' => '2'];
        $config = Configuration::get();
        configurationExpect($config->cacheTtl() === 3600 && $config->warmupMemoryLimit() === '768M'
            && $config->warmupMaxJobs() === 2, 'Matomo INI options were not applied');
        \Piwik\Config::getInstance()->SpeoReportPerformance = [];
    });
    configurationCheck('unsafe-options-use-bounded-defaults-with-fixed-reasons', function () {
        $config = new Configuration(['cache_ttl' => 0, 'warmup_max_jobs' => 0, 'warmup_max_seconds' => 'unbounded',
            'warmup_max_targets' => 101, 'warmup_target_max_age' => 1, 'warmup_record_interval' => 3601,
            'warmup_memory_limit' => '-1', 'warmup_enabled' => 'private-secret', 'warmup_storage_path' => ['secret']]);
        configurationExpect($config->cacheTtl() === 86400 && $config->warmupMaxJobs() === 3
            && $config->warmupMaxSeconds() === 120 && $config->warmupMemoryLimit() === '2G'
            && !$config->warmupEnabled() && count($config->issues()) === 9, 'Unsafe settings escaped validation');
        configurationExpect(!str_contains(json_encode($config->issues()), 'secret'), 'Configured value leaked in validation reasons');
    });
    configurationCheck('private-default-is-stable-installation-specific-and-not-created', function () {
        $first = new Configuration();
        $other = new Configuration([], PIWIK_USER_PATH . '-other');
        $path = $first->storagePath();
        configurationExpect($path === (new Configuration())->storagePath() && $path !== $other->storagePath(), 'Private instance identity is unstable or shared');
        configurationExpect(!str_starts_with($path, PIWIK_DOCUMENT_ROOT . '/') && !file_exists($path), 'Default storage became public or was created by validation');
        configurationExpect($first->storageStatus() === 'available_on_first_use' && !file_exists($path), 'Capability check wrote state');
    });
    foreach (['relative/path', PIWIK_DOCUMENT_ROOT, PIWIK_DOCUMENT_ROOT . '/tmp/private', "bad\0path"] as $index => $path) {
        configurationCheck('reject-public-or-invalid-storage-' . $index, function () use ($path) {
            $config = new Configuration(['warmup_storage_path' => $path]);
            configurationExpect($config->storageStatus() === 'invalid_path', 'Unsafe storage path accepted');
        });
    }
    configurationCheck('symlink-cannot-hide-public-storage', function () {
        $alias = dirname(PIWIK_USER_PATH) . '/web-alias';
        symlink(PIWIK_DOCUMENT_ROOT, $alias);
        try { configurationExpect((new Configuration(['warmup_storage_path' => $alias . '/hints']))->storageStatus() === 'invalid_path', 'Public symlink alias accepted'); }
        finally { unlink($alias); }
    });
    configurationCheck('private-external-path-and-mode-probe', function () {
        $path = dirname(PIWIK_USER_PATH) . '/external-private';
        mkdir($path, 0700);
        $config = new Configuration(['warmup_storage_path' => $path]);
        try {
            configurationExpect($config->storageStatus() === 'available', 'External private path rejected');
            chmod($path, 0755); clearstatcache();
            configurationExpect($config->storageStatus() === 'permissions_unsafe', 'Public permissions not detected');
            configurationExpect((fileperms($path) & 0777) === 0755, 'Read-only probe changed permissions');
        } finally { rmdir($path); }
    });
    configurationCheck('diagnostics-accept-only-fixed-codes-and-redact-paths', function () {
        Diagnostics::record('cache_hit'); Diagnostics::record('private-secret-token');
        $config = new Configuration(['warmup_storage_path' => dirname(PIWIK_USER_PATH) . '/private-secret-login']);
        $report = Diagnostics::report($config);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        configurationExpect($report['counter_scope'] === 'current_process' && $report['counters']['cache_hit'] === 1, 'Counter scope or known counter incorrect');
        configurationExpect(!str_contains($json, 'private-secret') && !str_contains($json, PIWIK_USER_PATH)
            && $report['configuration']['warmup_storage_path'] === 'configured', 'Diagnostics leaked private data');
    });
    configurationCheck('native-optional-system-check-uses-public-interface', function () {
        $check = new WarmupCheck();
        configurationExpect($check instanceof \Piwik\Plugins\Diagnostics\Diagnostic\Diagnostic, 'Native diagnostic interface missing');
        $results = $check->execute();
        configurationExpect(count($results) === 1 && $results[0] instanceof \Piwik\Plugins\Diagnostics\Diagnostic\DiagnosticResult, 'Native diagnostic result missing');
        configurationExpect($results[0]->getStatus() === 'ok', 'Available private warmup capabilities not recognized');
        $registration = require SPEO_PLUGIN_ROOT . '/config/config.php';
        configurationExpect(array_keys($registration) === ['diagnostics.optional'], 'Native optional-check registration incorrect');
    });
    configurationCheck('native-diagnostics-console-is-readonly-and-administrator-only', function () {
        $tester = new CommandTester(new DiagnoseReports());
        configurationExpect($tester->execute([], ['interactive' => false]) === 0, 'Administrator command failed');
        $report = json_decode(trim($tester->getDisplay()), true, 32, JSON_THROW_ON_ERROR);
        configurationExpect($report['counter_scope'] === 'current_process' && !file_exists(Configuration::get()->storagePath()), 'Diagnostics command wrote state');
        \Piwik\Piwik::$administrator = false;
        $declined = false;
        try { (new CommandTester(new DiagnoseReports()))->execute([], ['interactive' => false]); }
        catch (\RuntimeException $error) { $declined = true; }
        finally { \Piwik\Piwik::$administrator = true; }
        configurationExpect($declined, 'Non-administrator reached diagnostics');
    });
    $failed = count(array_filter($cases, static fn(array $case): bool => $case['status'] !== 'pass'));
    $report = ['fixture' => 'configuration', 'application_bootstrap' => false, 'database_or_customer_reports' => false,
        'checks' => count($cases), 'passed' => count($cases) - $failed, 'failed' => $failed, 'results' => $cases];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    speoFixtureReport('configuration', $json); echo $json;
    exit($failed ? 1 : 0);
}
