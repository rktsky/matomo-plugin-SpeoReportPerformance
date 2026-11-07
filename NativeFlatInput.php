<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\Metrics;

final class NativeFlatInput
{
    public const BACKUP_KEY = '__speo_report_performance_original_dimension_v1';

    /**
     * The ordinary core flattener still runs over these leaf-only rows. Preserve
     * its already correct dimension metadata across that cheap second pass.
     * Restore before the original queued filters so they see native metadata.
     * Requests that disable queues or request dimension columns are ineligible.
     */
    public static function prepare(DataTable $table, string $rootDimension, bool $skipRedundantFilters = true): DataTable
    {
        $canSkip = $skipRedundantFilters && PreparedFlatTable::sourceCompatible()
            && get_class($table) === DataTable::class && !$table->getRowFromId(DataTable::ID_SUMMARY_ROW);
        $mapping = $canSkip ? Metrics::getMappingFromIdToName() : [];
        // A native default rename is idempotent iff no remaining top-level key
        // can be renamed. Custom subtable queues can reintroduce numeric keys,
        // so verify the invariant instead of assuming it from the first pass.
        $renames = array_filter($mapping, static fn($value, $key) => $value != $key, ARRAY_FILTER_USE_BOTH);
        if ($canSkip && $table->getTotalsRow() && array_intersect_key($table->getTotalsRow()->getColumns(), $renames)) {
            $canSkip = false;
        }
        foreach ($table->getRows() as $row) {
            if (
                $canSkip && ($row->getIdSubDataTable() !== null || $row->getSubtable() !== false
                || array_intersect_key($row->getColumns(), $renames))
            ) {
                $canSkip = false;
            }
            $metadata = $row->getMetadata();
            $exists = array_key_exists($rootDimension, $metadata);
            $row->setMetadata(self::BACKUP_KEY, [
                'exists' => $exists, 'value' => $exists ? $metadata[$rootDimension] : null,
            ]);
        }
        $queued = $table->getQueuedFilters();
        $table->clearQueuedFilters();
        $table->queueFilter([self::class, 'restore'], [$rootDimension]);
        foreach ($queued as $filter) {
            $table->queueFilter($filter['className'], $filter['parameters']);
        }
        return $canSkip ? PreparedFlatTable::fromNormalized($table) : $table;
    }

    public static function restore(DataTable $table, string $rootDimension): void
    {
        foreach ($table->getRows() as $row) {
            $backup = $row->getMetadata(self::BACKUP_KEY);
            if (!is_array($backup) || !array_key_exists('exists', $backup)) {
                continue;
            }
            if ($backup['exists']) {
                $row->setMetadata($rootDimension, $backup['value']);
            } else {
                $row->deleteMetadata($rootDimension);
            }
            $row->deleteMetadata(self::BACKUP_KEY);
        }
    }
}
