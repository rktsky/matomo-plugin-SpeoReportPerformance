<?php
/** Private native-config/filter fixture; no Environment, API process, database or customer report. */
declare(strict_types=1);
namespace Piwik {
    final class Cache
    {
        public static object $lazy;
        public static object $transient;
        public static function getLazyCache(): object { return self::$lazy; }
        public static function getTransientCache(): object { return self::$transient; }
    }
}
namespace Piwik\Plugin {
    final class ReportsProvider
    {
        public static $report;
        public static function factory($module, $action) { return self::$report; }
    }
    final class Manager
    {
        public static bool $pagePerformance = true;
        public static object $enricher;
        public static function getInstance(): self { static $instance; return $instance ??= new self(); }
        public function getLoadedPluginsName(): array { return ['PrivateNumericFixture']; }
        public function isPluginActivated($name): bool { return self::$pagePerformance; }
        public function getLoadedPlugin($name): object { return self::$enricher; }
    }
}
namespace Piwik\Plugins\SpeoReportPerformance {
    /** Explicit own publication double: these isolated filter fixtures do not bootstrap native storage. */
    final class CopyLifecycle
    {
        public static function publish(RevisionContext $context, string $key, string $payload, int $ttl): bool
        {
            return \Piwik\Cache::getLazyCache()->save($key, $payload, $ttl);
        }
    }
    final class NativeSourceGuard
    {
        public static bool $pipeline = true;
        public static bool $cachePipeline = true;
        public static function isReviewedPipeline(): bool { return self::$pipeline; }
        public static function isReviewedCachePipeline(): bool { return self::$cachePipeline; }
    }
    final class RequestEligibility
    {
        public static ?string $reason = null;
        public static function reason(...$args): ?string { return self::$reason; }
    }
    final class RevisionContext
    {
        public bool $current = true;
        public bool $denied = false;
        public array $plan = [];
        public int $checks = 0;
        public function __construct(private \Piwik\DataTable $root, private string $key = 'private-principal-one') {}
        public function getKey(): string { return $this->key; }
        public function getFreshRoot(): \Piwik\DataTable { return $this->root; }
        public function isCurrent(): bool
        {
            ++$this->checks;
            if ($this->denied) { throw new \Piwik\NoAccessException('Fixture denied'); }
            return $this->plan ? array_shift($this->plan) : $this->current;
        }
        public static function canonicalize(mixed $value): mixed
        {
            if (is_array($value)) {
                if (!array_is_list($value)) { ksort($value, SORT_STRING); }
                foreach ($value as $key => $child) { $value[$key] = self::canonicalize($child); }
                return $value;
            }
            if (is_object($value) || is_resource($value)) { throw new \RuntimeException('Nonprimitive public fixture config'); }
            return $value;
        }
    }
}
namespace {
    use Piwik\DataTable;
    use Piwik\DataTable\Row;
    use Piwik\Plugin\ViewDataTable;
    use Piwik\Plugins\CoreVisualizations\Visualizations\HtmlTable;
    use Piwik\Plugins\Actions\Reports\GetPageUrls;
    use Piwik\Plugins\Actions\Reports\Base;
    use Piwik\Plugins\SpeoReportPerformance\UiNumericPageCache as NumericCache;
    use Piwik\Plugins\SpeoReportPerformance\ProcessedTableSnapshot;
    use Piwik\Plugins\SpeoReportPerformance\RevisionContext;
    require_once dirname(__DIR__) . '/bootstrap.php';
    speoAssertIsolated();
    umask(0077);
    speoFixtureBootstrap('ui-numeric-page-cache');
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
    $container = new \Piwik\Container\Container(); \Piwik\Container\StaticContainer::push($container);
    $container->set('Piwik\\Translation\\Translator', new class {
        public function getCurrentLanguage() { return 'en'; }
        public function translate($id, $args = [], $language = null) { return $id; }
    });
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
    final class NumericPercentFormatter extends \Piwik\NumberFormatter
    {
        public function __construct() {}
        public function formatPercent($value, $maximumFractionDigits = 0, $minimumFractionDigits = 0)
        {
            return number_format((float)$value, $maximumFractionDigits, '.', '') . '%';
        }
    }
    $container->set(\Piwik\NumberFormatter::class, new NumericPercentFormatter());
    final class NumericArrayCache
    {
        public array $data = [];
        public array $fetched = [];
        public array $saved = [];
        public bool $throwFetch = false;
        public bool $saveResult = true;
        public function contains($key): bool { return array_key_exists($key, $this->data); }
        public function fetch($key) { $this->fetched[] = $key; if ($this->throwFetch) { throw new \RuntimeException('Fixture fetch failure'); } return $this->data[$key] ?? false; }
        public function save($key, $value, $ttl = 0): bool { $this->saved[] = [$key, $value, $ttl]; if ($this->saveResult) { $this->data[$key] = $value; } return $this->saveResult; }
    }
    final class NumericEnricher
    {
        public int $calls = 0;
        public function enrichApi(DataTable $table, array $info): void { ++$this->calls; $table->setMetadata('extra_processed_metrics', array_values(\Piwik\Plugins\PagePerformance\Metrics::getAllPagePerformanceMetrics())); }
    }
    class NumericCustomView extends HtmlTable {}
    function numRow(string $label, int $hits = 50): Row
    {
        return new Row([Row::COLUMNS => ['label' => $label, 'nb_hits' => $hits, 'nb_visits' => $hits - 1,
            'nb_hits_with_time_generation' => 0, 'min_time_generation' => 0, 'max_time_generation' => 0],
            Row::METADATA => ['Actions_PageUrl' => $label, 'unrelated_row_percentage' => 'retained',
                'unknown_null' => null, 'label_row_percentage' => null]]);
    }
    function numPage(): DataTable
    {
        $table = new DataTable(); $table->setRows([500 => numRow('&amp;lt;first&amp;gt;', 50), 501 => numRow('/second', 40)]);
        $table->setTotalsRow(numRow('Totals', 200));
        $table->setAllTableMetadata(['processed_metrics_computed' => true, 'processed_metrics_formatted' => false,
            'dimensions' => ['Actions_PageUrl'], 'total_rows_before_limit' => 3000,
            'totals' => ['nb_hits' => 200, 'nb_visits' => 200], 'totalsUnformatted' => ['nb_hits' => 200, 'nb_visits' => 200], 'extra_processed_metrics' => []]);
        $table->setTableSortedBy('nb_hits'); $table->enableRecursiveSort(); $table->setAsBuiltWithoutArchives(false);
        return $table;
    }
    function numRowState(Row $row): array { return [$row->getColumns(), $row->getMetadata(), $row->isSummaryRow(), $row->maxVisitsSummed, $row->getIdSubDataTable()]; }
    function numState(DataTable $table): array
    {
        $rows = []; foreach ($table->getRowsWithoutSummaryRow() as $id => $row) { $rows[$id] = numRowState($row); }
        return ['rows' => $rows, 'totals' => $table->getTotalsRow() ? numRowState($table->getTotalsRow()) : null,
            'metadata' => $table->getAllTableMetadata(), 'sorted' => $table->getSortedByColumnName(),
            'recursive' => $table->isSortRecursiveEnabled(), 'built' => $table->wasBuiltWithoutArchives()];
    }
    function numReset(): void
    {
        $_GET = ['module' => 'Actions', 'action' => 'getPageUrls', 'viewDataTable' => 'table', 'widget' => '1',
            'enable_filter_excludelowpop' => '0', 'flat' => '1', 'idSite' => '353', 'period' => 'month', 'date' => '2026-10-01'];
        $_POST = []; $_REQUEST = $_GET; $_SERVER['QUERY_STRING'] = http_build_query($_GET);
        \Piwik\Cache::$lazy = new NumericArrayCache(); \Piwik\Cache::$transient = new NumericArrayCache();
        \Piwik\Plugin\Manager::$pagePerformance = true; \Piwik\Plugin\Manager::$enricher = new NumericEnricher();
        \Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard::$pipeline = true;
        \Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard::$cachePipeline = true;
        \Piwik\Plugins\SpeoReportPerformance\RequestEligibility::$reason = null;
        $mappingKey = \Piwik\CacheId::siteAware(\Piwik\CacheId::pluginAware('Metrics.mappingFromIdToName'));
        \Piwik\Cache::$transient->save($mappingKey, \Piwik\Metrics::$mappingFromIdToName);
        (new \ReflectionProperty(NumericCache::class, 'states'))->setValue(null);
    }
    function numView(string $class = HtmlTable::class): HtmlTable
    {
        $view = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $view->config = (new \ReflectionClass(HtmlTable\Config::class))->newInstanceWithoutConstructor();
        $view->config->filters = []; $view->config->translations = [];
        $view->config->columns_to_display = ['label', 'nb_hits', 'nb_visits']; $view->config->show_pivot_by_subtable = false;
        $view->config->disable_comparison = true; $view->config->enable_sort = true;
        $view->requestConfig = (new \ReflectionClass(HtmlTable\RequestConfig::class))->newInstanceWithoutConstructor();
        $view->requestConfig->apiMethodToRequestDataTable = 'Actions.getPageUrls';
        $view->requestConfig->filter_sort_column = 'nb_hits'; $view->requestConfig->filter_sort_order = 'desc';
        $view->requestConfig->filter_offset = 500; $view->requestConfig->request_parameters_to_modify = ['serialize' => 0];
        $nativeRequest = new \Piwik\ViewDataTable\Request($view->requestConfig);
        (new \ReflectionProperty(ViewDataTable::class, 'request'))->setValue($view, $nativeRequest);
        $report = (new \ReflectionClass(GetPageUrls::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Piwik\Plugin\Report::class, 'dimension'))->setValue($report,
            (new \ReflectionClass(\Piwik\Plugins\Actions\Columns\PageUrl::class))->newInstanceWithoutConstructor());
        (new \ReflectionProperty(\Piwik\Plugin\Report::class, 'metrics'))->setValue($report, ['label', 'nb_hits', 'nb_visits']);
        \Piwik\Plugin\ReportsProvider::$report = $report;
        (new \ReflectionProperty(\Piwik\Plugin\Visualization::class, 'report'))->setValue($view, $report);
        (new \ReflectionMethod(Base::class, 'addPageDisplayProperties'))->invoke($report, $view);
        (new \ReflectionMethod(Base::class, 'addBaseDisplayProperties'))->invoke($report, $view);
        $view->requestConfig->filter_limit = 500;
        return $view;
    }
    function numContext(string $key = 'private-principal-one'): RevisionContext { return new RevisionContext(numPage(), $key); }
    function numRequest(HtmlTable $view): array { return \Piwik\Plugins\SpeoReportPerformance\UiRequestBuilder::build($view); }
    function numNativePercentages(HtmlTable $view, DataTable $table, bool $site = true): void
    {
        $view->setDataTable($table);
        $summary = new DataTable(); $summary->addRow(new Row([Row::COLUMNS => ['label' => 'Site', 'nb_hits' => $site ? 400 : 100, 'nb_visits' => $site ? 400 : 100]]));
        (new \ReflectionProperty(HtmlTable::class, 'siteSummary'))->setValue($view, $summary);
        $view->afterGenericFiltersAreAppliedToLoadedDataTable();
        // Native PP adds row segments after afterGeneric but before queued filters.
        foreach ($table->getRows() as $row) { $row->setMetadata('segment', 'pageUrl==' . rawurlencode($row->getColumn('label'))); }
    }
    function numCapture(HtmlTable $view, DataTable $table, bool $site = true): void
    {
        numNativePercentages($view, $table, $site);
        foreach ($view->config->getPresentationFilters() as [$callback, $params]) { $table->queueFilter($callback, $params); }
        $table->queueFilter('SafeDecodeLabel'); $table->applyQueuedFilters();
    }
    function numAssert(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
    $results = [];
    function numCheck(string $name, callable $test): void
    {
        global $results;
        try { numReset(); $test(); $results[] = ['case' => $name, 'status' => 'pass']; }
        catch (\Throwable $error) { $results[] = ['case' => $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()]; }
    }
    numCheck('real-native-presentation-closure-contract-and-order', function () {
        $view = numView(); $filters = $view->config->getPresentationFilters();
        numAssert($view->config->getPriorityFilters() === [] && count($filters) === 2 && $filters[0][0] === 'ColumnCallbackAddMetadata', 'native presentation order differs');
        foreach ([$filters[0][1][2], $filters[1][0]] as $closure) {
            $r = new \ReflectionFunction($closure); numAssert(realpath($r->getFileName()) === realpath(PIWIK_DOCUMENT_ROOT . '/plugins/Actions/Reports/Base.php')
                && get_class($r->getClosureThis()) === GetPageUrls::class, 'fixture did not create actual native bound closures');
        }
    });
    numCheck('miss-first-presentation-captures-numeric-copy-only', function () {
        $view = numView(); $context = numContext(); $originalFilters = $view->config->filters; $mods = $view->requestConfig->request_parameters_to_modify;
        numAssert(!NumericCache::loadAttach($view, $context, numRequest($view)), 'empty cache unexpectedly hit');
        numAssert($view->config->getPriorityFilters() === [] && count($view->config->getPresentationFilters()) === 3
            && $view->requestConfig->request_parameters_to_modify === $mods, 'capture changed generic flags or priority ordering');
        $table = numPage(); numNativePercentages($view, $table); $before = numState($table); $rawLabel = $table->getRowFromId(500)->getColumn('label');
        $capture = $view->config->getPresentationFilters()[0]; $table->queueFilter('SafeDecodeLabel'); $queueBefore = $table->getQueuedFilters();
        $table->filter($capture[0], $capture[1]);
        numAssert(numState($table) === $before && $table->getQueuedFilters() === $queueBefore, 'copy capture changed source rows/metadata/queues');
        numAssert(count(\Piwik\Cache::$lazy->saved) === 1 && \Piwik\Cache::$lazy->saved[0][2] === 86400, 'capture not stored once with expected TTL');
        $saved = ProcessedTableSnapshot::decode(\Piwik\Cache::$lazy->saved[0][1], $context->getFreshRoot());
        numAssert($saved instanceof DataTable && $saved->getQueuedFilters() === [] && $saved->getRowFromId(500)->getColumn('label') === $rawLabel, 'cached page was presented or carried closures/queues');
        $savedMetadata = $saved->getRowFromId(500)->getMetadata();
        numAssert(array_key_exists('nb_hits_row_percentage', $savedMetadata) && $savedMetadata['nb_hits_row_percentage'] === null
            && array_key_exists('nb_hits_site_total_percentage', $savedMetadata) && $savedMetadata['nb_hits_site_total_percentage'] === null
            && $saved->getRowFromId(500)->getMetadata('unrelated_row_percentage') === 'retained', 'percentage stripping removed wrong fields');
        numAssert(array_key_exists('unknown_null', $savedMetadata) && $savedMetadata['unknown_null'] === null
            && array_key_exists('label_row_percentage', $savedMetadata) && $savedMetadata['label_row_percentage'] === null, 'unknown/label null metadata was removed');
        numAssert(!array_key_exists('nb_hits_row_percentage', $saved->getTotalsRow()->getMetadata()), 'totals percentage metadata not stripped');
        $table->filter($capture[0], $capture[1]); numAssert(count(\Piwik\Cache::$lazy->saved) === 1, 'numeric page captured twice');
        NumericCache::finish($view); numAssert($view->config->filters === $originalFilters && $view->requestConfig->request_parameters_to_modify === $mods, 'finish did not restore original state');
        NumericCache::finish($view); numAssert($view->config->filters === $originalFilters, 'finish was not idempotent');
    });
    numCheck('hit-offset500-selection-adapter-without-request-flag-mutation', function () {
        $first = numView(); $context = numContext(); NumericCache::loadAttach($first, $context, numRequest($first));
        $source = numPage(); numCapture($first, $source); NumericCache::finish($first);
        $view = numView(); $filters = $view->config->filters; $presentation = $view->config->getPresentationFilters(); $mods = $view->requestConfig->request_parameters_to_modify;
        numAssert(NumericCache::loadAttach($view, $context, numRequest($view)), 'stored numeric page not hit');
        numAssert($view->requestConfig->request_parameters_to_modify === $mods && count($view->config->getPriorityFilters()) === 0
            && count($view->config->getPresentationFilters()) === 3 && array_slice($view->config->getPresentationFilters(), 1) === $presentation,
            'hit modified flags before priority stage or changed native presentation filter order');
        $table = $view->getDataTable(); $expectedPayloadCount = count(\Piwik\Cache::$lazy->saved);
        $flat = (new \Piwik\API\DataTableManipulator\Flattener('Actions', 'getPageUrls', numRequest($view)))->flatten($table, '/', false);
        numAssert($flat instanceof \Piwik\Plugins\SpeoReportPerformance\SelectedPageTable && array_keys($flat->getRows()) === [500, 501], 'native flatten did not preserve selected sparse IDs');
        foreach ($view->config->getPriorityFilters() as [$callback, $params]) { $flat->filter($callback, $params); }
        numAssert(array_keys($flat->getRowsWithoutSummaryRow()) === [500, 501] && $flat->getSortedByColumnName() === 'nb_hits'
            && $flat->isSortRecursiveEnabled() && !$flat->wasBuiltWithoutArchives(), 'priority restoration lost sparse offset IDs/state');
        numAssert(!array_key_exists('disable_generic_filters', numRequest($view)) && $view->requestConfig->request_parameters_to_modify === $mods, 'selection adapter mutated request flags');
        $flat->filter('Pattern', ['label', 'matches-no-cached-row']); $flat->filter('Sort', ['nb_hits', 'asc']); $flat->filter('Limit', [500, 500]);
        numAssert(array_keys($flat->getRows()) === [500, 501] && $flat->getRowsCount() === 2, 'completed selection was applied a second time');
        $once = numRow('&amp;lt;first&amp;gt;'); $oracle = new DataTable(); $oracle->addRow($once); $oracle->filter('SafeDecodeLabel');
        numCapture($view, $flat);
        numAssert($flat->getRowFromId(500)->getColumn('label') === $once->getColumn('label'), 'hit label was safe-decoded twice or changed');
        numAssert(count(\Piwik\Cache::$lazy->saved) === $expectedPayloadCount, 'hit attached another capture filter');
        numAssert(\Piwik\Plugin\Manager::$enricher->calls === 1 && count($flat->getMetadata('extra_processed_metrics')) === 7, 'hit did not rebind fresh PagePerformance metrics exactly once');
        NumericCache::finish($view); numAssert($view->config->filters === $filters && $view->requestConfig->request_parameters_to_modify === $mods
            && !array_key_exists('disable_generic_filters', numRequest($view)), 'finish leaked internal flag/config into native UI');
    });
    numCheck('capture-finish-before-presentation-keeps-table-and-snapshot-independent', function () {
        $view = numView(); $context = numContext(); NumericCache::loadAttach($view, $context, numRequest($view));
        $table = numPage(); numCapture($view, $table); $payload = \Piwik\Cache::$lazy->saved[0][1];
        $table->getRowFromId(500)->setColumn('nb_hits', 'display 50'); $table->getRowFromId(500)->setMetadata('presentation-late', true);
        $decoded = ProcessedTableSnapshot::decode($payload, $context->getFreshRoot());
        numAssert($decoded->getRowFromId(500)->getColumn('nb_hits') === 50 && $decoded->getRowFromId(500)->getMetadata('presentation-late') === false, 'display mutation contaminated numeric snapshot');
        NumericCache::finish($view);
    });
    foreach ([[false, true], [true, false], [false, false], [true, true]] as [$cachedSite, $freshSite]) {
        numCheck('native-percentage-metadata-byteorder-site-' . (int)$cachedSite . '-to-' . (int)$freshSite, function () use ($cachedSite, $freshSite) {
            $coldView = numView(); $context = numContext(); NumericCache::loadAttach($coldView, $context, numRequest($coldView));
            numCapture($coldView, numPage(), $cachedSite); NumericCache::finish($coldView);
            $hitView = numView(); numAssert(NumericCache::loadAttach($hitView, $context, numRequest($hitView)), 'numeric page not hit for metadata ordering test');
            $flat = (new \Piwik\API\DataTableManipulator\Flattener('Actions', 'getPageUrls', numRequest($hitView)))->flatten($hitView->getDataTable(), '/', false);
            foreach ($hitView->config->getPriorityFilters() as [$callback, $params]) { $flat->filter($callback, $params); }
            numCapture($hitView, $flat, $freshSite);
            $oracleView = numView(); $oracle = numPage(); numCapture($oracleView, $oracle, $freshSite);
            foreach ([500, 501] as $id) {
                $actualRow = $flat->getRowFromId($id); $expectedRow = $oracle->getRowFromId($id);
                $actual = $actualRow->getMetadata(); $expected = $expectedRow->getMetadata();
                numAssert(json_encode([$actualRow->getColumns(), $actual], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
                    === json_encode([$expectedRow->getColumns(), $expected], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    'native fresh row metadata values/key order differ after cache hit');
                foreach (['nb_hits', 'nb_visits'] as $metric) {
                    numAssert($actual[$metric . '_row_percentage'] !== null, 'fresh row percentage left as null');
                    numAssert(array_key_exists($metric . '_site_total_percentage', $actual) === $freshSite, 'new/stale site percentage cleanup incorrect');
                }
                numAssert(array_key_exists('unknown_null', $actual) && $actual['unknown_null'] === null
                    && array_key_exists('label_row_percentage', $actual) && $actual['label_row_percentage'] === null,
                    'cleanup removed unrelated or label null metadata');
            }
            NumericCache::finish($hitView);
        });
    }
    foreach ([null, 'old-total-percent'] as $i => $percentage) {
        numCheck('totals-known-percentage-cache-decline-' . $i, function () use ($percentage) {
            $view = numView(); $context = numContext(); NumericCache::loadAttach($view, $context, numRequest($view));
            $table = numPage(); numNativePercentages($view, $table); $table->getTotalsRow()->setMetadata('nb_hits_row_percentage', $percentage);
            $before = numState($table); $capture = $view->config->getPresentationFilters()[0]; $table->filter($capture[0], $capture[1]);
            numAssert(\Piwik\Cache::$lazy->saved === [] && numState($table) === $before, 'stale totals percentage stored or source mutated');
            NumericCache::finish($view);
        });
    }
    foreach (['special-summary', 'formatted', 'uncomputed', 'oversized', 'subtable', 'shared-row'] as $kind) {
        numCheck('capture-decline-' . $kind, function () use ($kind) {
            $view = numView(); $context = numContext(); NumericCache::loadAttach($view, $context, numRequest($view)); $page = numPage();
            if ($kind === 'special-summary') { $page->addSummaryRow(numRow('Summary')); }
            elseif ($kind === 'formatted') { $page->setMetadata('processed_metrics_formatted', true); }
            elseif ($kind === 'uncomputed') { $page->setMetadata('processed_metrics_computed', false); }
            elseif ($kind === 'oversized') { $page->setRows(array_fill(0, 1001, numRow('/alias'))); }
            elseif ($kind === 'subtable') { $page->getRowFromId(500)->setSubtable(new DataTable()); }
            else { $row = $page->getRowFromId(500); $page->setRows([$row, $row]); }
            $capture = $view->config->getPresentationFilters()[0]; $page->filter($capture[0], $capture[1]);
            numAssert(\Piwik\Cache::$lazy->saved === [], 'unsupported numeric page stored'); NumericCache::finish($view);
        });
    }
    foreach (['priority', 'invalid-presentation', 'empty-columns', 'pivot', 'report', 'pipeline', 'eligibility'] as $kind) {
        numCheck('attach-gate-decline-' . $kind, function () use ($kind) {
            $view = numView(); $context = numContext();
            if ($kind === 'priority') { $view->config->filters[] = [static function () {}, [], true]; }
            elseif ($kind === 'invalid-presentation') { $view->config->filters[] = [new \stdClass(), [], false]; }
            elseif ($kind === 'empty-columns') { $view->config->columns_to_display = []; }
            elseif ($kind === 'pivot') { $view->config->show_pivot_by_subtable = true; }
            elseif ($kind === 'report') { $view->requestConfig->apiMethodToRequestDataTable = 'Actions.getPageTitles'; }
            elseif ($kind === 'pipeline') { \Piwik\Plugins\SpeoReportPerformance\NativeSourceGuard::$pipeline = false; }
            else { \Piwik\Plugins\SpeoReportPerformance\RequestEligibility::$reason = 'unsupported-compare'; }
            $filters = $view->config->filters; $mods = $view->requestConfig->request_parameters_to_modify;
            numAssert(!NumericCache::loadAttach($view, $context, numRequest($view)) && $view->config->filters === $filters
                && $view->requestConfig->request_parameters_to_modify === $mods && \Piwik\Cache::$lazy->fetched === [], 'gate decline mutated view or read external cache');
        });
    }
    numCheck('current-read-write-and-noaccess-gates', function () {
        $view = numView(); $context = numContext(); $context->current = false;
        numAssert(!NumericCache::loadAttach($view, $context, numRequest($view)) && \Piwik\Cache::$lazy->fetched === [], 'stale context read cache');
        $context->current = true; NumericCache::loadAttach($view, $context, numRequest($view)); $context->current = false;
        $capture = $view->config->getPresentationFilters()[0]; numPage()->filter($capture[0], $capture[1]);
        numAssert(\Piwik\Cache::$lazy->saved === [], 'stale context wrote page'); NumericCache::finish($view);
        $other = numView(); $denied = numContext(); $denied->denied = true; $thrown = false;
        try { NumericCache::loadAttach($other, $denied, numRequest($other)); } catch (\Piwik\NoAccessException $e) { $thrown = true; }
        numAssert($thrown, 'permission exception swallowed');
    });
    numCheck('principal-and-public-config-key-separation', function () {
        $first = numView(); NumericCache::loadAttach($first, numContext('principal-one'), numRequest($first)); $key1 = end(\Piwik\Cache::$lazy->fetched); NumericCache::finish($first);
        $second = numView(); NumericCache::loadAttach($second, numContext('principal-two'), numRequest($second)); $key2 = end(\Piwik\Cache::$lazy->fetched); NumericCache::finish($second);
        $third = numView(); $third->config->columns_to_display = ['label', 'nb_visits']; NumericCache::loadAttach($third, numContext('principal-one'), numRequest($third)); $key3 = end(\Piwik\Cache::$lazy->fetched); NumericCache::finish($third);
        numAssert($key1 !== $key2 && $key1 !== $key3 && str_starts_with($key1, 'principal-one_ui_numeric_'), 'principal/config scopes collided');
    });
    numCheck('changing-native-self-url-transport-reuses-key-and-hit-with-fresh-ui-properties', function () {
        $context = numContext(); $warm = numView();
        $warm->config->self_url = '?module=Actions&action=getPageUrls&random=111&uniqueId=widget-one&widget=1&transport=first';
        $footer = $warm->config->footer_icons; $showFooter = $warm->config->show_footer; $showIcons = $warm->config->show_footer_icons;
        numAssert(!NumericCache::loadAttach($warm, $context, numRequest($warm)), 'new URL fixture unexpectedly hit');
        $warmKey = end(\Piwik\Cache::$lazy->fetched); numCapture($warm, numPage()); NumericCache::finish($warm);
        foreach ([
            '?module=Actions&action=getPageUrls&random=222&uniqueId=widget-two&widget=0&transport=second',
            '?module=CoreHome&action=renderReportWidget&random=333&uniqueId=widget-three&widget=1&containerId=other',
        ] as $url) {
            $view = numView(); $view->config->self_url = $url;
            numAssert(NumericCache::loadAttach($view, $context, numRequest($view)), 'transport-only self_url change caused a numeric cache miss');
            numAssert(end(\Piwik\Cache::$lazy->fetched) === $warmKey, 'self_url transport changed numeric key');
            numAssert($view->config->self_url === $url && $view->config->footer_icons === $footer
                && $view->config->show_footer === $showFooter && $view->config->show_footer_icons === $showIcons
                && HtmlTable::ID === 'table', 'key projection changed fresh native URL/footer/visualization properties');
            NumericCache::finish($view);
            numAssert($view->config->self_url === $url && $view->config->footer_icons === $footer, 'finish replaced fresh URL or native footer');
        }
    });
    foreach (['columns', 'translations', 'css'] as $property) {
        numCheck('other-public-config-change-remains-keyed-and-misses-' . $property, function () use ($property) {
            $context = numContext(); $warm = numView(); $warm->config->self_url = '?random=111';
            NumericCache::loadAttach($warm, $context, numRequest($warm)); $warmKey = end(\Piwik\Cache::$lazy->fetched);
            numCapture($warm, numPage()); NumericCache::finish($warm);
            $view = numView(); $view->config->self_url = '?random=222';
            if ($property === 'columns') { $view->config->columns_to_display = ['label', 'nb_visits']; }
            elseif ($property === 'translations') { $view->config->translations['nb_hits'] = 'Changed native column translation'; }
            else { $view->config->datatable_css_class = 'changed-native-css'; }
            numAssert(!NumericCache::loadAttach($view, $context, numRequest($view))
                && end(\Piwik\Cache::$lazy->fetched) !== $warmKey, 'non-URL public configuration was incorrectly normalized away');
            NumericCache::finish($view);
        });
    }
    numCheck('state-weakmap-does-not-retain-view-closure-cycle', function () {
        $weak = (static function () { $view = numView(); NumericCache::loadAttach($view, numContext(), numRequest($view)); return \WeakReference::create($view); })();
        gc_collect_cycles(); numAssert($weak->get() === null, 'WeakMap/state/native CSS closure retained unreachable view');
        $map = (new \ReflectionProperty(NumericCache::class, 'states'))->getValue(); numAssert(count($map) === 0, 'unreachable view remains in state map');
    });
    numCheck('current-custom-presentation-callbacks-run-fresh-after-selection', function () {
        $context = numContext(); $first = numView();
        $first->config->filters[] = [static function (DataTable $table) { $table->setMetadata('current-presentation', 'first'); }, [], false];
        NumericCache::loadAttach($first, $context, numRequest($first)); numCapture($first, numPage()); NumericCache::finish($first);
        $second = numView(); $second->config->filters[] = [static function (DataTable $table) {
            $table->setMetadata('current-presentation', 'second'); $table->filter('Limit', [0, 1]);
        }, [], false];
        numAssert(NumericCache::loadAttach($second, $context, numRequest($second)), 'fresh presentation closure caused a numeric miss');
        $flat = (new \Piwik\API\DataTableManipulator\Flattener('Actions', 'getPageUrls', numRequest($second)))->flatten($second->getDataTable(), '/', false);
        numCapture($second, $flat);
        numAssert($flat->getRowsCount() === 1 && $flat->getMetadata('current-presentation') === 'second', 'presentation filter was skipped or stale');
        NumericCache::finish($second);
    });
    numCheck('selected-page-public-clone-preserves-ids-totals-and-resumes-filters', function () {
        $source = numPage(); $before = numState($source);
        $selected = \Piwik\Plugins\SpeoReportPerformance\SelectedPageTable::fromPage($source);
        $copy = $selected->getEmptyClone(true);
        foreach ($selected->getRows() as $row) { numAssert($copy->addRow($row) === $row, 'selected addRow did not return the added row'); }
        numAssert(array_keys($copy->getRows()) === [500, 501] && $copy->getSortedByColumnName() === 'nb_hits'
            && $copy->getTotalsRow() === $source->getTotalsRow(), 'public clone lost selected identity/sort/totals');
        $copy->filter('Piwik\\DataTable\\Filter\\Pattern', ['label', 'no-match']);
        $copy->filter('ExcludeLowPopulation', ['nb_hits', 1000]); $copy->filter('Truncate', [1]); $copy->filter('Limit', [500, 500]);
        $copy->setTotalsRow(numRow('WRONG PAGE TOTAL', 1));
        $copy->setMetadata(DataTable::TOTALS_ROW_IS_FILTERED_METADATA_NAME, true);
        numAssert($copy->getRowsCount() === 2 && $copy->getTotalsRow() === $source->getTotalsRow()
            && $copy->getMetadata(DataTable::TOTALS_ROW_IS_FILTERED_METADATA_NAME) === false, 'selection/filtered totals ran twice');
        numAssert(get_class($copy->getEmptyClone(false)) === DataTable::class, 'native totals scratch clone remained selected');
        $copy->resumePresentation(); $added = numRow('/presentation-add');
        numAssert($copy->addRow($added) === $added, 'presentation addRow did not preserve native return');
        $copy->filter('Limit', [0, 1]); $copy->setTotalsRow(numRow('Fresh totals', 9));
        numAssert($copy->getRowsCount() === 1 && $copy->getTotalsRow()->getColumn('nb_hits') === 9 && numState($source) === $before,
            'filter contract did not resume or source changed');
    });
    numCheck('native-selection-stage-contract-additions-are-visible-to-regression-suite', function () {
        $native = array_column(\Piwik\API\DataTableGenericFilter::getGenericFiltersInformation(), 0);
        numAssert($native === ['Pattern', 'PatternRecursive', 'ExcludeLowPopulation', 'Sort', 'Truncate', 'Limit'],
            'Native selection stage added or reordered filters; review the selected-page adapter');
    });
    numCheck('native-postprocessor-selected-page-preserves-search-totals', function () {
        $view = numView(); $request = numRequest($view);
        $request['filter_pattern'] = 'first|second'; $request['filter_column'] = 'label';
        $request['filter_limit'] = 500; $request['filter_offset'] = 500;
        $request['keep_totals_row'] = 1; $request['format_metrics'] = 0; $request['segment'] = '';
        $source = numPage(); $source->getTotalsRow()->setColumn('label', 'General_FilteredTotal');
        $source->getTotalsRow()->setColumn('nb_hits', 2000);
        $source->setMetadata(DataTable::TOTALS_ROW_IS_FILTERED_METADATA_NAME, true);
        $sourceTotal = numRowState($source->getTotalsRow());
        $selected = \Piwik\Plugins\SpeoReportPerformance\SelectedPageTable::fromPage(
            \Piwik\Plugins\SpeoReportPerformance\NativeFlatInput::prepare($source, 'Actions_PageUrl', false));
        $processor = new \Piwik\API\DataTablePostProcessor('Actions', 'getPageUrls', $request);
        $processor->setCallbackAfterGenericFilters(static function (DataTable $table) {
            $table->queueFilter(static function (DataTable $page) {
                numAssert($page instanceof \Piwik\Plugins\SpeoReportPerformance\SelectedPageTable, 'native processor lost selected adapter');
                $page->resumePresentation();
            });
        });
        $output = $processor->process($selected);
        numAssert(array_keys($output->getRows()) === [500, 501] && $output->getRowsCount() === 2
            && numRowState($output->getTotalsRow()) === $sourceTotal
            && $output->getMetadata(DataTable::TOTALS_ROW_IS_FILTERED_METADATA_NAME) === true,
            'real native postprocessor re-selected the page or recomputed totals from two rows');
        $output->filter('Limit', [0, 1]); numAssert($output->getRowsCount() === 1, 'native processing did not reach presentation boundary');
    });
    $failed = count(array_filter($results, static fn($r) => $r['status'] !== 'pass'));
    $report = ['fixture' => 'ui-numeric-page-cache', 'network_namespace_isolated' => true, 'application_bootstrap' => false,
        'database_or_customer_reports' => false, 'native_presentation_closures_and_config' => true,
        'source_sha256' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/UiNumericPageCache.php'),
        'selected_source_sha256' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/SelectedPageTable.php'),
        'builder_source_sha256' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/UiRequestBuilder.php'),
        'checks' => count($results), 'passed' => count($results) - $failed, 'failed' => $failed, 'results' => $results];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    speoFixtureReport('ui-numeric-page-cache', $json); echo $json; exit($failed ? 1 : 0);
}
