<?php

/** Isolated native-view dispatch binding; no application bootstrap or backend. */
declare(strict_types=1);

namespace Piwik\Plugin {
    final class Manager
    {
        public static function getInstance(): self { static $instance; return $instance ??= new self(); }
        public function getPluginsLoadedAndActivated(): array { return []; }
    }
}
namespace Piwik\Plugins\SpeoReportPerformance {
    function pendingUnexpectedIo(string $operation): never
    {
        $GLOBALS['pendingIo'][] = $operation;
        throw new \RuntimeException('Constructor attempted backend work: ' . $operation);
    }
    final class RevisionContext
    {
        public static function assertViewAccess(array $request): int { pendingUnexpectedIo('view-access'); }
        public static function prepare(array $request, ?array $raw = null): ?self { pendingUnexpectedIo('archive-revision'); }
    }
    final class ReportCache
    {
        public static function loadBase(RevisionContext $context): ?\Piwik\DataTable { pendingUnexpectedIo('base-cache'); }
    }
    final class UiNumericPageCache
    {
        public static function loadAttach($view, RevisionContext $context, array $request): bool { pendingUnexpectedIo('numeric-cache'); }
    }
}
namespace {
    use Piwik\DataTable;
    use Piwik\Plugin\ViewDataTable;
    use Piwik\Plugins\CoreVisualizations\Visualizations\HtmlTable;
    use Piwik\Plugins\SpeoReportPerformance\SpeoReportPerformance;
    use Piwik\Plugins\SpeoReportPerformance\NativeEndHooks;
    use Piwik\Plugins\SpeoReportPerformance\UiBasePreloader;
    use Piwik\Plugins\SpeoReportPerformance\UiPendingRequests;
    use Piwik\Plugins\SpeoReportPerformance\UiRequestBuilder;
    use Piwik\Plugins\SpeoReportPerformance\UiRequestContext;

