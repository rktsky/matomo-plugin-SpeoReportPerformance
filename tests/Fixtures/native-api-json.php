<?php
/** Private network-isolated fixtures. Native DataTable/Row; API/access/services are doubles. */
declare(strict_types=1);

namespace Piwik\Plugins\Actions {
    final class API
    {
        public static array $calls = [];
        public static $factory;
        public static function getInstance(): self { static $instance; return $instance ??= new self(); }
        public function getPageUrls($idSite, string $period, string $date, $segment = false,
            bool $expanded = false, $idSubtable = false, $depth = false, bool $flat = false,
            $futureOptional = 'native-default')
        {
            \Piwik::checkUserHasViewAccess($idSite);
            self::$calls[] = func_get_args();
            return (self::$factory)();
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/bootstrap.php';
    speoAssertIsolated();
    umask(0077);
    speoFixtureBootstrap('native-api-json');
    $loader = require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
    $loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
    require __DIR__ . '/fixtures/FuturePagePerformanceMetric.php';
    // Native API above intentionally doubles only its invocation boundary.
    class_alias(\Piwik\Piwik::class, 'Piwik');
    $container = new \Piwik\Container\Container();
    \Piwik\Container\StaticContainer::push($container);
    $container->set('Piwik\\Plugin\\Manager', new class {
        public function getLoadedPluginsName() { return ['SyntheticFixture']; }
    });
    $container->set('Piwik\\EventDispatcher', new class {
        public function postEvent($name, $params = [], $pending = false, $plugins = null) {}
    });
    $container->set('Piwik\\Translation\\Translator', new class {
        public function getCurrentLanguage() { return 'en'; }
        public function translate($id, $args = [], $language = null) { return $id; }
    });
    $access = new class {
        public bool $allowed = true;
        public int $checks = 0;
        public function checkUserHasViewAccess($id) {
            ++$this->checks;
            if (!$this->allowed) { throw new \RuntimeException('fixture-denied'); }
        }
    };
    $container->set('Piwik\\Access', $access);
    $container->set(\Piwik\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
    \Piwik\DataTable::setMaximumDepthLevelAllowedAtLeast(200);
    \Piwik\Site::setSites([353 => ['idsite' => 353, 'ecommerce' => 0, 'sitesearch' => 0,
        'exclude_unknown_urls' => 0, 'keep_url_fragment' => 0, 'name' => 'Fixture',
        'main_url' => 'https://fixture.invalid', 'timezone' => 'UTC']]);
    use Piwik\DataTable;
    use Piwik\DataTable\Row;
    use Piwik\Plugins\Actions\API;
    use Piwik\Plugins\SpeoReportPerformance\FlatTableSnapshot as Snapshot;
    use Piwik\Plugins\SpeoReportPerformance\NativeArchiveReader as Reader;
    use Piwik\Plugins\SpeoReportPerformance\PrimitiveValueGuard as Guard;
    use Piwik\Plugins\SpeoReportPerformance\ProcessedTableSnapshot as Processed;

    $passed = 0;
    function expect(bool $value, string $message): void {
        if (!$value) { throw new \RuntimeException($message); }
    }
    function test(string $name, callable $test): void {
        global $passed;
        $test(); ++$passed; echo "PASS $name\n";
    }
    function row(array $columns = [], array $metadata = []): Row {
        return new Row([Row::COLUMNS => $columns ?: ['label' => '/fixture', 'nb_hits' => 5], Row::METADATA => $metadata]);
    }
    function flat(): DataTable {
        $t = new DataTable();
        $t->addRow(row(['label' => '/fixture', 'nb_hits' => 5, 'goal' => [1 => ['nb_conversions' => 2]],
            'types' => [0, -1, 1.0, -0.0, null, false, true, '0', '01', PHP_INT_MAX]]));
        $t->addRow(row(['label' => 'O: R: E: C: S:', 'nb_hits' => 1], ['url' => 'https://fixture.invalid/ä']));
        $t->queueFilter('ReplaceSummaryRowLabel'); $t->queueFilter('ReplaceColumnNames');
        $t->setMetadata('dimensions', ['Actions_PageUrl']);
        $t->setMetadata('primitive', ['nested' => ['b' => true, 'n' => null, 3 => 'value']]);
        return $t;
    }
    function same(mixed $a, mixed $b): bool {
        if (gettype($a) !== gettype($b)) { return false; }
        if (is_float($a)) { return pack('d', $a) === pack('d', $b); }
        if (!is_array($a)) { return $a === $b; }
        if (array_keys($a) !== array_keys($b)) { return false; }
        foreach ($a as $key => $value) { if (!same($value, $b[$key])) { return false; } }
        return true;
    }
    function request(): array {
        return ['method' => 'Actions.getPageUrls', 'idSite' => 353, 'period' => 'month',
            'date' => '2026-10-01', 'segment' => '', 'flat' => '1', 'expanded' => true,
            'idSubtable' => false, 'depth' => false, 'filter_limit' => '100'];
    }
    final class CallbackProbe implements \JsonSerializable {
        public static int $calls = 0;
        public function jsonSerialize(): mixed { ++self::$calls; throw new \RuntimeException('callback'); }
        public function __serialize(): array { ++self::$calls; throw new \RuntimeException('callback'); }
        public function __wakeup(): void { ++self::$calls; throw new \RuntimeException('callback'); }
    }

    test('primitive-values-and-key-types', function () {
        expect((new Guard())->check(['a' => [null, false, 1, 1.0, -0.0, 'ä', 2 => 'z']]), 'primitive rejected');
    });
    foreach ([INF, -INF, NAN, new CallbackProbe()] as $i => $bad) {
        test('primitive-reject-' . $i, fn() => expect(!(new Guard())->check(['nested' => [$bad]]), 'unsafe primitive accepted'));
    }
    test('resource', function () { $r = fopen('php://memory', 'r+'); expect(!(new Guard())->check([$r]), 'resource'); fclose($r); });
    test('scalar-reference', function () { $x = 1; $a = ['a' => &$x]; expect(!(new Guard())->check($a), 'scalar ref'); });
    test('nested-reference', function () { $x = ['v' => 1]; $a = ['a' => ['b' => &$x]]; expect(!(new Guard())->check($a), 'nested ref'); });
    test('cycle', function () { $a = []; $a['self'] = &$a; expect(!(new Guard())->check($a), 'cycle'); });
    test('depth', function () { $a = 1; for ($i = 0; $i < 50; ++$i) { $a = [$a]; } expect(!(new Guard())->check($a), 'depth'); });
    test('json-budget-delimiters', function () {
        expect(Guard::jsonBudgetSupported('{"x":[{},1,"[:,"]}'), 'ordinary budget');
        expect(!Guard::jsonBudgetSupported(''), 'empty budget');
        expect(!Guard::jsonBudgetSupported('"' . str_repeat(',', Guard::MAX_NODES) . '"'), 'node upper exceeded');
    });
    test('native-values-proof-and-arguments', function () {
        $child = flat(); $root = flat(); $root->getFirstRow()->setSubtable($child);
        $unused = new DataTable();
        API::$factory = fn() => $root;
        $reader = new Reader();
        expect($reader->tryRead(request()) === $root, 'native identity');
        expect($reader->usedPrimitiveArchiveOnly(), 'proof');
        expect($reader->getOwnedTableIds() === [$root->getId(), $child->getId()], 'tree IDs only');
        expect(!in_array($unused->getId(), $reader->getOwnedTableIds(), true), 'unowned ID');
        $args = end(API::$calls);
        expect($args === [353, 'month', '2026-10-01', '', true, false, false, true, 'native-default'], 'all current parameters');
    });
    test('permission-before-api', function () use ($access) {
        $access->allowed = false; $before = count(API::$calls);
        try { (new Reader())->tryRead(request()); throw new \LogicException('not denied'); }
        catch (\RuntimeException $e) { expect($e->getMessage() === 'fixture-denied', 'wrong denial'); }
        finally { $access->allowed = true; }
        expect(count(API::$calls) === $before, 'API called before permission');
    });
    test('native-api-error-propagates-once', function () {
        API::$factory = static fn() => throw new \RuntimeException('native-fixture-error');
        $before = count(API::$calls);
        try { (new Reader())->tryRead(request()); throw new \LogicException('error swallowed'); }
        catch (\RuntimeException $e) { expect($e->getMessage() === 'native-fixture-error', 'wrong API error'); }
        expect(count(API::$calls) === $before + 1, 'API retried');
    });
    test('context-objects-excluded-from-proof', function () {
        $root = flat(); $root->setMetadata('site', new \Piwik\Site(353));
        $root->setMetadata('period', new \Piwik\Period\Month(\Piwik\Date::factory('2026-10-01')));
        API::$factory = fn() => $root; $reader = new Reader(); $reader->tryRead(request());
        expect($reader->usedPrimitiveArchiveOnly(), 'context proof');
    });
    foreach (['columns', 'metadata', 'totals', 'tablemetadata'] as $where) {
        test('native-proof-reject-' . $where, function () use ($where) {
            $root = flat(); $probe = new CallbackProbe();
            if ($where === 'columns') { $root->getFirstRow()->setColumn('bad', $probe); }
            if ($where === 'metadata') { $root->getFirstRow()->setMetadata('bad', $probe); }
            if ($where === 'totals') { $root->setTotalsRow(row(['bad' => $probe])); }
            if ($where === 'tablemetadata') { $root->setMetadata('bad', $probe); }
            API::$factory = fn() => $root; $reader = new Reader();
            expect($reader->tryRead(request()) === $root, 'native result lost');
            expect(!$reader->usedPrimitiveArchiveOnly(), 'unsafe proof');
        });
    }
    test('native-cycle-declines-proof', function () {
        $root = flat(); $root->getFirstRow()->setSubtable($root);
        API::$factory = fn() => $root; $reader = new Reader();
        expect($reader->tryRead(request()) === $root && !$reader->usedPrimitiveArchiveOnly(), 'cycle result');
        expect($reader->getOwnedTableIds() === [], 'ambiguous graph ownership');
        $root->getFirstRow()->removeSubtable();
    });
    foreach ([['flat' => 0], ['idSubtable' => 0], ['date' => '2026-02-30'], ['idSite' => 'all'], ['method' => 'Other.get']] as $i => $change) {
        test('request-decline-' . $i, function () use ($change) {
            $before = count(API::$calls); expect((new Reader())->tryRead(array_replace(request(), $change)) === null, 'request accepted');
            expect(count(API::$calls) === $before, 'API called on ineligible request');
        });
    }
    test('json-stream-roundtrip-types-order-summary-totals', function () {
        $table = flat(); $table->addSummaryRow(row(['label' => 'Others', 'nb_hits' => 1]));
        $table->setTotalsRow(row(['nb_hits' => 6, 'float' => 1.0]));
        $frozen = Snapshot::freeze($table); expect(is_array($frozen), 'freeze');
        $encoded = Snapshot::encodeFrozen($frozen); expect(is_string($encoded), 'encode');
        expect(str_starts_with(gzdecode($encoded), '{'), 'not JSON');
        $decoded = Snapshot::decode($encoded, new DataTable()); expect($decoded instanceof DataTable, 'decode');
        expect(same($frozen, Snapshot::freeze($decoded)), 'types/keys/order changed');
    });
    test('fresh-context-and-metrics-slot', function () {
        $table = flat(); $site = new \Piwik\Site(353); $period = new \Piwik\Period\Month(\Piwik\Date::factory('2026-10-01'));
        $table->setMetadata('site', $site); $table->setMetadata('period', $period);
        $table->setMetadata('segment', ''); $table->setMetadata('segmentPretty', '');
        $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME,
            [new \Piwik\Plugins\PagePerformance\Columns\Metrics\AverageTimeNetwork()]);
        $encoded = Snapshot::encode($table); expect(is_string($encoded), 'context encode');
        $fresh = new DataTable(); $fresh->setMetadata('site', $site); $fresh->setMetadata('period', $period);
        $fresh->setMetadata('segment', ''); $fresh->setMetadata('segmentPretty', '');
        $decoded = Snapshot::decode($encoded, $fresh); expect($decoded instanceof DataTable, 'fresh decode');
        expect($decoded->getMetadata('site') === $site && $decoded->getMetadata('period') === $period, 'fresh objects');
        expect($decoded->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME) === [], 'metric object persisted');
    });
    foreach (['flat', 'processed'] as $mode) {
        foreach (['current', 'future', 'foreign', 'callable'] as $metricKind) {
            test('metric-scope-' . $mode . '-' . $metricKind, function () use ($mode, $metricKind) {
                $t = flat();
                if ($mode === 'processed') {
                    $t->clearQueuedFilters(); $t->sort(static fn() => 0, 'nb_hits'); $t->enableRecursiveSort();
                }
                $metric = match ($metricKind) {
                    'current' => new \Piwik\Plugins\PagePerformance\Columns\Metrics\AverageTimeNetwork(),
                    'future' => new \Piwik\Plugins\PagePerformance\Columns\Metrics\SpeoSyntheticFutureMetric(),
                    'foreign' => new CallbackProbe(),
                    'callable' => new \Piwik\Plugins\CoreHome\Columns\Metrics\CallableProcessedMetric('fixture', static fn() => 1),
                };
                $t->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, [$metric]);
                $encoded = $mode === 'flat' ? Snapshot::encode($t) : Processed::encode($t);
                if (in_array($metricKind, ['foreign', 'callable'], true)) { expect($encoded === null, 'foreign metric accepted'); return; }
                expect(is_string($encoded), 'native current/future metric rejected');
                $raw = $mode === 'flat' ? gzdecode($encoded) : $encoded;
                expect(!str_contains($raw, 'must-never-be-persisted') && !str_contains($raw, get_class($metric)), 'metric state persisted');
                $restored = $mode === 'flat' ? Snapshot::decode($encoded, new DataTable()) : Processed::decode($encoded, new DataTable());
                expect($restored instanceof DataTable && $restored->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME) === [], 'fresh metric slot');
            });
        }
    }
    test('flat-empty-before-generic-sort', function () {
        $t = new DataTable(); $t->queueFilter('ReplaceSummaryRowLabel'); $t->queueFilter('ReplaceColumnNames');
        $encoded = Snapshot::encode($t); expect(is_string($encoded), 'empty flat base');
        $restored = Snapshot::decode($encoded, new DataTable());
        expect($restored instanceof DataTable && !$restored->isSortRecursiveEnabled(), 'empty base flag');
    });
    test('processed-native-generic-sort-sparse-ids', function () {
        $t = flat(); $t->clearQueuedFilters(); $t->filter('Sort', ['nb_hits', 'desc']);
        // Native limited pages may retain sparse row IDs. No sort on populated
        // decoded tables is permitted because it would reindex these IDs.
        $rows = $t->getRows(); $sparse = new DataTable(); $sparse->sort(static fn() => 0, 'nb_hits');
        $sparse->enableRecursiveSort(); $sparse[7] = $rows[0]; $sparse[19] = $rows[1];
        $encoded = Processed::encode($sparse); expect(is_string($encoded), 'sorted snapshot');
        $restored = Processed::decode($encoded, new DataTable());
        expect($restored instanceof DataTable && array_keys($restored->getRows()) === [7, 19], 'sparse IDs changed');
        expect($restored->getSortedByColumnName() === 'nb_hits' && $restored->isSortRecursiveEnabled(), 'sort state');
    });
    foreach (['empty-native-sort', 'disabled-column', 'no-sort'] as $kind) {
        test('processed-unprovable-sort-shape-' . $kind, function () use ($kind) {
            $t = $kind === 'empty-native-sort' ? new DataTable() : flat(); $t->clearQueuedFilters();
            if ($kind === 'empty-native-sort') { $t->filter('Sort', ['nb_hits', 'desc']); }
            if ($kind === 'disabled-column') { $t->filter('Sort', [false, 'desc']); }
            expect($t->getSortedByColumnName() === false, 'expected no public marker');
            expect($t->isSortRecursiveEnabled() === ($kind !== 'no-sort'), 'native constructor flag premise');
            expect(Processed::encode($t) === null, 'unprovable state accepted');
        });
    }
    foreach (['columns', 'metadata', 'totals', 'tablemetadata'] as $where) {
        test('end-hook-object-rejected-at-freeze-' . $where, function () use ($where) {
            $t = flat(); API::$factory = fn() => $t; $r = new Reader(); $r->tryRead(request());
            expect($r->usedPrimitiveArchiveOnly(), 'initial proof');
            $probe = new CallbackProbe();
            if ($where === 'columns') { $t->getFirstRow()->setColumn('new', ['nested' => $probe]); }
            if ($where === 'metadata') { $t->getFirstRow()->setMetadata('new', ['nested' => $probe]); }
            if ($where === 'totals') { $t->setTotalsRow(row(['new' => $probe])); }
            if ($where === 'tablemetadata') { $t->setMetadata('new', $probe); }
            expect(Snapshot::freeze($t) === null, 'pre-hook proof incorrectly trusted');
        });
    }
    test('freeze-reference-rejected', function () {
        $x = 1; $values = ['x' => &$x]; $t = flat(); $t->getFirstRow()->setColumn('ref', $values);
        expect(Snapshot::freeze($t) === null, 'freeze reference');
    });
    test('freeze-metadata-reference-rejected', function () {
        $x = 1; $values = ['x' => &$x]; $t = flat(); $t->setMetadata('ref', $values);
        expect(Snapshot::freeze($t) === null, 'metadata reference');
    });
    test('freeze-cow-survives-later-native-mutation', function () {
        $t = flat(); $before = Snapshot::freeze($t); expect(is_array($before), 'freeze');
        $t->getFirstRow()->setColumn('label', 'changed');
        $t->getFirstRow()->setMetadata('later', ['changed' => true]);
        $t->setMetadata('primitive', ['changed' => true]); $t->clearQueuedFilters();
        $decoded = Snapshot::decode(Snapshot::encodeFrozen($before), new DataTable());
        expect($decoded instanceof DataTable && same($before, Snapshot::freeze($decoded)), 'deferred COW changed');
    });
    test('frozen-object-never-callback', function () {
        $s = Snapshot::freeze(flat()); $s['rows'][0][1]['columns']['probe'] = new CallbackProbe();
        expect(Snapshot::encodeFrozen($s) === null && CallbackProbe::$calls === 0, 'object callback');
    });
    foreach ([INF, -INF, NAN] as $i => $value) {
        test('nonfinite-freeze-' . $i, function () use ($value) { $t = flat(); $t->getFirstRow()->setColumn('bad', $value); expect(Snapshot::freeze($t) === null, 'finite'); });
    }
    test('invalid-utf8-no-error', function () { $s = Snapshot::freeze(flat()); $s['rows'][0][1]['columns']['bad'] = "\xff"; expect(Snapshot::encodeFrozen($s) === null, 'UTF8'); });
    test('json-overflow-exponent-rejected', function () {
        $json = gzdecode(Snapshot::encode(flat())); $json = str_replace('"nb_hits":5', '"nb_hits":1e9999', $json);
        expect(Snapshot::decode(gzencode($json, 1), new DataTable()) === null, 'overflow accepted');
    });
    test('legacy-serialized-cache-never-unserialized', function () {
        $payload = 'O:13:"CallbackProbe":0:{}';
        expect(Snapshot::decode(gzencode($payload, 1), new DataTable()) === null, 'serialized object');
        expect(CallbackProbe::$calls === 0, 'unserialize callback');
    });
    test('json-object-looking-label-is-data', function () {
        $t = flat(); $t->getFirstRow()->setColumn('label', 'O:13:"CallbackProbe":0:{}');
        $restored = Snapshot::decode(Snapshot::encode($t), new DataTable());
        expect($restored instanceof DataTable && $restored->getFirstRow()->getColumn('label') === $t->getFirstRow()->getColumn('label'), 'marker string');
    });
    foreach (['trailing', 'concatenated', 'crc', 'size', 'header', 'truncated'] as $kind) {
        test('gzip-reject-' . $kind, function () use ($kind) {
            $encoded = Snapshot::encode(flat());
            if ($kind === 'trailing') { $encoded .= 'x'; }
            if ($kind === 'concatenated') { $encoded .= $encoded; }
            if ($kind === 'crc') { $encoded[-8] = chr(ord($encoded[-8]) ^ 1); }
            if ($kind === 'size') { $encoded = substr($encoded, 0, -4) . pack('V', 1); }
            if ($kind === 'header') { $encoded[3] = chr(8); }
            if ($kind === 'truncated') { $encoded = substr($encoded, 0, -1); }
            expect(Snapshot::decode($encoded, new DataTable()) === null, 'invalid gzip accepted');
        });
    }
    foreach (['null', '{}', '[]', '{"schema":1}', '{bad}', '{"schema":1}x'] as $i => $json) {
        test('json-schema-reject-' . $i, fn() => expect(Snapshot::decode(gzencode($json, 1), new DataTable()) === null, 'invalid schema'));
    }
    foreach (['schema', 'duplicateid', 'badcolumn', 'badsummary', 'badstate', 'badmetadata', 'badqueue'] as $kind) {
        test('schema-before-native-allocation-' . $kind, function () use ($kind) {
            $s = Snapshot::freeze(flat());
            if ($kind === 'schema') { $s['schema'] = 2; }
            if ($kind === 'duplicateid') { $s['rows'][1][0] = $s['rows'][0][0]; }
            if ($kind === 'badcolumn') { $s['rows'][0][1]['columns'] = false; }
            if ($kind === 'badsummary') { $s['summary'] = $s['rows'][0][1]; }
            if ($kind === 'badstate') { $s['state']['recursiveSort'] = 1; }
            if ($kind === 'badmetadata') { $s['metadata'][] = ['site', 'value', 'forged']; }
            if ($kind === 'badqueue') { $s['queues'][] = ['className' => 'Foreign', 'parameters' => []]; }
            $encoded = gzencode(json_encode($s, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 1);
            $fresh = new DataTable(); $before = \Piwik\DataTable\Manager::getInstance()->getMostRecentTableId();
            expect(Snapshot::decode($encoded, $fresh) === null, 'bad schema');
            expect(\Piwik\DataTable\Manager::getInstance()->getMostRecentTableId() === $before, 'allocated before validation');
        });
    }
    test('native-source-contract-no-pins-or-raw-archive', function () {
        $source = file_get_contents(SPEO_PLUGIN_ROOT . '/NativeArchiveReader.php');
        foreach (['PHP_VERSION', 'Version::', 'hash_file(', 'getBlob(', 'safe_unserialize(', 'fromSerializedArray('] as $token) {
            expect(!str_contains($source, $token), 'internal reader dependency: ' . $token);
        }
        $source = file_get_contents(SPEO_PLUGIN_ROOT . '/FlatTableSnapshot.php');
        foreach (['PHP_VERSION', 'PrimitiveArrayCodec', 'unserialize('] as $token) {
            expect(!str_contains($source, $token), 'serialized cache dependency: ' . $token);
        }
    });
    test('no-object-callbacks-total', fn() => expect(CallbackProbe::$calls === 0, 'callback was run'));
    echo 'OK ' . $passed . " fixtures; native DataTable/Row + API/access doubles; no app bootstrap/DB/reports\n";
}
