<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\DataTable\Row;

/**
 * A page whose selection, order and totals are already complete. Only this own
 * table defers repeated selection filters until its first presentation filter.
 * It uses DataTable's public extension methods; no native object is patched.
 */
final class SelectedPageTable extends DataTable
{
    private bool $selected = true;
    private array $selectedIds = [];
    private int $nextRow = 0;
    private bool $receivingRows = false;
    private const SELECTION_FILTERS = ['Pattern', 'PatternRecursive', 'ExcludeLowPopulation', 'Sort', 'Truncate', 'Limit'];

    public static function fromPage(DataTable $source): self
    {
        if ($source->getSummaryRow() !== null || $source->getRowsCount() > ProcessedTableSnapshot::MAX_ROWS) {
            throw new \UnexpectedValueException('Unsupported selected page');
        }
        $table = new self();
        $table->selected = false;
        self::copySortMarker($source, $table);
        foreach ($source->getRows() as $id => $row) {
            if (!is_int($id) || $id < 0 || !$row instanceof Row || $row->getIdSubDataTable() !== null || $row->getSubtable() !== false) {
                throw new \UnexpectedValueException('Unsupported selected row');
            }
            $table[$id] = $row;
        }
        $table->selectedIds = array_keys($source->getRows());
        $table->setLabelsHaveChanged();
        $table->setAllTableMetadata($source->getAllTableMetadata());
        if ($source->getTotalsRow()) {
            $table->setTotalsRow($source->getTotalsRow());
        }
        $table->enableRecursiveSort(); // The completed generic Sort uses recursiveSort=true.
        $table->setAsBuiltWithoutArchives($source->wasBuiltWithoutArchives());
        foreach ($source->getQueuedFilters() as $filter) {
            $table->queueFilter($filter['className'], $filter['parameters']);
        }
        $table->selected = true;
        return $table;
    }

    /** A native flatten pass may append the same leaves; preserve their page IDs. */
    public function getEmptyClone($keepFilters = true)
    {
        // Totals calculators request an unfiltered scratch table. Their scratch
        // work is ordinary native work; only the finished page remains sealed.
        $copy = $keepFilters && $this->selected ? new self() : new DataTable();
        $copy->setAllTableMetadata($this->getAllTableMetadata());
        if ($keepFilters) {
            foreach ($this->getQueuedFilters() as $filter) {
                $copy->queueFilter($filter['className'], $filter['parameters']);
            }
        }
        if ($copy instanceof self) {
            $copy->selectedIds = $this->selectedIds;
            $copy->receivingRows = true;
            if ($this->getTotalsRow()) {
                self::setTotalsOnCopy($copy, $this->getTotalsRow());
            }
            self::copySortMarker($this, $copy);
            $copy->enableRecursiveSort();
            $copy->setAsBuiltWithoutArchives($this->wasBuiltWithoutArchives());
        }
        return $copy;
    }

    private static function setTotalsOnCopy(self $copy, Row $total): void
    {
        $copy->selected = false;
        $copy->setTotalsRow($total);
        $copy->selected = true;
    }

    /** Native Sort's public contract supplies the marker without protected state. */
    private static function copySortMarker(DataTable $source, DataTable $copy): void
    {
        $column = $source->getSortedByColumnName();
        if (is_string($column) && $column !== '') {
            // The empty table records its sort marker without visiting rows;
            // sparse IDs are subsequently inserted through public ArrayAccess.
            $copy->sort(static fn() => 0, $column);
        }
    }

    public function addRow($row)
    {
        if ($this->selected && $this->receivingRows) {
            if (!$row instanceof Row || !array_key_exists($this->nextRow, $this->selectedIds)) {
                throw new \UnexpectedValueException('Selected page row count changed');
            }
            $this[$this->selectedIds[$this->nextRow++]] = $row;
            $this->setLabelsHaveChanged();
            return $row;
        }
        return parent::addRow($row);
    }

    public function filter($className, $parameters = array())
    {
        $name = is_string($className) ? preg_replace('/^Piwik\\\\DataTable\\\\Filter\\\\/', '', $className) : null;
        if ($this->selected && in_array($name, self::SELECTION_FILTERS, true)) {
            return;
        }
        parent::filter($className, $parameters);
    }

    /** Search totals must not be re-summed from just this selected page. */
    public function setTotalsRow(Row $totalsRow)
    {
        if (!$this->selected) {
            parent::setTotalsRow($totalsRow);
        }
    }

    public function setMetadata($name, $value)
    {
        if ($this->selected && $name === DataTable::TOTALS_ROW_IS_FILTERED_METADATA_NAME) {
            return;
        }
        parent::setMetadata($name, $value);
    }

    /** Called by our FIRST presentation filter, then all filters run normally. */
    public function resumePresentation(): void
    {
        if ($this->receivingRows && $this->nextRow !== count($this->selectedIds)) {
            throw new \UnexpectedValueException('Selected page was not fully reconstructed');
        }
        $this->selected = false;
        $this->receivingRows = false;
    }
}
