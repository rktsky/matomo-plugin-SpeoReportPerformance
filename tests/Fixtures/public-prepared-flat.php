<?php
/**
 * Private CLI-only synthetic equivalence tests. Never loaded by the plugin.
 * ReportsProvider and translation/cache services below are fixture-only doubles;
 * the original installed DataTable/Row and native Flattener files stay unchanged.
 * No application Environment, FrontController, config, API or database is loaded.
 * Run: unshare --net -- php /absolute/private/path/tests/synthetic.php
 */
declare(strict_types=1);

namespace Piwik\Plugin {
    final class ReportsProvider
    {
        public static array $dimensions = ['Actions.PageUrl'];
        public static int $calls = 0;
        public static function factory($module, $action)
        {
            ++self::$calls;
            if ($module !== 'Actions' || $action !== 'getPageUrls') {
                throw new \RuntimeException('Unexpected fixture report lookup');
            }
            return new \SyntheticReport(self::$dimensions);
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/bootstrap.php';
    speoAssertIsolated();
    umask(0077);
    speoFixtureBootstrap('public-prepared-flat');
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
    $container = new \Piwik\Container\Container();
    \Piwik\Container\StaticContainer::push($container);
    $container->set('Piwik\\Plugin\\Manager', new class {
        public function getLoadedPluginsName() { return ['SyntheticFixture']; }
    });
    $container->set('Piwik\\Translation\\Translator', new class {
        public function getCurrentLanguage() { return 'en'; }
        public function translate($id, $args = [], $language = null) { return $id === 'General_Others' ? 'Andere' : $id; }
    });
    $container->set('Piwik\\EventDispatcher', new class {
        public function postEvent($name, $params = [], $pending = false, $plugins = null) {}
    });
    $cache = new class {
        private array $data = [];
        public function fetch($key) { return $this->data[$key] ?? false; }
        public function contains($key) { return array_key_exists($key, $this->data); }
        public function save($key, $value, $lifetime = 0) { $this->data[$key] = $value; return true; }
    };
    $container->set('Matomo\\Cache\\Transient', $cache);
    $container->set(\Piwik\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
    $_GET = $_POST = ['idSite' => 1, 'flat' => 1];
    $mappingKey = \Piwik\CacheId::siteAware(\Piwik\CacheId::pluginAware('Metrics.mappingFromIdToName'));
    $cache->save($mappingKey, \Piwik\Metrics::$mappingFromIdToName);
    \Piwik\DataTable::setMaximumDepthLevelAllowedAtLeast(100);

    final class SyntheticDimension
    {
        public function __construct(private string $id) {}
        public function getId() { return $this->id; }
    }
    final class SyntheticReport
    {
        public function __construct(private array $dimensions) {}
        public function getDimension() { return new SyntheticDimension($this->dimensions[0]); }
        public function getSubtableDimension() { return $this->getNthLevelTableDimension(1); }
        public function getNthLevelTableDimension($level) { return new SyntheticDimension($this->dimensions[$level] ?? $this->dimensions[0]); }
    }
    class SyntheticSourceGuardBase {}
    final class SyntheticSourceGuardChild extends SyntheticSourceGuardBase {}
    function row($label, int $count = 1, array $metadata = [], ?\Piwik\DataTable $child = null): \Piwik\DataTable\Row
    {
        $row = new \Piwik\DataTable\Row([
            \Piwik\DataTable\Row::COLUMNS => ['label' => $label, 'nb_visits' => $count, 'nb_hits' => 2 * $count],
            \Piwik\DataTable\Row::METADATA => $metadata,
        ]);
        if ($child !== null) { $row->setSubtable($child); }
        return $row;
    }
    function table(array $rows = []): \Piwik\DataTable
    {
        $table = new \Piwik\DataTable();
        foreach ($rows as $row) { $table->addRow($row); }
        $table->queueFilter('ReplaceSummaryRowLabel');
        $table->queueFilter('ReplaceColumnNames');
        return $table;
    }
    function standardTree(): \Piwik\DataTable
    {
        $deep = table([row(' leaf ', 4, ['url' => 'https://fixture.invalid/folder/nested/leaf'])]);
        $child = table([row('//child', 3), row('nested', 4, [], $deep)]);
        $child->addSummaryRow(row('ignored-summary-label', 2, ['url' => 'https://fixture.invalid/summary']));
        $root = table([row('/root', 8), row('/folder/', 9, ['logo' => 'parent.svg', 'tag' => 'parent'], $child)]);
        $root->addSummaryRow(row('ignored-root-summary', 1, ['url' => 'https://fixture.invalid/root-summary']));
        $root->disableRecursiveFilters();
        return $root;
    }
    function snapshotRow(\Piwik\DataTable\Row $row): array
    {
        $sub = $row->getSubtable();
        return ['class' => get_class($row), 'columns' => $row->getColumns(), 'metadata' => $row->getMetadata(),
            'is_summary' => $row->isSummaryRow(), 'subtable' => $sub instanceof \Piwik\DataTable ? snapshot($sub) : null];
    }
    function snapshot(\Piwik\DataTable $table): array
    {
        $rows = [];
        foreach ($table->getRows() as $key => $row) { $rows[$key] = snapshotRow($row); }
        return ['rows' => $rows, 'metadata' => $table->getAllTableMetadata(),
            'totals' => $table->getTotalsRow() ? snapshotRow($table->getTotalsRow()) : null];
    }
    function firstDifference($left, $right, string $path = '$'): ?string
    {
        if (gettype($left) !== gettype($right)) { return $path . ' (type)'; }
        if (!is_array($left)) { return $left === $right ? null : $path; }
        if (array_keys($left) !== array_keys($right)) { return $path . ' (keys/order)'; }
        foreach ($left as $key => $value) {
            $difference = firstDifference($value, $right[$key], $path . '[' . json_encode($key) . ']');
            if ($difference !== null) { return $difference; }
        }
        return null;
    }
    function finishTable(\Piwik\DataTable $table, bool $paginate): \Piwik\DataTable
    {
        if ($paginate) {
            $table->filter('Sort', ['nb_visits', 'desc', true, false]);
            $table->filter('Limit', [0, 2]);
        }
        $table->applyQueuedFilters();
        return $table;
    }

    $cases = [];
    $cases['nested-summary-slashes'] = fn() => standardTree();
    $cases['existing-root-dimension'] = function () {
        $t = standardTree();
        $t->getRowFromId(0)->setMetadata('Actions_PageUrl', 'preexisting-root-dimension');
        return $t;
    };
    $cases['existing-dimensions-order'] = function () {
        $t = standardTree();
        $t->setMetadata('dimensions', ['Foreign_Dimension', 'Actions_PageUrl', 'Foreign_Dimension']);
        $t->setMetadata('custom', ['retained' => true, 'nested' => [1, false, '0']]);
        return $t;
    };
    $cases['logo-falsy-metadata'] = function () {
        $child = table([row('inherit', 1, ['logo' => false, 'tag' => false]), row('empty', 2, ['logo' => '', 'tag' => '']), row('zero', 3, ['logo' => '0', 'tag' => 0])]);
        return table([row('/parent/', 6, ['logo' => 'parent.svg', 'tag' => 'parent'], $child)]);
    };
    $cases['dimension-falsy'] = fn() => table([row('/zero-int', 1, ['Actions_PageUrl' => 0]), row('/zero-str', 2, ['Actions_PageUrl' => '0']), row('/empty', 3, ['Actions_PageUrl' => ''])]);
    $cases['summary-dimension'] = function () {
        $child = table([row('/child', 2)]);
        $child->addSummaryRow(row('summary', 3, ['Actions_PageUrl' => 'existing-summary', 'url' => 'https://fixture.invalid/s']));
        return table([row('/folder/', 5, ['Actions_PageUrl' => 'existing-parent'], $child)]);
    };
    $cases['empty-subtable'] = fn() => table([row('/empty/', 1, [], table()), row('/real', 2)]);
    $cases['empty-root'] = fn() => table();
    $cases['blank-false-zero-labels'] = fn() => table([row(false, 1, ['Actions_PageUrl' => 'retained']), row('0', 2), row(" \t whitespace \n", 3)]);
    $cases['root-queued-filter'] = function () {
        $t = standardTree();
        $t->queueFilter(function ($flat) {
            foreach ($flat->getRows() as $row) {
                $row->setColumn('nb_visits', $row->getColumn('nb_visits') + 1);
                $row->setMetadata('observed_dimension_by_root_queue', $row->getMetadata('Actions_PageUrl'));
            }
        });
        return $t;
    };
    $cases['subtable-queued-filter'] = function () {
        $child = table([row('/child', 2)]);
        $child->queueFilter(function ($sub) {
            foreach ($sub->getRows() as $row) {
                $row->setColumn('label', $row->getColumn('label') . '|once');
                $row->setColumn('nb_visits', $row->getColumn('nb_visits') + 3);
                $row->setMetadata('sub_queue_count', 1 + (int)$row->getMetadata('sub_queue_count'));
            }
        });
        return table([row('/parent/', 2, [], $child)]);
    };
    $cases['totals-and-metadata'] = function () {
        $t = standardTree();
        $t->setTotalsRow(row('Totals', 18, ['totals_source' => 'synthetic']));
        $t->setMetadata('totals', ['nb_visits' => 18, 'nb_hits' => 36]);
        $t->setMetadata('totalsUnformatted', ['nb_visits' => 18, 'nb_hits' => 36]);
        return $t;
    };
    $cases['numeric-metric-columns'] = function () {
        $t = table();
        $t->addRow(new \Piwik\DataTable\Row([\Piwik\DataTable\Row::COLUMNS => ['label' => '/numeric', \Piwik\Metrics::INDEX_NB_VISITS => 7, \Piwik\Metrics::INDEX_PAGE_NB_HITS => 11]]));
        return $t;
    };
    $cases['custom-separator'] = fn() => standardTree();
    $cases['multiple-dimension-depths'] = fn() => standardTree();

    $request = ['module' => 'API', 'method' => 'Actions.getPageUrls', 'idSite' => 1, 'period' => 'month', 'date' => '2026-10-01', 'flat' => 1, 'totals' => 0];
    $results = [];
    foreach ($cases as $name => $build) {
        foreach ([false, true] as $paginate) {
            $label = $name . ($paginate ? ':sort-limit' : ':all-rows');
            try {
                \Piwik\Plugin\ReportsProvider::$dimensions = $name === 'multiple-dimension-depths' ? ['Actions.PageUrl', 'Synthetic.Level1', 'Synthetic.Level2'] : ['Actions.PageUrl'];
                $source = $build();
                $sourceBefore = snapshot($source);
                $reason = \Piwik\Plugins\SpeoReportPerformance\TreeGuard::reason($source);
                if ($reason !== null) { throw new \RuntimeException('Unexpected guard rejection: ' . $reason); }
                $separator = $name === 'custom-separator' ? ' > ' : '/';
                $native = new \Piwik\API\DataTableManipulator\Flattener('Actions', 'getPageUrls', $request);
                $expected = finishTable($native->flatten(\Piwik\Plugins\SpeoReportPerformance\TreeGuard::copy($source), $separator, false), $paginate);
                $linear = new \Piwik\Plugins\SpeoReportPerformance\LinearFlattener('Actions', 'getPageUrls', $request);
                $linearTable = $linear->flatten(\Piwik\Plugins\SpeoReportPerformance\TreeGuard::copy($source), $separator, false);
                $prepared = \Piwik\Plugins\SpeoReportPerformance\NativeFlatInput::prepare($linearTable, 'Actions_PageUrl');
                if (get_class($prepared) !== \Piwik\Plugins\SpeoReportPerformance\PreparedFlatTable::class) {
                    throw new \RuntimeException('Normalized fixture did not exercise PreparedFlatTable');
                }
                $secondNative = new \Piwik\API\DataTableManipulator\Flattener('Actions', 'getPageUrls', $request);
                $actual = finishTable($secondNative->flatten($prepared, $separator, false), $paginate);
                if (get_class($actual) !== \Piwik\DataTable::class) {
                    throw new \RuntimeException('Prepared input escaped the native second pass');
                }
                $left = snapshot($expected); $right = snapshot($actual);
                $difference = firstDifference($left, $right);
                if ($difference !== null) {
                    file_put_contents(__DIR__ . '/test-runtime/' . $name . ($paginate ? '-page' : '-full') . '.native.ser', serialize($left));
                    file_put_contents(__DIR__ . '/test-runtime/' . $name . ($paginate ? '-page' : '-full') . '.optimized.ser', serialize($right));
                }
                $sourceDifference = firstDifference($sourceBefore, snapshot($source));
                if ($sourceDifference !== null) { throw new \RuntimeException('Source mutated at ' . $sourceDifference); }
                $results[] = ['case' => $label, 'status' => $difference === null ? 'pass' : 'fail', 'first_difference' => $difference,
                    'native_sha256' => hash('sha256', serialize($left)), 'optimized_sha256' => hash('sha256', serialize($right))];

                // Retain the previously validated unprepared input path as a
                // second oracle for this narrow skip, independently of the
                // original one-pass native hierarchy comparison above.
                $baselineConverter = new \Piwik\Plugins\SpeoReportPerformance\LinearFlattener('Actions', 'getPageUrls', $request);
                $baselineFlat = $baselineConverter->flatten(\Piwik\Plugins\SpeoReportPerformance\TreeGuard::copy($source), $separator, false);
                $baselineInput = \Piwik\Plugins\SpeoReportPerformance\NativeFlatInput::prepare($baselineFlat, 'Actions_PageUrl', false);
                if (get_class($baselineInput) !== \Piwik\DataTable::class) {
                    throw new \RuntimeException('Explicit baseline unexpectedly enabled prepared input');
                }
                $baselineNative = new \Piwik\API\DataTableManipulator\Flattener('Actions', 'getPageUrls', $request);
                $baselineOutput = finishTable($baselineNative->flatten($baselineInput, $separator, false), $paginate);
                if (get_class($baselineOutput) !== \Piwik\DataTable::class) {
                    throw new \RuntimeException('Baseline native output is not the original DataTable class');
                }
                $baselineState = snapshot($baselineOutput);
                $phaseTwoDifference = firstDifference($baselineState, $right);
                $baselineDifference = firstDifference($left, $baselineState);
                $results[] = ['case' => $label . ':prepared-vs-baseline',
                    'status' => $phaseTwoDifference === null && $baselineDifference === null ? 'pass' : 'fail',
                    'first_difference' => $phaseTwoDifference, 'baseline_native_difference' => $baselineDifference,
                    'result_class' => get_class($actual),
                    'baseline_sha256' => hash('sha256', serialize($baselineState)),
                    'prepared_sha256' => hash('sha256', serialize($right))];
            } catch (\Throwable $error) {
                $results[] = ['case' => $label, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()];
            }
        }
    }
    $guardCases = [];
    $guardCases['private-metadata-collision'] = function () { return table([row('/a', 1, [\Piwik\Plugins\SpeoReportPerformance\NativeFlatInput::BACKUP_KEY => ['exists' => false]])]); };
    $guardCases['shared-or-cyclic-table'] = function () { $sub = table([row('/leaf')]); return table([row('/a', 1, [], $sub), row('/b', 1, [], $sub)]); };
    $guardCases['shared-row'] = function () { $shared = row('/shared'); return table([row('/a', 1, [], table([$shared])), row('/b', 1, [], table([$shared]))]); };
    $guardCases['unloaded-subtable'] = function () { $r = row('/unloaded'); $r->setNonLoadedSubtableId(987654321); return table([$r]); };
    $guardCases['custom-table-class'] = fn() => new class extends \Piwik\DataTable {};
    foreach ($guardCases as $expected => $build) {
        try {
            $reason = \Piwik\Plugins\SpeoReportPerformance\TreeGuard::reason($build());
            $results[] = ['case' => 'guard:' . $expected, 'status' => $reason === $expected ? 'pass' : 'fail', 'actual_reason' => $reason];
        } catch (\Throwable $error) { $results[] = ['case' => 'guard:' . $expected, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()]; }
    }

    $compatiblePreparedSource = \Piwik\Plugins\SpeoReportPerformance\PreparedFlatTable::sourceCompatible();
    $results[] = ['case' => 'prepared:public-table-capabilities', 'status' => $compatiblePreparedSource ? 'pass' : 'fail'];
    $normalizedFlat = static fn() => table([row('/normalized', 7, ['Actions_PageUrl' => '/normalized'])]);
    $preparedSequences = [
        'custom-mapping' => static function ($input) {
            $input->filter('ReplaceSummaryRowLabel');
            $input->filter('ReplaceColumnNames', [['nb_visits' => 'custom_visits']]);
        },
        'second-default-call-runs-natively' => static function ($input) {
            $input->filter('ReplaceSummaryRowLabel');
            $input->filter('ReplaceColumnNames');
            $input->getRowFromId(0)->setColumn(\Piwik\Metrics::INDEX_NB_VISITS, 19);
            $input->filter('ReplaceColumnNames');
        },
        'intervening-callback-disarms' => static function ($input) {
            $input->filter('ReplaceSummaryRowLabel');
            $input->filter(static function ($table) {
                $table->getRowFromId(0)->setColumn(\Piwik\Metrics::INDEX_NB_VISITS, 23);
                $table->getRowFromId(0)->setMetadata('intervening_callback', true);
            });
            $input->filter('ReplaceColumnNames');
        },
        'wrong-first-order-disarms' => static function ($input) {
            $input->filter('ReplaceColumnNames');
            $input->getRowFromId(0)->setColumn(\Piwik\Metrics::INDEX_NB_VISITS, 29);
            $input->filter('ReplaceSummaryRowLabel');
            $input->filter('ReplaceColumnNames');
        },
    ];
    foreach ($preparedSequences as $name => $sequence) {
        try {
            $baseline = \Piwik\Plugins\SpeoReportPerformance\NativeFlatInput::prepare($normalizedFlat(), 'Actions_PageUrl', false);
            $prepared = \Piwik\Plugins\SpeoReportPerformance\NativeFlatInput::prepare($normalizedFlat(), 'Actions_PageUrl', true);
            if (get_class($prepared) !== \Piwik\Plugins\SpeoReportPerformance\PreparedFlatTable::class) {
                throw new \RuntimeException('Filter sequence did not exercise prepared input');
            }
            $sequence($baseline); $sequence($prepared);
            $baseline->applyQueuedFilters(); $prepared->applyQueuedFilters();
            $difference = firstDifference(snapshot($baseline), snapshot($prepared));
            $results[] = ['case' => 'prepared:' . $name, 'status' => $difference === null ? 'pass' : 'fail', 'first_difference' => $difference];
        } catch (\Throwable $error) {
            $results[] = ['case' => 'prepared:' . $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()];
        }
    }
    $ineligiblePreparedInputs = [
        'reintroduced-numeric-key' => static function () use ($normalizedFlat) {
            $table = $normalizedFlat();
            $table->getRowFromId(0)->setColumn(\Piwik\Metrics::INDEX_NB_VISITS, 31);
            return $table;
        },
        'numeric-totals-key' => static function () use ($normalizedFlat) {
            $table = $normalizedFlat();
            $totals = row('Totals', 7);
            $totals->setColumn(\Piwik\Metrics::INDEX_NB_VISITS, 37);
            $table->setTotalsRow($totals);
            return $table;
        },
        'unloaded-subtable' => static function () use ($normalizedFlat) {
            $table = $normalizedFlat();
            $table->getRowFromId(0)->setNonLoadedSubtableId(987654321);
            return $table;
        },
        'special-summary-row' => static function () use ($normalizedFlat) {
            $table = $normalizedFlat();
            $table->addSummaryRow(row('Not-yet-normalized summary', 5));
            return $table;
        },
    ];
    foreach ($ineligiblePreparedInputs as $name => $build) {
        try {
            $input = $build();
            $output = \Piwik\Plugins\SpeoReportPerformance\NativeFlatInput::prepare($input, 'Actions_PageUrl', true);
            $results[] = ['case' => 'prepared:fallback:' . $name,
                'status' => get_class($output) === \Piwik\DataTable::class ? 'pass' : 'fail',
                'actual_class' => get_class($output)];
        } catch (\Throwable $error) {
            $results[] = ['case' => 'prepared:fallback:' . $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()];
        }
    }
    try {
        $source = new \Piwik\DataTable();
        $source[500] = new \Piwik\DataTable\Row([\Piwik\DataTable\Row::COLUMNS => ['label' => '/typed', 'nb_hits' => 5, 'zero' => -0.0, 'float' => 1.25,
            'goals' => ['idgoal=1' => ['nb_conversions' => 2]]], \Piwik\DataTable\Row::METADATA => ['typed' => [false, null, '0', 0, 0.0]]]);
        $source->setMetadata('custom-primitive', ['keep' => true]);
        $source->setTotalsRow(row('Totals', 5));
        $source->queueFilter('ReplaceColumnNames');
        $prepared = \Piwik\Plugins\SpeoReportPerformance\NativeFlatInput::prepare($source, 'Actions_PageUrl');
        $empty = $prepared->getEmptyClone(true); $bare = $prepared->getEmptyClone(false);
        $ok = $prepared instanceof \Piwik\Plugins\SpeoReportPerformance\PreparedFlatTable
            && array_keys($prepared->getRows()) === [500]
            && $prepared->getRowFromLabel('/typed') === $source->getRowFromId(500)
            && serialize($prepared->getRowFromId(500)->getColumns()) === serialize($source->getRowFromId(500)->getColumns())
            && get_class($empty) === \Piwik\DataTable::class && get_class($bare) === \Piwik\DataTable::class
            && $empty->getRowsCount() === 0 && $bare->getRowsCount() === 0
            && $empty->getAllTableMetadata() === $source->getAllTableMetadata()
            && $empty->getQueuedFilters() === $prepared->getQueuedFilters() && $bare->getQueuedFilters() === []
            && $empty->getId() !== $prepared->getId();
        $results[] = ['case' => 'prepared:public-state-typed-sparse-id-and-explicit-clone', 'status' => $ok ? 'pass' : 'fail'];
    } catch (\Throwable $error) { $results[] = ['case' => 'prepared:public-state-typed-sparse-id-and-explicit-clone', 'status' => 'error', 'error' => $error->getMessage()]; }
    $failures = array_values(array_filter($results, fn($r) => $r['status'] !== 'pass'));
    $report = ['fixture' => 'public-prepared-flat', 'tests' => count($results), 'passed' => count($results) - count($failures),
        'failed' => count($failures), 'network_namespace_isolated' => true, 'application_bootstrap' => false, 'database_or_customer_reports' => false,
        'sources' => ['PreparedFlatTable' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/PreparedFlatTable.php'),
            'NativeFlatInput' => hash_file('sha256', SPEO_PLUGIN_ROOT . '/NativeFlatInput.php')], 'results' => $results];
    speoFixtureReport('public-prepared-flat', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo json_encode(['tests' => $report['tests'], 'passed' => $report['passed'], 'failed' => $report['failed'], 'failures' => $failures], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($failures ? 1 : 0);
}
