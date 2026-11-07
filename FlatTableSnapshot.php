<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\DataTable\Manager;
use Piwik\DataTable\Row;
use Piwik\Site;

/**
 * Own adapter of ProcessedTableSnapshot for canonical full flat input BEFORE native postprocessing.
 * Caller owns permission checks, revision/request cache keys and excluding
 * unknown archive forms, comparisons, disabled processing and DataRounding.
 * freeze validates the actual values AFTER API end hooks before capturing COW
 * arrays. No raw archive format, PHP serialization grammar or source pin is
 * trusted. JSON and bounded streaming gzip encoding can run after the response.
 */
final class FlatTableSnapshot
{
    public const MAX_ROWS = 150000;
    public const MAX_BYTES = 268435456;
    public const MAX_COMPRESSED_BYTES = 33554432;
    private const JSON_DEPTH = 48;
    private const ROOT_FILTERS = [
        ['className' => 'ReplaceSummaryRowLabel', 'parameters' => []],
        ['className' => 'ReplaceColumnNames', 'parameters' => []],
    ];
    private const CONTEXT = ['site', 'period', 'segment', 'segmentPretty'];

    /** null declines caching; it never truncates, transforms, or mutates input. */
    public static function freeze(DataTable $table): ?array
    {
        try {
            if (
                get_class($table) !== DataTable::class || $table->getQueuedFilters() !== self::ROOT_FILTERS
                || $table->getRowsCount() > self::MAX_ROWS
            ) {
                return null;
            }
            $seen = [];
            $rows = [];
            foreach ($table->getRows() as $id => $row) {
                if ($id === DataTable::ID_SUMMARY_ROW) {
                    continue;
                }
                if (!is_int($id) || $id < 0) {
                    return null;
                }
                $rows[] = [$id, self::exportRow($row, $seen)];
            }
            $summary = $table->getSummaryRow();
            $summary = $summary === null ? null : self::exportRow($summary, $seen);
            if ($summary !== null && !$summary['summary']) {
                return null;
            }
            $totals = $table->getTotalsRow();
            $totals = $totals === null ? null : self::exportRow($totals, $seen);
            $metadata = [];
            $originalMetadata = $table->getAllTableMetadata();
            foreach ($originalMetadata as $key => $value) {
                if (\ReflectionReference::fromArrayElement($originalMetadata, $key) !== null) {
                    return null;
                }
                if (in_array($key, self::CONTEXT, true)) {
                    $type = self::contextType($key, $value);
                    if ($type === null) {
                        return null;
                    }
                    $metadata[] = [$key, 'fresh', $type];
                } elseif ($key === DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME) {
                    // Never cache metric instances. Keep this slot's position;
                    // normal PagePerformance end handlers fill it anew on hit.
                    if (!is_array($value) || !array_is_list($value)) {
                        return null;
                    }
                    foreach ($value as $metric) {
                        // Objects are NOT persisted or invoked. The real native
                        // API end hooks supply their current instances on hits.
                        $prefix = 'Piwik\\Plugins\\PagePerformance\\Columns\\Metrics\\';
                        if (
                            !$metric instanceof \Piwik\Plugin\ProcessedMetric
                            || !str_starts_with(get_class($metric), $prefix)
                            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', substr(get_class($metric), strlen($prefix))) !== 1
                        ) {
                            return null;
                        }
                    }
                    $metadata[] = [$key, 'metrics', null];
                } else {
                    $metadata[] = [$key, 'value', $value];
                }
            }
            $snapshot = ['schema' => 1, 'rows' => $rows, 'summary' => $summary, 'totals' => $totals,
                'metadata' => $metadata, 'queues' => self::ROOT_FILTERS, 'state' => [
                    'sortedBy' => $table->getSortedByColumnName(),
                    // This boundary is the newly flattened base BEFORE native
                    // GenericSort, not an arbitrary already processed table.
                    'recursiveSort' => false,
                    'builtWithoutArchives' => $table->wasBuiltWithoutArchives(),
                ]];
            // Do not rely on a reader proof from BEFORE arbitrary API end hooks.
            // References would let later native formatting mutate deferred data.
            return (new PrimitiveValueGuard())->check($snapshot) ? $snapshot : null;
        } catch (\Throwable $error) {
            return null;
        }
    }

