<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\API\DataTablePostProcessor;
use Piwik\DataTable\DataTableInterface;

/**
 * Compatibility boundary for Matomo's native processing implementation.
 *
 * DataTablePostProcessor is public PHP, but is not a documented stable plugin
 * API. Keep this dependency explicit and confined here. Reusing the native
 * implementation preserves totals, metric formatting and generic filtering;
 * the plugin neither copies that pipeline nor modifies its source or registry.
 * Native-output integration tests must pass for every supported Matomo line.
 */
final class NativeProcessingAdapter
{
    public const PROCESSED_METRICS_COMPUTED_FLAG = 'processed_metrics_computed';

    public static function supported(): bool
    {
        return NativeSourceGuard::publicMethod(DataTablePostProcessor::class, 'process')
            && NativeSourceGuard::publicMethod(DataTablePostProcessor::class, 'applyTotalsCalculator');
    }

    public static function process(DataTableInterface $table, array $request): DataTableInterface
    {
        return (new DataTablePostProcessor('Actions', 'getPageUrls', $request))->process($table);
    }

    public static function totals(DataTableInterface $table, array $request): DataTableInterface
    {
        return (new DataTablePostProcessor('Actions', 'getPageUrls', $request))->applyTotalsCalculator($table);
    }
}
