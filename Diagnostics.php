<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

/** Capability information and fixed, process-local counters without report or user data. */
final class Diagnostics
{
    private const REASONS = [
        'cache_hit', 'cache_miss', 'cache_store_failed', 'cache_declined',
        'snapshot_declined', 'pipeline_declined', 'warmup_hint_recorded',
        'warmup_hint_skipped', 'warmup_storage_unavailable', 'warmup_manifest_busy',
        'warmup_manifest_invalid', 'warmup_job_failed', 'warmup_job_requested',
        'warmup_job_warm', 'warmup_job_skipped', 'warmup_job_busy',
    ];
    private static array $counts = [];

    /** Callers pass only fixed reason codes, never request values or exception messages. */
    public static function record(string $reason): void
    {
        if (!in_array($reason, self::REASONS, true)) {
            return;
        }
        $count = self::$counts[$reason] ?? 0;
        self::$counts[$reason] = $count < PHP_INT_MAX ? $count + 1 : $count;
    }

    public static function counters(): array
    {
        return array_replace(array_fill_keys(self::REASONS, 0), self::$counts);
    }

    /** No filesystem writes, request inspection or analytics API calls. */
    public static function report(?Configuration $configuration = null): array
    {
        $configuration ??= Configuration::get();
        return [
            'php_version' => PHP_VERSION,
            'worker_process_support' => function_exists('proc_open') && function_exists('proc_terminate'),
            'file_lock_support' => function_exists('flock'),
            'private_storage' => $configuration->storageStatus(),
            'configuration' => $configuration->describe(),
            'configuration_issues' => $configuration->issues(),
            // These are diagnostic observations of this process only. Separate
            // web requests and later console commands do not share counters.
            'counter_scope' => 'current_process',
            'counters' => self::counters(),
        ];
    }
}
