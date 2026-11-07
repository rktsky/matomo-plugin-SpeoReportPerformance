<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

/** Own cache values only: never decodes or changes Matomo archive data. */
final class PrimitiveValueGuard
{
    public const MAX_BYTES = 268435456;
    public const MAX_NODES = 12000000;
    public const MAX_DEPTH = 48;

    private int $nodes = 0;
    private int $bytes = 0;

    /**
     * Values are inspected without invoking object, iterator or JSON callbacks.
     * Reference slots are rejected before descent, including scalar aliases and
     * array cycles. The same instance accumulates budgets across several rows.
     */
    public function check(mixed $value, int $depth = 0): bool
    {
        if (++$this->nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (\ReflectionReference::fromArrayElement($value, $key) !== null) {
                    return false;
                }
                $this->bytes += is_string($key) ? strlen($key) : 8;
                if ($this->bytes > self::MAX_BYTES || !$this->check($item, $depth + 1)) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($value)) {
            $this->bytes += strlen($value);
            return $this->bytes <= self::MAX_BYTES;
        }
        return $value === null || is_bool($value) || is_int($value)
            || (is_float($value) && is_finite($value));
    }

    /**
     * C-level conservative budget BEFORE json_decode allocates PHP arrays.
     * On valid JSON this never undercounts value nodes: ':' counts object
     * values, ',' extra array values, '[' array containers, plus the root.
     * Delimiters in strings and object commas only overcount. This function
     * does not assert JSON validity; the native JSON parser must still run.
     */
    public static function jsonBudgetSupported(string $json): bool
    {
        $length = strlen($json);
        return $length > 0 && $length <= self::MAX_BYTES
            && 1 + substr_count($json, ':') + substr_count($json, ',')
                + substr_count($json, '[') <= self::MAX_NODES;
    }
}