    require dirname(__DIR__) . '/bootstrap.php';
    speoFixtureBootstrap('ui-pending-requests');
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);

    $container = new \Piwik\Container\Container();
    \Piwik\Container\StaticContainer::push($container);
    $container->set(\Piwik\Log\LoggerInterface::class, new \Psr\Log\NullLogger());

    /** Native view/config/request classes, with application-only construction omitted. */
    function pendingView(array $modify = []): HtmlTable
    {
        $view = (new \ReflectionClass(HtmlTable::class))->newInstanceWithoutConstructor();
        $view->config = (new \ReflectionClass(HtmlTable\Config::class))->newInstanceWithoutConstructor();
        $view->config->columns_to_display = ['label', 'nb_hits'];
        $view->config->show_pivot_by_subtable = false;
        $view->config->enable_sort = true;
        $view->config->disable_comparison = true;
        $view->requestConfig = (new \ReflectionClass(HtmlTable\RequestConfig::class))->newInstanceWithoutConstructor();
        $view->requestConfig->apiMethodToRequestDataTable = 'Actions.getPageUrls';
        $view->requestConfig->filter_limit = 500;
        $view->requestConfig->flat = 1;
        $view->requestConfig->filter_sort_column = 'nb_hits';
        $view->requestConfig->filter_sort_order = 'desc';
        $view->requestConfig->disable_queued_filters = false;
        $view->requestConfig->request_parameters_to_modify = $modify;
        (new \ReflectionProperty(ViewDataTable::class, 'request'))->setValue(
            $view,
            new \Piwik\ViewDataTable\Request($view->requestConfig)
        );
        return $view;
    }
    function pendingParameters(): array
    {
        return ['idSite' => 353, 'period' => 'month', 'date' => '2026-10-01',
            'segment' => false, 'expanded' => false, 'idSubtable' => false, 'depth' => false, 'flat' => true];
    }
    function pendingAssert(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    $results = [];
    function pendingCheck(string $name, callable $test): void
    {
        global $results;
        $_GET = ['module' => 'Actions', 'action' => 'getPageUrls', 'idSite' => '353',
            'period' => 'month', 'date' => '2026-10-01', 'flat' => '1', 'filter_limit' => '500'];
        $_POST = [];
        $_SERVER['QUERY_STRING'] = http_build_query($_GET);
        $GLOBALS['pendingIo'] = [];
        try {
            $test();
            pendingAssert($GLOBALS['pendingIo'] === [], 'staging or binding performed backend IO');
            $results[] = ['case' => $name, 'status' => 'pass'];
        } catch (\Throwable $error) {
            $results[] = ['case' => $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage(), 'trace' => $error->getTraceAsString()];
        }
        gc_collect_cycles();
    }
    pendingCheck('constructor-configuration-stages-without-data-or-backend', function () {
        $view = pendingView(['totals' => 1]);
        $beforeGet = $_GET; $beforePost = $_POST;
        UiBasePreloader::configureEnd($view);
        $modify = $view->requestConfig->request_parameters_to_modify;
        pendingAssert(isset($modify[UiPendingRequests::PARAMETER]) && $modify['totals'] === 1, 'view was not staged');
        pendingAssert($_GET === $beforeGet && $_POST === $beforePost, 'constructor altered ambient request');
        $missing = false;
        try { $view->getDataTable(); } catch (\Exception $expected) { $missing = true; }
        pendingAssert($missing && UiBasePreloader::getLastDecision() === 'ui-pending-dispatch', 'constructor loaded a report');
    });
    pendingCheck('temporary-preference-view-and-real-view-bind-independently', function () {
        $temporary = pendingView(); $real = pendingView();
        UiBasePreloader::configureEnd($temporary);
        UiBasePreloader::configureEnd($real);
        $temporaryToken = $temporary->requestConfig->request_parameters_to_modify[UiPendingRequests::PARAMETER];
        $realToken = $real->requestConfig->request_parameters_to_modify[UiPendingRequests::PARAMETER];
        pendingAssert($temporaryToken !== $realToken, 'identical views shared a dispatch identity');
        pendingAssert(UiPendingRequests::take(pendingParameters(), $real->getRequestArray()) === $real, 'dispatch consumed temporary view');
        pendingAssert(isset($temporary->requestConfig->request_parameters_to_modify[UiPendingRequests::PARAMETER]), 'unused temporary view was consumed');
        pendingAssert($real->requestConfig->request_parameters_to_modify === [], 'real-view marker was not restored');
    });
    pendingCheck('identical-views-dispatch-in-reverse-creation-order', function () {
        $first = pendingView(); $second = pendingView();
        UiBasePreloader::configureEnd($first); UiBasePreloader::configureEnd($second);
        $firstRequest = $first->getRequestArray(); $secondRequest = $second->getRequestArray();
        pendingAssert(UiPendingRequests::take(pendingParameters(), $secondRequest) === $second, 'second view misbound');
        pendingAssert(UiPendingRequests::take(pendingParameters(), $firstRequest) === $first, 'first view misbound');
        pendingAssert(UiPendingRequests::take(pendingParameters(), $firstRequest) === null, 'dispatch token was reusable');
    });
    pendingCheck('staging-is-weak-and-does-not-retain-unused-view', function () {
        $view = pendingView(); UiBasePreloader::configureEnd($view);
        $request = $view->getRequestArray(); $weak = \WeakReference::create($view);
        unset($view); gc_collect_cycles();
        pendingAssert($weak->get() === null, 'staging retained an unused temporary view');
        pendingAssert(UiPendingRequests::take(pendingParameters(), $request) === null, 'dead view remained dispatchable');
    });
    foreach (['idSite' => 354, 'period' => 'day', 'date' => '2026-09-01', 'segment' => 'countryCode==CH',
        'expanded' => true, 'idSubtable' => 12, 'depth' => 2, 'flat' => false] as $name => $changed) {
        pendingCheck('modified-api-argument-declined-' . $name, function () use ($name, $changed) {
            $view = pendingView(['totals' => 1]); UiBasePreloader::configureEnd($view);
            $request = $view->getRequestArray(); $parameters = pendingParameters(); $parameters[$name] = $changed;
            pendingAssert(UiPendingRequests::take($parameters, $request) === null, 'modified native API argument accepted');
            pendingAssert($view->requestConfig->request_parameters_to_modify === ['totals' => 1], 'marker leaked after decline');
            pendingAssert(UiPendingRequests::take(pendingParameters(), $request) === null, 'declined dispatch token could be replayed');
        });
    }
    foreach (['array-depth' => ['depth', []], 'object-segment' => ['segment', new \stdClass()]] as $shape => [$name, $changed]) {
        pendingCheck('non-scalar-api-argument-declined-' . $shape, function () use ($name, $changed) {
            $view = pendingView(); UiBasePreloader::configureEnd($view);
            $parameters = pendingParameters(); $parameters[$name] = $changed;
            pendingAssert(UiPendingRequests::take($parameters, $view->getRequestArray()) === null, 'non-scalar native API argument accepted');
            pendingAssert($view->requestConfig->request_parameters_to_modify === [], 'marker leaked after non-scalar argument decline');
        });
    }
    foreach (['missing', 'extra'] as $shape) {
        pendingCheck('changed-api-signature-declined-' . $shape, function () use ($shape) {
            $view = pendingView(); UiBasePreloader::configureEnd($view);
            $parameters = pendingParameters();
            if ($shape === 'missing') { unset($parameters['depth']); } else { $parameters['future'] = false; }
            pendingAssert(UiPendingRequests::take($parameters, $view->getRequestArray()) === null, 'changed API argument set accepted');
            pendingAssert($view->requestConfig->request_parameters_to_modify === [], 'marker leaked on signature mismatch');
        });
    }
    pendingCheck('unrelated-public-parameter-changes-preserved-on-consumption', function () {
        $view = pendingView(['totals' => 1]); UiBasePreloader::configureEnd($view);
        $view->requestConfig->request_parameters_to_modify['keep_totals_row'] = 1;
        pendingAssert(UiPendingRequests::take(pendingParameters(), $view->getRequestArray()) === $view, 'matching dispatch declined');
        pendingAssert($view->requestConfig->request_parameters_to_modify === ['totals' => 1, 'keep_totals_row' => 1], 'foreign parameter change lost');
    });
    pendingCheck('foreign-marker-is-never-replaced-or-adopted', function () {
        $view = pendingView([UiPendingRequests::PARAMETER => 'foreign']);
        UiBasePreloader::configureEnd($view);
        pendingAssert($view->requestConfig->request_parameters_to_modify === [UiPendingRequests::PARAMETER => 'foreign'], 'foreign marker overwritten');
        pendingAssert(UiPendingRequests::take(pendingParameters(), $view->getRequestArray()) === null, 'foreign marker adopted');
    });
    pendingCheck('changed-owner-marker-is-never-consumed', function () {
        $view = pendingView(); UiBasePreloader::configureEnd($view); $original = $view->getRequestArray();
        $view->requestConfig->request_parameters_to_modify[UiPendingRequests::PARAMETER] = 'replacement';
        pendingAssert(UiPendingRequests::take(pendingParameters(), $original) === null, 'changed owner marker accepted');
        pendingAssert($view->requestConfig->request_parameters_to_modify[UiPendingRequests::PARAMETER] === 'replacement', 'replacement marker erased');
    });
    pendingCheck('builder-hides-marker-before-cache-request-keying', function () {
        $view = pendingView(); $before = UiRequestBuilder::build($view); UiBasePreloader::configureEnd($view);
        pendingAssert(isset($view->getRequestArray()[UiPendingRequests::PARAMETER]), 'native dispatch lost staged marker');
        pendingAssert(UiRequestBuilder::build($view) === $before, 'staged identity leaked into canonical cache request');
    });
    pendingCheck('owned-request-scope-hides-ambient-proxy-marker-and-restores-it', function () {
        $view = pendingView(); UiBasePreloader::configureEnd($view); $raw = $view->getRequestArray();
        $clean = UiRequestBuilder::build($view);
        \Piwik\Context::executeWithQueryParameters($raw, static function () use ($clean): void {
            $beforeGet = $_GET; $beforePost = $_POST;
            UiRequestContext::run($clean, static function (): void {
                pendingAssert(!array_key_exists(UiPendingRequests::PARAMETER, $_GET) && !array_key_exists(UiPendingRequests::PARAMETER, $_POST), 'marker reached archive/cache API scope');
            });
            pendingAssert($_GET === $beforeGet && $_POST === $beforePost, 'scoped marker removal changed caller request');
        });
        pendingAssert(!array_key_exists(UiPendingRequests::PARAMETER, $_GET), 'outer request retained marker');
    });
    pendingCheck('unused-binding-cancelled-before-render-with-other-settings-preserved', function () {
        $view = pendingView(['totals' => 1]); UiBasePreloader::configureEnd($view);
        $request = $view->getRequestArray();
        $view->requestConfig->request_parameters_to_modify['keep_totals_row'] = 1;
        UiPendingRequests::cancel($view);
        pendingAssert($view->requestConfig->request_parameters_to_modify === ['totals' => 1, 'keep_totals_row' => 1], 'unused binding left a render marker');
        pendingAssert(UiPendingRequests::take(pendingParameters(), $request) === null, 'cancelled binding remained dispatchable');
    });
    pendingCheck('cancelling-one-view-cannot-cancel-another-view-binding', function () {
        $first = pendingView(); $second = pendingView();
        UiBasePreloader::configureEnd($first); UiBasePreloader::configureEnd($second);
        $secondRequest = $second->getRequestArray();
        $first->requestConfig->request_parameters_to_modify = $second->requestConfig->request_parameters_to_modify;
        UiPendingRequests::cancel($first);
        pendingAssert(UiPendingRequests::take(pendingParameters(), $secondRequest) === $second, 'foreign view could cancel another binding');
    });
    pendingCheck('outer-native-dispatch-replaces-only-native-performance-definitions-once', function () {
        $table = new DataTable();
        $native = array_values(\Piwik\Plugins\PagePerformance\Metrics::getAllPagePerformanceMetrics());
        $foreignFirst = new \Piwik\Plugins\Actions\Columns\Metrics\BounceRate();
        $foreignLast = new \Piwik\Plugins\Actions\Columns\Metrics\AverageTimeOnPage();
        $table->setMetadata('before', 'fixture');
        $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, [$foreignFirst, ...$native, $foreignLast]);
        $table->setMetadata('after', ['fixture' => true]);
        $keys = array_keys($table->getAllTableMetadata());
        NativeEndHooks::prepareForNativeDispatch($table);
        pendingAssert($table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME) === [$foreignFirst, $foreignLast], 'native definitions duplicated or foreign metrics removed');
        pendingAssert(array_keys($table->getAllTableMetadata()) === $keys, 'metadata slot order changed');
        NativeEndHooks::prepareForNativeDispatch($table);
        pendingAssert($table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME) === [$foreignFirst, $foreignLast], 'cleanup was not idempotent');
        // Model the ordinary outer native callback appending the current definitions.
        $existing = $table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME);
        $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, array_merge($existing, $native));
        pendingAssert(count($table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME)) === count($native) + 2, 'outer native metrics were not present exactly once');
    });
    pendingCheck('dispatch-preparation-does-not-add-a-missing-metadata-slot', function () {
        $table = new DataTable(); $table->setMetadata('fixture', 1);
        NativeEndHooks::prepareForNativeDispatch($table);
        pendingAssert($table->getAllTableMetadata() === ['fixture' => 1], 'absent metric metadata was synthesized');
    });
    pendingCheck('dispatch-preparation-preserves-non-array-metadata-for-native-handling', function () {
        $table = new DataTable(); $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, false);
        NativeEndHooks::prepareForNativeDispatch($table);
        pendingAssert($table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME) === false, 'foreign metadata shape was altered');
    });
    pendingCheck('public-intercept-returns-the-exact-bound-native-view-table', function () {
        $first = pendingView(); $second = pendingView();
        UiBasePreloader::configureEnd($first); UiBasePreloader::configureEnd($second);
        $firstTable = new DataTable(); $secondTable = new DataTable();
        $first->setDataTable($firstTable); $second->setDataTable($secondTable);
        $plugin = (new \ReflectionClass(SpeoReportPerformance::class))->newInstanceWithoutConstructor();
        $value = null;
        $plugin->readArchive($value, pendingParameters(), 'Actions', 'getPageUrls', $first->getRequestArray());
        pendingAssert($value === $firstTable, 'interception returned data for a different identical view');
        pendingAssert($first->requestConfig->request_parameters_to_modify === [], 'consumed marker reached post-load view');
        pendingAssert(isset($second->requestConfig->request_parameters_to_modify[UiPendingRequests::PARAMETER]), 'unrelated view was consumed');
    });
    $failed = count(array_filter($results, static fn($row) => $row['status'] !== 'pass'));
    $report = ['fixture' => 'ui-pending-requests', 'application_bootstrap' => false,
        'database_or_customer_reports' => false, 'backend_doubles_only' => true,
        'native_factory_end_to_end' => false, 'checks' => count($results),
        'passed' => count($results) - $failed, 'failed' => $failed, 'results' => $results];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    speoFixtureReport('ui-pending-requests', $json); echo $json;
    exit($failed ? 1 : 0);
}
