<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\Cache;
use Piwik\DataTable;
use Piwik\DataTable\Manager as TableManager;
use Piwik\DataTable\Row;
use Piwik\NoAccessException;
use Piwik\Plugin\Manager;
use Piwik\Plugin\ReportsProvider;
use Piwik\Plugins\CoreVisualizations\Visualizations\HtmlTable;
use Piwik\Version;

/** Numeric page boundary only; all native presentation/HTML work runs afresh. */
final class UiNumericPageCache
{
    /** Values are weak too: original presentation closures may capture the view. */
    private static ?\WeakMap $states = null;
    private static string $lastDecision = 'not-run';
    public static function getLastDecision(): string
    {
        return self::$lastDecision;
    }

    /** true supplies a cache hit; false lets the caller load its ordinary base. */
    public static function loadAttach($view, RevisionContext $context, array $request): bool
    {
        self::$lastDecision = 'native-ineligible';
        $restore = null;
        $state = null;
        try {
            if (
                !is_object($view) || get_class($view) !== HtmlTable::class
                || !NativeSourceGuard::isReviewedPipeline() || !NativeSourceGuard::isReviewedCachePipeline()
                || $view->config->getPriorityFilters() !== [] || empty($view->config->columns_to_display)
                || $view->config->show_pivot_by_subtable !== false
                || $view->requestConfig->getApiModuleToRequest() !== 'Actions'
                || $view->requestConfig->getApiMethodToRequest() !== 'getPageUrls'
                || ($request['period'] ?? '') !== 'month' || !in_array($request['flat'] ?? null, [true, 1, '1'], true)
                || !in_array($request['segment'] ?? false, [null, false, ''], true)
                || !is_numeric($request['filter_limit'] ?? null) || (int)$request['filter_limit'] < 1
                || (int)$request['filter_limit'] > ProcessedTableSnapshot::MAX_ROWS
            ) {
                return false;
            }
            if (
                RequestEligibility::reason(new DataTable(), ['module' => 'Actions', 'action' => 'getPageUrls'], $request, Version::VERSION) !== null
                || !self::validPresentation($view)
            ) {
                self::$lastDecision = 'native-unreviewed-ui';
                return false;
            }
            self::$states ??= new \WeakMap();
            if (isset(self::$states[$view])) {
                return false;
            }
            $report = ReportsProvider::factory('Actions', 'getPageUrls');
            if (!$report || get_class($report) !== 'Piwik\\Plugins\\Actions\\Reports\\GetPageUrls' || !$report->getDimension()) {
                return false;
            }
            $properties = $view->config->getProperties();
            unset($properties['filters'], $properties['self_url']); // Fresh presentation/link transport, not numeric data.
            $hash = hash('sha256', json_encode(
                ['source' => hash_file('sha256', __FILE__), 'selected' => hash_file('sha256', __DIR__ . '/SelectedPageTable.php'), 'config' => RevisionContext::canonicalize($properties)],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            ));
            $key = $context->getKey() . '_ui_numeric_' . $hash;
            if (!$context->isCurrent()) {
                return false;
            } // Includes the native permission check.
            $payload = Cache::getLazyCache()->fetch($key);
            $table = is_string($payload) ? ProcessedTableSnapshot::decode($payload, $context->getFreshRoot()) : null;
            if ($table !== null && (!self::numericPage($table) || !$context->isCurrent())) {
                $table = null;
            }
            $state = (object)['context' => $context, 'key' => $key, 'filters' => $view->config->filters,
                'modify' => $view->requestConfig->request_parameters_to_modify,
                'metrics' => array_keys($report->getMetrics()), 'hit' => $table !== null, 'captured' => false];
            $weakView = \WeakReference::create($view);
            if ($table !== null) {
                NativeEndHooks::enrich($table, $request);
                $prepared = SelectedPageTable::fromPage(NativeFlatInput::prepare(
                    $table,
                    str_replace('.', '_', $report->getDimension()->getId()),
                    false
                ));
                $restore = [$view->config->columns_to_display, $view->requestConfig->filter_sort_column, $view->requestConfig->filter_sort_order];
                self::defaults($view, $context->getFreshRoot());
                $clearNulls = static function ($current) use ($state): void {
                    if (!$current instanceof DataTable) {
                        return;
                    }
                    if ($current instanceof SelectedPageTable) {
                        $current->resumePresentation();
                    }
                    foreach ($current->getRows() as $row) {
                        $metadata = $row->getMetadata();
                        foreach ($state->metrics as $metric) {
                            if ($metric === 'label') {
                                continue;
                            }
                            foreach (['_row_percentage', '_site_total_percentage'] as $suffix) {
                                $key = $metric . $suffix;
                                if (array_key_exists($key, $metadata) && $metadata[$key] === null) {
                                    unset($metadata[$key]);
                                }
                            }
                        }
                        $row->setAllMetadata($metadata);
                    }
                };
                $view->config->filters = array_merge([[$clearNulls, [], false]], $state->filters);
                $view->setDataTable($prepared); // Last, nonthrowing native commit.
            } else {
                $capture = static function ($current) use ($state, $weakView): void {
                    if ($weakView->get() && $current instanceof DataTable) {
                        self::capture($current, $state);
                    }
                };
                // FIRST presentation filter, not a priority filter. It executes
                // after native segment/metric/page work but before label/HTML work.
                $view->config->filters = array_merge([[$capture, [], false]], $state->filters);
            }
            self::$states[$view] = \WeakReference::create($state);
            Diagnostics::record($state->hit ? 'cache_hit' : 'cache_miss');
            self::$lastDecision = $state->hit ? 'ui-numeric-hit' : 'ui-numeric-capture-attached';
            return $state->hit;
        } catch (\Throwable $error) {
            if ($restore !== null && $state !== null) {
                [$view->config->columns_to_display, $view->requestConfig->filter_sort_column, $view->requestConfig->filter_sort_order] = $restore;
                $view->config->filters = $state->filters;
                $view->requestConfig->request_parameters_to_modify = $state->modify;
            }
            if ($error instanceof NoAccessException) {
                throw $error;
            }
            self::$lastDecision = 'native-numeric-error';
            return false;
        }
    }

