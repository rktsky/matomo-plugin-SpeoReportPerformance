<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\API\Request as ApiRequest;
use Piwik\DataTable;
use Piwik\DataTable\DataTableInterface;
use Piwik\NoAccessException;
use Piwik\Plugin\Manager;
use Piwik\Plugin\ReportsProvider;
use Piwik\Plugins\CoreVisualizations\Visualizations\HtmlTable;
use Piwik\Version;

/**
 * Optional ViewDataTable.configure.end handler, registered after=true.
 * Stages a weak, request-local view binding without reading report data.
 * Actual native API dispatch invokes loadForDispatch(). Uses public
 * setDataTable(), never replaces a visualization ID/class.
 * Native UI defaults, HTML formatting and priority/presentation filters remain
 * in Visualization::render(). Cold input is built once through BaseLoadScope,
 * whose root API plan returns full prepared input without generic processing.
 */
final class UiBasePreloader
{
    private static bool $busy = false;
    private static string $lastDecision = 'not-run';

    public static function getLastDecision(): string
    {
        return self::$lastDecision;
    }

    /** Constructors also run for preference validation; register no report data here. */
    public static function configureEnd($view): void
    {
        if (self::$busy || !is_object($view) || get_class($view) !== HtmlTable::class) {
            return;
        }
        try {
            if (
                !self::supportsNativeUi($view)
                || ApiRequest::isCurrentApiRequestTheRootApiRequest()
                || ApiRequest::isCurrentApiRequestNestedInAnotherApiRequest()
                || $view->requestConfig->getApiModuleToRequest() !== 'Actions'
                || $view->requestConfig->getApiMethodToRequest() !== 'getPageUrls'
                || $view->config->show_pivot_by_subtable !== false
            ) {
                return;
            }
            try {
                $view->getDataTable();
                return;
            } catch (\Exception $missingTable) {
                // Native getter throws until an input is supplied.
            }
            $request = UiRequestBuilder::build($view);
            if ($request === null || ($request['format'] ?? '') !== 'original') {
                return;
            }
            if (RequestEligibility::reason(new DataTable(), ['module' => 'Actions', 'action' => 'getPageUrls'], $request, Version::VERSION) !== null) {
                return;
            }
            if (UiPendingRequests::stage($view, $request)) {
                self::$lastDecision = 'ui-pending-dispatch';
            }
        } catch (\Throwable $error) {
            self::$lastDecision = 'native-pending-declined';
        }
    }

