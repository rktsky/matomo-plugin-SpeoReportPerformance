<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\DataTable\Manager;
use Piwik\DataTable\Row;
use Piwik\Site;

/**
 * Primitive-only snapshot of a COMPLETED, limited native postprocessor result.
 * Caller owns permission checks, revision/request cache keys and excluding
 * unlimited requests, comparisons, disabled processing and DataRounding.
 */
final class ProcessedTableSnapshot
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 16777216;
    private const MAX_NODES = 300000;
    private const CONTEXT = ['site', 'period', 'segment', 'segmentPretty'];
    private const PERIODS = ['Piwik\\Period\\Day', 'Piwik\\Period\\Week', 'Piwik\\Period\\Month',
        'Piwik\\Period\\Year', 'Piwik\\Period\\Range'];

    /** null declines caching; it never truncates, transforms, or mutates input. */
    public static function encode(DataTable $table): ?string
    {
        try {
            // json_encode obeys serialize_precision. Refuse a lossy operator
            // setting instead of silently rounding cached metric floats.
            $precision = (int) ini_get('serialize_precision');
            if ($precision !== -1 && $precision < 17) {
                return null;
            }
            if (
                get_class($table) !== DataTable::class || $table->getQueuedFilters() !== []
                || $table->getRowsCount() > self::MAX_ROWS
            ) {
                return null;
            }
            // No public marker cannot distinguish skipped Sort from Sort's
            // constructor enabling recursive sorting on an empty/disabled
            // column. Decline that concrete shape, never read a private flag.
            $sorted = $table->getSortedByColumnName();
            if (!is_string($sorted) || $sorted === '') {
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
                        // PagePerformance's current native handler recreates
                        // these objects. Persist only the metadata slot, never
                        // instances/properties. Future native metrics need no
                        // source/version pin; foreign/callable metrics decline.
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
                'metadata' => $metadata, 'state' => [
                    'sortedBy' => $sorted,
                    // At this boundary the native GenericSort has completed.
                    'recursiveSort' => true,
                    'builtWithoutArchives' => $table->wasBuiltWithoutArchives(),
                ]];
            $nodes = 0;
            $bytes = 0;
            if (!self::primitive($snapshot, 0, $nodes, $bytes)) {
                return null;
            }
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 64);
            return strlen($encoded) <= self::MAX_BYTES ? $encoded : null;
        } catch (\Throwable $error) {
            return null;
        }
    }

    /** Only creates ordinary native DataTable/Row objects; never unserializes objects. */
    public static function decode(string $encoded, DataTable $freshRoot): ?DataTable
    {
        $firstId = null;
        try {
            if (get_class($freshRoot) !== DataTable::class || strlen($encoded) > self::MAX_BYTES) {
                return null;
            }
            $snapshot = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
            if (
                !is_array($snapshot) || array_keys($snapshot) !== ['schema', 'rows', 'summary', 'totals', 'metadata', 'state']
                || $snapshot['schema'] !== 1 || !is_array($snapshot['rows']) || !array_is_list($snapshot['rows'])
                || count($snapshot['rows']) + ($snapshot['summary'] !== null ? 1 : 0) > self::MAX_ROWS
                || !is_array($snapshot['metadata']) || !array_is_list($snapshot['metadata'])
                || !self::stateValid($snapshot['state'])
            ) {
                return null;
            }
            $nodes = 0;
            $bytes = 0;
            if (!self::primitive($snapshot, 0, $nodes, $bytes)) {
                return null;
            }
            $ids = [];
            foreach ($snapshot['rows'] as $entry) {
                if (
                    !is_array($entry) || array_keys($entry) !== [0, 1] || !is_int($entry[0]) || $entry[0] < 0
                    || isset($ids[$entry[0]]) || !self::rowValid($entry[1])
                ) {
                    return null;
                }
                $ids[$entry[0]] = true;
            }
            foreach (['summary', 'totals'] as $kind) {
                if ($snapshot[$kind] !== null && !self::rowValid($snapshot[$kind])) {
                    return null;
                }
            }
            if ($snapshot['summary'] !== null && !$snapshot['summary']['summary']) {
                return null;
            }
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
            $sorted = $snapshot['state']['sortedBy'];
            if (is_string($sorted) && $sorted !== '') {
                $table->sort(static fn() => 0, $sorted);
            }
            foreach ($snapshot['rows'] as [$id, $row]) {
                $table[$id] = self::importRow($row);
            }
            if ($snapshot['summary'] !== null) {
                $table->addSummaryRow(self::importRow($snapshot['summary']));
            }
            if ($snapshot['totals'] !== null) {
                $table->setTotalsRow(self::importRow($snapshot['totals']));
            }
            $table->setAllTableMetadata($metadata);
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
            && is_string($data['sortedBy']) && $data['sortedBy'] !== ''
            && $data['recursiveSort'] === true && is_bool($data['builtWithoutArchives']);
    }

    private static function contextType(string $key, $value): ?string
    {
        if ($key === 'site') {
            return is_object($value) && get_class($value) === Site::class ? Site::class : null;
        }
        if ($key === 'period') {
            return is_object($value) && in_array(get_class($value), self::PERIODS, true) ? get_class($value) : null;
        }
        return is_string($value) ? 'string' : null;
    }

    /** Reject resources, objects, references/cycles, nonfinite floats and oversized data. */
    private static function primitive($value, int $depth, int &$nodes, int &$bytes): bool
    {
        if (++$nodes > self::MAX_NODES || $depth > 48) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (\ReflectionReference::fromArrayElement($value, $key) !== null) {
                    return false;
                }
                $bytes += is_string($key) ? strlen($key) : 8;
                if ($bytes > self::MAX_BYTES || !self::primitive($item, $depth + 1, $nodes, $bytes)) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($value)) {
            $bytes += strlen($value);
            return $bytes <= self::MAX_BYTES;
        }
        return $value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value));
    }
}
