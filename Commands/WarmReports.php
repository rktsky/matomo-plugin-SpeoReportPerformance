<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\SpeoReportPerformance\HotReportWarmup;

final class WarmReports extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('speo:reports-warm');
        $this->setDescription('Warm missing revision-bound bases for recently viewed page reports.');
        $this->setHelp('Defaults: check at most 20 recorded targets and rebuild at most 3 missing bases in fresh sequential PHP workers within 120 seconds. Configure limits in [SpeoReportPerformance]. Run as the PHP operating-system user with access to the same private warmup_storage_path. Existing or ineligible bases do not use rebuild slots. No archive generation, date shifting or tokens.');
        $this->addRequiredValueOption('target', null, 'Internal single-target worker: opaque manifest identifier.');
    }

    protected function doExecute(): int
    {
        $target = $this->getInput()->getOption('target');
        if ($target !== null && (!is_string($target) || preg_match('/\A[a-f0-9]{64}\z/D', $target) !== 1)) {
            throw new \InvalidArgumentException('Invalid warmup target');
        }
        $result = HotReportWarmup::run($target);
        $this->getOutput()->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return ($result['status'] ?? '') === 'failed' || ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
