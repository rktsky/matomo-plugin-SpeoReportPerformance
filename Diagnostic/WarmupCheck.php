<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance\Diagnostic;

use Piwik\Plugins\Diagnostics\Diagnostic\Diagnostic;
use Piwik\Plugins\Diagnostics\Diagnostic\DiagnosticResult;
use Piwik\Plugins\SpeoReportPerformance\Diagnostics;

/** Optional native system check: ordinary report processing does not require warmup. */
final class WarmupCheck implements Diagnostic
{
    public function execute(): array
    {
        try {
            $report = Diagnostics::report();
            if (!$report['configuration']['warmup_enabled']) {
                return [DiagnosticResult::informationalResult('Speo report warmup', 'Disabled in [SpeoReportPerformance].')];
            }
            $ready = $report['worker_process_support'] && $report['file_lock_support']
                && in_array($report['private_storage'], ['available', 'available_on_first_use'], true)
                && $report['configuration_issues'] === [];
            $comment = $ready
                ? 'Private storage and bounded worker support are available. Run speo:reports-warm as the same operating-system user as PHP.'
                : 'Optional warmup needs writable private storage outside the Matomo document root, PHP process support and valid [SpeoReportPerformance] settings. Run speo:reports-diagnose for fixed status codes; use one shared warmup_storage_path when PHP and cron have separate temporary namespaces.';
            return [DiagnosticResult::singleResult('Speo report warmup', $ready ? DiagnosticResult::STATUS_OK : DiagnosticResult::STATUS_WARNING, $comment)];
        } catch (\Throwable $error) {
            return [DiagnosticResult::singleResult(
                'Speo report warmup',
                DiagnosticResult::STATUS_WARNING,
                'Optional warmup capabilities could not be inspected. Check [SpeoReportPerformance] settings and run speo:reports-diagnose.'
            )];
        }
    }
}
