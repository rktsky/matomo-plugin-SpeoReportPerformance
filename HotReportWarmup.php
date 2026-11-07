<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\Access;
use Piwik\API\Request;
use Piwik\Cache;
use Piwik\Container\StaticContainer;
use Piwik\Plugins\Actions\ArchivingHelper;
use Piwik\Translation\Translator;

/** Private, token-free hot-target manifest and bounded console orchestration. */
final class HotReportWarmup
{
    public const MAX_TARGETS = 20;
    public const MAX_AGE = 604800;
    public const MAX_JOBS = 3;
    public const MAX_SECONDS = 120;
    public const RECORD_INTERVAL = 300;
    private static bool $warming = false;
    /** Kept through native shutdown/base encoding, not merely API::process(). */
    private static $workerLock = null;

    /** Caller has already checked view access and complete cache eligibility. */
    public static function record(RevisionContext $context): bool
    {
        if (self::$warming || !Configuration::get()->warmupEnabled()) {
            return false;
        } // Background work is not user demand.
        try {
            $request = $context->getWarmRequest();
            $language = $context->getLanguage();
            if (!self::validRequest($request, $language)) {
                Diagnostics::record('warmup_hint_skipped');
                return false;
            }
            ksort($request, SORT_STRING);
            $target = ['request' => $request, 'language' => $language];
            $id = hash('sha256', json_encode($target, JSON_THROW_ON_ERROR));
            $recorded = self::locked(static function (string $dir) use ($target, $id): bool {
                $targets = self::read($dir);
                if ($targets === null) {
                    return false;
                }
                $now = time();
                if (isset($targets[$id]) && $now - $targets[$id]['lastSeen'] < Configuration::get()->warmupRecordInterval()) {
                    return true; // Existing optional hint: no manifest write.
                }
                $targets[$id] = $target + ['lastSeen' => $now];
                uasort($targets, static fn(array $a, array $b): int => $b['lastSeen'] <=> $a['lastSeen']);
                return self::write($dir, array_slice($targets, 0, Configuration::get()->warmupMaxTargets(), true));
            }, false);
            Diagnostics::record($recorded ? 'warmup_hint_recorded' : 'warmup_hint_skipped');
            return $recorded;
        } catch (\Throwable $error) {
            Diagnostics::record('warmup_hint_skipped');
            return false;
        }
    }

