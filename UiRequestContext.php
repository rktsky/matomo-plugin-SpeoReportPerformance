<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

/** Owned GET/POST scope for native API hooks; no dependency on core internals. */
final class UiRequestContext
{
    public static function run(array $request, callable $callback): mixed
    {
        $savedGet = $_GET;
        $savedPost = $_POST;
        $hadQuery = array_key_exists('QUERY_STRING', $_SERVER);
        $savedQuery = $_SERVER['QUERY_STRING'] ?? null;
        try {
            unset($_GET[UiPendingRequests::PARAMETER], $_POST[UiPendingRequests::PARAMETER]);
            foreach ($request as $name => $value) {
                $_GET[$name] = $value;
                $_POST[$name] = $value;
            }
            return $callback();
        } finally {
            $_GET = $savedGet;
            $_POST = $savedPost;
            if ($hadQuery) {
                $_SERVER['QUERY_STRING'] = $savedQuery;
            } else {
                unset($_SERVER['QUERY_STRING']);
            }
        }
    }
}
