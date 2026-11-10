<?php
/** Private CLI-only warmup fixtures: helper|command. No application bootstrap/DB/reports. */
declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/bootstrap.php';
    speoAssertIsolated();
    $GLOBALS['warmupFixtureMode'] = $argv[1] ?? 'helper';
    if (!in_array($GLOBALS['warmupFixtureMode'], ['helper', 'command'], true)) { throw new \RuntimeException('Unknown fixture mode'); }
    $GLOBALS['warmupFixtureNow'] = 1800000000;
    $GLOBALS['warmupFixtureMonotonic'] = 1000.0;
    $GLOBALS['warmupFixtureSuperUser'] = false;
    $GLOBALS['warmupFixtureFsyncCalls'] = 0;
    $GLOBALS['warmupFixtureSettings'] = [];
    $GLOBALS['warmupFixtureForeignOwner'] = false;
}
namespace Piwik\Plugins\SpeoReportPerformance {
    if ($GLOBALS['warmupFixtureMode'] === 'helper') {
        function time(): int { return $GLOBALS['warmupFixtureNow']; }
        function microtime(bool $asFloat = false) { return $GLOBALS['warmupFixtureMonotonic']; }
        function fileowner(string $path): int|false {
            if ($GLOBALS['warmupFixtureForeignOwner'] && function_exists('posix_geteuid')) { return posix_geteuid() + 1; }
            return \fileowner($path);
        }
        function fsync($stream): bool {
            ++$GLOBALS['warmupFixtureFsyncCalls'];
            throw new \RuntimeException('Optional cache hints must never fsync');
        }
        /** Explicit manifest-only own publication double; lifecycle is proved separately on original Redis. */
        final class CopyLifecycle
        {
            public static function withPublication(int $nativeId, callable $publish): mixed { return $publish(); }
        }
        final class NativeSourceGuard
        {
            public static bool $pipeline = true;
            public static bool $cachePipeline = true;
            public static function isReviewedPipeline(): bool { return self::$pipeline; }
            public static function isReviewedCachePipeline(): bool { return self::$cachePipeline; }
        }
        final class RevisionContext
        {
            public static bool $decline = false;
            public static array $prepared = [];
            public function __construct(private array $request, private string $language = 'en') {}
            public function getNativeId(): int { return (int) $this->request['idSite']; }
            public function getWarmRequest(): array { return $this->request; }
            public function getLanguage(): string { return $this->language; }
            public function getBaseKey(): string { return 'fixture-base-' . $this->request['idSite']; }
            public static function prepare(array $request): ?self
            {
                self::$prepared[] = $request;
                return self::$decline ? null : new self($request);
            }
        }
    } else {
        final class HotReportWarmup
        {
            public static array $calls = [];
            public static array $result = ['attempted' => 0, 'requested' => 0, 'warm' => 0, 'skipped' => 0, 'failed' => 0];
            public static function run(?string $target = null): array { self::$calls[] = $target; return self::$result; }
        }
    }
}
namespace Piwik {
    if ($GLOBALS['warmupFixtureMode'] === 'helper') {
        final class Config
        {
            public array $SpeoReportPerformance;
            public function __construct() { $this->SpeoReportPerformance = $GLOBALS['warmupFixtureSettings']; }
            public static function getInstance(): self { return new self(); }
        }
        final class Access
        {
            public static int $calls = 0;
            public static function doAsSuperUser(callable $callback)
            {
                ++self::$calls; $previous = $GLOBALS['warmupFixtureSuperUser']; $GLOBALS['warmupFixtureSuperUser'] = true;
                try { return $callback(); } finally { $GLOBALS['warmupFixtureSuperUser'] = $previous; }
            }
        }
        final class Cache
        {
            public static object $lazy;
            public static function getLazyCache(): object { return self::$lazy; }
        }
    }
}
namespace Piwik\API {
    if ($GLOBALS['warmupFixtureMode'] === 'helper') {
        final class Request
        {
            public static array $calls = [];
            public static bool $fail = false;
            public static bool $recordSuppressed = false;
            public function __construct(private array $request, array $defaults = []) {}
            public function process()
            {
                self::$calls[] = ['request' => $this->request, 'get' => $_GET, 'post' => $_POST, 'request_globals' => $_REQUEST,
                    'query' => $_SERVER['QUERY_STRING'] ?? null, 'superuser' => $GLOBALS['warmupFixtureSuperUser']];
                self::$recordSuppressed = !\Piwik\Plugins\SpeoReportPerformance\HotReportWarmup::record(
                    new \Piwik\Plugins\SpeoReportPerformance\RevisionContext($this->request));
                echo 'fixture-output-must-not-escape'; ob_start(); echo 'nested-fixture-buffer';
                if (self::$fail) { throw new \RuntimeException('Fixture API failure'); }
                return 'synthetic-only';
            }
        }
    }
}
namespace Piwik\Plugins\Actions {
    if ($GLOBALS['warmupFixtureMode'] === 'helper') {
        final class ArchivingHelper
        {
            public static int $reloads = 0;
            public static function reloadConfig(): void { ++self::$reloads; }
        }
    }
}
namespace Piwik\Translation {
    if ($GLOBALS['warmupFixtureMode'] === 'helper') {
        final class Translator
        {
            public string $language = 'de';
            public array $changes = [];
            public function getCurrentLanguage(): string { return $this->language; }
            public function setCurrentLanguage(string $language): void { $this->changes[] = $language; $this->language = $language; }
        }
    }
}
namespace Piwik\Plugins\SpeoReportPerformance {
    if ($GLOBALS['warmupFixtureMode'] === 'helper') {
        final class WarmupProcess
        {
            public static array $calls = [];
            public static array $plans = [];
            public static int $active = 0;
            public static int $maxActive = 0;
            private array $plan = [];
            private ?float $timeout = null;
            public function __construct(private array $command) {}
            public function setTimeout(?float $timeout): static { $this->timeout = $timeout; return $this; }
            public function run(): int
            {
                self::$calls[] = ['command' => $this->command, 'timeout' => $this->timeout];
                $this->plan = array_shift(self::$plans) ?? ['status' => 'skipped'];
                ++self::$active; self::$maxActive = max(self::$maxActive, self::$active);
                try {
                    $GLOBALS['warmupFixtureMonotonic'] += $this->plan['advance'] ?? 0;
                    if ($this->plan['throw'] ?? false) { throw new \RuntimeException('Fixture process timeout'); }
                    return ($this->plan['successful'] ?? true) ? 0 : 1;
                } finally { --self::$active; }
            }
            public function getOutput(): string {
                if (isset($this->plan['output'])) { return $this->plan['output']; }
                $result = ['status' => $this->plan['status'] ?? 'skipped'];
                if (array_key_exists('rebuild_attempted', $this->plan)) { $result['rebuild_attempted'] = $this->plan['rebuild_attempted']; }
                return json_encode($result);
            }
            public function isSuccessful(): bool { return $this->plan['successful'] ?? true; }
        }
    }
}
namespace {
    use Piwik\Plugins\SpeoReportPerformance\HotReportWarmup;
    use Piwik\Plugins\SpeoReportPerformance\RevisionContext;
    use Piwik\Plugins\SpeoReportPerformance\Commands\WarmReports;
    use Symfony\Component\Console\Tester\CommandTester;
    use Piwik\Plugins\SpeoReportPerformance\WarmupProcess as Process;

