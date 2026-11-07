<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

// Register through Matomo's documented diagnostic extension point.
return [
    'diagnostics.optional' => Piwik\DI::add([
        Piwik\DI::get(Piwik\Plugins\SpeoReportPerformance\Diagnostic\WarmupCheck::class),
    ]),
];
