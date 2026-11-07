<?php
/** Private CLI fixture. Native HtmlTable/public request/setter/loader; owned scope and backend doubles only. */
declare(strict_types=1);
namespace Piwik\Plugins\SpeoReportPerformance {
    final class UiNumericPageCache
    {
        public static function loadAttach($view, RevisionContext $context, array $request): bool { return false; }
    }
    final class NativeSourceGuard
    {
        public static bool $ui = true;
        public static bool $throw = false;
        public static bool $pipeline = true;
        public static bool $cachePipeline = true;
        public static int $sourceMatchCalls = 0;
        public static function isReviewedPipeline(): bool { return self::$pipeline; }
        public static function isReviewedCachePipeline(): bool { return self::$cachePipeline; }
        public static function matchesClassSource(object $object, string $class, string $file, string $hash): bool
        {
            ++self::$sourceMatchCalls;
            if (self::$throw) { throw new \RuntimeException('Fixture source failure'); }
            return self::$ui && get_class($object) === $class
                && realpath((new \ReflectionClass($object))->getFileName()) === realpath($file)
                && hash_equals($hash, hash_file('sha256', $file));
        }
    }
    final class RequestEligibility
    {
        public static ?string $reason = null;
        public static array $calls = [];
        public static function reason($probe, array $info, array $request, string $version): ?string
        {
            $GLOBALS['uiEvents'][] = 'eligibility'; self::$calls[] = [$info, $request, $version]; return self::$reason;
        }
    }
    final class RevisionContext
    {
        public static bool $denied = false;
        public static bool $decline = false;
        public static array $calls = [];
        public static \Piwik\DataTable $root;
        public static function assertViewAccess(array $request): int
        {
            $GLOBALS['uiEvents'][] = 'view-access';
            if (self::$denied) { throw new \Piwik\NoAccessException('Fixture denied'); }
            return (int)$request['idSite'];
        }
        public static function prepare(array $request, ?array $raw = null): ?self
        {
            $GLOBALS['uiEvents'][] = 'context';
            self::$calls[] = ['request' => $request, 'raw' => $raw, 'get' => $_GET, 'post' => $_POST,
                'query' => $_SERVER['QUERY_STRING'] ?? null];
            return self::$decline ? null : new self();
        }
        public function getFreshRoot(): \Piwik\DataTable { return self::$root; }
    }
    final class HotReportWarmup
    {
        public static array $calls = [];
        public static $callback = null;
        public static function record(RevisionContext $context): bool
        {
            $GLOBALS['uiEvents'][] = 'record'; self::$calls[] = $context;
            if (self::$callback) { (self::$callback)(); }
            return true;
        }
    }
    final class ReportCache
    {
        public static ?\Piwik\DataTable $base = null;
        public static bool $throw = false;
        public static int $calls = 0;
        public static ?\Closure $callback = null;
        public static int $callbackCalls = 0;
        public static function loadBase(RevisionContext $context): ?\Piwik\DataTable
        {
            $GLOBALS['uiEvents'][] = 'cache'; ++self::$calls;
            if (self::$throw) { throw new \RuntimeException('Fixture cache failure'); }
            if (self::$callback !== null) {
                $callback = self::$callback; self::$callback = null; ++self::$callbackCalls;
                $callback();
            }
            return self::$base;
        }
    }
    final class NativeFlatInput
    {
        public static array $calls = [];
        public static bool $throw = false;
        public static function prepare(\Piwik\DataTable $table, string $dimension): \Piwik\DataTable
        {
            $GLOBALS['uiEvents'][] = 'prepare'; self::$calls[] = [$table, $dimension];
            if (self::$throw) { throw new \RuntimeException('Fixture prepare failure'); }
            return $table;
        }
    }
    final class BaseLoadScope
    {
        public static array $calls = [];
        public static mixed $result = null;
        public static bool $throw = false;
        public static function run(callable $load): mixed
        {
            $GLOBALS['uiEvents'][] = 'scope'; self::$calls[] = $load;
            // Deliberately NEVER execute the API closure in this unit fixture.
            if (self::$throw) { throw new \RuntimeException('Fixture scope failure'); }
            return self::$result;
        }
    }
}
namespace Piwik\Plugins\Actions\Reports {
    final class GetPageUrls
    {
        public bool $noDimension = false;
        public function getDimension() { return $this->noDimension ? null : new class { public function getId(): string { return 'Actions.PageUrl'; } }; }
    }
}
namespace Piwik\Plugin {
    final class ReportsProvider
    {
        public static $report;
        public static function factory($module, $method)
        {
            $GLOBALS['uiEvents'][] = 'report';
            if ($module !== 'Actions' || $method !== 'getPageUrls') { throw new \RuntimeException('Unexpected fixture report'); }
            return self::$report;
        }
    }
    final class Manager
    {
        public static array $plugins = [];
        public static bool $active = true;
        public static $enricher;
        public static function getInstance(): self { static $self; return $self ??= new self(); }
        public function getPluginsLoadedAndActivated(): array { return self::$plugins; }
        public function isPluginActivated($name): bool { return self::$active; }
        public function getLoadedPlugin($name) { return self::$enricher; }
    }
}
namespace Piwik\API {
    final class Proxy
    {
        public static int $calls = 0;
        public static function getInstance(): self { static $self; return $self ??= new self(); }
        public function call(...$args) { ++self::$calls; throw new \RuntimeException('A real API path must never run in this fixture'); }
    }
}
namespace {
    use Piwik\DataTable;
    use Piwik\DataTable\Row;
    use Piwik\Plugin\ViewDataTable;
    use Piwik\Plugin\Visualization;
    use Piwik\Plugins\CoreVisualizations\Visualizations\HtmlTable;
    use Piwik\Plugins\SpeoReportPerformance\UiBasePreloader;
    use Piwik\Plugins\SpeoReportPerformance\UiRequestBuilder;
    use Piwik\Plugins\SpeoReportPerformance\UiRequestContext;
    use Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard;
    use Piwik\Plugins\SpeoReportPerformance\RequestEligibility;
    use Piwik\Plugins\SpeoReportPerformance\RevisionContext;
    use Piwik\Plugins\SpeoReportPerformance\ReportCache;
    use Piwik\Plugins\SpeoReportPerformance\HotReportWarmup;
    use Piwik\Plugins\SpeoReportPerformance\NativeFlatInput;
    use Piwik\Plugins\SpeoReportPerformance\BaseLoadScope;
    require_once dirname(__DIR__) . '/bootstrap.php';
    speoAssertIsolated();
    umask(0077);
    speoFixtureBootstrap('ui-base-preloader');
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
    $container = new \Piwik\Container\Container(); \Piwik\Container\StaticContainer::push($container);
    $container->set('Piwik\\EventDispatcher', new class {
        public function postEvent($event, $params = [], $pending = false, $plugins = null): void
        {
            if ($event === 'API.Request.dispatch.end') {
                $manager = \Piwik\Plugin\Manager::getInstance();
                if ($manager->isPluginActivated('PagePerformance')) {
                    $manager->getLoadedPlugin('PagePerformance')->enrichApi($params[0], $params[1]);
                }
            }
        }
    });
    $container->set(\Piwik\Log\LoggerInterface::class, new \Psr\Log\NullLogger());

