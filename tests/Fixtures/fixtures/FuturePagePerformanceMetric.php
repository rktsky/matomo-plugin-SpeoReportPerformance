<?php
/** Own isolated test class. No original class or source is replaced. */
namespace Piwik\Plugins\PagePerformance\Columns\Metrics;

final class SpeoSyntheticFutureMetric extends AverageTimeNetwork
{
    public string $futureNativeState = 'must-never-be-persisted';
}
