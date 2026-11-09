<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\Plugin;
use Piwik\Plugin\Manager;
use Piwik\Plugin\ReportsProvider;
use Piwik\Request;
use Piwik\Version;
use Piwik\API\Request as ApiRequest;

class SpeoReportPerformance extends Plugin
{
    private bool $busy = false;
    private string $lastDecision = 'not-run';
    private string $lastReadDecision = 'not-run';
    private array $lastTimings = [];
    private array $ownedRequests = [];
    private ?RevisionContext $basePublicationContext = null;
    private bool $basePrimitiveProof = false;
    private array $ownedInputIds = [];

    public function registerEvents()
    {
        // Default generic handlers (eg GAImporter and PagePerformance) must
        // finish inspecting/enriching the original hierarchy before this runs.
        return [
            'ViewDataTable.configure.end' => ['function' => 'preloadUiBase', 'after' => true],
            'Visualization.beforeRender' => ['function' => 'finishUiCache', 'after' => true],
            'Request.shouldDisablePostProcessing' => ['function' => 'preparePostProcessing', 'after' => true],
            'API.Request.intercept' => ['function' => 'readArchive', 'after' => true],
            'API.Request.dispatch.end' => ['function' => 'accelerate', 'after' => true],
            'SpeoControl.reportCopies.readiness' => 'reportCopyReadiness',
            'SpeoControl.reportCopies.erase' => 'eraseReportCopies',
        ];
    }

    /**
     * Supply read-only actual own-copy coverage to the independent control operator.
     * @param array $result Reference DTO created by the owned caller, never an HTTP input.
     * @return void Missing legacy baseline remains explicitly unverified.
     * @since 1.3.0
     */
    public function reportCopyReadiness(array &$result): void
    {
        $result = CopyLifecycle::readiness();
    }

    /**
     * Evict only the caller's positively sealed physical property through native cache APIs.
     * @param int $nativeId Original control-validated physical scope.
     * @param array $result Reference receipt populated only after native absence readback.
     * @return void Errors propagate to keep the control's completion journal open.
     * @since 1.3.0
     */
    public function eraseReportCopies(int $nativeId, array &$result): void
    {
        $result = CopyLifecycle::erase($nativeId);
    }

    public function getLastDecision(): string
    {
        return $this->lastDecision;
    }
    public function getLastReadDecision(): string
    {
        return $this->lastReadDecision;
    }
    public function getLastTimings(): array
    {
        return $this->lastTimings;
    }
    public function preloadUiBase($view): void
    {
        UiBasePreloader::configureEnd($view);
    }
    public function finishUiCache($view): void
    {
        UiPendingRequests::cancel($view);
        UiNumericPageCache::finish($view);
    }

    /**
     * Matomo's documented hook lets this plugin run the SAME native
     * processing adapter once, after the ordinary API end handlers. No authentication
     * or data access occurs here: Request calls this before authenticating.
     */
    public function preparePostProcessing(&$disabled, array $request): void
    {
        if (!ApiRequest::isCurrentApiRequestTheRootApiRequest()) {
            return;
        }
        $this->ownedRequests = [];
        if ($disabled) {
            return;
        }
        try {
            if (
                !self::cacheRequestShape($request) || !NativeSourceGuard::isReviewedPipeline()
                || $this->conflictingPluginHook() !== null || !NativeSourceGuard::isReviewedCachePipeline()
            ) {
                return;
            }
            $this->ownedRequests[0] = ['request' => $request, 'context' => null, 'hit' => false, 'base_hit' => false, 'primitive_tree' => false, 'owned_ids' => [], 'base_only' => BaseLoadScope::isActive()];
            $disabled = true;
        } catch (\Throwable $error) {
            // Leave the original ResponseBuilder in charge if preparation fails.
        }
    }

