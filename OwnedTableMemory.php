<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\DataTable\Manager;

/** Release only this plugin's superseded input and copy containers after commit. */
final class OwnedTableMemory
{
    public static function releaseExact(array $originalIds, array $copyIds, array $excluded): void
    {
        $manager = Manager::getInstance();
        $exclude = array_fill_keys($excluded, true);
        $copies = [];
        foreach ($copyIds as $id) {
            if (!is_int($id) || isset($exclude[$id]) || !isset($manager[$id])) {
                continue;
            }
            $table = $manager->getTable($id);
            if (get_class($table) !== DataTable::class) {
                return;
            }
            foreach ($table->getRows() as $row) {
                if (get_class($row) !== \Piwik\DataTable\Row::class || $row->getIdSubDataTable() !== null || $row->isSubtableLoaded()) {
                    return;
                }
            }
            $copies[$id] = $table;
        }
        foreach ($copies as $table) {
            $table->deleteRows(array_keys($table->getRows()));
        }
        foreach (array_keys($copies) as $id) {
            $manager->deleteTable($id);
        }
        // The reader's ID set predates API end hooks. A hook may attach an
        // existing table later. Native destruction follows those edges, so a
        // complete current ownership proof is required before deleting ANY
        // original container. A failed proof leaves the original graph intact.
        if (!self::originalGraphIsOwned($manager, $originalIds, $exclude)) {
            return;
        }
        foreach ($originalIds as $id) {
            if (is_int($id) && !isset($exclude[$id]) && isset($manager[$id])) {
                $manager->deleteTable($id);
            }
        }
    }

    private static function originalGraphIsOwned(Manager $manager, array $ids, array $excluded): bool
    {
        $tables = [];
        $seenRows = [];
        try {
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1 || isset($excluded[$id])) {
                    return false;
                }
                if (!isset($manager[$id])) {
                    continue;
                }
                $table = $manager->getTable($id);
                if (get_class($table) !== DataTable::class) {
                    return false;
                }
                $tables[$id] = $table;
            }
            foreach ($tables as $table) {
                $rows = $table->getRows();
                if ($table->getTotalsRow() !== null) {
                    $rows[] = $table->getTotalsRow();
                }
                foreach ($rows as $row) {
                    if (get_class($row) !== \Piwik\DataTable\Row::class || isset($seenRows[spl_object_id($row)])) {
                        return false;
                    }
                    $seenRows[spl_object_id($row)] = true;
                    $child = $row->getSubtable();
                    if ($child instanceof DataTable) {
                        $childId = $child->getId();
                        if (!isset($tables[$childId]) || $tables[$childId] !== $child || isset($excluded[$childId])) {
                            return false;
                        }
                    } elseif ($row->getIdSubDataTable() !== null) {
                        return false;
                    }
                }
            }
            return true;
        } catch (\Throwable $unsupportedGraph) {
            return false;
        }
    }
}