    /** Visualization.beforeRender, after=true: restore before native change logging. */
    public static function finish($view): void
    {
        if (!is_object($view) || self::$states === null || !isset(self::$states[$view])) {
            return;
        }
        $state = self::$states[$view]->get();
        if ($state) {
            $view->requestConfig->request_parameters_to_modify = $state->modify;
            $view->config->filters = $state->filters;
        }
        unset(self::$states[$view]);
    }

    private static function capture(DataTable $table, object $state): void
    {
        if ($state->hit || $state->captured || !self::numericPage($table)) {
            return;
        }
        $state->captured = true;
        $copy = null;
        try {
            $copy = self::copyPage($table, $state->metrics);
            if ($copy === null) {
                return;
            }
            $payload = ProcessedTableSnapshot::encode($copy);
            if ($payload !== null && $state->context->isCurrent()) {
                self::$lastDecision = Cache::getLazyCache()->save($state->key, $payload, Configuration::get()->cacheTtl())
                    ? 'ui-numeric-stored' : 'ui-numeric-store-declined';
            }
        } catch (\Throwable $error) {
            Diagnostics::record('cache_store_failed');
            /* Optional cache must not break the rendered report. */
        } finally {
            if ($copy !== null) {
                TableManager::getInstance()->deleteTable($copy->getId());
            }
        }
    }

    private static function numericPage(DataTable $table): bool
    {
        return get_class($table) === DataTable::class && $table->getRowsCount() <= ProcessedTableSnapshot::MAX_ROWS
            && $table->getSummaryRow() === null
            && $table->getMetadata(NativeProcessingAdapter::PROCESSED_METRICS_COMPUTED_FLAG) === true
            && !$table->getMetadata('processed_metrics_formatted');
    }

    /** Every row is owned by this copy; source queues/rows remain untouched. */
    private static function copyPage(DataTable $source, array $metrics): ?DataTable
    {
        $copy = new DataTable();
        try {
            $seen = [];
            $rows = [];
            $sorted = $source->getSortedByColumnName();
            if (is_string($sorted) && $sorted !== '') {
                $copy->sort(static fn() => 0, $sorted);
            }
            foreach ($source->getRows() as $id => $row) {
                if (!self::rowOwned($row, $seen)) {
                    return null;
                }
                $row = clone $row;
                $slots = [];
                foreach ($metrics as $metric) {
                    if ($metric !== 'label') {
                        $slots[$metric . '_row_percentage'] = null;
                        $slots[$metric . '_site_total_percentage'] = null;
                    }
                }
                $metadata = [];
                $inserted = false;
                foreach ($row->getMetadata() as $key => $value) {
                    if (array_key_exists($key, $slots)) {
                        if (!$inserted) {
                            $metadata += $slots;
                            $inserted = true;
                        }
                    } else {
                        $metadata[$key] = $value;
                    }
                }
                if (!$inserted && $slots !== []) {
                    return null;
                }
                $row->setAllMetadata($metadata);
                $rows[$id] = $row;
            }
            foreach ($rows as $id => $row) {
                $copy[$id] = $row;
            }
            if ($source->getTotalsRow()) {
                if (!self::rowOwned($source->getTotalsRow(), $seen)) {
                    return null;
                }
                $total = clone $source->getTotalsRow();
                foreach ($metrics as $metric) {
                    if ($metric === 'label') {
                        continue;
                    }
                    if (
                        array_key_exists($metric . '_row_percentage', $total->getMetadata())
                        || array_key_exists($metric . '_site_total_percentage', $total->getMetadata())
                    ) {
                        return null;
                    }
                }
                $copy->setTotalsRow($total);
            }
            $copy->setAllTableMetadata($source->getAllTableMetadata());
            $copy->enableRecursiveSort(); // Native generic Sort in every eligible UI request.
            $copy->setAsBuiltWithoutArchives($source->wasBuiltWithoutArchives());
            $copy->clearQueuedFilters();
            return $copy;
        } finally {
            if (
                count($rows) !== $source->getRowsCount()
                || $source->getTotalsRow() && $copy->getTotalsRow() === null
            ) {
                TableManager::getInstance()->deleteTable($copy->getId());
            }
        }
    }

    private static function rowOwned($row, array &$seen): bool
    {
        if (
            !$row instanceof Row || get_class($row) !== Row::class || $row->getIdSubDataTable() !== null
            || $row->getSubtable() !== false || isset($seen[spl_object_id($row)])
        ) {
            return false;
        }
        $seen[spl_object_id($row)] = true;
        return true;
    }

    private static function defaults($view, DataTable $root): void
    {
        UiRequestBuilder::defaults($view, $root);
    }

    /** Presentation filters remain current callbacks; none are serialized. */
    private static function validPresentation($view): bool
    {
        foreach ($view->config->getPresentationFilters() as $filter) {
            if (!is_array($filter) || !array_key_exists(0, $filter) || !isset($filter[1]) || !is_array($filter[1])) {
                return false;
            }
            if (!is_string($filter[0]) && !is_callable($filter[0])) {
                return false;
            }
        }
        return true;
    }
}
