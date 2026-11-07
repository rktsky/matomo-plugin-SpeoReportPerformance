<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

/** Request-local handoff of canonical input to the documented setDataTable API. */
final class BaseLoadScope
{
    private static int $depth = 0;
    private static ?\Piwik\DataTable $produced = null;
    private static ?RevisionContext $context = null;
    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
    public static function context(): ?RevisionContext
    {
        return self::$context;
    }
    public static function markPrepared(\Piwik\DataTable $table): void
    {
        if (self::isActive()) {
            self::$produced = $table;
        }
    }
    public static function run(callable $load, ?RevisionContext $context = null): mixed
    {
        $previous = self::$produced;
        $previousContext = self::$context;
        self::$context = $context;
        self::$produced = null;
        ++self::$depth;
        try {
            $result = $load();
            return $result instanceof \Piwik\DataTable && $result === self::$produced ? $result : null;
        } finally {
            --self::$depth;
            self::$produced = $previous;
            self::$context = $previousContext;
        }
    }
}
