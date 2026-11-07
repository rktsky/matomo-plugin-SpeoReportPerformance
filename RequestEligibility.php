<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;

final class RequestEligibility
{
    /** Fail open to the unchanged native path for every unreviewed request shape. */
    public static function reason($value, array $info, array $request, string $version): ?string
    {
        // Runtime versions namespace cache entries; they are not eligibility.
        if (($info['module'] ?? '') !== 'Actions' || ($info['action'] ?? '') !== 'getPageUrls') {
            return 'different-api';
        }
        if (!$value instanceof DataTable || get_class($value) !== DataTable::class) {
            return 'non-single-datatable';
        }
        if (!in_array($request['flat'] ?? null, [true, 1, '1'], true)) {
            return 'not-flat';
        }
        // Whole exports retain the native memory budget. The owned tree copy
        // would otherwise add more than 800 MiB before rendering 116k rows.
        $limit = $request['filter_limit'] ?? 1000;
        if ((int) $limit < 1 || (int) $limit > 1000) {
            return 'native-large-export';
        }
        if (!in_array($request['disable_generic_filters'] ?? false, [null, false, 0, '0', ''], true)) {
            return 'native-unfiltered-export';
        }
        if (array_key_exists('idSubtable', $request) && !in_array($request['idSubtable'], [null, false], true)) {
            return 'native-subtable-request';
        }
        $site = $request['idSite'] ?? null;
        if (!(is_int($site) && $site > 0) && !(is_string($site) && ctype_digit($site) && (int) $site > 0)) {
            return 'non-single-site';
        }

        $disabledOnly = ['idSubtable', 'pivotBy', 'compare', 'include_aggregate_rows', 'show_dimensions',
            'disable_queued_filters', 'disable_root_datatable_post_processor', 'disablePostProcessing'];
        foreach ($disabledOnly as $name) {
            if (array_key_exists($name, $request) && !in_array($request[$name], [null, false, 0, '0', ''], true)) {
                return 'unsupported-' . $name;
            }
        }
        foreach (['compareDates', 'comparePeriods', 'compareSegments', 'idSites', 'idGoal', 'secondaryDimension', 'depth'] as $name) {
            if (array_key_exists($name, $request) && !in_array($request[$name], [null, false, 0, '0', '', []], true)) {
                return 'unsupported-' . $name;
            }
        }
        // Observed table/dashboard transport parameters do not change the API
        // hierarchy. Keep their accepted shapes narrow instead of allowing all
        // controller parameters through this API fast path.
        foreach (['isFooterExpandedInDashboard', 'search_recursive', 'showtitle', 'forceView'] as $name) {
            if (array_key_exists($name, $request) && !in_array($request[$name], [true, false, 0, 1, '0', '1', '', 'true', 'false'], true)) {
                return 'unsupported-' . $name;
            }
        }
        foreach (['random', 'totalRows'] as $name) {
            if (
                array_key_exists($name, $request)
                && !(is_int($request[$name]) && $request[$name] >= 0)
                && !(is_string($request[$name]) && ctype_digit($request[$name]))
            ) {
                return 'unsupported-' . $name;
            }
        }
        foreach (['viewDataTable' => 'table', 'uniqueId' => 'widgetActionsgetPageUrls', 'widget' => ''] as $name => $expected) {
            if (array_key_exists($name, $request) && $request[$name] !== $expected) {
                return 'unsupported-' . $name;
            }
        }
        $allowed = [
            'module', 'action', 'method', 'idSite', 'idsite', 'period', 'date', 'segment', 'expanded', 'idSubtable', 'depth',
            'flat', 'format', 'format_metrics', 'serialize', 'language', 'token_auth', 'force_api_session', 'cb',
            'filter_limit', 'filter_offset', 'filter_sort_column', 'filter_sort_order', 'filter_pattern', 'filter_column',
            'filter_pattern_recursive', 'filter_column_recursive', 'filter_excludelowpop', 'filter_excludelowpop_value',
            'filter_truncate', 'keep_summary_row', 'keep_totals_row', 'keep_totals_row_label', 'totals',
            'hideColumns', 'showColumns', 'showMetadata', 'translateColumnNames', 'disable_generic_filters',
            'fetch_archive_state', 'include_aggregate_rows', 'show_dimensions', 'compare', 'compareDates',
            'comparePeriods', 'compareSegments', 'pivotBy', 'disable_queued_filters',
            'disable_root_datatable_post_processor', 'disablePostProcessing', 'idSites', 'idGoal', 'secondaryDimension',
            'viewDataTable', 'isFooterExpandedInDashboard', 'uniqueId', 'widget', 'random', 'search_recursive', 'totalRows', 'showtitle', 'forceView'
        ];
        foreach (array_keys($request) as $name) {
            if (!in_array($name, $allowed, true)) {
                return 'unknown-parameter';
            }
        }
        return null;
    }
}