    umask(0077);
    speoFixtureBootstrap('warmup', true);
    if (!is_dir(PIWIK_DOCUMENT_ROOT)) { mkdir(PIWIK_DOCUMENT_ROOT, 0700, true); }
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
    $mode = $GLOBALS['warmupFixtureMode'];
    $privateDirectory = \Piwik\Plugins\SpeoReportPerformance\Configuration::get()->storagePath();
    $manifest = $privateDirectory . '/hot-reports.json';
    $results = [];
    function warmExpect(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
    function warmCheck(string $name, callable $test): void
    {
        global $results;
        try { $test(); $results[] = ['case' => $name, 'status' => 'pass']; }
        catch (\Throwable $error) { $results[] = ['case' => $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()]; }
    }
    function warmRequest(int $id = 1): array
    {
        return ['module' => 'API', 'method' => 'Actions.getPageUrls', 'idSite' => $id, 'period' => 'month',
            'date' => '2026-10-01', 'flat' => 1, 'filter_limit' => 500, 'filter_offset' => 0,
            'filter_sort_column' => 'nb_hits', 'filter_sort_order' => 'desc', 'format' => 'JSON',
            'format_metrics' => 1, 'totals' => 1];
    }
    function warmManifest(): array
    {
        global $manifest;
        return json_decode(file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
    }
    function writeWarmManifest(array $value): void
    {
        global $manifest;
        file_put_contents($manifest, json_encode($value, JSON_THROW_ON_ERROR)); chmod($manifest, 0600); clearstatcache();
    }
    function resetWarmFixture(): void
    {
        global $privateDirectory;
        $lock = new \ReflectionProperty(HotReportWarmup::class, 'workerLock');
        $resource = $lock->getValue(); if (is_resource($resource)) { flock($resource, LOCK_UN); fclose($resource); }
        $lock->setValue(null);
        (new \ReflectionProperty(HotReportWarmup::class, 'warming'))->setValue(false);
        if (is_dir($privateDirectory) && !is_link($privateDirectory)) {
            foreach (array_merge(glob($privateDirectory . '/*'), glob($privateDirectory . '/.hot-*')) as $path) {
                if (is_file($path) || is_link($path)) { unlink($path); }
            }
        } elseif (is_link($privateDirectory)) { unlink($privateDirectory); }
        $GLOBALS['warmupFixtureNow'] = 1800000000; $GLOBALS['warmupFixtureMonotonic'] = 1000.0;
        $GLOBALS['warmupFixtureSettings'] = [];
        $GLOBALS['warmupFixtureForeignOwner'] = false;
        Process::$calls = Process::$plans = []; Process::$active = Process::$maxActive = 0;
        \Piwik\API\Request::$calls = []; \Piwik\API\Request::$fail = false; \Piwik\API\Request::$recordSuppressed = false;
        RevisionContext::$decline = false; RevisionContext::$prepared = [];
        \Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard::$pipeline = true;
        \Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard::$cachePipeline = true;
        \Piwik\Access::$calls = 0; \Piwik\Plugins\Actions\ArchivingHelper::$reloads = 0;
        \Piwik\Cache::$lazy->warm = false; \Piwik\Cache::$lazy->fail = false; \Piwik\Cache::$lazy->keys = [];
    }
    function recordWarmTarget(array $request = [], string $language = 'en'): string
    {
        warmExpect(HotReportWarmup::record(new RevisionContext($request ?: warmRequest(), $language)), 'recording supported target failed');
        return array_key_first(warmManifest()['targets']);
    }

    if ($mode === 'helper') {
        $container = new \Piwik\Container\Container();
        \Piwik\Container\StaticContainer::push($container);
        $translator = new \Piwik\Translation\Translator();
        $container->set(\Piwik\Translation\Translator::class, $translator);
        \Piwik\Cache::$lazy = new class {
            public bool $warm = false;
            public bool $fail = false;
            public array $keys = [];
            public function contains(string $key): bool {
                $this->keys[] = $key;
                if ($this->fail) { throw new \RuntimeException('Fixture pre-API cache failure'); }
                return $this->warm;
            }
        };
        resetWarmFixture();
        warmCheck('manifest-valid-private-atomic-token-free', function () use ($manifest, $privateDirectory) {
            recordWarmTarget();
            $data = warmManifest(); $id = array_key_first($data['targets']);
            warmExpect(array_keys($data) === ['schema', 'targets'] && $data['schema'] === 1, 'manifest envelope incorrect');
            warmExpect(count($data['targets']) === 1 && preg_match('/\A[a-f0-9]{64}\z/D', $id) === 1, 'opaque target ID invalid');
            warmExpect((fileperms($privateDirectory) & 0777) === 0700 && (fileperms($manifest) & 0777) === 0600, 'private modes incorrect');
            $inode = fileinode($manifest); $lockInode = fileinode($privateDirectory . '/manifest.lock');
            $GLOBALS['warmupFixtureNow'] += HotReportWarmup::RECORD_INTERVAL; recordWarmTarget(); clearstatcache();
            warmExpect(fileinode($manifest) !== $inode && fileinode($privateDirectory . '/manifest.lock') === $lockInode, 'atomic replacement/stable lock not observed');
            warmExpect(glob($privateDirectory . '/.hot-*') === [], 'temporary manifest leaked');
        });
        warmCheck('record-key-normalization-cooldown-skips-manifest-write', function () use ($manifest) {
            resetWarmFixture(); $id = recordWarmTarget(); $request = array_reverse(warmRequest(), true);
            $lastSeen = $GLOBALS['warmupFixtureNow']; $before = file_get_contents($manifest); $inode = fileinode($manifest);
            $GLOBALS['warmupFixtureNow'] += 100; recordWarmTarget($request);
            $targets = warmManifest()['targets']; clearstatcache();
            warmExpect(count($targets) === 1 && array_key_first($targets) === $id && $targets[$id]['lastSeen'] === $lastSeen
                && file_get_contents($manifest) === $before && fileinode($manifest) === $inode,
                'repeat target was rewritten within five-minute cooldown');
        });
        warmCheck('record-refreshes-at-exact300-second-boundary', function () use ($manifest) {
            resetWarmFixture(); $id = recordWarmTarget(); $before = file_get_contents($manifest); $inode = fileinode($manifest);
            $GLOBALS['warmupFixtureNow'] += HotReportWarmup::RECORD_INTERVAL - 1; recordWarmTarget(); clearstatcache();
            warmExpect(file_get_contents($manifest) === $before && fileinode($manifest) === $inode, '299-second hint was rewritten');
            ++$GLOBALS['warmupFixtureNow']; recordWarmTarget(); clearstatcache();
            warmExpect(warmManifest()['targets'][$id]['lastSeen'] === $GLOBALS['warmupFixtureNow']
                && fileinode($manifest) !== $inode, '300-second hint did not refresh atomically');
        });
        warmCheck('record-cooldown-is-per-target-not-global', function () use ($manifest) {
            resetWarmFixture(); $first = recordWarmTarget(warmRequest(1)); $initial = $GLOBALS['warmupFixtureNow'];
            $GLOBALS['warmupFixtureNow'] += 100; recordWarmTarget(warmRequest(2));
            $targets = warmManifest()['targets'];
            warmExpect(count($targets) === 2 && $targets[$first]['lastSeen'] === $initial, 'new target was suppressed by another target cooldown');
            $before = file_get_contents($manifest); $inode = fileinode($manifest);
            ++$GLOBALS['warmupFixtureNow']; recordWarmTarget(warmRequest(1)); clearstatcache();
            warmExpect(file_get_contents($manifest) === $before && fileinode($manifest) === $inode,
                'cooldown repeat changed another target or manifest');
        });
        warmCheck('optional-manifest-never-fsyncs', function () {
            resetWarmFixture(); recordWarmTarget();
            $GLOBALS['warmupFixtureNow'] += HotReportWarmup::RECORD_INTERVAL; recordWarmTarget();
            warmExpect($GLOBALS['warmupFixtureFsyncCalls'] === 0, 'optional cache hint requested durable fsync');
        });
        warmCheck('record-max20-newest-first', function () {
            resetWarmFixture();
            for ($i = 1; $i <= 25; ++$i) { ++$GLOBALS['warmupFixtureNow']; recordWarmTarget(warmRequest($i)); }
            $targets = array_values(warmManifest()['targets']);
            warmExpect(count($targets) === 20 && $targets[0]['request']['idSite'] === 25 && $targets[19]['request']['idSite'] === 6, 'max20/recency trimming incorrect');
        });
        warmCheck('record-seven-day-expiry-inclusive', function () {
            resetWarmFixture(); recordWarmTarget(warmRequest(1));
            $GLOBALS['warmupFixtureNow'] += HotReportWarmup::MAX_AGE; recordWarmTarget(warmRequest(2));
            warmExpect(count(warmManifest()['targets']) === 2, 'exact seven-day boundary expired too early');
            ++$GLOBALS['warmupFixtureNow']; recordWarmTarget(warmRequest(3));
            $ids = array_column(array_values(warmManifest()['targets']), 'request');
            warmExpect(count($ids) === 2 && !in_array(1, array_column($ids, 'idSite'), true), 'expired target retained');
        });
        foreach (['bc', 'all', true, false, 1, 0, '1', '0', ''] as $i => $value) {
            warmCheck('record-supported-format-metrics-' . $i, function () use ($value) {
                resetWarmFixture(); $r = warmRequest(); $r['format_metrics'] = $value; recordWarmTarget($r);
                $targets = warmManifest()['targets'];
                warmExpect(reset($targets)['request']['format_metrics'] === $value, 'metrics flag changed');
            });
        }
        foreach ([false, null, ''] as $i => $value) {
            warmCheck('record-decline-present-empty-subtable-' . $i, function () use ($value, $manifest) {
                resetWarmFixture(); recordWarmTarget(); $before = file_get_contents($manifest);
                $r = warmRequest(); $r['idSubtable'] = $value;
                warmExpect(!HotReportWarmup::record(new RevisionContext($r)) && file_get_contents($manifest) === $before,
                    'explicit empty subtable presence was normalized into a different warm base');
            });
        }
        foreach ([0, '0', []] as $i => $value) {
            warmCheck('record-decline-native-subtable-zero-or-array-' . $i, function () use ($value, $manifest) {
                resetWarmFixture(); recordWarmTarget(); $before = file_get_contents($manifest);
                $r = warmRequest(); $r['idSubtable'] = $value;
                warmExpect(!HotReportWarmup::record(new RevisionContext($r)), 'real or invalid subtable request accepted');
                warmExpect(file_get_contents($manifest) === $before, 'declined subtable modified manifest');
            });
        }
        foreach (['true', 'false', ''] as $i => $value) {
            warmCheck('record-observed-actions-ui-search-recursive-' . $i, function () use ($value) {
                resetWarmFixture(); $r = warmRequest();
                $r['module'] = 'Actions'; $r['action'] = 'getPageUrls';
                $r['viewDataTable'] = 'table'; $r['search_recursive'] = $value;
                recordWarmTarget($r);
                $targets = warmManifest()['targets']; $stored = reset($targets)['request'];
                ksort($r, SORT_STRING);
                warmExpect($stored === $r, 'actual Actions UI fields, presence or values were dropped/changed');
            });
        }
        warmCheck('record-existing-api-index-with-ui-fields-preserved', function () {
            resetWarmFixture(); $r = warmRequest(); $r['module'] = 'API'; $r['action'] = 'index'; $r['viewDataTable'] = 'table';
            recordWarmTarget($r); $targets = warmManifest()['targets']; $stored = reset($targets)['request'];
            ksort($r, SORT_STRING);
            warmExpect($stored === $r, 'existing API/index request changed');
        });
        warmCheck('record-corehome-render-report-widget-pair-preserved', function () {
            resetWarmFixture(); $r = warmRequest(); $r['module'] = 'CoreHome'; $r['action'] = 'renderReportWidget';
            $r['viewDataTable'] = 'table'; $r['search_recursive'] = 'true'; recordWarmTarget($r);
            $targets = warmManifest()['targets']; $stored = reset($targets)['request']; ksort($r, SORT_STRING);
            warmExpect($stored === $r, 'CoreHome/widget bridge changed or dropped fields');
        });
        $badRequests = [
            'token' => ['token_auth' => 'fixture-secret-marker'], 'label' => ['label' => '/private-title'],
            'pattern' => ['filter_pattern' => 'private-pattern'], 'unknown' => ['future_flag' => 1],
            'segment' => ['segment' => 'countryCode==CH'], 'compare' => ['compareDates' => ['2026-09-01']],
            'wrong-method' => ['method' => 'Live.getLastVisitsDetails'], 'wrong-period' => ['period' => 'day'],
            'wrong-limit' => ['filter_limit' => -1], 'wrong-offset' => ['filter_offset' => 100],
            'wrong-date' => ['date' => '2026-10-02'], 'invalid-month' => ['date' => '2026-13-01'],
            'site-string' => ['idSite' => '1'], 'site-zero' => ['idSite' => 0], 'nonflat' => ['flat' => 0],
            'custom-total-label' => ['keep_totals_row_label' => 'User content'], 'bad-metric-mode' => ['format_metrics' => 'future'],
            'unrelated-module' => ['module' => 'Live'], 'unrelated-action' => ['action' => 'getLastVisitsDetails'],
            'unsafe-search-string' => ['search_recursive' => 'yes'], 'unsafe-search-array' => ['search_recursive' => ['true']],
            'transport-showtitle' => ['showtitle' => 1], 'transport-forceview' => ['forceView' => 1],
            'transport-footer-state' => ['isFooterExpandedInDashboard' => true],
            'corehome-index-crosspair' => ['module' => 'CoreHome', 'action' => 'index'],
            'corehome-getpageurls-crosspair' => ['module' => 'CoreHome', 'action' => 'getPageUrls'],
            'api-renderwidget-crosspair' => ['module' => 'API', 'action' => 'renderReportWidget'],
            'actions-renderwidget-crosspair' => ['module' => 'Actions', 'action' => 'renderReportWidget'],
        ];
        foreach ($badRequests as $name => $overrides) {
            warmCheck('record-decline-' . $name, function () use ($overrides, $manifest) {
                resetWarmFixture(); recordWarmTarget(); $before = file_get_contents($manifest);
                warmExpect(!HotReportWarmup::record(new RevisionContext(array_replace(warmRequest(), $overrides))), 'privacy/shape-invalid request accepted');
                warmExpect(file_get_contents($manifest) === $before, 'declined record modified private manifest');
            });
        }
        warmCheck('record-language-decline', function () { resetWarmFixture(); warmExpect(!HotReportWarmup::record(new RevisionContext(warmRequest(), '../../etc/passwd')), 'unsafe language accepted'); });
        warmCheck('record-manifest-lock-busy-nonblocking', function () use ($privateDirectory, $manifest) {
            resetWarmFixture(); recordWarmTarget(); $before = file_get_contents($manifest);
            $lock = fopen($privateDirectory . '/manifest.lock', 'c'); flock($lock, LOCK_EX);
            try { warmExpect(!HotReportWarmup::record(new RevisionContext(warmRequest(2))), 'busy manifest lock ignored'); }
            finally { flock($lock, LOCK_UN); fclose($lock); }
            warmExpect(file_get_contents($manifest) === $before, 'busy record modified manifest');
        });
        foreach (['schema', 'hash', 'future', 'permissions', 'oversized', 'json', 'symlink'] as $corruption) {
            warmCheck('record-corrupt-manifest-' . $corruption, function () use ($corruption, $manifest, $privateDirectory) {
                resetWarmFixture(); recordWarmTarget(); $data = warmManifest(); $id = array_key_first($data['targets']);
                if ($corruption === 'schema') { $data['schema'] = 2; writeWarmManifest($data); }
                elseif ($corruption === 'hash') { $target = $data['targets'][$id]; $data['targets'] = [str_repeat('0', 64) => $target]; writeWarmManifest($data); }
                elseif ($corruption === 'future') { $data['targets'][$id]['lastSeen'] = $GLOBALS['warmupFixtureNow'] + 301; writeWarmManifest($data); }
                elseif ($corruption === 'permissions') { chmod($manifest, 0644); }
                elseif ($corruption === 'oversized') { file_put_contents($manifest, str_repeat('x', 65537)); }
                elseif ($corruption === 'json') { file_put_contents($manifest, '{'); }
                else { rename($manifest, $privateDirectory . '/fixture-original.json'); symlink($privateDirectory . '/fixture-original.json', $manifest); }
                clearstatcache(); $before = file_get_contents($manifest);
                warmExpect(!HotReportWarmup::record(new RevisionContext(warmRequest(2))), 'invalid manifest accepted/overwritten');
                warmExpect(file_get_contents($manifest) === $before, 'invalid manifest changed');
            });
        }
        warmCheck('parent-scans-past-warm-targets-max3-rebuilds-sequential-argv', function () {
            resetWarmFixture(); for ($i = 1; $i <= 5; ++$i) { ++$GLOBALS['warmupFixtureNow']; recordWarmTarget(warmRequest($i)); }
            Process::$plans = [['status' => 'requested', 'advance' => 10], ['status' => 'warm', 'advance' => 20],
                ['status' => 'busy'], ['status' => 'requested'], ['status' => 'requested']];
            $result = HotReportWarmup::run();
            warmExpect($result === ['attempted' => 5, 'rebuild_attempts' => 3, 'requested' => 3, 'warm' => 1, 'skipped' => 1, 'failed' => 0], 'batch counts incorrect');
            warmExpect(Process::$maxActive === 1 && Process::$active === 0 && count(Process::$calls) === 5, 'workers were not bounded/sequential');
            warmExpect(array_column(Process::$calls, 'timeout') === [120.0, 110.0, 90.0, 90.0, 90.0], 'remaining-wall-time budgets incorrect');
            foreach (Process::$calls as $call) {
                warmExpect(array_slice($call['command'], 0, 5) === [PHP_BINARY, '-d', 'memory_limit=2G', PIWIK_DOCUMENT_ROOT . '/console', 'speo:reports-warm'], 'worker argv was changed or shell-based');
                warmExpect(preg_match('/\A--target=[a-f0-9]{64}\z/D', $call['command'][5]) === 1, 'worker argument exposes request data');
            }
            warmExpect(\Piwik\API\Request::$calls === [], 'parent directly executed an API request');
        });
        warmCheck('parent-all20-warm-are-checked-without-rebuild', function () {
            resetWarmFixture(); for ($i = 1; $i <= 20; ++$i) { recordWarmTarget(warmRequest($i)); }
            Process::$plans = array_fill(0, 20, ['status' => 'warm']);
            $result = HotReportWarmup::run();
            warmExpect($result['attempted'] === 20 && $result['warm'] === 20 && $result['rebuild_attempts'] === 0
                && count(Process::$calls) === 20, 'warm targets exhausted rebuild cap or scan limit changed');
        });
        warmCheck('parent-stops-after-three-real-rebuilds', function () {
            resetWarmFixture(); for ($i = 1; $i <= 20; ++$i) { recordWarmTarget(warmRequest($i)); }
            Process::$plans = array_fill(0, 20, ['status' => 'requested']);
            $result = HotReportWarmup::run();
            warmExpect($result['attempted'] === 3 && $result['requested'] === 3 && $result['rebuild_attempts'] === 3,
                'actual rebuilds exceeded max3');
        });
        warmCheck('parent-ineligible-and-pre-api-failures-do-not-use-rebuild-slots', function () {
            resetWarmFixture(); for ($i = 1; $i <= 8; ++$i) { recordWarmTarget(warmRequest($i)); }
            Process::$plans = [['status' => 'warm'], ['status' => 'skipped'],
                ['status' => 'failed', 'successful' => false, 'rebuild_attempted' => false],
                ['status' => 'requested'], ['status' => 'requested'], ['status' => 'requested'], ['status' => 'requested']];
            $result = HotReportWarmup::run();
            warmExpect($result['attempted'] === 6 && $result['requested'] === 3 && $result['failed'] === 1
                && $result['rebuild_attempts'] === 3, 'declined/pre-api failures starved later missing bases');
        });
        warmCheck('parent-stops-at120-seconds', function () {
            resetWarmFixture(); for ($i = 1; $i <= 3; ++$i) { recordWarmTarget(warmRequest($i)); }
            Process::$plans = [['status' => 'warm', 'advance' => 120]];
            $r = HotReportWarmup::run(); warmExpect($r['attempted'] === 1 && count(Process::$calls) === 1, 'deadline allowed another worker');
        });
        warmCheck('parent-timeout-nonzero-and-malformed-output', function () {
            resetWarmFixture(); for ($i = 1; $i <= 3; ++$i) { recordWarmTarget(warmRequest($i)); }
            Process::$plans = [['successful' => false], ['throw' => true], ['output' => 'fixture invalid JSON']];
            $r = HotReportWarmup::run(); warmExpect($r['attempted'] === 3 && $r['failed'] === 3 && $r['skipped'] === 0
                && $r['rebuild_attempts'] === 3, 'unknown/fatal failures escaped actual rebuild cap');
        });
        warmCheck('parent-post-api-failures-consume-rebuild-slots', function () {
            resetWarmFixture(); for ($i = 1; $i <= 8; ++$i) { recordWarmTarget(warmRequest($i)); }
            Process::$plans = array_fill(0, 8, ['status' => 'failed', 'successful' => false, 'rebuild_attempted' => true]);
            $r = HotReportWarmup::run();
            warmExpect($r['attempted'] === 3 && $r['failed'] === 3 && $r['rebuild_attempts'] === 3,
                'failed API rebuild attempts escaped max3');
        });
        foreach (['warm', 'requested', 'failed', 'context-decline'] as $scenario) {
            warmCheck('worker-' . $scenario . '-restores-globals-language-buffers', function () use ($scenario, $translator, $manifest) {
                resetWarmFixture(); $id = recordWarmTarget(); $manifestBefore = file_get_contents($manifest);
                \Piwik\Cache::$lazy->warm = $scenario === 'warm'; \Piwik\API\Request::$fail = $scenario === 'failed'; RevisionContext::$decline = $scenario === 'context-decline';
                $_GET = ['fixture_get' => 'old']; $_POST = ['fixture_post' => 'old']; $_REQUEST = ['fixture_request' => 'old']; $_SERVER['QUERY_STRING'] = 'fixture_original=1';
                $expectedGlobals = [$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']]; $oldLanguage = $translator->language;
                ob_start(); $result = HotReportWarmup::run($id); $escaped = ob_get_clean();
                $expectedStatus = $scenario === 'context-decline' ? 'skipped' : $scenario;
                $expectedResult = ['status' => $expectedStatus];
                if ($scenario === 'failed') { $expectedResult['rebuild_attempted'] = true; }
                warmExpect($result === $expectedResult, 'worker status incorrect');
                warmExpect($escaped === '' && [$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']] === $expectedGlobals, 'worker leaked output or globals');
                warmExpect($translator->language === $oldLanguage && !$GLOBALS['warmupFixtureSuperUser'], 'language/access scope not restored');
                warmExpect(\Piwik\Plugins\Actions\ArchivingHelper::$reloads === 2, 'archiving config not restored');
                warmExpect(file_get_contents($manifest) === $manifestBefore, 'background worker refreshed user demand');
                $apiExpected = in_array($scenario, ['requested', 'failed'], true);
                warmExpect(count(\Piwik\API\Request::$calls) === ($apiExpected ? 1 : 0), 'warm/declined target recomputed or missing target not requested');
                if ($apiExpected) {
                    $call = \Piwik\API\Request::$calls[0]; $target = warmManifest()['targets'][$id]['request'];
                    warmExpect($call['request'] === $target && $call['get'] === $target && $call['post'] === [] && $call['request_globals'] === $target && $call['superuser'], 'worker did not preserve native request/superuser context');
                    warmExpect($call['request']['date'] === '2026-10-01' && \Piwik\API\Request::$recordSuppressed, 'request date shifted or worker self-recorded');
                }
            });
        }
        warmCheck('worker-lock-remains-held-after-api-return', function () use ($privateDirectory) {
            resetWarmFixture(); $id = recordWarmTarget(); \Piwik\Cache::$lazy->warm = true; HotReportWarmup::run($id);
            $other = fopen($privateDirectory . '/worker.lock', 'c');
            try { warmExpect(!flock($other, LOCK_EX | LOCK_NB), 'worker lock released before shutdown/base encoding'); }
            finally { fclose($other); }
        });
        warmCheck('worker-pre-api-failure-reports-no-rebuild-attempt', function () {
            resetWarmFixture(); $id = recordWarmTarget(); \Piwik\Cache::$lazy->fail = true;
            $result = HotReportWarmup::run($id);
            warmExpect($result === ['status' => 'failed', 'rebuild_attempted' => false]
                && \Piwik\API\Request::$calls === [] && !$GLOBALS['warmupFixtureSuperUser'],
                'pre-API failure incorrectly consumed a real rebuild or leaked auth scope');
        });
        foreach (['pipeline', 'cachePipeline'] as $guard) {
            warmCheck('worker-sourceguard-decline-' . $guard, function () use ($guard) {
                resetWarmFixture(); $id = recordWarmTarget();
                \Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard::${$guard} = false;
                warmExpect(HotReportWarmup::run($id) === ['status' => 'skipped'], 'unreviewed pipeline executed warmup');
                warmExpect(RevisionContext::$prepared === [] && \Piwik\API\Request::$calls === [], 'guard decline prepared context or executed API');
            });
        }
        warmCheck('worker-busy-no-context-or-api', function () use ($privateDirectory) {
            resetWarmFixture(); $id = recordWarmTarget(); $lock = fopen($privateDirectory . '/worker.lock', 'c'); flock($lock, LOCK_EX);
            try { warmExpect(HotReportWarmup::run($id) === ['status' => 'busy'], 'busy worker was not skipped'); }
            finally { flock($lock, LOCK_UN); fclose($lock); }
            warmExpect(RevisionContext::$prepared === [] && \Piwik\API\Request::$calls === [], 'busy worker entered native request');
        });
        warmCheck('worker-invalid-missing-target-and-language', function () {
            resetWarmFixture(); warmExpect(HotReportWarmup::run('invalid') === ['status' => 'skipped'], 'invalid opaque ID accepted');
            warmExpect(HotReportWarmup::run(str_repeat('f', 64)) === ['status' => 'skipped'], 'unknown target executed');
            resetWarmFixture(); $id = recordWarmTarget([], 'zz');
            warmExpect(!is_file(PIWIK_INCLUDE_PATH . '/lang/zz.json') && HotReportWarmup::run($id) === ['status' => 'skipped'], 'missing language entered native API');
            warmExpect(\Piwik\API\Request::$calls === [], 'invalid worker touched API');
        });
        warmCheck('newly-created-directory-owner-rechecked-before-write', function () use ($privateDirectory, $manifest) {
            resetWarmFixture();
            if (is_dir($privateDirectory)) { rmdir($privateDirectory); }
            $GLOBALS['warmupFixtureForeignOwner'] = true;
            try {
                warmExpect(!HotReportWarmup::record(new RevisionContext(warmRequest())), 'Directory created with a different owner was adopted');
                warmExpect(!file_exists($manifest), 'Foreign-owner directory received private state');
            } finally { $GLOBALS['warmupFixtureForeignOwner'] = false; }
        });
        warmCheck('configured-warmup-disable-no-work', function () use ($manifest) {
            resetWarmFixture(); recordWarmTarget(); $before = file_get_contents($manifest);
            $GLOBALS['warmupFixtureSettings'] = ['warmup_enabled' => 0];
            warmExpect(!HotReportWarmup::record(new RevisionContext(warmRequest(2))), 'disabled warmup still recorded demand');
            warmExpect(HotReportWarmup::run()['attempted'] === 0 && Process::$calls === [], 'disabled warmup started workers');
            warmExpect(file_get_contents($manifest) === $before, 'disabled warmup changed state');
        });
        warmCheck('configured-job-memory-and-target-limits', function () {
            resetWarmFixture(); $GLOBALS['warmupFixtureSettings'] = ['warmup_max_targets' => 4, 'warmup_max_jobs' => 2, 'warmup_memory_limit' => '768M'];
            for ($id = 1; $id <= 5; ++$id) { ++$GLOBALS['warmupFixtureNow']; recordWarmTarget(warmRequest($id)); }
            warmExpect(count(warmManifest()['targets']) === 4, 'configured target cap ignored');
            Process::$plans = array_fill(0, 5, ['status' => 'requested']);
            $result = HotReportWarmup::run();
            warmExpect($result['rebuild_attempts'] === 2 && count(Process::$calls) === 2, 'configured rebuild cap ignored');
            warmExpect(Process::$calls[0]['command'][2] === 'memory_limit=768M', 'configured worker memory ignored');
        });
        warmCheck('configured-time-budget', function () {
            resetWarmFixture();
            for ($id = 1; $id <= 4; ++$id) { ++$GLOBALS['warmupFixtureNow']; recordWarmTarget(warmRequest($id)); }
            $GLOBALS['warmupFixtureSettings'] = ['warmup_max_seconds' => 15];
            Process::$plans = array_fill(0, 4, ['status' => 'warm', 'advance' => 8]);
            $result = HotReportWarmup::run();
            warmExpect($result['attempted'] === 2 && array_column(Process::$calls, 'timeout') === [15.0, 7.0], 'configured elapsed budget ignored');
        });
        warmCheck('configured-interval-and-age', function () use ($manifest) {
            resetWarmFixture(); $GLOBALS['warmupFixtureSettings'] = ['warmup_record_interval' => 10, 'warmup_target_max_age' => 3600];
            $first = recordWarmTarget(); $before = file_get_contents($manifest);
            $GLOBALS['warmupFixtureNow'] += 9; recordWarmTarget();
            warmExpect(file_get_contents($manifest) === $before, 'configured record interval ignored');
            ++$GLOBALS['warmupFixtureNow']; recordWarmTarget();
            warmExpect(file_get_contents($manifest) !== $before, 'configured interval never refreshed');
            $GLOBALS['warmupFixtureNow'] += 3601; recordWarmTarget(warmRequest(2));
            warmExpect(!isset(warmManifest()['targets'][$first]), 'configured target age ignored');
        });
        resetWarmFixture();
    } else {
        warmCheck('native-signature-name-definition', function () {
            $command = new WarmReports();
            warmExpect($command instanceof \Piwik\Plugin\ConsoleCommand && $command->getName() === 'speo:reports-warm', 'native command interface incorrect');
            $option = $command->getDefinition()->getOption('target');
            warmExpect($option->isValueRequired() && !$option->isArray() && $option->getDefault() === null, 'target parser semantics incorrect');
        });
        warmCheck('native-help-never-executes-warmup', function () {
            HotReportWarmup::$calls = [];
            $command = new WarmReports(); $help = new \Symfony\Component\Console\Command\HelpCommand(); $help->setCommand($command);
            $tester = new CommandTester($help); $exit = $tester->execute([], ['interactive' => false]); $display = $tester->getDisplay();
            warmExpect($exit === 0 && str_contains($display, 'speo:reports-warm') && str_contains($display, '--target') && str_contains($display, '120'), 'native help rendering failed');
            warmExpect(HotReportWarmup::$calls === [], 'help executed a warmup');
        });
        warmCheck('native-command-default-batch-json', function () {
            HotReportWarmup::$calls = []; HotReportWarmup::$result = ['attempted' => 3, 'requested' => 1, 'warm' => 2, 'skipped' => 0, 'failed' => 0];
            $tester = new CommandTester(new WarmReports()); $code = $tester->execute([], ['interactive' => false]);
            warmExpect($code === 0 && HotReportWarmup::$calls === [null] && json_decode(trim($tester->getDisplay()), true) === HotReportWarmup::$result, 'batch command delegation/JSON incorrect');
        });
        warmCheck('native-command-batch-failed-count-exits1', function () {
            HotReportWarmup::$calls = []; HotReportWarmup::$result = ['attempted' => 4, 'rebuild_attempts' => 3,
                'requested' => 2, 'warm' => 1, 'skipped' => 0, 'failed' => 1];
            $tester = new CommandTester(new WarmReports()); $code = $tester->execute([], ['interactive' => false]);
            warmExpect($code === 1 && HotReportWarmup::$calls === [null]
                && json_decode(trim($tester->getDisplay()), true) === HotReportWarmup::$result, 'batch failures did not propagate exit1');
        });
        foreach (['warm', 'requested', 'busy', 'skipped', 'failed'] as $status) {
            warmCheck('native-command-target-status-' . $status, function () use ($status) {
                HotReportWarmup::$calls = []; HotReportWarmup::$result = ['status' => $status]; $id = str_repeat('a', 64);
                $tester = new CommandTester(new WarmReports()); $code = $tester->execute(['--target' => $id], ['interactive' => false]);
                warmExpect($code === ($status === 'failed' ? 1 : 0) && HotReportWarmup::$calls === [$id]
                    && json_decode(trim($tester->getDisplay()), true) === ['status' => $status], 'target status/exit mapping incorrect');
            });
        }
        foreach (['', 'x', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65), '../../fixture'] as $i => $value) {
            warmCheck('native-command-invalid-target-' . $i, function () use ($value) {
                HotReportWarmup::$calls = []; $thrown = false;
                try { (new CommandTester(new WarmReports()))->execute(['--target' => $value], ['interactive' => false]); }
                catch (\InvalidArgumentException $error) { $thrown = true; }
                warmExpect($thrown && HotReportWarmup::$calls === [], 'invalid target reached warmup helper');
            });
        }
        warmCheck('native-command-unknown-option-rejected-before-helper', function () {
            HotReportWarmup::$calls = []; $thrown = false;
            try { (new CommandTester(new WarmReports()))->execute(['--future-option' => 'x'], ['interactive' => false]); }
            catch (\Symfony\Component\Console\Exception\InvalidOptionException $error) { $thrown = true; }
            warmExpect($thrown && HotReportWarmup::$calls === [], 'unknown option reached helper');
        });
    }
    $failed = count(array_filter($results, static fn($r) => $r['status'] !== 'pass'));
    $report = ['fixture' => 'warmup-' . $mode, 'network_namespace_isolated' => true, 'application_bootstrap' => false,
        'database_or_customer_reports' => false, 'copy_lifecycle_publication' => 'explicit own double; not lifecycle proof', 'helper_source_sha256' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/HotReportWarmup.php'),
        'command_source_sha256' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/Commands/WarmReports.php'),
        'checks' => count($results), 'passed' => count($results) - $failed, 'failed' => $failed, 'results' => $results];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    speoFixtureReport('warmup-' . $mode, $json); echo $json;
    exit($failed ? 1 : 0);
}
