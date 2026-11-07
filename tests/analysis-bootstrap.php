<?php

/** Autoload types only. This never starts a Matomo environment or reads a site. */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- A static-analysis bootstrap defines required native path constants.

require_once __DIR__ . '/bootstrap.php';

$root = getenv('MATOMO_ROOT');
if ($root === false || !is_file($root . '/vendor/autoload.php')) {
    throw new RuntimeException('Set MATOMO_ROOT to an original extracted Matomo distribution.');
}
define('PIWIK_INCLUDE_PATH', $root);
define('PIWIK_DOCUMENT_ROOT', $root);
define('PIWIK_USER_PATH', sys_get_temp_dir() . '/speo-static-analysis-unused');
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Piwik\\Plugins\\SpeoReportPerformance\\', SPEO_PLUGIN_ROOT, true);
