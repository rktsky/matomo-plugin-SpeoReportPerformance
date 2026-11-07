<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;

/**
 * Own normalized input using only the public DataTable extension methods.
 * Its explicit empty clone ends this adapter without copying protected state.
 */
final class PreparedFlatTable extends DataTable
{
    private int $nextRedundantFilter = 0;
    private const FILTERS = ['ReplaceSummaryRowLabel', 'ReplaceColumnNames'];

    /** Caller proved no summary row/subtables and no renameable metric keys. */
    public static function fromNormalized(DataTable $source): self
    {
        $prepared = new self();
        $prepared->setAllTableMetadata($source->getAllTableMetadata());
        $sorted = $source->getSortedByColumnName();
        if (is_string($sorted) && $sorted !== '') {
            $prepared->sort(static fn() => 0, $sorted);
        }
        foreach ($source->getRows() as $id => $row) {
            $prepared[$id] = $row;
        }
        $prepared->setLabelsHaveChanged();
        if ($source->getTotalsRow()) {
            $prepared->setTotalsRow($source->getTotalsRow());
        }
        $prepared->setAsBuiltWithoutArchives($source->wasBuiltWithoutArchives());
        foreach ($source->getQueuedFilters() as $filter) {
            $prepared->queueFilter($filter['className'], $filter['parameters']);
        }
        return $prepared;
    }

    /** Explicitly terminate this input adapter at the public clone boundary. */
    public function getEmptyClone($keepFilters = true)
    {
        $copy = new DataTable();
        $copy->setAllTableMetadata($this->getAllTableMetadata());
        if ($keepFilters) {
            foreach ($this->getQueuedFilters() as $filter) {
                $copy->queueFilter($filter['className'], $filter['parameters']);
            }
        }
        return $copy;
    }

    public function filter($className, $parameters = array())
    {
        // Skip only the first EXACT native pair. A custom mapping, callback,
        // different order or any intervening filter permanently disarms this
        // optimization; that call and every subsequent call execute natively.
        if (
            $this->nextRedundantFilter < 2
            && $className === self::FILTERS[$this->nextRedundantFilter]
            && $parameters === []
        ) {
            ++$this->nextRedundantFilter;
            return;
        }
        $this->nextRedundantFilter = 2;
        parent::filter($className, $parameters);
    }

    public static function sourceCompatible(): bool
    {
        return method_exists(DataTable::class, 'filter')
            && method_exists(DataTable::class, 'getEmptyClone')
            && method_exists(DataTable::class, 'offsetSet');
    }
}