    private static function cacheRequestShape(array $request): bool
    {
        if (
            ($request['method'] ?? '') !== 'Actions.getPageUrls'
            || ($request['period'] ?? '') !== 'month'
            || !in_array($request['segment'] ?? false, [null, false, ''], true)
        ) {
            return false;
        }
        $limit = $request['filter_limit'] ?? \Piwik\Config::getInstance()->General['API_datatable_default_limit'];
        if (filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < 1 || (int) $limit > 1000) {
            return false;
        }
        $date = $request['date'] ?? null;
        if (!is_string($date) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date) !== 1) {
            return false;
        }
        $reason = RequestEligibility::reason(new DataTable(), ['module' => 'Actions', 'action' => 'getPageUrls'], $request, Version::VERSION);
        return $reason === null;
    }

    /** Intended API interception; native authentication and end hooks remain active. */
    public function readArchive(&$value, array $parameters, string $module, string $method, array $rawRequest): void
    {
        if ($value !== null || $module !== 'Actions' || $method !== 'getPageUrls' || $this->busy) {
            return;
        }
        if (
            !ApiRequest::isCurrentApiRequestTheRootApiRequest()
            && !ApiRequest::isCurrentApiRequestNestedInAnotherApiRequest()
        ) {
            $view = UiPendingRequests::take($parameters, $rawRequest);
            if ($view !== null) {
                UiBasePreloader::loadForDispatch($view);
                try {
                    $prepared = $view->getDataTable();
                    if ($prepared instanceof \Piwik\DataTable\DataTableInterface) {
                        if ($prepared instanceof DataTable) {
                            NativeEndHooks::prepareForNativeDispatch($prepared);
                        }
                        $value = $prepared;
                    }
                } catch (\Exception $missingTable) {
                    // An optional preload decline leaves the native Proxy call intact.
                }
            }
            return;
        }
        if (!ApiRequest::isCurrentApiRequestTheRootApiRequest() || !isset($this->ownedRequests[0])) {
            return;
        }
        $request = array_replace($rawRequest, $parameters);
        $request['filter_limit'] ??= \Piwik\Config::getInstance()->General['API_datatable_default_limit'];
        $info = ['module' => $module, 'action' => $method];
        $reason = RequestEligibility::reason(new DataTable(), $info, $request, Version::VERSION);
        if ($reason !== null) {
            $this->lastReadDecision = $reason;
            return;
        }
        if (!NativeSourceGuard::isReviewedPipeline()) {
            $this->lastReadDecision = 'unreviewed-pipeline';
            return;
        }
        if (($conflict = $this->conflictingPluginHook()) !== null) {
            $this->lastReadDecision = $conflict;
            return;
        }
        $report = ReportsProvider::factory('Actions', 'getPageUrls');
        if (!$report || get_class($report) !== 'Piwik\\Plugins\\Actions\\Reports\\GetPageUrls') {
            $this->lastReadDecision = 'custom-report-definition';
            return;
        }
        if (ApiRequest::isCurrentApiRequestTheRootApiRequest() && isset($this->ownedRequests[0]) && self::cacheRequestShape($request)) {
            $start = hrtime(true);
            // This point is AFTER native authentication. prepare() also checks
            // site view permission before reading revisions or fetching a hit.
            $context = RevisionContext::prepare($request, $this->ownedRequests[0]['request']);
            $scoped = BaseLoadScope::context();
            if ($scoped !== null && $context !== null) {
                // Preserve the revision captured BEFORE the first native root
                // lookup. Native request-local archive-ID caches may otherwise
                // outlive a publication between the UI and its API handoff.
                $before = $scoped->keyMaterial();
                $after = $context->keyMaterial();
                $context = $before['request'] === $after['request'] && $before['environment'] === $after['environment'] ? $scoped : null;
            }
            $this->ownedRequests[0]['context'] = $context;
            if ($context !== null) {
                if (PHP_SAPI !== 'cli') {
                    HotReportWarmup::record($context);
                }
                $hit = $this->ownedRequests[0]['base_only'] ? null : ReportCache::load($context);
                if ($hit !== null) {
                    $this->ownedRequests[0]['hit'] = true;
                    $this->lastTimings['cache_lookup_seconds'] = (hrtime(true) - $start) / 1e9;
                    $this->lastReadDecision = 'revision-cache-hit';
                    $value = $hit;
                    return;
                }
                $base = ReportCache::loadBase($context);
                if ($base !== null) {
                    $this->ownedRequests[0]['base_hit'] = true;
                    $this->lastTimings['cache_lookup_seconds'] = (hrtime(true) - $start) / 1e9;
                    $this->lastReadDecision = 'revision-base-cache-hit';
                    $value = $base;
                    return;
                }
            }
            $this->lastTimings['cache_lookup_seconds'] = (hrtime(true) - $start) / 1e9;
        }
        $start = hrtime(true);
        $reader = new NativeArchiveReader();
        // The reader checks permission before accessing any archive. A denied
        // access exception propagates through Matomo's ordinary API response.
        $result = $reader->tryRead($request);
        $this->lastTimings['reader_seconds'] = (hrtime(true) - $start) / 1e9;
        $this->lastTimings['reader_detail_ms'] = $reader->getLastTimings();
        $this->lastReadDecision = $reader->getLastDecision();
        if (ApiRequest::isCurrentApiRequestTheRootApiRequest() && isset($this->ownedRequests[0])) {
            $this->ownedRequests[0]['primitive_tree'] = $reader->usedPrimitiveArchiveOnly();
            $this->ownedRequests[0]['owned_ids'] = $reader->getOwnedTableIds();
        }
        if ($result !== null) {
            $value = $result;
        }
    }

    public function accelerate(&$value, array $info): void
    {
        if (
            ApiRequest::isCurrentApiRequestTheRootApiRequest() && isset($this->ownedRequests[0])
            && ($info['module'] ?? '') === 'Actions' && ($info['action'] ?? '') === 'getPageUrls'
        ) {
            $plan = $this->ownedRequests[0];
            $this->ownedRequests = [];
            if ($plan['hit']) {
                $this->lastDecision = 'revision-cache-hit';
                return;
            }
            if ($plan['base_hit']) {
                $report = ReportsProvider::factory('Actions', 'getPageUrls');
                $dimension = str_replace('.', '_', $report->getDimension()->getId());
                $value = NativeFlatInput::prepare($value, $dimension);
                $this->lastDecision = 'revision-base-cache-hit';
            } else {
                $this->basePublicationContext = $plan['context'];
                $this->basePrimitiveProof = $plan['primitive_tree'];
                $this->ownedInputIds = $plan['owned_ids'];
                try {
                    if ($plan['primitive_tree']) {
                        $this->accelerateTree($value, $info, $plan['request']);
                    } else {
                        $this->lastDecision = 'native-nonprimitive-input';
                    }
                } finally {
                    $this->basePublicationContext = null;
                    $this->basePrimitiveProof = false;
                    $this->ownedInputIds = [];
                }
            }
            if ($plan['base_only']) {
                // The native visualization will run its own HTML formatter,
                // priority/presentation callbacks and postprocessing exactly once.
                $this->lastDecision .= '-ui-base';
                if ($value instanceof DataTable) {
                    BaseLoadScope::markPrepared($value);
                }
                return;
            }
            if ($value instanceof \Piwik\DataTable\DataTableInterface) {
                $start = hrtime(true);
                // Identical native processor and raw request as ResponseBuilder.
                // Empty segments ensure its disabled-path rounding is a no-op.
                $value = NativeProcessingAdapter::process($value, $plan['request']);
                $this->lastTimings['native_postprocessing_seconds'] = (hrtime(true) - $start) / 1e9;
                if ($plan['context'] !== null && $value instanceof DataTable) {
                    $start = hrtime(true);
                    $stored = ReportCache::save($plan['context'], $value);
                    $this->lastTimings['cache_save_seconds'] = (hrtime(true) - $start) / 1e9;
                    $this->lastDecision .= $stored ? '-cache-stored' : '-cache-declined';
                }
            }
            return;
        }
        $this->lastDecision = 'native-path';
    }

    private function accelerateTree(&$value, array $info, ?array $postProcessingRequest = null): void
    {
        if ($this->busy) {
            return;
        }
        $rawRequest = Request::fromRequest()->getParameters();
        $effective = array_replace($rawRequest, $info['parameters'] ?? []);
        $effective['filter_limit'] ??= \Piwik\Config::getInstance()->General['API_datatable_default_limit'];
        $reason = RequestEligibility::reason($value, $info, $effective, Version::VERSION);
        if ($reason !== null) {
            $this->lastDecision = $reason;
            return;
        }
        // Native postprocessing tests parameter PRESENCE (eg idSubtable), not
        // just values. Never inject API signature defaults into this request.
        $request = $postProcessingRequest ?? $rawRequest;
        if (!NativeSourceGuard::isReviewedPipeline()) {
            $this->lastDecision = 'unreviewed-pipeline';
            return;
        }
        // A direct Proxy::call has no later ResponseBuilder pass to finish this input.
        if (
            !ApiRequest::isCurrentApiRequestTheRootApiRequest()
            && !ApiRequest::isCurrentApiRequestNestedInAnotherApiRequest()
        ) {
            $this->lastDecision = 'outside-native-response-pipeline';
            return;
        }
        try {
            $start = hrtime(true);
            $conflict = $this->conflictingPluginHook();
            if ($conflict !== null) {
                $this->lastDecision = $conflict;
                return;
            }
            $reason = TreeGuard::reason($value);
            if ($reason !== null) {
                $this->lastDecision = $reason;
                return;
            }
            $this->lastTimings['tree_guard_seconds'] = (hrtime(true) - $start) / 1e9;
        } catch (\Throwable $e) {
            $this->lastDecision = 'native-fallback-guard';
            return;
        }

        $this->busy = true;
        $committed = false;
        $copyIds = [];
        $flat = null;
        try {
            $report = ReportsProvider::factory('Actions', 'getPageUrls');
            if (!$report || get_class($report) !== 'Piwik\\Plugins\\Actions\\Reports\\GetPageUrls') {
                $this->lastDecision = 'custom-report-definition';
                return;
            }
            $dimension = $report->getDimension();
            if (!$dimension) {
                $this->lastDecision = 'missing-report-dimension';
                return;
            }
            $start = hrtime(true);
            $working = TreeGuard::copy($value);
            $copyIds = TreeGuard::copiedIds();
            $this->lastTimings['copy_seconds'] = (hrtime(true) - $start) / 1e9;
            // This remains before flattening, exactly as in the core pipeline.
            // Existing totals metadata causes the later native calculation to skip.
            $start = hrtime(true);
            $working = NativeProcessingAdapter::totals($working, $request);
            $this->lastTimings['totals_seconds'] = (hrtime(true) - $start) / 1e9;
            $converter = new LinearFlattener('Actions', 'getPageUrls', $request);
            $start = hrtime(true);
            $flat = $converter->flatten($working, $report->getRecursiveLabelSeparator(), false);
            $this->lastTimings['flatten_seconds'] = (hrtime(true) - $start) / 1e9;
            if ($this->basePublicationContext !== null && $this->basePrimitiveProof) {
                $start = hrtime(true);
                ReportCache::saveBaseAfterResponse($this->basePublicationContext, $flat);
                $this->lastTimings['freeze_base_seconds'] = (hrtime(true) - $start) / 1e9;
            }
            $start = hrtime(true);
            $value = NativeFlatInput::prepare($flat, str_replace('.', '_', $dimension->getId()));
            $this->lastTimings['prepare_seconds'] = (hrtime(true) - $start) / 1e9;
            $this->lastDecision = 'accelerated';
            $committed = true;
        } catch (\Throwable $e) {
            // Only an owned copy was touched. Preserve the original native path.
            // Do not log report content, request parameters or tokens.
            $this->lastDecision = 'native-fallback-' . get_class($e);
        } finally {
            $this->busy = false;
        }
        if ($committed && $this->basePublicationContext !== null && $this->basePrimitiveProof) {
            // A committed result cannot fall back to the now superseded input.
            // Cleanup failure merely leaves memory for core request cleanup.
            try {
                $start = hrtime(true);
                OwnedTableMemory::releaseExact(
                    $this->ownedInputIds,
                    $copyIds,
                    [$value->getId(), $flat->getId(), $this->basePublicationContext->getFreshRoot()->getId()]
                );
                $this->lastTimings['release_owned_seconds'] = (hrtime(true) - $start) / 1e9;
            } catch (\Throwable $error) {
/* Preserve the valid committed result. */
            }
        }
    }

    private function conflictingPluginHook(): ?string
    {
        foreach (Manager::getInstance()->getPluginsLoadedAndActivated() as $plugin) {
            if ($plugin === $this || $plugin->getPluginName() === 'SpeoReportPerformance') {
                continue;
            }
            $hooks = $plugin->registerEvents();
            if (
                isset($hooks['Request.shouldDisablePostProcessing'])
                && !NativeSourceGuard::isReviewedPrivacyManagerDecision($plugin, $hooks['Request.shouldDisablePostProcessing'])
            ) {
                return 'custom-postprocessing-decision';
            }
            foreach (['API.Request.dispatch.end', 'API.Request.intercept'] as $event) {
                $hook = $hooks[$event] ?? null;
                if (is_array($hook) && !empty($hook['after'])) {
                    return 'other-after-plugin';
                }
            }
        }
        return null;
    }
}