    /** Fresh native PHP process per target bounds memory/language lifetimes. */
    public static function run(?string $targetId = null): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('CLI required');
        }
        $configuration = Configuration::get();
        if (!$configuration->warmupEnabled()) {
            return $targetId === null ? self::emptyCounts() : ['status' => 'skipped'];
        }
        if ($targetId !== null) {
            return self::runTarget($targetId);
        }
        $targets = self::locked(static fn(string $dir): ?array => self::read($dir), null);
        $counts = self::emptyCounts();
        if (!is_array($targets)) {
            return $counts;
        }
        $deadline = microtime(true) + $configuration->warmupMaxSeconds();
        // Check all retained targets, not just the three newest. Already warm
        // and safely declined targets consume no actual rebuild slot.
        foreach (array_slice(array_keys($targets), 0, $configuration->warmupMaxTargets()) as $id) {
            if ($counts['rebuild_attempts'] >= $configuration->warmupMaxJobs()) {
                break;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            ++$counts['attempted'];
            try {
                $process = new WarmupProcess([PHP_BINARY, '-d', 'memory_limit=' . $configuration->warmupMemoryLimit(),
                    PIWIK_DOCUMENT_ROOT . '/console', 'speo:reports-warm', '--target=' . $id]);
                $process->setTimeout($remaining);
                $process->run();
                $result = json_decode(trim($process->getOutput()), true);
                $status = is_array($result) ? ($result['status'] ?? '') : '';
                if (!$process->isSuccessful() || $status === 'failed') {
                    ++$counts['failed'];
                    // A complete native worker result can prove it failed
                    // before entering an API rebuild. Unknown/fatal children
                    // count conservatively so failures cannot evade the cap.
                    if ($status !== 'failed' || ($result['rebuild_attempted'] ?? null) !== false) {
                        ++$counts['rebuild_attempts'];
                    }
                } elseif ($status === 'requested') {
                    ++$counts['requested'];
                    ++$counts['rebuild_attempts'];
                } elseif ($status === 'warm') {
                    ++$counts['warm'];
                } elseif (in_array($status, ['skipped', 'busy'], true)) {
                    ++$counts['skipped'];
                } else {
                    ++$counts['failed'];
                    ++$counts['rebuild_attempts'];
                    $status = 'failed';
                }
                Diagnostics::record('warmup_job_' . $status);
            } catch (\Throwable $error) {
                ++$counts['failed'];
                ++$counts['rebuild_attempts'];
                Diagnostics::record('warmup_job_failed');
            }
        }
        return $counts;
    }

    private static function runTarget(string $id): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) {
            return ['status' => 'skipped'];
        }
        $configuration = Configuration::get();
        ini_set('memory_limit', $configuration->warmupMemoryLimit());
        set_time_limit($configuration->warmupMaxSeconds());
        $dir = self::directory();
        if (is_link($dir . '/worker.lock')) {
            return ['status' => 'skipped'];
        }
        $lock = fopen($dir . '/worker.lock', 'ce');
        if (!$lock || !chmod($dir . '/worker.lock', 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return ['status' => 'busy'];
        }
        self::$workerLock = $lock;
        $targets = self::locked(static fn(string $path): ?array => self::read($path), null);
        if (!is_array($targets) || !isset($targets[$id])) {
            return ['status' => 'skipped'];
        }
        $target = $targets[$id];
        $request = $target['request'];
        $language = $target['language'];
        if (!is_file(PIWIK_INCLUDE_PATH . '/lang/' . $language . '.json')) {
            return ['status' => 'skipped'];
        }
        $translator = StaticContainer::get(Translator::class);
        $previousLanguage = $translator->getCurrentLanguage();
        $get = $_GET;
        $post = $_POST;
        $requestGlobals = $_REQUEST;
        $query = $_SERVER['QUERY_STRING'] ?? null;
        $bufferLevel = ob_get_level();
        $rebuildAttempted = false;
        self::$warming = true;
        ob_start();
        try {
            $translator->setCurrentLanguage($language);
            ArchivingHelper::reloadConfig();
            $_GET = $_REQUEST = $request;
            $_POST = [];
            $_SERVER['QUERY_STRING'] = http_build_query($request);
            return Access::doAsSuperUser(static function () use ($request, &$rebuildAttempted): array {
                if (!NativeSourceGuard::isReviewedPipeline() || !NativeSourceGuard::isReviewedCachePipeline()) {
                    return ['status' => 'skipped'];
                }
                $context = RevisionContext::prepare($request);
                if ($context === null) {
                    return ['status' => 'skipped'];
                }
                if (Cache::getLazyCache()->contains($context->getBaseKey())) {
                    return ['status' => 'warm'];
                }
                // Preserve the recorded API format, metrics and totals flags.
                // Only missing base snapshots enter the ordinary native API.
                $rebuildAttempted = true;
                $response = (new Request($request, []))->process();
                unset($response);
                return ['status' => 'requested'];
            });
        } catch (\Throwable $error) {
            return ['status' => 'failed', 'rebuild_attempted' => $rebuildAttempted];
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $_GET = $get;
            $_POST = $post;
            $_REQUEST = $requestGlobals;
            if ($query === null) {
                unset($_SERVER['QUERY_STRING']);
            } else {
                $_SERVER['QUERY_STRING'] = $query;
            }
            $translator->setCurrentLanguage($previousLanguage);
            ArchivingHelper::reloadConfig();
            self::$warming = false;
        }
    }

    /** Explicit privacy allowlist; no token, login, pattern, label or URL field. */
    private static function validRequest(array $request, string $language): bool
    {
        if (
            preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})?\z/D', $language) !== 1
            || ($request['method'] ?? '') !== 'Actions.getPageUrls' || ($request['period'] ?? '') !== 'month'
            || !in_array($request['flat'] ?? null, [true, 1, '1'], true)
            || !is_int($request['idSite'] ?? null) || $request['idSite'] < 1
            || ($request['filter_limit'] ?? null) !== 500 || ($request['filter_offset'] ?? null) !== 0
            || ($request['filter_sort_column'] ?? null) !== 'nb_hits' || ($request['filter_sort_order'] ?? null) !== 'desc'
            || !is_string($request['date'] ?? null)
            || preg_match('/\A([0-9]{4})-([0-9]{2})-01\z/D', $request['date'], $parts) !== 1
            || !checkdate((int)$parts[2], 1, (int)$parts[1])
        ) {
            return false;
        }
        // Even false/null PRESENCE changes native totals/subrequest semantics.
        // Do not replay stale manifest targets normalized under the older rule.
        if (array_key_exists('idSubtable', $request)) {
            return false;
        }
        if (
            ($request['module'] ?? null) === 'CoreHome' && ($request['action'] ?? null) !== 'renderReportWidget'
            || ($request['action'] ?? null) === 'renderReportWidget' && ($request['module'] ?? null) !== 'CoreHome'
        ) {
            return false;
        }
        $fixed = ['method', 'period', 'idSite', 'date', 'flat', 'filter_limit', 'filter_offset', 'filter_sort_column', 'filter_sort_order'];
        $empty = ['segment', 'idSubtable', 'depth', 'include_aggregate_rows', 'show_dimensions', 'compare',
            'compareDates', 'comparePeriods', 'compareSegments', 'pivotBy', 'disable_queued_filters',
            'disable_root_datatable_post_processor', 'disablePostProcessing', 'idSites', 'idGoal', 'secondaryDimension'];
        $booleans = ['expanded', 'format_metrics', 'serialize', 'force_api_session', 'totals', 'keep_totals_row', 'search_recursive'];
        foreach ($request as $key => $value) {
            if (in_array($key, $fixed, true)) {
                continue;
            }
            if (in_array($key, $empty, true) && in_array($value, [null, false, 0, '0', '', []], true)) {
                continue;
            }
            if ($key === 'format_metrics' && in_array($value, ['bc', 'all'], true)) {
                continue;
            }
            if (in_array($key, $booleans, true) && in_array($value, [true, false, 1, 0, '1', '0', ''], true)) {
                continue;
            }
            if ($key === 'search_recursive' && in_array($value, ['true', 'false'], true)) {
                continue;
            }
            if ($key === 'format' && is_string($value) && in_array(strtolower($value), ['json', 'xml', 'html', 'csv', 'tsv', 'php', 'original'], true)) {
                continue;
            }
            // Internal ViewDataTable requests retain their HTTP controller
            // defaults; API Request dispatch itself uses the fixed method.
            if ($key === 'module' && in_array($value, ['API', 'Actions', 'CoreHome'], true)) {
                continue;
            }
            if ($key === 'action' && in_array($value, ['index', 'getPageUrls', 'renderReportWidget'], true)) {
                continue;
            }
            if ($key === 'viewDataTable' && $value === 'table') {
                continue;
            }
            if ($key === 'language' && is_string($value) && preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})?\z/D', $value)) {
                continue;
            }
            if (($key === 'totalRows' || $key === 'idsite') && (is_int($value) && $value >= 0 || is_string($value) && ctype_digit($value))) {
                continue;
            }
            // Explicit custom totals labels would contain report/user content.
            return false;
        }
        return true;
    }

    private static function directory(): string
    {
        $dir = Configuration::get()->storagePath();
        if (
            is_link($dir) || (file_exists($dir) && (!is_dir($dir)
            || function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid()))
        ) {
            throw new \RuntimeException('Private warmup directory unavailable');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Private warmup directory unavailable');
        }
        // Another process may win mkdir(). Never chmod or adopt its directory
        // merely because a directory appeared between the initial checks.
        clearstatcache(true, $dir);
        if (
            is_link($dir) || !is_dir($dir)
            || function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid()
            || !@chmod($dir, 0700)
        ) {
            throw new \RuntimeException('Private warmup directory unavailable');
        }
        return $dir;
    }

    private static function locked(callable $callback, mixed $fallback): mixed
    {
        try {
            $dir = self::directory();
            $path = $dir . '/manifest.lock';
            if (is_link($path)) {
                Diagnostics::record('warmup_storage_unavailable');
                return $fallback;
            }
            $lock = fopen($path, 'ce');
            if (!$lock || !chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                Diagnostics::record('warmup_manifest_busy');
                return $fallback;
            }
            try {
                return $callback($dir);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable $error) {
            Diagnostics::record('warmup_storage_unavailable');
            return $fallback;
        }
    }

    private static function read(string $dir): ?array
    {
        $path = $dir . '/hot-reports.json';
        if (!file_exists($path)) {
            return [];
        }
        if (is_link($path) || !is_file($path) || (fileperms($path) & 0077) || filesize($path) > 65536) {
            return self::invalidManifest();
        }
        $data = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) || array_keys($data) !== ['schema', 'targets'] || $data['schema'] !== 1
            || !is_array($data['targets']) || count($data['targets']) > 100
        ) {
            return self::invalidManifest();
        }
        $targets = [];
        foreach ($data['targets'] as $id => $target) {
            if (
                !is_string($id) || preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1 || !is_array($target)
                || array_keys($target) !== ['request', 'language', 'lastSeen'] || !is_array($target['request'])
                || !is_string($target['language']) || !is_int($target['lastSeen'])
                || !self::validRequest($target['request'], $target['language']) || $target['lastSeen'] > time() + 300
                || hash('sha256', json_encode(['request' => $target['request'], 'language' => $target['language']], JSON_THROW_ON_ERROR)) !== $id
            ) {
                return self::invalidManifest();
            }
            if ($target['lastSeen'] >= time() - Configuration::get()->warmupTargetMaxAge()) {
                $targets[$id] = $target;
            }
        }
        uasort($targets, static fn(array $a, array $b): int => $b['lastSeen'] <=> $a['lastSeen']);
        return array_slice($targets, 0, Configuration::get()->warmupMaxTargets(), true);
    }

    private static function invalidManifest(): ?array
    {
        Diagnostics::record('warmup_manifest_invalid');
        return null;
    }

    private static function emptyCounts(): array
    {
        return ['attempted' => 0, 'rebuild_attempts' => 0, 'requested' => 0, 'warm' => 0, 'skipped' => 0, 'failed' => 0];
    }

    private static function write(string $dir, array $targets): bool
    {
        $path = $dir . '/hot-reports.json';
        if (is_link($path)) {
            return false;
        }
        $temporary = tempnam($dir, '.hot-');
        if (!$temporary) {
            return false;
        }
        try {
            $json = json_encode(['schema' => 1, 'targets' => $targets], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > 65536 || !chmod($temporary, 0600)) {
                return false;
            }
            $handle = fopen($temporary, 'wb');
            if (!$handle) {
                return false;
            }
            // This is an optional cache hint, not durable application data.
            // Atomic rename prevents torn reads; losing a hint on a crash is safe.
            try {
                if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                    return false;
                }
            } finally {
                fclose($handle);
            }
            return rename($temporary, $path);
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }
}
