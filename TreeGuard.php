<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\DataTable\Row;

final class TreeGuard
{
    private static array $copiedIds = [];
    public static function copiedIds(): array
    {
        return self::$copiedIds;
    }
    public static function reason(DataTable $table): ?string
    {
        $tables = [];
        $rows = [];
        return self::inspect($table, $tables, $rows, 0);
    }

    private static function inspect(DataTable $table, array &$tables, array &$rows, int $depth): ?string
    {
        if ($depth > 64) {
            return 'unreviewed-depth';
        }
        $id = spl_object_id($table);
        if (isset($tables[$id])) {
            return 'shared-or-cyclic-table';
        }
        $tables[$id] = true;
        if (get_class($table) !== DataTable::class) {
            return 'custom-table-class';
        }
        foreach ($table->getRows() as $row) {
            if (get_class($row) !== Row::class) {
                return 'custom-row-class';
            }
            if (isset($rows[spl_object_id($row)])) {
                return 'shared-row';
            }
            $rows[spl_object_id($row)] = true;
            if (array_key_exists(NativeFlatInput::BACKUP_KEY, $row->getMetadata())) {
                return 'private-metadata-collision';
            }
            $sub = $row->getSubtable();
            if ($sub instanceof DataTable) {
                $reason = self::inspect($sub, $tables, $rows, $depth + 1);
                if ($reason !== null) {
                    return $reason;
                }
            } elseif ($row->getIdSubDataTable() !== null) {
                return 'unloaded-subtable';
            }
        }
        // Totals are copied as a flat independent Row. Never leave a copied
        // totals pointer referring back into the original graph, or permit an
        // alias that would make later transformations change ordinary rows.
        $totals = $table->getTotalsRow();
        if ($totals !== null) {
            if (get_class($totals) !== Row::class) {
                return 'custom-row-class';
            }
            if (isset($rows[spl_object_id($totals)])) {
                return 'shared-row';
            }
            $rows[spl_object_id($totals)] = true;
            if (array_key_exists(NativeFlatInput::BACKUP_KEY, $totals->getMetadata())) {
                return 'private-metadata-collision';
            }
            if ($totals->getIdSubDataTable() !== null || $totals->getSubtable() !== false) {
                return 'totals-subtable';
            }
        }
        return null;
    }

    /**
     * Work on an owned tree so a runtime failure can safely return the untouched
     * native result. Native DataTable::__clone is shallow: explicitly copy rows,
     * summary/totals rows and every loaded subtable while retaining queues.
     */
    public static function copy(DataTable $source, bool $root = true): DataTable
    {
        if ($root) {
            self::$copiedIds = [];
        }
        $copy = clone $source;
        self::$copiedIds[] = $copy->getId();
        $copy->deleteRows(array_keys($copy->getRows()));
        foreach ($source->getRows() as $id => $row) {
            $newRow = clone $row;
            $sub = $row->getSubtable();
            if ($sub instanceof DataTable) {
                $newRow->setSubtable(self::copy($sub, false));
            } else {
                $newRow->removeSubtable();
            }
            if ($id === DataTable::ID_SUMMARY_ROW) {
                $copy->addSummaryRow($newRow);
            } else {
                $copy[$id] = $newRow;
            }
        }
        $copy->setLabelsHaveChanged();
        if ($source->getTotalsRow()) {
            $copy->setTotalsRow(clone $source->getTotalsRow());
        }
        return $copy;
    }
}
