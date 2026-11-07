<?php
/** Synthetic version ARGUMENTS only. Does not load/test those Matomo releases. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
speoAssertIsolated();
umask(0077);
speoFixtureBootstrap('future-version-contract');
$loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
$loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
use Piwik\DataTable;
use Piwik\Plugins\SpeoReportPerformance\RequestEligibility;
use Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard;

$versions = ['5.14.2', '5.14.3', '5.99.99', '6.0.0', '99.0.0'];
$valid = ['module' => 'API', 'action' => 'index', 'method' => 'Actions.getPageUrls', 'idSite' => 1,
    'period' => 'month', 'date' => '2026-10-01', 'flat' => '1', 'format' => 'original',
    'serialize' => '0', 'filter_limit' => 500];
$info = ['module' => 'Actions', 'action' => 'getPageUrls'];
$table = new DataTable(); $results = [];
function versionCheck(string $name, callable $test): void {
    global $results;
    try { $test(); $results[] = ['case' => $name, 'status' => 'pass']; }
    catch (\Throwable $error) { $results[] = ['case' => $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()]; }
}
function versionAssert(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
$unsupported = [
    'hierarchical' => [['flat' => 0], 'not-flat'],
    'unlimited-export' => [['filter_limit' => -1], 'native-large-export'],
    'oversized-page' => [['filter_limit' => 1001], 'native-large-export'],
    'unfiltered-export' => [['disable_generic_filters' => 1], 'native-unfiltered-export'],
    'subtable-integer-zero' => [['idSubtable' => 0], 'native-subtable-request'],
    'subtable-string-zero' => [['idSubtable' => '0'], 'native-subtable-request'],
    'subtable-array' => [['idSubtable' => []], 'native-subtable-request'],
    'multi-site' => [['idSite' => 'all'], 'non-single-site'],
    'pivot' => [['pivotBy' => 'Actions.PageUrl'], 'unsupported-pivotBy'],
    'comparison' => [['compare' => 1], 'unsupported-compare'],
    'nonempty-comparison-dates' => [['compareDates' => ['2026-09-01']], 'unsupported-compareDates'],
    'disabled-queues' => [['disable_queued_filters' => 1], 'unsupported-disable_queued_filters'],
    'foreign-visualization' => [['viewDataTable' => 'pie'], 'unsupported-viewDataTable'],
    'unsafe-ui-shape' => [['search_recursive' => ['1']], 'unsupported-search_recursive'],
    'unknown-parameter' => [['future_unknown_flag' => true], 'unknown-parameter'],
];
foreach ($versions as $version) {
    versionCheck('same-valid-request-accepts-synthetic-version-' . $version, static function () use ($version, $valid, $info, $table) {
        versionAssert(RequestEligibility::reason($table, $info, $valid, $version) === null, 'valid shape declined because of synthetic version argument');
    });
    versionCheck('same-ui-presence-accepts-synthetic-version-' . $version, static function () use ($version, $valid, $info, $table) {
        $ui = array_replace($valid, ['module' => 'CoreHome', 'action' => 'renderReportWidget', 'viewDataTable' => 'table',
            'uniqueId' => 'widgetActionsgetPageUrls', 'widget' => '', 'idSubtable' => false, 'compareDates' => [], 'search_recursive' => 'true']);
        versionAssert(RequestEligibility::reason($table, $info, $ui, $version) === null, 'valid UI shape depends on synthetic version argument');
    });
    foreach ($unsupported as $name => [$changes, $expected]) {
        versionCheck($name . '-same-decline-synthetic-version-' . $version, static function () use ($changes, $expected, $version, $valid, $info, $table) {
            $request = array_replace($valid, $changes);
            versionAssert(RequestEligibility::reason($table, $info, $request, $version) === $expected, 'unsupported shape changed its decline reason');
        });
    }
    versionCheck('different-api-same-decline-synthetic-version-' . $version, static function () use ($version, $valid, $table) {
        versionAssert(RequestEligibility::reason($table, ['module' => 'Live', 'action' => 'getLastVisitsDetails'], $valid, $version) === 'different-api', 'foreign API shape accepted');
    });
    versionCheck('different-value-class-same-decline-synthetic-version-' . $version, static function () use ($version, $valid, $info) {
        versionAssert(RequestEligibility::reason(new \stdClass(), $info, $valid, $version) === 'non-single-datatable', 'foreign value shape accepted');
    });
}
versionCheck('public-native-method-capability-without-plugin-manager', static function () {
    versionAssert(NativeSourceGuard::publicMethod(DataTable::class, 'getRows'), 'documented native public method missing');
});
versionCheck('missing-native-method-capability-declines-without-plugin-manager', static function () {
    versionAssert(!NativeSourceGuard::publicMethod(DataTable::class, '__speo_nonexistent_contract_method'), 'missing native method accepted');
});
versionCheck('no-application-environment-or-plugin-manager-loaded', static function () {
    versionAssert(!class_exists('Piwik\\Environment', false) && !class_exists('Piwik\\Plugin\\Manager', false), 'fixture initialized an application service');
});
// Plugin callback declarations are tested in a SECOND isolated CLI process.
// Only its registerEvents/Manager receivers are fixture doubles, never production.
$handlerFixture = <<<'HANDLERS'
<?php
declare(strict_types=1);
namespace Piwik\Plugin {
    final class Manager {
        public static array $plugins = [];
        public static function getInstance(): self { static $instance; return $instance ??= new self(); }
        public function getPluginsLoadedAndActivated(): array { return self::$plugins; }
    }
}
namespace Piwik\Plugins\PagePerformance {
    final class PagePerformance {
        public array $hooks = [];
        public function getPluginName(): string { return 'PagePerformance'; }
        public function registerEvents(): array { return $this->hooks; }
        public function enrichApi(): void { throw new \RuntimeException('Fixture callbacks must not execute'); }
        public function renamedEnrichment(): void { throw new \RuntimeException('Fixture callbacks must not execute'); }
        public function addActionMetrics(): void { throw new \RuntimeException('Fixture callbacks must not execute'); }
    }
}
namespace {
    require_once __TEST_BOOTSTRAP__;
    speoFixtureBootstrap('future-version-handlers');
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', __OWN_DIRECTORY__, true);
    use Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard as Guard;
    use Piwik\Plugin\Manager;
    use Piwik\Plugins\PagePerformance\PagePerformance;
    final class UnknownReceiver {
        public function __construct(public array $hooks = []) {}
        public function getPluginName(): string { return 'UnknownFixture'; }
        public function registerEvents(): array { return $this->hooks; }
        public function enrichApi(): void { throw new \RuntimeException('Callback executed'); }
    }
    $plugin = new PagePerformance(); $foreign = new PagePerformance(); $unknown = new UnknownReceiver();
    $cases = [];
    $check = static function(string $name, callable $test) use (&$cases): void {
        try { if (!$test()) { throw new \RuntimeException('Handler contract mismatch'); } $cases[] = ['case' => $name, 'status' => 'pass']; }
        catch (\Throwable $e) { $cases[] = ['case' => $name, 'status' => 'error', 'error' => get_class($e) . ': ' . $e->getMessage()]; }
    };
    $positive = ['string' => 'enrichApi', 'before' => ['function' => 'enrichApi', 'before' => true],
        'after' => ['function' => 'enrichApi', 'after' => true], 'same-receiver' => [$plugin, 'enrichApi'],
        'wrapped-same-receiver' => ['function' => [$plugin, 'enrichApi'], 'after' => true]];
    foreach ($positive as $name => $hook) {
        $check('callback-normalizes-' . $name, static fn() => Guard::handlerMethod($hook, $plugin) === 'enrichApi');
        $check('known-pipeline-accepts-' . $name, static function() use($plugin, $hook): bool {
            $plugin->hooks = ['API.Request.dispatch.end' => $hook]; Manager::$plugins = [$plugin]; return Guard::isReviewedCachePipeline();
        });
    }
    $invalid = ['null' => null, 'false' => false, 'true' => true, 'integer' => 42, 'empty-array' => [],
        'function-null' => ['function' => null], 'function-false' => ['function' => false],
        'function-array' => ['function' => []], 'function-closure' => ['function' => static fn() => null],
        'closure' => static fn() => null, 'foreign-same-class-receiver' => [$foreign, 'enrichApi'],
        'foreign-receiver' => [$unknown, 'enrichApi'], 'class-name-receiver' => [PagePerformance::class, 'enrichApi'],
        'three-elements' => [$plugin, 'enrichApi', false], 'non-string-method' => [$plugin, 42],
        'missing-zero-key' => [1 => $plugin, 2 => 'enrichApi']];
    foreach ($invalid as $name => $hook) {
        $check('callback-invalid-normalizes-null-' . $name, static fn() => Guard::handlerMethod($hook, $plugin) === null);
        if ($hook !== null) {
            $check('known-pipeline-rejects-invalid-' . $name, static function() use($plugin, $hook): bool {
                $plugin->hooks = ['API.Request.dispatch.end' => $hook]; Manager::$plugins = [$plugin]; return !Guard::isReviewedCachePipeline();
            });
        }
    }
    $check('known-pipeline-accepts-renamed-existing-native-callback', static function() use($plugin): bool {
        $plugin->hooks = ['API.Request.dispatch.end' => 'renamedEnrichment']; Manager::$plugins = [$plugin]; return Guard::isReviewedCachePipeline();
    });
    foreach (['unknown-string' => 'unrecognizedMethod', 'unknown-structured' => ['function' => 'unrecognizedMethod', 'after' => true]] as $name => $hook) {
        $check('known-pipeline-rejects-' . $name, static function() use($plugin, $hook): bool {
            $plugin->hooks = ['API.Request.dispatch.end' => $hook]; Manager::$plugins = [$plugin]; return !Guard::isReviewedCachePipeline();
        });
    }
    foreach (['API.Request.dispatch.end', 'Request.getRenamedModuleAndAction'] as $event) {
        foreach (['string' => 'enrichApi', 'invalid-array' => [], 'invalid-wrapper' => ['function' => null]] as $name => $hook) {
            $check('unknown-plugin-sentinel-rejects-' . $event . '-' . $name, static function() use($unknown, $event, $hook): bool {
                $unknown->hooks = [$event => $hook]; Manager::$plugins = [$unknown]; return !Guard::isReviewedCachePipeline();
            });
        }
    }
    $check('null-hook-means-no-native-registration', static function() use($unknown): bool {
        // Native isset semantics: null is not a registered callback, unlike an invalid array.
        $unknown->hooks = ['API.Request.dispatch.end' => null]; Manager::$plugins = [$unknown]; return Guard::isReviewedCachePipeline();
    });
    $privacy = (new \ReflectionClass(\Piwik\Plugins\PrivacyManager\PrivacyManager::class))->newInstanceWithoutConstructor();
    foreach (['string' => 'shouldDisablePostProcessing', 'before' => ['function' => 'shouldDisablePostProcessing', 'before' => true],
        'after' => ['function' => 'shouldDisablePostProcessing', 'after' => true], 'receiver' => [$privacy, 'shouldDisablePostProcessing']] as $name => $hook) {
        $check('native-privacy-method-accepts-' . $name, static fn() => Guard::isReviewedPrivacyManagerDecision($privacy, $hook));
    }
    foreach (['null' => null, 'empty' => [], 'unknown' => 'notReviewed', 'foreign-receiver' => [$unknown, 'shouldDisablePostProcessing'],
        'null-wrapper' => ['function' => null], 'invalid-array' => [$privacy, 12]] as $name => $hook) {
        $check('native-privacy-method-rejects-' . $name, static fn() => !Guard::isReviewedPrivacyManagerDecision($privacy, $hook));
    }
    $check('foreign-privacy-class-rejects', static fn() => !Guard::isReviewedPrivacyManagerDecision($unknown, 'shouldDisablePostProcessing'));
    echo json_encode($cases, JSON_THROW_ON_ERROR); exit(0);
}
HANDLERS;
$handlerFixture = str_replace(
    ['__OWN_DIRECTORY__', '__TEST_BOOTSTRAP__'],
    [var_export(SPEO_PLUGIN_ROOT, true), var_export(dirname(__DIR__) . '/bootstrap.php', true)],
    $handlerFixture
);
if (!is_dir(PIWIK_USER_PATH)) { mkdir(PIWIK_USER_PATH, 0700, true); }
$handlerFile = PIWIK_USER_PATH . '/handler-fixture.php'; file_put_contents($handlerFile, $handlerFixture); chmod($handlerFile, 0600);
$process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $handlerFile], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new \RuntimeException('Could not start private handler fixture'); }
$handlerOutput = stream_get_contents($pipes[1]); fclose($pipes[1]); $handlerErrors = stream_get_contents($pipes[2]); fclose($pipes[2]); $handlerStatus = proc_close($process);
versionCheck('handler-fixture-subprocess-clean-exit', static function() use($handlerStatus, $handlerErrors): void {
    versionAssert($handlerStatus === 0 && $handlerErrors === '', 'handler fixture failed: ' . $handlerErrors);
});
if ($handlerStatus === 0 && $handlerErrors === '') {
    foreach (json_decode($handlerOutput, true, 512, JSON_THROW_ON_ERROR) as $case) { $results[] = $case; }
}
$failed = count(array_filter($results, static fn($r) => $r['status'] !== 'pass'));
$report = ['fixture' => 'future-version-argument-contract', 'synthetic_version_arguments_only' => $versions,
    'other_matomo_releases_loaded' => false, 'other_matomo_releases_existence_verified' => false,
    'callback_registry_doubles_in_separate_subprocess' => true, 'native_privacy_method_not_executed' => true,
    'installed_matomo_version' => \Piwik\Version::VERSION, 'installed_php_version' => PHP_VERSION,
    'network_namespace_isolated' => true, 'application_bootstrap' => false, 'database_or_customer_reports' => false,
    'source_sha256' => ['RequestEligibility' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/RequestEligibility.php'),
        'NativeSourceGuard' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/NativeSourceGuard.php')],
    'checks' => count($results), 'passed' => count($results) - $failed, 'failed' => $failed, 'results' => $results];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
speoFixtureReport('future-version-contract', $json); echo $json; exit($failed ? 1 : 0);
