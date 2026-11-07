<?php
/** Private primitive-only memory ownership review; no Environment/API/DB/customer reports. */
declare(strict_types=1);
namespace Piwik\Plugin {
    final class ReportsProvider {
        public static function factory($module, $action) {
            if ($module !== 'Actions' || $action !== 'getPageUrls') { throw new \RuntimeException('Unexpected fixture report'); }
            return new \ReviewReport();
        }
    }
}
namespace {
    require_once dirname(__DIR__) . '/bootstrap.php';
    speoAssertIsolated();
    umask(0077);
    speoFixtureBootstrap('root-memory-contract-review');
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
    $container = new \Piwik\Container\Container(); \Piwik\Container\StaticContainer::push($container);
    $container->set('Piwik\\Plugin\\Manager', new class { public function getLoadedPluginsName() { return ['ReviewFixture']; } });
    $container->set('Piwik\\Translation\\Translator', new class {
        public function getCurrentLanguage() { return 'en'; }
        public function translate($id, $args = [], $language = null) { return $id === 'General_Others' ? 'Others' : $id; }
    });
    $container->set('Piwik\\EventDispatcher', new class { public function postEvent($name, $params = [], $pending = false, $plugins = null) {} });
    $cache = new class {
        private array $data = [];
        public function fetch($key) { return $this->data[$key] ?? false; }
        public function contains($key) { return array_key_exists($key, $this->data); }
        public function save($key, $value, $lifetime = 0) { $this->data[$key] = $value; return true; }
    };
    $container->set('Matomo\\Cache\\Transient', $cache);
    $container->set(\Piwik\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
    $_GET = $_POST = ['idSite' => 1, 'flat' => 1];
    $cache->save(\Piwik\CacheId::siteAware(\Piwik\CacheId::pluginAware('Metrics.mappingFromIdToName')), \Piwik\Metrics::$mappingFromIdToName);

    use Piwik\DataTable;
    use Piwik\DataTable\Row;
    use Piwik\DataTable\Manager;
    use Piwik\Plugins\SpeoReportPerformance\TreeGuard;
    use Piwik\Plugins\SpeoReportPerformance\LinearFlattener;
    use Piwik\Plugins\SpeoReportPerformance\OwnedTableMemory;
    final class ReviewDimension { public function getId() { return 'Actions.PageUrl'; } }
    final class ReviewReport {
        public function getDimension() { return new ReviewDimension(); }
        public function getSubtableDimension() { return new ReviewDimension(); }
        public function getNthLevelTableDimension($depth) { return new ReviewDimension(); }
    }
    class ReviewCustomTotals extends Row {
        public static int $clones = 0;
        public static int $destructs = 0;
        public function __clone() { ++self::$clones; }
        public function __destruct() { ++self::$destructs; parent::__destruct(); }
    }
    function reviewRow(string $label, ?DataTable $child = null): Row {
        $row = new Row([Row::COLUMNS => ['label' => $label, 'nb_hits' => 3], Row::METADATA => ['first' => 1, 'url' => 'https://fixture.invalid/', 'last' => null]]);
        if ($child !== null) { $row->setSubtable($child); } return $row;
    }
    function reviewTable(array $rows = []): DataTable {
        $table = new DataTable(); foreach ($rows as $row) { $table->addRow($row); } return $table;
    }
    function reviewRowState(Row $row): array {
        return [get_class($row), spl_object_id($row), $row->getColumns(), $row->getMetadata(), $row->getIdSubDataTable(), $row->isSubtableLoaded(), $row->isSummaryRow(), $row->maxVisitsSummed];
    }
    function reviewState(DataTable $table): array {
        $rows = []; foreach ($table->getRows() as $id => $row) { $rows[$id] = reviewRowState($row); }
        return ['id' => $table->getId(), 'class' => get_class($table), 'rows' => $rows, 'metadata' => $table->getAllTableMetadata(),
            'totals' => $table->getTotalsRow() ? reviewRowState($table->getTotalsRow()) : null,
            'queues' => $table->getQueuedFilters(), 'sort' => $table->getSortedByColumnName()];
    }
    function reviewAssert(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
    $results = [];
    function reviewCheck(string $name, callable $test): void {
        global $results;
        try { $detail = $test(); $results[] = ['case' => $name, 'status' => 'pass'] + (is_array($detail) ? $detail : []); }
        catch (\Throwable $error) { $results[] = ['case' => $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()]; }
    }
    function reviewFlatten(DataTable $root): array {
        $copy = TreeGuard::copy($root); $ids = TreeGuard::copiedIds();
        $flat = (new LinearFlattener('Actions', 'getPageUrls', ['idSite' => 1, 'flat' => 1]))->flatten($copy, '/', false);
        return [$flat, $ids];
    }
    foreach (['normal-row', 'summary-row'] as $location) {
        reviewCheck('late-foreign-subtable-preserves-entire-original-graph-' . $location, function () use ($location) {
            $foreign = reviewTable([reviewRow('foreign-leaf')]);
            $ownedChild = reviewTable([reviewRow('owned-leaf')]);
            $original = reviewTable([reviewRow('owned-parent', $ownedChild)]);
            $lateRow = reviewRow('late-parent');
            if ($location === 'normal-row') { $original->addRow($lateRow); } else { $original->addSummaryRow($lateRow); }
            $readerIds = [$original->getId(), $ownedChild->getId()];
            // Simulate an ordinary native API end-hook after reader IDs were captured.
            $lateRow->setSubtable($foreign);
            $before = [reviewState($original), reviewState($ownedChild), reviewState($foreign)];
            reviewAssert(TreeGuard::reason($original) === null, 'ordinary native foreign edge should be copied for a correct result');
            [$flat, $copyIds] = reviewFlatten($original); $flatBefore = reviewState($flat);
            OwnedTableMemory::releaseExact($readerIds, $copyIds, [$flat->getId()]);
            $manager = Manager::getInstance();
            foreach ([$original, $ownedChild, $foreign] as $table) { reviewAssert(isset($manager[$table->getId()]), 'original or foreign table explicitly destroyed'); }
            reviewAssert([reviewState($original), reviewState($ownedChild), reviewState($foreign)] === $before, 'original or foreign edge/row mutated during declined original cleanup');
            foreach ($copyIds as $id) { reviewAssert(!isset($manager[$id]), 'safe copy container not released'); }
            reviewAssert(reviewState($flat) === $flatBefore, 'committed flat result changed');
        });
    }
    reviewCheck('clean-owned-graph-still-released-with-unrelated-native-table-alive', function () {
        $foreign = reviewTable([reviewRow('unrelated')]); $foreignBefore = reviewState($foreign);
        $child = reviewTable([reviewRow('child-leaf')]); $root = reviewTable([reviewRow('parent', $child)]);
        $ids = [$root->getId(), $child->getId()]; [$flat, $copyIds] = reviewFlatten($root); $before = reviewState($flat);
        OwnedTableMemory::releaseExact($ids, $copyIds, [$flat->getId()]);
        $manager = Manager::getInstance();
        foreach (array_merge($ids, $copyIds) as $id) { reviewAssert(!isset($manager[$id]), 'owned table was not released'); }
        reviewAssert(isset($manager[$foreign->getId()]) && reviewState($foreign) === $foreignBefore && reviewState($flat) === $before,
            'unrelated table or committed flat result changed');
    });
    foreach (['loaded', 'unloaded'] as $shape) {
        reviewCheck('totals-subtable-declines-before-copy-' . $shape, function () use ($shape) {
            $child = reviewTable([reviewRow('totals-child')]); $root = reviewTable([reviewRow('leaf')]);
            $total = $shape === 'loaded' ? reviewRow('Totals', $child)
                : new Row([Row::COLUMNS => ['label' => 'Totals', 'nb_hits' => 3], Row::DATATABLE_ASSOCIATED => $child->getId()]);
            $root->setTotalsRow($total); $before = [reviewState($root), reviewState($child)]; $managerId = Manager::getInstance()->getMostRecentTableId();
            reviewAssert(TreeGuard::reason($root) !== null, 'totals subtable passed the tree guard');
            reviewAssert([reviewState($root), reviewState($child)] === $before && Manager::getInstance()->getMostRecentTableId() === $managerId,
                'guard allocated/copied/mutated unsupported totals');
        });
    }
    foreach (['ordinary-row', 'summary-row', 'other-table-row', 'two-totals'] as $alias) {
        reviewCheck('totals-alias-declines-before-copy-' . $alias, function () use ($alias) {
            $row = reviewRow('shared'); $root = reviewTable();
            if ($alias === 'ordinary-row') { $root->addRow($row); $root->setTotalsRow($row); }
            elseif ($alias === 'summary-row') { $root->addSummaryRow($row); $root->setTotalsRow($row); }
            elseif ($alias === 'other-table-row') { $child = reviewTable([$row]); $root->addRow(reviewRow('parent', $child)); $root->setTotalsRow($row); }
            else { $child = reviewTable([reviewRow('child')]); $child->setTotalsRow($row); $root->addRow(reviewRow('parent', $child)); $root->setTotalsRow($row); }
            $before = reviewState($root); $managerId = Manager::getInstance()->getMostRecentTableId();
            reviewAssert(TreeGuard::reason($root) !== null, 'totals alias passed the tree guard');
            reviewAssert(reviewState($root) === $before && Manager::getInstance()->getMostRecentTableId() === $managerId, 'alias guard copied/mutated source');
        });
    }
    reviewCheck('foreign-totals-class-declines-without-clone-or-destructor', function () {
        $root = reviewTable([reviewRow('leaf')]);
        $total = new ReviewCustomTotals([Row::COLUMNS => ['label' => 'Totals', 'nb_hits' => 3]]); $root->setTotalsRow($total);
        $clones = ReviewCustomTotals::$clones; $destructs = ReviewCustomTotals::$destructs; $before = reviewState($root);
        reviewAssert(TreeGuard::reason($root) !== null, 'foreign totals class passed the tree guard');
        reviewAssert(ReviewCustomTotals::$clones === $clones && ReviewCustomTotals::$destructs === $destructs && reviewState($root) === $before,
            'guard called foreign lifecycle method or mutated table');
    });
    reviewCheck('public-copy-preserves-sparse-ids-summary-totals-key-order-index', function () {
        $root = new DataTable(); $root[7] = reviewRow('seven'); $root[500] = reviewRow('five-hundred'); $root->setLabelsHaveChanged();
        $root->addSummaryRow(reviewRow('summary')); $root->setTotalsRow(reviewRow('Totals'));
        $root->setAllTableMetadata(['first' => 1, 'nested' => ['z' => 1, 'a' => 2], 'last' => null]);
        $root->queueFilter('ReplaceColumnNames'); $root->getRowFromLabel('seven'); $before = reviewState($root);
        reviewAssert(TreeGuard::reason($root) === null, 'canonical sparse tree rejected'); $copy = TreeGuard::copy($root);
        reviewAssert(array_keys($copy->getRows()) === [7, 500, DataTable::ID_SUMMARY_ROW], 'sparse IDs/order changed');
        reviewAssert($copy->getRowFromLabel('seven') === $copy->getRowFromId(7)
            && $copy->getRowFromLabel('five-hundred') === $copy->getRowFromId(500), 'label index retained original or incorrect sparse row');
        reviewAssert($copy->getAllTableMetadata() === $root->getAllTableMetadata() && $copy->getQueuedFilters() === $root->getQueuedFilters(), 'metadata/queue order changed');
        foreach ($root->getRows() as $id => $row) { reviewAssert($copy->getRowFromId($id) !== $row
            && $copy->getRowFromId($id)->getColumns() === $row->getColumns() && $copy->getRowFromId($id)->getMetadata() === $row->getMetadata(), 'row ownership or primitive order changed'); }
        reviewAssert($copy->getTotalsRow() !== $root->getTotalsRow() && $copy->getTotalsRow()->getColumns() === $root->getTotalsRow()->getColumns()
            && reviewState($root) === $before, 'totals ownership or original changed');
    });
    $sources = []; foreach (['TreeGuard', 'LinearFlattener', 'OwnedTableMemory', 'NativeFlatInput'] as $file) { $sources[$file] = hash_file('sha256', SPEO_PLUGIN_ROOT . '/' . $file . '.php'); }
    $failed = count(array_filter($results, static fn($r) => $r['status'] !== 'pass'));
    $report = ['fixture' => 'root-memory-contract-review', 'network_namespace_isolated' => true, 'application_bootstrap' => false,
        'database_or_customer_reports' => false, 'native_datatable_row_and_filters' => true, 'report_provider_fixture_only' => true,
        'source_sha256' => $sources, 'checks' => count($results), 'passed' => count($results) - $failed, 'failed' => $failed, 'results' => $results];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    speoFixtureReport('root-memory-contract-review', $json); echo $json; exit($failed ? 1 : 0);
}