    final class UiRequestProvider
    {
        public function __construct(private object $config, public array $extra = []) {}
        public function getRequestArray(): array
        {
            return $this->extra + ['method' => $this->config->apiMethodToRequestDataTable, 'format' => 'original',
                'flat' => '1', 'idSite' => 353, 'period' => 'month', 'date' => '2026-10-01', 'filter_limit' => 500,
                'filter_sort_column' => $this->config->filter_sort_column, 'filter_sort_order' => $this->config->filter_sort_order];
        }
    }
    final class UiFreshEnricher
    {
        public array $calls = [];
        public bool $throw = false;
        public function enrichApi(DataTable $table, array $info): void
        {
            $GLOBALS['uiEvents'][] = 'enrich'; $this->calls[] = [$table, $info];
            if ($this->throw) { throw new \RuntimeException('Fixture enrichment failure'); }
            $table->setMetadata('extra_processed_metrics', array_values(\Piwik\Plugins\PagePerformance\Metrics::getAllPagePerformanceMetrics()));
        }
    }
    class UiCustomHtmlTable extends HtmlTable {}
    function uiTable(array $columns): DataTable
    {
        $table = new DataTable(); $table->addRow(new Row([Row::COLUMNS => $columns])); return $table;
    }
    function uiReset(): void
    {
        $GLOBALS['uiEvents'] = [];
        NativeSourceGuard::$ui = NativeSourceGuard::$pipeline = NativeSourceGuard::$cachePipeline = true; NativeSourceGuard::$throw = false; NativeSourceGuard::$sourceMatchCalls = 0;
        RequestEligibility::$reason = null; RequestEligibility::$calls = [];
        RevisionContext::$denied = RevisionContext::$decline = false; RevisionContext::$calls = [];
        RevisionContext::$root = uiTable(['label' => '/root', 'nb_hits' => 12, 'nb_visits' => 8, 'nb_uniq_visitors' => 6]);
        ReportCache::$base = uiTable(['label' => '/leaf', 'nb_hits' => 4, 'nb_visits' => 3, 'nb_uniq_visitors' => 2]);
        ReportCache::$throw = false; ReportCache::$calls = 0;
        ReportCache::$callback = null; ReportCache::$callbackCalls = 0;
        HotReportWarmup::$calls = []; HotReportWarmup::$callback = null;
        NativeFlatInput::$calls = []; NativeFlatInput::$throw = false;
        BaseLoadScope::$calls = []; BaseLoadScope::$throw = false;
        BaseLoadScope::$result = uiTable(['label' => '/scope-prepared', 'nb_hits' => 5]);
        \Piwik\Plugin\ReportsProvider::$report = new \Piwik\Plugins\Actions\Reports\GetPageUrls();
        \Piwik\Plugin\Manager::$active = true; \Piwik\Plugin\Manager::$enricher = new UiFreshEnricher();
        \Piwik\Plugin\Manager::$plugins = [];
        \Piwik\API\Proxy::$calls = 0;
        (new \ReflectionProperty(\Piwik\API\Request::class, 'nestedApiInvocationCount'))->setValue(0);
        (new \ReflectionProperty(UiBasePreloader::class, 'busy'))->setValue(false);
        (new \ReflectionProperty(UiBasePreloader::class, 'lastDecision'))->setValue('not-run');
        $_GET = ['module' => 'Actions', 'action' => 'getPageUrls', 'viewDataTable' => 'table', 'transport_fixture' => 'old-get'];
        $_POST = ['post_fixture' => 'old-post']; $_REQUEST = ['request_fixture' => 'unchanged'];
        $_SERVER['QUERY_STRING'] = 'module=Actions&action=getPageUrls&viewDataTable=table';
    }
    function uiView(array $extra = [], string $class = HtmlTable::class): HtmlTable
    {
        $view = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $view->config = (new \ReflectionClass(HtmlTable\Config::class))->newInstanceWithoutConstructor();
        $view->config->columns_to_display = ['label', 'nb_hits'];
        $view->config->show_pivot_by_subtable = false; $view->config->enable_sort = true; $view->config->disable_comparison = true;
        $view->requestConfig = (new \ReflectionClass(HtmlTable\RequestConfig::class))->newInstanceWithoutConstructor();
        $view->requestConfig->apiMethodToRequestDataTable = 'Actions.getPageUrls';
        $view->requestConfig->filter_sort_column = 'nb_hits'; $view->requestConfig->filter_sort_order = 'desc';
        $view->requestConfig->disable_queued_filters = false;
        (new \ReflectionProperty(ViewDataTable::class, 'request'))->setValue($view, new UiRequestProvider($view->requestConfig, $extra));
        return $view;
    }
    function uiHasTable(HtmlTable $view): bool { try { $view->getDataTable(); return true; } catch (\Exception $e) { return false; } }
    function uiExpect(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
    $results = [];
    function uiCheck(string $name, callable $test): void
    {
        global $results;
        try {
            uiReset(); $test();
            uiExpect(\Piwik\API\Proxy::$calls === 0, 'fixture entered a native API path');
            uiExpect(HotReportWarmup::$calls === [], 'CLI UI bridge recorded browser demand');
            $results[] = ['case' => $name, 'status' => 'pass'];
        }
        catch (\Throwable $error) { $results[] = ['case' => $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()]; }
    }
    uiCheck('hit-public-native-setter-and-protected-loader-skip-api', function () {
        $view = uiView(); $base = ReportCache::$base; UiBasePreloader::loadForDispatch($view);
        uiExpect(UiBasePreloader::getLastDecision() === 'ui-base-hit-native-ui-processing' && $view->getDataTable() === $base, 'hit did not set owned canonical base');
        $loaded = (new \ReflectionMethod(Visualization::class, 'loadDataTableFromAPI'))->invoke($view);
        uiExpect($loaded === $base, 'original native loader did not short-circuit to the public setter value');
        uiExpect(HtmlTable::ID === 'table' && get_class($view) === HtmlTable::class, 'native visualization identity changed');
        uiExpect($GLOBALS['uiEvents'] === ['eligibility', 'view-access', 'context', 'cache', 'report', 'enrich', 'prepare'], 'access/cache/enrichment/preparation ordering changed');
        uiExpect(HotReportWarmup::$calls === [], 'CLI cache hit refreshed browser demand');
        uiExpect(count($base->getMetadata('extra_processed_metrics')) === 7 && NativeFlatInput::$calls[0] === [$base, 'Actions_PageUrl'], 'fresh metric/dimension input incorrect');
        uiExpect(BaseLoadScope::$calls === [], 'base hit unexpectedly entered cold API scope');
        $info = \Piwik\Plugin\Manager::$enricher->calls[0][1];
        uiExpect(array_keys($info) === ['className', 'module', 'action', 'parameters']
            && $info['className'] === \Piwik\Plugins\Actions\API::class
            && array_keys($info['parameters']) === ['idSite', 'period', 'date', 'segment', 'expanded', 'idSubtable', 'depth', 'flat']
            && !array_key_exists('filter_limit', $info['parameters']), 'reconstructed event did not preserve the documented native contract');
    });
    uiCheck('public-request-builder-and-owned-context-exact-scope-restoration', function () {
        $view = uiView(['format_metrics' => 'bc', 'disable_queued_filters' => 0]);
        $expected = $view->buildApiRequestArray(); $globals = [$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']];
        uiExpect($expected['format_metrics'] === '1' && !array_key_exists('disable_queued_filters', $expected), 'native builder was not exercised');
        UiBasePreloader::loadForDispatch($view); $call = RevisionContext::$calls[0];
        $bridge = $expected; $bridge['serialize'] = '0';
        uiExpect($call['request'] === $bridge && $call['raw'] === $bridge, 'context request/raw do not match bridge-only serialize override');
        foreach ($bridge as $key => $value) { uiExpect($call['get'][$key] === $value && $call['post'][$key] === $value, 'owned scope did not bind both globals'); }
        uiExpect([$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']] === $globals && $call['query'] === $globals[3], 'owned scope leaked globals or rewrote raw query string');
        uiExpect($view->buildApiRequestArray() === $expected, 'bridge serialize override leaked into native UI request builder');
    });
    uiCheck('cache-miss-exactly-one-scope-without-extra-enrichment-or-preparation', function () {
        $view = uiView(['serialize' => '1']); ReportCache::$base = null; $columns = $view->config->columns_to_display;
        $nativeRequest = $view->buildApiRequestArray(); $prepared = BaseLoadScope::$result;
        UiBasePreloader::loadForDispatch($view);
        uiExpect(uiHasTable($view) && $view->getDataTable() === $prepared && UiBasePreloader::getLastDecision() === 'ui-base-built-native-ui-processing', 'miss did not hand prepared scope result to native setter');
        uiExpect(count(BaseLoadScope::$calls) === 1 && HotReportWarmup::$calls === [] && ReportCache::$calls === 1
            && NativeFlatInput::$calls === [] && \Piwik\Plugin\Manager::$enricher->calls === [] && $view->config->columns_to_display === $columns, 'miss duplicated scope, enrichment or preparation');
        $variables = (new \ReflectionFunction(BaseLoadScope::$calls[0]))->getStaticVariables();
        $bridge = $nativeRequest; $bridge['serialize'] = '0';
        uiExpect(($variables['request'] ?? null) === $bridge && $nativeRequest['serialize'] === '1'
            && $view->buildApiRequestArray() === $nativeRequest, 'cold closure request did not isolate serialize=0 from UI request/config');
        uiExpect((new \ReflectionMethod(Visualization::class, 'loadDataTableFromAPI'))->invoke($view) === $prepared, 'native loader did not skip API after cold scope handoff');
        uiExpect(!\Piwik\API\Request::isCurrentApiRequestTheRootApiRequest() && !\Piwik\API\Request::isCurrentApiRequestNestedInAnotherApiRequest(), 'fixture accidentally executed the real API closure');
    });
    foreach ([null, 'invalid-serialized-output', new \stdClass()] as $i => $invalid) {
        uiCheck('cold-scope-invalid-interface-fallback-' . $i, function () use ($invalid) {
            $view = uiView(); ReportCache::$base = null; BaseLoadScope::$result = $invalid;
            UiBasePreloader::loadForDispatch($view);
            uiExpect(!uiHasTable($view) && count(BaseLoadScope::$calls) === 1 && NativeFlatInput::$calls === []
                && UiBasePreloader::getLastDecision() === 'native-invalid-base-input', 'invalid scope interface escaped fallback');
        });
    }
    uiCheck('cold-scope-exception-fallback-restores-globals-and-busy', function () {
        $view = uiView(); ReportCache::$base = null; BaseLoadScope::$throw = true; $globals = [$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']];
        UiBasePreloader::loadForDispatch($view);
        uiExpect(!uiHasTable($view) && UiBasePreloader::getLastDecision() === 'native-preload-error'
            && [$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']] === $globals, 'scope error leaked data/globals');
        BaseLoadScope::$throw = false; UiBasePreloader::loadForDispatch($view); uiExpect(uiHasTable($view), 'scope error left busy latch set');
    });
    foreach ([1, 2] as $counter) {
        uiCheck('actual-native-api-counter-fallback-' . $counter, function () use ($counter) {
            $view = uiView(); (new \ReflectionProperty(\Piwik\API\Request::class, 'nestedApiInvocationCount'))->setValue($counter);
            UiBasePreloader::loadForDispatch($view);
            uiExpect(!uiHasTable($view) && UiBasePreloader::getLastDecision() === 'native-nested-ui' && $GLOBALS['uiEvents'] === [], 'root/nested API context entered UI bridge');
        });
    }
    foreach (['JSON', 'php', '', null] as $i => $format) {
        uiCheck('non-original-ui-format-fallback-' . $i, function () use ($format) {
            $view = uiView(['format' => $format]); UiBasePreloader::loadForDispatch($view);
            uiExpect(!uiHasTable($view) && UiBasePreloader::getLastDecision() === 'native-ui-format' && $GLOBALS['uiEvents'] === [], 'non-original format entered bridge');
        });
    }
    uiCheck('view-access-denial-propagates-before-cache-and-restores-state', function () {
        $view = uiView(); RevisionContext::$denied = true; $globals = [$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']]; $denied = false;
        try { UiBasePreloader::loadForDispatch($view); } catch (\Piwik\NoAccessException $e) { $denied = true; }
        uiExpect($denied && ReportCache::$calls === 0 && RevisionContext::$calls === [] && HotReportWarmup::$calls === [], 'permission denial swallowed or cache read before permission');
        uiExpect([$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']] === $globals, 'denied path leaked globals');
        RevisionContext::$denied = false; UiBasePreloader::loadForDispatch($view); uiExpect(uiHasTable($view), 'denial left busy latch set');
    });
    foreach (['pipeline', 'cachePipeline'] as $field) {
        uiCheck('sourceguard-fallback-' . $field, function () use ($field) {
            $view = uiView(); NativeSourceGuard::${$field} = false; UiBasePreloader::loadForDispatch($view);
            uiExpect(!uiHasTable($view) && ReportCache::$calls === 0 && RevisionContext::$calls === [], 'unreviewed source pipeline reached cache');
        });
    }
    foreach ([true, null, 0, '0'] as $i => $pivot) {
        uiCheck('strict-pivot-fallback-' . $i, function () use ($pivot) {
            $view = uiView(); $view->config->show_pivot_by_subtable = $pivot; UiBasePreloader::loadForDispatch($view);
            uiExpect(!uiHasTable($view) && UiBasePreloader::getLastDecision() === 'native-pivot-ui' && $GLOBALS['uiEvents'] === [], 'pivot controls or backend were changed');
        });
    }
    uiCheck('existing-table-not-overwritten', function () {
        $view = uiView(); $existing = uiTable(['label' => '/supplied']); $view->setDataTable($existing); UiBasePreloader::loadForDispatch($view);
        uiExpect($view->getDataTable() === $existing && ReportCache::$calls === 0 && UiBasePreloader::getLastDecision() === 'native-existing-table', 'another intended hook table was overwritten');
    });
    uiCheck('foreign-visualization-subclass-and-inputs-ignored', function () {
        foreach ([null, false, new \stdClass(), uiView([], UiCustomHtmlTable::class)] as $view) { UiBasePreloader::loadForDispatch($view); }
        uiExpect($GLOBALS['uiEvents'] === [] && ReportCache::$calls === 0, 'foreign visualization entered helper pipeline');
    });
    foreach (['Actions.getPageTitles', 'Live.getLastVisitsDetails'] as $method) {
        uiCheck('foreign-report-' . $method, function () use ($method) {
            $view = uiView(); $view->requestConfig->apiMethodToRequestDataTable = $method; UiBasePreloader::loadForDispatch($view);
            uiExpect(!uiHasTable($view) && $GLOBALS['uiEvents'] === [], 'foreign report entered helper pipeline');
        });
    }
    uiCheck('eligibility-reason-falls-back-before-permissions', function () {
        $view = uiView(); RequestEligibility::$reason = 'unsupported-flat'; UiBasePreloader::loadForDispatch($view);
        uiExpect($GLOBALS['uiEvents'] === ['eligibility'] && !uiHasTable($view) && UiBasePreloader::getLastDecision() === 'native-unsupported-flat', 'ineligible request crossed permission/cache boundary');
    });
    uiCheck('context-decline-does-not-record-or-cache', function () {
        $view = uiView(); RevisionContext::$decline = true; UiBasePreloader::loadForDispatch($view);
        uiExpect(!uiHasTable($view) && HotReportWarmup::$calls === [] && ReportCache::$calls === 0 && UiBasePreloader::getLastDecision() === 'native-no-context', 'missing context did work');
    });
    uiCheck('custom-report-fallback', function () {
        $view = uiView(); \Piwik\Plugin\ReportsProvider::$report = new \stdClass(); UiBasePreloader::loadForDispatch($view);
        uiExpect(!uiHasTable($view) && NativeFlatInput::$calls === [] && UiBasePreloader::getLastDecision() === 'native-custom-report', 'custom report was used');
    });
    uiCheck('missing-dimension-fallback', function () {
        $view = uiView(); \Piwik\Plugin\ReportsProvider::$report->noDimension = true; UiBasePreloader::loadForDispatch($view);
        uiExpect(!uiHasTable($view) && NativeFlatInput::$calls === [], 'dimensionless report was used');
    });
    uiCheck('inactive-pageperformance-does-not-enrich', function () {
        $view = uiView(); \Piwik\Plugin\Manager::$active = false; UiBasePreloader::loadForDispatch($view);
        uiExpect(uiHasTable($view) && \Piwik\Plugin\Manager::$enricher->calls === [] && count(NativeFlatInput::$calls) === 1, 'inactive PagePerformance was touched');
    });
    foreach (['cache', 'enrich', 'prepare'] as $failure) {
        uiCheck('exception-fail-open-and-busy-latch-restored-' . $failure, function () use ($failure) {
            $view = uiView(); $globals = [$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']];
            if ($failure === 'cache') { ReportCache::$throw = true; } elseif ($failure === 'enrich') { \Piwik\Plugin\Manager::$enricher->throw = true; } else { NativeFlatInput::$throw = true; }
            UiBasePreloader::loadForDispatch($view);
            uiExpect(!uiHasTable($view) && UiBasePreloader::getLastDecision() === 'native-preload-error', 'backend error did not preserve native fallback');
            uiExpect([$_GET, $_POST, $_REQUEST, $_SERVER['QUERY_STRING']] === $globals, 'error leaked owned request scope globals');
            ReportCache::$throw = NativeFlatInput::$throw = \Piwik\Plugin\Manager::$enricher->throw = false;
            UiBasePreloader::loadForDispatch($view); uiExpect(uiHasTable($view), 'backend error left helper busy');
        });
    }
    uiCheck('reentrant-hook-does-not-nest-cache-load', function () {
        $view = uiView(); ReportCache::$callback = static function () use ($view) { UiBasePreloader::loadForDispatch($view); };
        UiBasePreloader::loadForDispatch($view);
        uiExpect(uiHasTable($view) && ReportCache::$calls === 1 && ReportCache::$callbackCalls === 1
            && ReportCache::$callback === null && HotReportWarmup::$calls === [], 'reentrant configure hook nested backend work or recorded CLI demand');
    });
    foreach ([['label' => '/r', 'nb_hits' => 12, 'nb_visits' => 8, 'nb_uniq_visitors' => 6],
        ['label' => '/r', 'nb_hits' => 12, 'nb_visits' => 8], ['label' => '/r', 'first_metric' => 12, 'second_metric' => 8],
        ['label' => '/r', \Piwik\Metrics::INDEX_PAGE_NB_HITS => 12, \Piwik\Metrics::INDEX_NB_VISITS => 8,
            \Piwik\Metrics::INDEX_NB_UNIQ_VISITORS => 6]] as $i => $columns) {
        uiCheck('native-data-defaults-derived-from-fresh-root-' . $i, function () use ($columns) {
            $view = uiView(); $view->config->columns_to_display = []; $view->requestConfig->filter_sort_column = ''; $view->requestConfig->filter_sort_order = 'asc';
            RevisionContext::$root = uiTable($columns); ReportCache::$base = uiTable(['label' => '/leaf', 'leaf_only_metric' => 99]);
            $expectedConfig = clone $view->config; $expectedRequest = clone $view->requestConfig;
            $rootColumns = RevisionContext::$root->getColumns(); $hasVisits = in_array('nb_visits', $rootColumns); $hasUnique = in_array('nb_uniq_visitors', $rootColumns);
            $expectedConfig->setDefaultColumnsToDisplay($rootColumns, $hasVisits, $hasUnique);
            $expectedRequest->setDefaultSort($expectedConfig->columns_to_display, $hasUnique, $rootColumns);
            UiBasePreloader::loadForDispatch($view);
            uiExpect(uiHasTable($view) && $view->config->columns_to_display === $expectedConfig->columns_to_display
                && $view->requestConfig->filter_sort_column === $expectedRequest->filter_sort_column
                && $view->requestConfig->filter_sort_order === $expectedRequest->filter_sort_order, 'fresh root native defaults differ or leaf-derived defaults leaked');
        });
    }
    uiCheck('explicit-columns-sort-and-order-preserved', function () {
        $view = uiView(); $view->config->columns_to_display = ['label', 'custom']; $view->requestConfig->filter_sort_column = 'custom'; $view->requestConfig->filter_sort_order = 'asc';
        UiBasePreloader::loadForDispatch($view);
        uiExpect(uiHasTable($view) && $view->config->columns_to_display === ['label', 'custom'] && $view->requestConfig->filter_sort_column === 'custom'
            && $view->requestConfig->filter_sort_order === 'asc', 'explicit user settings were overwritten');
    });
    foreach ([false, true] as $after) {
        uiCheck('foreign-configure-end-after-handler-' . (int) $after, function () use ($after) {
            \Piwik\Plugin\Manager::$plugins = [new class($after) {
                public function __construct(private bool $after) {}
                public function getPluginName(): string { return 'FixtureForeignPlugin'; }
                public function registerEvents(): array { return ['ViewDataTable.configure.end' => ['function' => 'fixture', 'after' => $this->after]]; }
            }];
            $view = uiView(); UiBasePreloader::loadForDispatch($view);
            uiExpect(uiHasTable($view) === !$after, 'late foreign configuration handler was not excluded');
            if ($after) { uiExpect(ReportCache::$calls === 0 && RevisionContext::$calls === [], 'foreign after handler reached cache context'); }
        });
    }
    foreach (['ui', 'throw'] as $field) {
        uiCheck('whole-file-source-pin-no-longer-controls-ui-' . $field, function () use ($field) {
            $view = uiView(); NativeSourceGuard::${$field} = $field === 'throw';
            UiBasePreloader::loadForDispatch($view);
            uiExpect(uiHasTable($view) && NativeSourceGuard::$sourceMatchCalls === 0, 'UI still depends on complete native source hashes');
        });
    }
    foreach (['config', 'requestConfig'] as $property) {
        uiCheck('missing-public-ui-capability-falls-back-' . $property, function () use ($property) {
            $view = uiView(); $view->$property = new \stdClass(); UiBasePreloader::loadForDispatch($view);
            uiExpect(!uiHasTable($view) && ReportCache::$calls === 0 && $GLOBALS['uiEvents'] === [], 'missing capability crossed cache boundary');
        });
    }
    foreach ([['module' => 'Actions', 'action' => 'getPageUrls'], ['module' => 'CoreHome', 'action' => 'renderReportWidget']] as $transport) {
        uiCheck('raw-controller-and-presence-preserved-' . $transport['module'], function () use ($transport) {
            $_GET = $transport + ['viewDataTable' => 'table', 'random' => '4242', 'widget' => '', 'idSubtable' => false];
            $_POST = ['post_fixture' => 'old-post'];
            $view = uiView(['serialize' => '1', 'compareDates' => [], 'search_recursive' => 'false']);
            $expected = $view->buildApiRequestArray(); $before = [$_GET, $_POST, $_REQUEST, $_SERVER];
            UiBasePreloader::loadForDispatch($view);
            $bridge = $expected; $bridge['serialize'] = '0';
            uiExpect(uiHasTable($view) && RevisionContext::$calls[0]['request'] === $bridge, 'raw UI request changed');
            foreach (array_keys($transport + ['widget' => '', 'idSubtable' => false]) as $key) {
                uiExpect(array_key_exists($key, $bridge) && array_key_exists($key, $expected)
                    && $bridge[$key] === $expected[$key], 'public request key presence/type changed: ' . $key);
            }
            uiExpect($view->buildApiRequestArray() === $expected && [$_GET, $_POST, $_REQUEST, $_SERVER] === $before, 'bridge changed native request/config/globals');
        });
    }
    foreach ([true, false, 0, null] as $sortIndex => $sort) {
        uiCheck('public-builder-matches-native-sort-policy-' . $sortIndex, function () use ($sort) {
            foreach ([[], ['format_metrics' => 'bc'], ['format_metrics' => '1'], ['format_metrics' => '0'],
                ['format_metrics' => null], ['format_metrics' => 'all']] as $format) {
                foreach ([false, true] as $disableQueued) {
                    foreach ([[], ['disable_queued_filters' => '0'], ['disable_queued_filters' => '1'], ['disable_queued_filters' => null]] as $queueFlag) {
                        $view = uiView($format + $queueFlag + ['serialize' => '1']);
                        $view->config->enable_sort = $sort; $view->requestConfig->disable_queued_filters = $disableQueued;
                        $beforeConfig = get_object_vars($view->config); $beforeRequest = get_object_vars($view->requestConfig);
                        uiExpect(UiRequestBuilder::build($view) === $view->buildApiRequestArray(), 'public request policies diverged from native non-comparison builder');
                        uiExpect(get_object_vars($view->config) === $beforeConfig && get_object_vars($view->requestConfig) === $beforeRequest,
                            'builder mutated native configuration');
                    }
                }
            }
        });
    }
    uiCheck('public-builder-does-not-call-internal-builder', function () {
        $view = new class {
            public object $config; public object $requestConfig; public int $calls = 0;
            public function __construct() { $this->config = (object)['enable_sort' => true]; $this->requestConfig = (object)['disable_queued_filters' => false]; }
            public function isComparing(): bool { return false; }
            public function getRequestArray(): array { ++$this->calls; return ['format_metrics' => 'bc', 'widget' => '', 'idSubtable' => null, 'serialize' => '1']; }
            public function buildApiRequestArray(): never { throw new \RuntimeException('Internal API was called'); }
        };
        uiExpect(UiRequestBuilder::build($view) === ['format_metrics' => '1', 'widget' => '', 'idSubtable' => null, 'serialize' => '1']
            && $view->calls === 1, 'documented request API or key presence not preserved');
    });
    uiCheck('comparison-view-retains-native-load', function () {
        $view = uiView(['comparePeriods' => ['day']]); $view->config->disable_comparison = false;
        UiBasePreloader::loadForDispatch($view);
        uiExpect(!uiHasTable($view) && ReportCache::$calls === 0 && $GLOBALS['uiEvents'] === []
            && UiBasePreloader::getLastDecision() === 'native-comparison-ui', 'comparison view entered bridge');
    });
    uiCheck('public-request-builder-invalid-result-declines', function () {
        $view = new class {
            public function isComparing(): bool { return false; }
            public function getRequestArray(): string { return 'bad'; }
        };
        $threw = false; try { UiRequestBuilder::build($view); } catch (\UnexpectedValueException $e) { $threw = true; }
        uiExpect($threw, 'unexpected public API result was accepted');
    });
    foreach (['absent', 'null', 'string'] as $queryKind) {
        foreach ([false, true] as $throw) {
            uiCheck('owned-context-restores-query-presence-' . $queryKind . '-throw-' . (int)$throw, function () use ($queryKind, $throw) {
                if ($queryKind === 'absent') { unset($_SERVER['QUERY_STRING']); }
                elseif ($queryKind === 'null') { $_SERVER['QUERY_STRING'] = null; }
                else { $_SERVER['QUERY_STRING'] = 'original=query'; }
                $_GET['null_present'] = null; $_POST['false_present'] = false;
                $before = [$_GET, $_POST, $_REQUEST, $_SERVER]; $caught = false;
                try {
                    $result = UiRequestContext::run(['module' => 'CoreHome', 'action' => 'renderReportWidget', 'flat' => '1', 'empty' => '', 'false' => false], function () use ($throw, $before) {
                        uiExpect($_GET['module'] === 'CoreHome' && $_POST['module'] === 'CoreHome'
                            && $_GET['empty'] === '' && $_POST['false'] === false && $_REQUEST === $before[2]
                            && $_SERVER === $before[3], 'scope dropped values or changed query/request globals');
                        $_GET['leak'] = true; unset($_POST['post_fixture']); $_SERVER['QUERY_STRING'] = 'changed';
                        if ($throw) { throw new \RuntimeException('Expected scope failure'); }
                        return 42;
                    });
                    uiExpect(!$throw && $result === 42, 'callback return lost');
                } catch (\RuntimeException $e) { if (!$throw || $e->getMessage() !== 'Expected scope failure') { throw $e; } $caught = true; }
                uiExpect($caught === $throw && [$_GET, $_POST, $_REQUEST, $_SERVER] === $before, 'scope did not restore values and key presence exactly');
            });
        }
    }
    uiCheck('owned-context-nested-and-empty-scopes-restore-outer', function () {
        $before = [$_GET, $_POST, $_REQUEST, $_SERVER];
        $result = UiRequestContext::run(['module' => 'CoreHome', 'flat' => '1'], function () {
            $outer = [$_GET, $_POST, $_REQUEST, $_SERVER];
            $inner = UiRequestContext::run(['module' => 'Actions', 'flat' => null], static fn() => [$_GET['module'], $_POST['flat']]);
            uiExpect($inner === ['Actions', null] && [$_GET, $_POST, $_REQUEST, $_SERVER] === $outer, 'nested scope did not restore outer scope');
            return UiRequestContext::run([], static fn() => 'empty');
        });
        uiExpect($result === 'empty' && [$_GET, $_POST, $_REQUEST, $_SERVER] === $before, 'empty/nested scope leaked');
    });
    uiCheck('owned-defaults-match-native-fallback-matrix', function () {
        foreach ([[], ['label' => '/root'], ['label' => '/root', 'nb_visits' => 1],
            ['label' => '/root', 'nb_uniq_visitors' => 1], ['label' => '/root', 'nb_hits' => 5, 'nb_visits' => 3, 'nb_uniq_visitors' => 2],
            ['label' => '/root', 'custom' => 3, 'other' => 2],
            ['label' => '/root', \Piwik\Metrics::INDEX_PAGE_NB_HITS => 3, \Piwik\Metrics::INDEX_NB_UNIQ_VISITORS => 2]] as $columns) {
            foreach ([[], ['label', 'nb_hits'], ['label', 'nb_uniq_visitors'], ['custom']] as $display) {
                foreach (['', '0', 'explicit'] as $sort) {
                    $root = uiTable($columns); $view = uiView();
                    $view->config->columns_to_display = $display; $view->requestConfig->filter_sort_column = $sort; $view->requestConfig->filter_sort_order = 'asc';
                    $nativeConfig = clone $view->config; $nativeRequest = clone $view->requestConfig;
                    $actual = $root->getColumns(); $hasVisits = in_array('nb_visits', $actual); $hasUnique = in_array('nb_uniq_visitors', $actual);
                    if (empty($nativeConfig->columns_to_display)) { $nativeConfig->setDefaultColumnsToDisplay($actual, $hasVisits, $hasUnique); }
                    if (empty($nativeRequest->filter_sort_column)) { $nativeRequest->setDefaultSort($nativeConfig->columns_to_display, $hasUnique, $actual); }
                    UiRequestBuilder::defaults($view, $root);
                    uiExpect($view->config->columns_to_display === $nativeConfig->columns_to_display
                        && $view->requestConfig->filter_sort_column === $nativeRequest->filter_sort_column
                        && $view->requestConfig->filter_sort_order === $nativeRequest->filter_sort_order, 'owned default fallback differs from native');
                }
            }
        }
    });
    uiCheck('owned-defaults-use-only-public-properties', function () {
        $view = (object)['config' => (object)['columns_to_display' => []],
            'requestConfig' => (object)['filter_sort_column' => '', 'filter_sort_order' => 'asc']];
        UiRequestBuilder::defaults($view, uiTable(['label' => '/root', 'nb_visits' => 3, 'nb_uniq_visitors' => 2]));
        uiExpect($view->config->columns_to_display === ['label', 'nb_uniq_visitors']
            && $view->requestConfig->filter_sort_column === 'nb_uniq_visitors'
            && $view->requestConfig->filter_sort_order === 'desc', 'defaults require internal native configuration methods');
        $before = [get_object_vars($view->config), get_object_vars($view->requestConfig)];
        UiRequestBuilder::defaults($view, uiTable(['label' => '/different', 'custom' => 9]));
        uiExpect([get_object_vars($view->config), get_object_vars($view->requestConfig)] === $before, 'explicit defaults changed on second call');
    });
    $failed = count(array_filter($results, static fn($r) => $r['status'] !== 'pass'));
    $report = ['fixture' => 'ui-base-preloader', 'network_namespace_isolated' => true, 'application_bootstrap' => false,
        'database_or_customer_reports' => false, 'backend_doubles_only' => true,
        'cold_api_closure_executed' => false, 'real_scope_reader_integration_tested' => false,
        'source_sha256' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/UiBasePreloader.php'),
        'builder_sha256' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/UiRequestBuilder.php'),
        'context_sha256' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/UiRequestContext.php'),
        'checks' => count($results), 'passed' => count($results) - $failed, 'failed' => $failed, 'results' => $results];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    speoFixtureReport('ui-base-preloader', $json); echo $json; exit($failed ? 1 : 0);
}
