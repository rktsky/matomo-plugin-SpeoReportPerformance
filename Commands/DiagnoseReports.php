<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance\Commands;

use Piwik\Piwik;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\SpeoReportPerformance\Diagnostics;

/** Local administrator diagnostics; deliberately exposes no HTTP API or controller. */
final class DiagnoseReports extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('speo:reports-diagnose');
        $this->setDescription('Show privacy-safe report-cache and optional warmup capabilities.');
        $this->setHelp('Read-only diagnostics. Includes validated settings, capability status and counters for this process only. Does not print storage paths, target identifiers, report parameters, tokens or usernames.');
    }

    protected function doExecute(): int
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('CLI required');
        }
        // Matomo grants its local console commands super-user access. Keeping
        // the check explicit also protects nonstandard command integrations.
        Piwik::checkUserHasSuperUserAccess();
        $this->getOutput()->writeln(json_encode(Diagnostics::report(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