    /** Called only by the real native UI Proxy interception, after authentication. */
    public static function loadForDispatch($view): void
    {
        if (self::$busy || !is_object($view) || get_class($view) !== HtmlTable::class) {
            return;
        }
        try {
            if (!self::supportsNativeUi($view)) {
                self::$lastDecision = 'native-unreviewed-ui';
                return;
            }
            if (ApiRequest::isCurrentApiRequestTheRootApiRequest() || ApiRequest::isCurrentApiRequestNestedInAnotherApiRequest()) {
                self::$lastDecision = 'native-nested-ui';
                return;
            }
        } catch (\Throwable $error) {
            self::$lastDecision = 'native-unreviewed-ui';
            return;
        }
        // Never replace a table supplied by another intended configuration hook.
        try {
            $view->getDataTable();
            self::$lastDecision = 'native-existing-table';
            return;
        } catch (\Exception $missingTable) {
        /* Documented getter throws if no table is loaded. */
        }
        if (
            $view->requestConfig->getApiModuleToRequest() !== 'Actions'
            || $view->requestConfig->getApiMethodToRequest() !== 'getPageUrls'
        ) {
            return;
        }

        // PageUrls root/subtable dimensions are the same: its native pivot UI
        // is false. Preserve every other control shape by retaining native load.
        if ($view->config->show_pivot_by_subtable !== false) {
            self::$lastDecision = 'native-pivot-ui';
            return;
        }
        try {
            $request = UiRequestBuilder::build($view);
        } catch (\Throwable $error) {
            self::$lastDecision = 'native-ui-request';
            return;
        }
        if ($request === null) {
            self::$lastDecision = 'native-comparison-ui';
            return;
        }
        if (($request['format'] ?? '') !== 'original') {
            self::$lastDecision = 'native-ui-format';
            return;
        }
        // Bridge-only serialization flag; do not alter requestConfig or the
        // original request later rebuilt by native UI postprocessing.
        $request['serialize'] = '0';
        self::$busy = true;
        try {
            // Bind only the explicit bridge parameters for native consumers of
            // GET/POST. The owned scope restores presence and values in finally;
            // it does not depend on Matomo's internal Context helper.
            UiRequestContext::run($request, static function () use ($view, $request): void {
                if (!NativeSourceGuard::isReviewedPipeline() || !NativeSourceGuard::isReviewedCachePipeline()) {
                    self::$lastDecision = 'native-unreviewed-pipeline';
                    return;
                }
                $probe = new DataTable();
                $reason = RequestEligibility::reason($probe, ['module' => 'Actions', 'action' => 'getPageUrls'], $request, Version::VERSION);
                if ($reason !== null) {
                    self::$lastDecision = 'native-' . $reason;
                    return;
                }
                // Explicit native permission gate BEFORE any external cache read.
                RevisionContext::assertViewAccess($request);
                $context = RevisionContext::prepare($request, $request);
                if ($context === null) {
                    self::$lastDecision = 'native-no-context';
                    return;
                }
                if (PHP_SAPI !== 'cli') {
                    HotReportWarmup::record($context);
                }
                if (UiNumericPageCache::loadAttach($view, $context, $request)) {
                    self::$lastDecision = 'ui-numeric-hit-native-presentation';
                    return;
                }
                $base = ReportCache::loadBase($context);
                $hit = $base !== null;
                if (!$hit) {
                    // The coordinator's intended API-root scope returns the
                    // COMPLETE prepared table. It must not run generic filters,
                    // format rows, paginate, or write a finished API-page cache.
                    $prepared = BaseLoadScope::run(static fn() => (new ApiRequest($request, []))->process(), $context);
                } else {
                    $report = ReportsProvider::factory('Actions', 'getPageUrls');
                    if (
                        !$report || get_class($report) !== 'Piwik\\Plugins\\Actions\\Reports\\GetPageUrls'
                        || !$report->getDimension()
                    ) {
                        self::$lastDecision = 'native-custom-report';
                        return;
                    }
                    // The setter skips Proxy and hence its PagePerformance hook.
                    // Misses already ran that hook and NativeFlatInput once.
                    NativeEndHooks::enrich($base, $request);
                    $prepared = NativeFlatInput::prepare($base, str_replace('.', '_', $report->getDimension()->getId()));
                }
                if (!$prepared instanceof DataTableInterface) {
                    self::$lastDecision = 'native-invalid-base-input';
                    return;
                }
                // Native postDataTableLoadedFromAPI normally derives these from
                // the first-level report BEFORE flattening. Fresh root has the
                // same column keys: Actions/Goals/end handlers only change
                // labels, metadata or values of existing goal columns here.
                UiRequestBuilder::defaults($view, $context->getFreshRoot());
                $view->setDataTable($prepared);
                self::$lastDecision = $hit ? 'ui-base-hit-native-ui-processing' : 'ui-base-built-native-ui-processing';
            });
        } catch (NoAccessException $denied) {
            throw $denied;
        } catch (\Throwable $error) {
            self::$lastDecision = 'native-preload-error';
        } finally {
            self::$busy = false;
        }
    }

    /** Check the public extension contract, never the installed release/hash. */
    private static function supportsNativeUi(object $view): bool
    {
        foreach (['getDataTable', 'setDataTable', 'getRequestArray', 'isComparing'] as $method) {
            if (!is_callable([$view, $method])) {
                return false;
            }
        }
        if (
            !isset($view->config, $view->requestConfig)
            || !is_object($view->config) || !is_object($view->requestConfig)
        ) {
            return false;
        }
        foreach (['enable_sort', 'show_pivot_by_subtable', 'columns_to_display'] as $property) {
            if (!property_exists($view->config, $property)) {
                return false;
            }
        }
        foreach (['disable_queued_filters', 'filter_sort_column', 'filter_sort_order'] as $property) {
            if (!property_exists($view->requestConfig, $property)) {
                return false;
            }
        }
        foreach (['getApiModuleToRequest', 'getApiMethodToRequest'] as $method) {
            if (!is_callable([$view->requestConfig, $method])) {
                return false;
            }
        }
        foreach (['isCurrentApiRequestTheRootApiRequest', 'isCurrentApiRequestNestedInAnotherApiRequest'] as $method) {
            if (!is_callable([ApiRequest::class, $method])) {
                return false;
            }
        }
        // Another after-handler could still change the supplied table/request
        // after this cache key is built. Ordinary handlers have already run.
        foreach (Manager::getInstance()->getPluginsLoadedAndActivated() as $plugin) {
            if ($plugin->getPluginName() === 'SpeoReportPerformance') {
                continue;
            }
            $hook = $plugin->registerEvents()['ViewDataTable.configure.end'] ?? null;
            if (is_array($hook) && !empty($hook['after'])) {
                return false;
            }
        }
        return true;
    }
}
