<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Piwik;
use Piwik\Plugins\Actions\API as ActionsAPI;

/**
 * Direct native API invocation for API.Request.intercept; never a raw archive
 * reader. The parent Proxy still runs all API end hooks and postprocessing.
 */
final class NativeArchiveReader
{
    private const MAX_DEPTH = 128;
    private const MAX_TABLES = 200000;
    private const CONTEXT = ['site', 'period', 'segment', 'segmentPretty'];
    private string $lastDecision = 'not-run';
    private bool $primitiveArchiveOnly = false;
    private array $ownedTableIds = [];
    private array $lastTimings = [];

    public function getOwnedTableIds(): array
    {
        return $this->ownedTableIds;
    }
    public function getLastDecision(): string
    {
        return $this->lastDecision;
    }
    /** Compatibility name: proof now comes from actual native values, not raw serialization. */
    public function usedPrimitiveArchiveOnly(): bool
    {
        return $this->primitiveArchiveOnly;
    }
    /** Milliseconds only; never report content, parameters, URLs or tokens. */
    public function getLastTimings(): array
    {
        return $this->lastTimings;
    }

    /**
     * Final named API parameters must already be merged over the raw request.
     * Permission/API exceptions propagate through Matomo's normal error path.
     * No Proxy or nested Request is created, so dispatch hooks run exactly once.
     */
    public function tryRead(array $request): ?DataTable
    {
        $this->primitiveArchiveOnly = false;
        $this->ownedTableIds = $this->lastTimings = [];
        $reason = self::requestReason($request);
        if ($reason !== null) {
            $this->lastDecision = $reason;
            return null;
        }
        if (!self::sourceCompatible()) {
            $this->lastDecision = 'missing-native-capability';
            return null;
        }
        Piwik::checkUserHasViewAccess((int) $request['idSite']);

        $api = ActionsAPI::getInstance();
        if (!is_callable([$api, 'getPageUrls'])) {
            $this->lastDecision = 'missing-native-api';
            return null;
        }
        $method = new \ReflectionMethod($api, 'getPageUrls');
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
                $this->lastDecision = 'unsupported-native-parameter';
                return null;
            }
            if (array_key_exists($name, $request)) {
                $arguments[] = $request[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                $this->lastDecision = 'missing-native-parameter';
                return null;
            }
        }
        $started = hrtime(true);
        // Reflection only reads the PUBLIC declaration. invokeArgs follows the
        // native method's current parameter order and PHP scalar coercion just
        // as Proxy's call_user_func_array; no private state or code is accessed.
        $table = $method->invokeArgs($api, $arguments);
        $this->lastTimings['native_api_ms'] = (hrtime(true) - $started) / 1e6;
        if (!$table instanceof DataTable) {
            $this->lastDecision = 'non-single-native-table';
            return null;
        }
        $started = hrtime(true);
        $this->primitiveArchiveOnly = $this->inspectTree($table);
        $this->lastTimings['value_proof_ms'] = (hrtime(true) - $started) / 1e6;
        // A proof failure declines optional COW caching, not a correct native
        // report. In particular never query the archive a second time here.
        $this->lastDecision = 'native-actions-api';
        return $table;
    }

    /** Own IDs are only the returned graph, never every newly registered table. */
    private function inspectTree(DataTable $root): bool
    {
        $guard = new PrimitiveValueGuard();
        $stack = [[$root, 0]];
        $seen = [];
        $rowsSeen = [];
        $ids = [];
        $supported = true;
        try {
            while ($stack !== []) {
                [$table, $depth] = array_pop($stack);
                $oid = spl_object_id($table);
                if (isset($seen[$oid]) || $depth > self::MAX_DEPTH || count($seen) >= self::MAX_TABLES) {
                    $this->ownedTableIds = [];
                    return false;
                }
                $seen[$oid] = true;
                $ids[] = $table->getId();
                $metadata = $table->getAllTableMetadata();
                if (!is_array($metadata)) {
                    $this->ownedTableIds = [];
                    return false;
                }
                foreach ($metadata as $key => $value) {
                    if (\ReflectionReference::fromArrayElement($metadata, $key) !== null) {
                        $supported = false;
                    }
                    // Only native request context is excluded from stored data.
                    if (!in_array($key, self::CONTEXT, true) && !$guard->check($value)) {
                        $supported = false;
                    }
                }
                $rows = $table->getRows(); // Native includes its summary row.
                $totals = $table->getTotalsRow();
                if ($totals !== null) {
                    $rows[] = $totals;
                }
                foreach ($rows as $row) {
                    if (!$row instanceof Row || isset($rowsSeen[spl_object_id($row)])) {
                        $supported = false;
                        continue;
                    }
                    $rowsSeen[spl_object_id($row)] = true;
                    if (!$guard->check($row->getColumns()) || !$guard->check($row->getMetadata())) {
                        $supported = false;
                    }
                    $child = $row->getSubtable();
                    if ($child instanceof DataTable) {
                        $stack[] = [$child, $depth + 1];
                    } elseif ($row->getIdSubDataTable() !== null) {
                        $supported = false;
                    }
                }
            }
            $this->ownedTableIds = $ids;
            return $supported;
        } catch (\Throwable $error) {
            $this->ownedTableIds = [];
            return false;
        }
    }

    public static function requestReason(array $request): ?string
    {
        if (array_key_exists('idSubtable', $request) && !in_array($request['idSubtable'], [null, false], true)) {
            return 'native-subtable-request';
        }
        if (($request['method'] ?? 'Actions.getPageUrls') !== 'Actions.getPageUrls') {
            return 'different-api';
        }
        if (!in_array($request['flat'] ?? null, [true, 1, '1'], true)) {
            return 'not-flat';
        }
        $site = $request['idSite'] ?? null;
        if (!((is_int($site) && $site > 0) || (is_string($site) && preg_match('/\A[1-9][0-9]*\z/D', $site) === 1 && (string) (int) $site === $site))) {
            return 'non-single-site';
        }
        if (!in_array($request['period'] ?? null, ['day', 'week', 'month', 'year'], true)) {
            return 'unsupported-period';
        }
        $date = $request['date'] ?? null;
        if (
            !is_string($date) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return 'non-single-date';
        }
        if (isset($request['segment']) && $request['segment'] !== false && !is_string($request['segment'])) {
            return 'unsupported-segment';
        }
        foreach (
            ['idSubtable', 'depth', 'pivotBy', 'compare', 'include_aggregate_rows', 'show_dimensions',
            'disable_queued_filters', 'disable_root_datatable_post_processor', 'disablePostProcessing',
            'compareDates', 'comparePeriods', 'compareSegments', 'idSites', 'idGoal', 'secondaryDimension'] as $name
        ) {
            if (array_key_exists($name, $request) && !in_array($request[$name], [null, false, 0, '0', '', []], true)) {
                return 'unsupported-' . $name;
            }
        }
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
        $allowed = ['module', 'action', 'method', 'idSite', 'idsite', 'period', 'date', 'segment', 'expanded', 'idSubtable', 'depth',
            'flat', 'format', 'format_metrics', 'serialize', 'language', 'token_auth', 'force_api_session', 'cb',
            'filter_limit', 'filter_offset', 'filter_sort_column', 'filter_sort_order', 'filter_pattern', 'filter_column',
            'filter_pattern_recursive', 'filter_column_recursive', 'filter_excludelowpop', 'filter_excludelowpop_value',
            'filter_truncate', 'keep_summary_row', 'keep_totals_row', 'keep_totals_row_label', 'totals',
            'hideColumns', 'showColumns', 'showMetadata', 'translateColumnNames', 'disable_generic_filters',
            'fetch_archive_state', 'include_aggregate_rows', 'show_dimensions', 'compare', 'compareDates',
            'comparePeriods', 'compareSegments', 'pivotBy', 'disable_queued_filters',
            'disable_root_datatable_post_processor', 'disablePostProcessing', 'idSites', 'idGoal', 'secondaryDimension',
            'viewDataTable', 'isFooterExpandedInDashboard', 'uniqueId', 'widget', 'random', 'search_recursive', 'totalRows', 'showtitle', 'forceView'];
        foreach (array_keys($request) as $name) {
            if (!in_array($name, $allowed, true)) {
                return 'unknown-parameter';
            }
        }
        return null;
    }

    /** Capabilities, not source hashes, versions or exact loaded file paths. */
    public static function sourceCompatible(): bool
    {
        if (
            !is_callable([ActionsAPI::class, 'getInstance'])
            || !is_callable([Piwik::class, 'checkUserHasViewAccess'])
        ) {
            return false;
        }
        foreach (
            [DataTable::class => ['getRows', 'getTotalsRow', 'getAllTableMetadata', 'getId'],
            Row::class => ['getColumns', 'getMetadata', 'getSubtable', 'getIdSubDataTable']] as $class => $methods
        ) {
            foreach ($methods as $method) {
                if (!method_exists($class, $method) || !(new \ReflectionMethod($class, $method))->isPublic()) {
                    return false;
                }
            }
        }
        return method_exists(ActionsAPI::class, 'getPageUrls')
            && (new \ReflectionMethod(ActionsAPI::class, 'getPageUrls'))->isPublic();
    }
}
