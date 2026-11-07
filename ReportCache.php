<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\Cache;
use Piwik\DataTable;

/** Small finished report pages in Matomo's configured cache backend. */
final class ReportCache
{
    public static function load(RevisionContext $context): ?DataTable
    {
        try {
            $payload = Cache::getLazyCache()->fetch($context->getKey());
            if (!is_string($payload)) {
                Diagnostics::record('cache_miss');
                return null;
            }
            $table = ProcessedTableSnapshot::decode($payload, $context->getFreshRoot());
            // The revision must still be current after payload reconstruction.
            if ($table !== null && $context->isCurrent()) {
                Diagnostics::record('cache_hit');
                return $table;
            }
        } catch (\Throwable $error) {
            // Cache failures must never become report failures.
        }
        return null;
    }

    public static function save(RevisionContext $context, DataTable $table): bool
    {
        try {
            $payload = ProcessedTableSnapshot::encode($table);
            if ($payload === null || !$context->isCurrent()) {
                return false;
            }
            return (bool) Cache::getLazyCache()->save($context->getKey(), $payload, Configuration::get()->cacheTtl());
        } catch (\Throwable $error) {
            return false;
        }
    }

    public static function loadBase(RevisionContext $context): ?DataTable
    {
        try {
            $payload = Cache::getLazyCache()->fetch($context->getBaseKey());
            if (!is_string($payload)) {
                Diagnostics::record('cache_miss');
                return null;
            }
            $table = FlatTableSnapshot::decode($payload, $context->getFreshRoot());
            $valid = $table !== null && $context->isCurrent();
            Diagnostics::record($valid ? 'cache_hit' : 'cache_declined');
            return $valid ? $table : null;
        } catch (\Throwable $error) {
            return null;
        }
    }

    public static function saveBaseAfterResponse(RevisionContext $context, DataTable $table): void
    {
        $frozen = FlatTableSnapshot::freeze($table);
        if ($frozen === null) {
            return;
        }
        $authorizedCli = PHP_SAPI === 'cli' && \Piwik\Piwik::hasUserSuperUserAccess();
        // Publish only after the response is finished. No FPM worker is forked,
        // and the same native revision/permission check applies at publication.
        register_shutdown_function(static function () use ($context, $frozen, $authorizedCli): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $publish = static function () use ($context, $frozen): void {
                try {
                    $payload = FlatTableSnapshot::encodeFrozen($frozen);
                    if ($payload !== null && $context->isCurrent()) {
                        Cache::getLazyCache()->save($context->getBaseKey(), $payload, Configuration::get()->cacheTtl());
                    }
                } catch (\Throwable $error) {
                    Diagnostics::record('cache_store_failed');
                    // Optional cache work must not affect the response.
                }
            };
            // Native Console restores its auth scope before PHP shutdown.
            // Retain only an already-authorized local CLI publication scope.
            // FPM never gains privileges here; every reader checks view access.
            if ($authorizedCli) {
                \Piwik\Access::doAsSuperUser($publish);
            } else {
                $publish();
            }
        });
    }
}
