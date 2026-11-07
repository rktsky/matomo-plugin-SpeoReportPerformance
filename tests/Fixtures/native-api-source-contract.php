<?php
/** Source/signature check only. No API invocation, application bootstrap or database. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
speoAssertIsolated();
speoFixtureBootstrap('native-api-source-contract');
require PIWIK_INCLUDE_PATH . '/vendor/autoload.php';
$method = new ReflectionMethod(Piwik\Plugins\Actions\API::class, 'getPageUrls');
$parameters = $method->getParameters();
$names = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $parameters);
if (!$method->isPublic() || $names !== ['idSite', 'period', 'date', 'segment', 'expanded', 'idSubtable', 'depth', 'flat']) {
    throw new RuntimeException('Review current public Actions API signature');
}
foreach ($parameters as $parameter) {
    if ($parameter->isPassedByReference() || $parameter->isVariadic()) { throw new RuntimeException('Unsupported signature'); }
}
$source = file_get_contents($method->getFileName());
if (!str_contains($source, 'Piwik::checkUserHasViewAccess($idSite)')
    || !str_contains($source, '$this->filterActionsDataTable($dataTable, Action::TYPE_PAGE_URL)')) {
    throw new RuntimeException('Review native permission/filter delegation');
}
$proxy = file_get_contents(PIWIK_INCLUDE_PATH . '/core/API/Proxy.php');
$intercept = strpos($proxy, "Piwik::postEvent('API.Request.intercept'");
$invoke = strpos($proxy, 'if ($returnedValue === null)', $intercept);
$specific = strpos($proxy, "Piwik::postEvent(sprintf('API.%s.%s.end'", $invoke);
$generic = strpos($proxy, "Piwik::postEvent('API.Request.dispatch.end'", $specific);
if ($intercept === false || $invoke === false || $specific === false || $generic === false
    || !($intercept < $invoke && $invoke < $specific && $specific < $generic)) {
    throw new RuntimeException('Review native interception/end-hook order');
}
echo "OK native public Actions signature (8 named parameters), permission/Actions filter delegation, Proxy end-hook source order; no native API call\n";
