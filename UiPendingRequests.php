<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

/** Bind actual UI dispatch to its view without loading constructor-only views. */
final class UiPendingRequests
{
    public const PARAMETER = 'speo_ui_request';
    private static array $pending = [];

    public static function stage(object $view, array $request): bool
    {
        foreach (self::$pending as $key => $state) {
            if ($state['view']->get() === null) {
                unset(self::$pending[$key]);
            }
        }
        $modify = $view->requestConfig->request_parameters_to_modify;
        if (
            !is_array($modify) || array_key_exists(self::PARAMETER, $modify)
            || count(self::$pending) >= 64
        ) {
            return false;
        }
        $parameters = NativeEndHooks::parameters($request);
        $token = bin2hex(random_bytes(24));
        self::$pending[$token] = ['view' => \WeakReference::create($view), 'parameters' => $parameters];
        // This documented request property binds identical reports to their
        // exact view. A temporary preference-validation view never dispatches.
        $modify[self::PARAMETER] = $token;
        $view->requestConfig->request_parameters_to_modify = $modify;
        return true;
    }

    /** Remove an unused binding before rendering a supplied or failed input. */
    public static function cancel(object $view): void
    {
        $modify = $view->requestConfig->request_parameters_to_modify;
        $token = is_array($modify) ? ($modify[self::PARAMETER] ?? null) : null;
        if (
            !is_string($token) || !isset(self::$pending[$token])
            || self::$pending[$token]['view']->get() !== $view
        ) {
            return;
        }
        unset(self::$pending[$token], $modify[self::PARAMETER]);
        $view->requestConfig->request_parameters_to_modify = $modify;
    }

    public static function take(array $parameters, array $rawRequest): ?object
    {
        $token = $rawRequest[self::PARAMETER] ?? null;
        if (!is_string($token) || !isset(self::$pending[$token])) {
            return null;
        }
        $state = self::$pending[$token];
        unset(self::$pending[$token]);
        $view = $state['view']->get();
        if ($view === null) {
            return null;
        }
        $modify = $view->requestConfig->request_parameters_to_modify;
        if (!is_array($modify) || ($modify[self::PARAMETER] ?? null) !== $token) {
            return null;
        }
        // Restore before cache keying, post-load change tracking or rendering.
        // Preserve unrelated changes made by another public configuration hook.
        unset($modify[self::PARAMETER]);
        $view->requestConfig->request_parameters_to_modify = $modify;
        if (
            array_diff_key($parameters, $state['parameters']) !== []
            || array_diff_key($state['parameters'], $parameters) !== []
        ) {
            return null;
        }
        foreach ($state['parameters'] as $name => $expected) {
            // Native Proxy converts documented scalar parameters. Compare
            // their values while accepting its string/int/bool normalization.
            if (
                (!is_scalar($parameters[$name]) && $parameters[$name] !== null)
                || $parameters[$name] != $expected
            ) {
                return null;
            }
        }
        return $view;
    }
}