    /** Convenience path for isolated tests; production can encode after response. */
    public static function encode(DataTable $table): ?string
    {
        $snapshot = self::freeze($table);
        return $snapshot === null ? null : self::encodeFrozen($snapshot);
    }

    /** Revalidates callers' frozen data; never invokes object JSON callbacks. */
    public static function encodeFrozen(array $snapshot): ?string
    {
        try {
            $precision = (int) ini_get('serialize_precision');
            if (($precision !== -1 && $precision < 17) || !self::envelopeValid($snapshot)) {
                return null;
            }
            if (!(new PrimitiveValueGuard())->check($snapshot)) {
                return null;
            }
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, self::JSON_DEPTH);
            if (!PrimitiveValueGuard::jsonBudgetSupported($json)) {
                return null;
            }
            // gzencode reserves a worst-case output buffer as large as the
            // entire input. Stream bounded chunks after the response instead.
            $deflater = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 1]);
            if ($deflater === false) {
                return null;
            }
            $compressed = '';
            $length = strlen($json);
            for ($offset = 0; $offset < $length; $offset += 65536) {
                $last = $offset + 65536 >= $length;
                $part = deflate_add($deflater, substr($json, $offset, 65536), $last ? ZLIB_FINISH : ZLIB_NO_FLUSH);
                if ($part === false || strlen($compressed) + strlen($part) > self::MAX_COMPRESSED_BYTES) {
                    return null;
                }
                $compressed .= $part;
            }
            return is_string($compressed) && strlen($compressed) <= self::MAX_COMPRESSED_BYTES ? $compressed : null;
        } catch (\Throwable $error) {
            return null;
        }
    }

    /** Only creates ordinary native DataTable/Row objects; never unserializes objects. */
    public static function decode(string $encoded, DataTable $freshRoot): ?DataTable
    {
        $firstId = null;
        try {
            if (get_class($freshRoot) !== DataTable::class) {
                return null;
            }
            $raw = self::inflate($encoded);
            if ($raw === null) {
                return null;
            }
            // Budget BEFORE allocation. Native JSON creates only arrays/scalars,
            // no object instances, aliases, reference slots, autoload or hooks.
            // Old PHP-serialized cache entries are intentionally a cache miss.
            if (!PrimitiveValueGuard::jsonBudgetSupported($raw)) {
                return null;
            }
            $snapshot = json_decode($raw, true, self::JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
            unset($raw);
            if (!is_array($snapshot) || !self::envelopeValid($snapshot)) {
                return null;
            }
            // Native decode may turn an overflowing JSON exponent into INF.
            // C-level re-encoding validates finite floats/UTF8/depth without a
            // multi-million-call PHP primitive walk. No objects can be present.
            $validation = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, self::JSON_DEPTH);
            if (!PrimitiveValueGuard::jsonBudgetSupported($validation)) {
                return null;
            }
            unset($validation);
            $freshMetadata = $freshRoot->getAllTableMetadata();
            $metadata = [];
            foreach ($snapshot['metadata'] as $entry) {
                if (
                    !is_array($entry) || array_keys($entry) !== [0, 1, 2]
                    || !(is_int($entry[0]) || is_string($entry[0]))
                    || array_key_first([$entry[0] => true]) !== $entry[0]
                    || array_key_exists($entry[0], $metadata)
                ) {
                    return null;
                }
                [$key, $kind, $value] = $entry;
                if ($kind === 'fresh' && in_array($key, self::CONTEXT, true)) {
                    if (!array_key_exists($key, $freshMetadata) || self::contextType($key, $freshMetadata[$key]) !== $value) {
                        return null;
                    }
                    $metadata[$key] = $freshMetadata[$key];
                } elseif ($kind === 'metrics' && $key === DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME && $value === null) {
                    $metadata[$key] = [];
                } elseif (
                    $kind === 'value' && !in_array($key, self::CONTEXT, true)
                    && $key !== DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME
                ) {
                    $metadata[$key] = $value;
                } else {
                    return null;
                }
            }
            // Validate everything before allocating/registering native objects.
            $firstId = Manager::getInstance()->getMostRecentTableId();
            $table = new DataTable();
            // Public sort() on an EMPTY target sets only the native sort marker.
            // Sorting populated sparse rows would reindex their native IDs.
            $sorted = $snapshot['state']['sortedBy'];
            if (is_string($sorted) && $sorted !== '') {
                $table->sort(static fn() => 0, $sorted);
            }
            foreach (array_keys($snapshot['rows']) as $index) {
                [$id, $row] = $snapshot['rows'][$index];
                $table[$id] = self::importRow($row);
                unset($snapshot['rows'][$index]);
            }
            unset($row);
            if ($snapshot['summary'] !== null) {
                $table->addSummaryRow(self::importRow($snapshot['summary']));
            }
            if ($snapshot['totals'] !== null) {
                $table->setTotalsRow(self::importRow($snapshot['totals']));
            }
            $table->setAllTableMetadata($metadata);
            foreach (self::ROOT_FILTERS as $filter) {
                $table->queueFilter($filter['className'], []);
            }
            if ($snapshot['state']['recursiveSort']) {
                $table->enableRecursiveSort();
            }
            $table->setAsBuiltWithoutArchives($snapshot['state']['builtWithoutArchives']);
            // Rebuild the derived label index on first native lookup.
            $table->setLabelsHaveChanged();
            return $table;
        } catch (\Throwable $error) {
            if ($firstId !== null) {
                Manager::getInstance()->deleteAll($firstId);
            }
            return null;
        }
    }

    private static function exportRow($row, array &$seen): array
    {
        if (
            !$row instanceof Row || get_class($row) !== Row::class || $row->getIdSubDataTable() !== null
            || $row->getSubtable() !== false || $row->getFlags() !== 0 || $row->getIteratorClass() !== 'ArrayIterator'
            || isset($seen[spl_object_id($row)])
        ) {
            throw new \UnexpectedValueException('Unsupported row');
        }
        $seen[spl_object_id($row)] = true;
        if (!(is_int($row->maxVisitsSummed) || is_float($row->maxVisitsSummed))) {
            throw new \UnexpectedValueException('Unsupported row state');
        }
        // Native comparisons store request-local Manager IDs, not portable data.
        if (array_key_exists(Row::COMPARISONS_METADATA_NAME, $row->getMetadata())) {
            throw new \UnexpectedValueException('Comparison association');
        }
        return ['columns' => $row->getColumns(), 'metadata' => $row->getMetadata(),
            'summary' => $row->isSummaryRow(), 'maxVisitsSummed' => $row->maxVisitsSummed];
    }

    private static function importRow(array $data): Row
    {
        $row = new Row([Row::COLUMNS => $data['columns'], Row::METADATA => $data['metadata']]);
        if ($data['summary']) {
            $row->setIsSummaryRow();
        }
        $row->maxVisitsSummed = $data['maxVisitsSummed'];
        return $row;
    }

    private static function rowValid($data): bool
    {
        return is_array($data) && array_keys($data) === ['columns', 'metadata', 'summary', 'maxVisitsSummed']
            && is_array($data['columns']) && is_array($data['metadata']) && is_bool($data['summary'])
            && !array_key_exists(Row::COMPARISONS_METADATA_NAME, $data['metadata'])
            && (is_int($data['maxVisitsSummed']) || is_float($data['maxVisitsSummed']));
    }

    private static function stateValid($data): bool
    {
        return is_array($data) && array_keys($data) === ['sortedBy', 'recursiveSort', 'builtWithoutArchives']
            && ($data['sortedBy'] === false || (is_string($data['sortedBy']) && $data['sortedBy'] !== ''))
            && $data['recursiveSort'] === false && is_bool($data['builtWithoutArchives']);
    }

    private static function contextType(string $key, $value): ?string
    {
        if ($key === 'site') {
            return $value instanceof Site ? get_class($value) : null;
        }
        if ($key === 'period') {
            return $value instanceof \Piwik\Period ? get_class($value) : null;
        }
        return is_string($value) ? 'string' : null;
    }

    private static function envelopeValid(array $snapshot): bool
    {
        if (
            array_keys($snapshot) !== ['schema', 'rows', 'summary', 'totals', 'metadata', 'queues', 'state']
            || $snapshot['schema'] !== 1 || !is_array($snapshot['rows']) || !array_is_list($snapshot['rows'])
            || count($snapshot['rows']) + ($snapshot['summary'] !== null ? 1 : 0) > self::MAX_ROWS
            || !is_array($snapshot['metadata']) || !array_is_list($snapshot['metadata'])
            || $snapshot['queues'] !== self::ROOT_FILTERS || !self::stateValid($snapshot['state'])
        ) {
            return false;
        }
        $ids = [];
        foreach ($snapshot['rows'] as $entry) {
            if (
                !is_array($entry) || array_keys($entry) !== [0, 1] || !is_int($entry[0]) || $entry[0] < 0
                || isset($ids[$entry[0]]) || !self::rowValid($entry[1])
            ) {
                return false;
            }
            $ids[$entry[0]] = true;
        }
        foreach (['summary', 'totals'] as $kind) {
            if ($snapshot[$kind] !== null && !self::rowValid($snapshot[$kind])) {
                return false;
            }
        }
        if ($snapshot['summary'] !== null && !$snapshot['summary']['summary']) {
            return false;
        }
        $keys = [];
        foreach ($snapshot['metadata'] as $entry) {
            if (
                !is_array($entry) || array_keys($entry) !== [0, 1, 2]
                || !(is_int($entry[0]) || is_string($entry[0]))
                || array_key_first([$entry[0] => true]) !== $entry[0]
                || array_key_exists($entry[0], $keys)
            ) {
                return false;
            }
            [$key, $kind, $value] = $entry;
            $keys[$key] = true;
            if ($kind === 'fresh' && in_array($key, self::CONTEXT, true)) {
                // A class name is compared only with the fresh context value;
                // it is never instantiated or autoloaded from cache data.
                $typeAllowed = in_array($key, ['site', 'period'], true)
                    ? is_string($value) && $value !== '' : $value === 'string';
                if (!$typeAllowed) {
                    return false;
                }
            } elseif ($kind === 'metrics' && $key === DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME && $value === null) {
                continue;
            } elseif (
                $kind === 'value' && !in_array($key, self::CONTEXT, true)
                && $key !== DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME
            ) {
                continue;
            } else {
                return false;
            }
        }
        return true;
    }

    /** Bounded single canonical gzip member: size, CRC and full input consumption. */
    private static function inflate(string $encoded): ?string
    {
        $length = strlen($encoded);
        if ($length < 18 || $length > self::MAX_COMPRESSED_BYTES) {
            return null;
        }
        static $header;
        $header ??= substr(gzencode('', 1), 0, 10);
        if (substr($encoded, 0, 10) !== $header) {
            return null;
        }
        $size = unpack('Vsize', substr($encoded, -4))['size'];
        if ($size < 1 || $size > self::MAX_BYTES) {
            return null;
        }
        $body = substr($encoded, 10, -8);
        // max_length is ALWAYS positive and bounded. A forged small ISIZE or
        // expansion bomb fails in native zlib before schema/row allocation.
        $json = @gzinflate($body, $size);
        if (
            !is_string($json) || strlen($json) !== $size
            || pack('V', crc32($json)) !== substr($encoded, -8, 4)
        ) {
            return null;
        }

        // gzinflate alone accepts trailing deflate bytes. Verify consumption
        // with a streaming pass; discard bounded chunks instead of allocating
        // another complete uncompressed copy. The first pass already proved
        // the output bound for this exact byte string.
        $stream = @inflate_init(ZLIB_ENCODING_RAW);
        if ($stream === false) {
            return null;
        }
        $bodyLength = strlen($body);
        for ($offset = 0; $offset < $bodyLength; $offset += 4096) {
            $last = $offset + 4096 >= $bodyLength;
            $chunk = @inflate_add($stream, substr($body, $offset, 4096), $last ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            if (!is_string($chunk)) {
                return null;
            }
            unset($chunk);
            if (inflate_get_status($stream) === ZLIB_STREAM_END && !$last) {
                return null;
            }
        }
        if (inflate_get_status($stream) !== ZLIB_STREAM_END || inflate_get_read_len($stream) !== $bodyLength) {
            return null;
        }
        return $json;
    }
}
