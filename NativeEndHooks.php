<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\DataTable;
use Piwik\Piwik;
use Piwik\Plugins\Actions\API as ActionsAPI;

/** Restore request-local enrichment through registered, documented observers. */
final class NativeEndHooks
{
    /** The outer UI Proxy supplies its fresh native metric definitions once. */
    public static function prepareForNativeDispatch(DataTable $table): void
    {
        $metrics = $table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME);
        if (!is_array($metrics)) {
            return;
        }
        // A prepared input can already carry definitions from its inner API
        // load. Native PagePerformance appends its definitions, so retaining
        // those objects would duplicate metadata at the outer dispatch. Keep
        // unrelated metrics and the existing metadata slot/order intact.
        $metrics = array_values(array_filter($metrics, static fn($metric): bool =>
            !$metric instanceof \Piwik\Plugin\ProcessedMetric
            || !str_starts_with(get_class($metric), 'Piwik\\Plugins\\PagePerformance\\Columns\\Metrics\\')));
        $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, $metrics);
    }

    public static function enrich(DataTable &$table, array $request): void
    {
        // Cache eligibility rejects unknown data-changing observers. Native
        // PagePerformance metric instances are recreated for this request;
        // their private callback names are deliberately not part of our API.
        $parameters = self::parameters($request);
        $info = ['className' => ActionsAPI::class, 'module' => 'Actions',
            'action' => 'getPageUrls', 'parameters' => $parameters];
        Piwik::postEvent('API.Request.dispatch.end', [&$table, $info]);
    }
    /** Resolve the current public report signature without renderer-only flags. */
    public static function parameters(array $request): array
    {
        $parameters = [];
        foreach ((new \ReflectionMethod(ActionsAPI::class, 'getPageUrls'))->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $request)) {
                $parameters[$name] = $request[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $parameters[$name] = $parameter->getDefaultValue();
            } else {
                throw new \InvalidArgumentException('Missing native report parameter');
            }
        }
        // Match the documented event shape; renderer flags are not API method
        // arguments. Site IDs are normalized at the preceding permission gate.
        $parameters['idSite'] = (int) $parameters['idSite'];
        return $parameters;
    }
}
