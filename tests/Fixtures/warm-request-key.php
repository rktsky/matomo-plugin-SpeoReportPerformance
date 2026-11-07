<?php
/** Private native-independent key equivalence regression. Never deploy. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
    speoAssertIsolated();
require SPEO_PLUGIN_ROOT . '/RevisionContext.php';
use Piwik\Plugins\SpeoReportPerformance\RevisionContext;
function contextFor(array $request): RevisionContext
{
    $reflection = new ReflectionClass(RevisionContext::class);
    $context = $reflection->newInstanceWithoutConstructor();
    foreach (['request' => $request, 'environment' => ['principal_sha256' => 'fixture'], 'vector' => ['fixture' => 1]] as $key => $value) {
        $reflection->getProperty($key)->setValue($context, $value);
    }
    return $context;
}
$request = ['method' => 'Actions.getPageUrls', 'idSite' => 1, 'period' => 'month', 'date' => '2026-10-01',
    'flat' => 1, 'format' => 'original', 'serialize' => '0', 'format_metrics' => '1', 'totals' => 1,
    'keep_totals_row' => 1, 'filter_limit' => 100, 'filter_offset' => 500, 'filter_sort_column' => false,
    'filter_sort_order' => 'desc', 'filter_pattern' => 'private-fixture-search', 'widget' => '', 'random' => '123'];
$context = contextFor($request);
$warm = $context->getWarmRequest();
if (isset($warm['filter_pattern']) || $warm['filter_limit'] !== 500 || $warm['filter_offset'] !== 0
    || $context->getBaseKey() !== contextFor($warm)->getBaseKey()) { throw new RuntimeException('Warm key mismatch'); }
$checked = 1;
foreach ([false, null, '', 0, '0', 1, [], 'fixture'] as $subtable) {
    if (contextFor($request + ['idSubtable' => $subtable])->getWarmRequest() !== []) {
        throw new RuntimeException('Subtable-presence request must not be warmed with changed filters');
    }
    ++$checked;
}
echo json_encode(['cases' => $checked, 'passed' => true]) . "\n";
