<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;

/** Explicit UI policies on the documented ViewDataTable::getRequestArray API. */
final class UiRequestBuilder
{
    /** Comparisons retain native loading; no configuration or raw key is changed. */
    public static function build(object $view): ?array
    {
        if ($view->isComparing()) {
            return null;
        }
        $request = $view->getRequestArray();
        if (!is_array($request)) {
            throw new \UnexpectedValueException('UI request must be an array');
        }
        unset($request[UiPendingRequests::PARAMETER]);
        if ($view->config->enable_sort === false) {
            $request['filter_sort_column'] = '';
            $request['filter_sort_order'] = '';
        }
        if (!array_key_exists('format_metrics', $request) || $request['format_metrics'] === 'bc') {
            $request['format_metrics'] = '1';
        }
        if (!$view->requestConfig->disable_queued_filters && array_key_exists('disable_queued_filters', $request)) {
            unset($request['disable_queued_filters']);
        }
        return $request;
    }

    /** Set only missing display/sort defaults through documented properties. */
    public static function defaults(object $view, DataTable $root): void
    {
        $columns = $root->getColumns();
        $hasVisits = in_array('nb_visits', $columns);
        $hasUnique = in_array('nb_uniq_visitors', $columns);
        if (empty($view->config->columns_to_display)) {
            $display = ($hasVisits || $hasUnique)
                ? ['label', $hasUnique ? 'nb_uniq_visitors' : 'nb_visits'] : $columns;
            $view->config->columns_to_display = array_filter($display);
        }
        if (empty($view->requestConfig->filter_sort_column)) {
            $sort = $hasUnique && in_array('nb_uniq_visitors', $view->config->columns_to_display)
                ? 'nb_uniq_visitors' : 'nb_visits';
            if (!in_array($sort, $columns)) {
                foreach ($columns as $column) {
                    if ($column != 'label') {
                        $sort = $column;
                        break;
                    }
                }
            }
            $view->requestConfig->filter_sort_column = $sort;
            $view->requestConfig->filter_sort_order = 'desc';
        }
    }
}
