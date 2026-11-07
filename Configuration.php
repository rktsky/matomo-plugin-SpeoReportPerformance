<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\Config;

/** Validated, installation-local options from Matomo's public INI configuration API. */
final class Configuration
{
    private array $values = [];
    private array $issues = [];
    private string $installationPath;
    private string $temporaryPath;

    /** Explicit inputs make validation testable without bootstrapping Matomo. */
    public function __construct(array $values = [], ?string $installationPath = null, ?string $temporaryPath = null)
    {
        $this->installationPath = $installationPath ?? (defined('PIWIK_USER_PATH') ? PIWIK_USER_PATH : PIWIK_DOCUMENT_ROOT);
        $this->temporaryPath = $temporaryPath ?? sys_get_temp_dir();
        $this->values['cache_ttl'] = $this->integer($values, 'cache_ttl', 86400, 60, 604800);
        $this->values['warmup_max_jobs'] = $this->integer($values, 'warmup_max_jobs', 3, 1, 20);
        $this->values['warmup_max_seconds'] = $this->integer($values, 'warmup_max_seconds', 120, 1, 900);
        $this->values['warmup_max_targets'] = $this->integer($values, 'warmup_max_targets', 20, 1, 100);
        $this->values['warmup_target_max_age'] = $this->integer($values, 'warmup_target_max_age', 604800, 3600, 2592000);
        $this->values['warmup_record_interval'] = $this->integer($values, 'warmup_record_interval', 300, 1, 3600);
        $enabled = $values['warmup_enabled'] ?? 1;
        $this->values['warmup_enabled'] = in_array($enabled, [true, 1, '1'], true);
        if (!in_array($enabled, [true, false, 1, 0, '1', '0'], true)) {
            $this->issues[] = 'invalid_warmup_enabled';
        }
        $memory = $values['warmup_memory_limit'] ?? '2G';
        if (
            !is_string($memory) || preg_match('/\A([1-9][0-9]{0,4})([MG])\z/Di', $memory, $parts) !== 1
            || ($megabytes = (int)$parts[1] * (strtoupper($parts[2]) === 'G' ? 1024 : 1)) < 64 || $megabytes > 65536
        ) {
            $memory = '2G';
            $this->issues[] = 'invalid_warmup_memory_limit';
        }
        $this->values['warmup_memory_limit'] = strtoupper($memory);
        $path = $values['warmup_storage_path'] ?? '';
        if (!is_string($path) || str_contains($path, "\0")) {
            $this->issues[] = 'invalid_warmup_storage_path';
            $path = null;
        }
        $this->values['warmup_storage_path'] = $path;
    }

    public static function get(): self
    {
        // An absent plugin section is the normal default configuration.
        try {
            $values = Config::getInstance()->SpeoReportPerformance;
        } catch (\Throwable $error) {
            $values = [];
        }
        return new self(is_array($values) ? $values : []);
    }

    public function cacheTtl(): int
    {
        return $this->values['cache_ttl'];
    }
    public function warmupEnabled(): bool
    {
        return $this->values['warmup_enabled'];
    }
    public function warmupMemoryLimit(): string
    {
        return $this->values['warmup_memory_limit'];
    }
    public function warmupMaxJobs(): int
    {
        return $this->values['warmup_max_jobs'];
    }
    public function warmupMaxSeconds(): int
    {
        return $this->values['warmup_max_seconds'];
    }
    public function warmupMaxTargets(): int
    {
        return $this->values['warmup_max_targets'];
    }
    public function warmupTargetMaxAge(): int
    {
        return $this->values['warmup_target_max_age'];
    }
    public function warmupRecordInterval(): int
    {
        return $this->values['warmup_record_interval'];
    }
    public function issues(): array
    {
        return $this->issues;
    }

    /** Settings safe to copy into a bug report; never include installation paths. */
    public function describe(): array
    {
        $values = $this->values;
        $values['warmup_storage_path'] = $values['warmup_storage_path'] === '' ? 'automatic' : 'configured';
        return $values;
    }

    /**
     * Default state lives outside the web root, without assuming a hosting layout.
     * Services with separate temporary namespaces must configure one shared private path.
     */
    public function storagePath(): string
    {
        $path = $this->values['warmup_storage_path'];
        if ($path === null) {
            throw new \RuntimeException('Invalid warmup storage configuration');
        }
        if ($path === '') {
            $identity = realpath($this->installationPath) ?: $this->installationPath;
            $path = rtrim($this->temporaryPath, '/\\') . '/matomo-speo-report-performance-' . substr(hash('sha256', $identity), 0, 24);
        }
        if (!self::isAbsolute($path) || is_link($path)) {
            throw new \RuntimeException('Warmup storage must be an absolute non-symlink path');
        }
        $resolved = self::resolvePath($path);
        $webRoot = self::resolvePath(defined('PIWIK_DOCUMENT_ROOT') ? PIWIK_DOCUMENT_ROOT : $this->installationPath);
        if ($resolved === $webRoot || str_starts_with($resolved . '/', rtrim($webRoot, '/') . '/')) {
            throw new \RuntimeException('Warmup storage must be outside the Matomo document root');
        }
        return $resolved;
    }

    /** Read-only capability probe; does not create directories or expose their names. */
    public function storageStatus(): string
    {
        if (!$this->warmupEnabled()) {
            return 'disabled';
        }
        try {
            $path = $this->storagePath();
            if (file_exists($path)) {
                if (!is_dir($path)) {
                    return 'invalid_path';
                }
                if (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) {
                    return 'owner_mismatch';
                }
                if ((fileperms($path) & 0077) !== 0) {
                    return 'permissions_unsafe';
                }
                return is_readable($path) && is_writable($path) ? 'available' : 'unwritable';
            }
            $parent = dirname($path);
            while (!file_exists($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }
            return is_dir($parent) && is_writable($parent) ? 'available_on_first_use' : 'unwritable';
        } catch (\Throwable $error) {
            return 'invalid_path';
        }
    }

    private function integer(array $values, string $key, int $default, int $minimum, int $maximum): int
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }
        $value = $values[$key];
        if (
            (is_int($value) || is_string($value) && preg_match('/\A[0-9]{1,10}\z/D', $value) === 1)
            && (int)$value >= $minimum && (int)$value <= $maximum
        ) {
            return (int)$value;
        }
        $this->issues[] = 'invalid_' . $key;
        return $default;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    /** Resolve existing parents too, so an alias cannot hide a path inside the web root. */
    private static function resolvePath(string $path): string
    {
        $tail = [];
        while (($resolved = realpath($path)) === false) {
            $parent = dirname($path);
            if ($parent === $path || in_array(basename($path), ['.', '..'], true)) {
                throw new \RuntimeException('Warmup storage parent unavailable');
            }
            array_unshift($tail, basename($path));
            $path = $parent;
        }
        return rtrim(str_replace('\\', '/', $resolved), '/') . ($tail === [] ? '' : '/' . implode('/', $tail));
    }
}
