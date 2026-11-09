<?php

/**
 * Fence and index owned persistent report copies for exact-property lifecycle operations.
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\Cache;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Development;
use Piwik\Config;
use Piwik\Option;
use Piwik\Piwik;

/**
 * Uses native Cache/Option APIs and private file leases; never queries or changes native tables directly.
 * A missing legacy-copy baseline is deliberately distinct from an empty future-key registry.
 * @since 1.3.0
 */
final class CopyLifecycle
{
    /** @var int Hard bound on retained key metadata; a full index declines optional cache publication. */
    private const MAX_KEYS = 20000;

    /**
     * Publish one finished snapshot only while its property is not permanently sealed.
     * Indexing precedes the cache write, so a lost acknowledgement never leaves an unindexed new copy.
     * @param RevisionContext $context Original fresh permission/revision-bound report context.
     * @param string $key Own context-derived native cache key.
     * @param string $payload Bounded encoded report snapshot, never stored in the authority index.
     * @param int $ttl Original configured cache lifetime in seconds.
     * @return bool False preserves the normal report when optional caching cannot be confirmed.
     * @since 1.3.0
     */
    public static function publish(RevisionContext $context, string $key, string $payload, int $ttl): bool
    {
        try {
            return self::withPublication($context->getNativeId(), static function () use ($context, $key, $payload, $ttl): bool {
                if (!$context->isCurrent() || !self::validKey($key) || $ttl < 1 || $ttl > 604800) {
                    return false;
                }
                self::index($context->getNativeId(), $key);
                return Cache::getLazyCache()->save($key, $payload, $ttl) === true;
            });
        } catch (\Throwable $error) {
            return false;
        }
    }

    /**
     * Fence a late snapshot or neutral warmup-hint write independently of API end-event ordering.
     * @template T
     * @param int $nativeId Positive native property whose optional copy would be published.
     * @param callable():T $publish Existing own publication while the shared lease remains held.
     * @return T Original callback result; a busy/sealed/unknown state throws before publication.
     * @throws \RuntimeException When exclusion or actual native authority cannot be verified.
     * @since 1.3.0
     */
    public static function withPublication(int $nativeId, callable $publish): mixed
    {
        self::positive($nativeId);
        return self::locked('copy-writers.lock', LOCK_SH, static function () use ($nativeId, $publish): mixed {
            // One exact native Option lookup; ordinary cache hits never parse the full key index.
            $sealKey = self::optionKey() . '.sealed.' . $nativeId;
            Option::clearCachedOption($sealKey);
            if (Option::get($sealKey) !== false) {
                throw new \RuntimeException('report_copy_property_sealed');
            }
            // The physical seal belongs to the owned control, not an HTTP parameter.
            if (class_exists(\Piwik\Plugins\SpeoControl\ActiveErasure::class)) {
                \Piwik\Plugins\SpeoControl\ActiveErasure::guardRecording($nativeId);
            }
            return $publish();
        });
    }

    /**
     * Read exact current integration readiness without granting a legacy baseline or taking effects.
     * @return array{version:int,legacyCopiesVerified:bool,bindingHash:string} Native authority observation.
     * @throws \RuntimeException On malformed/foreign state rather than inventing an empty index.
     * @since 1.3.0
     */
    public static function readiness(): array
    {
        $registry = self::read();
        return ['version' => 1, 'legacyCopiesVerified' => self::baselineVerified($registry['baseline']), 'bindingHash' => self::binding(), 'sourceHash' => self::sourceHash(), 'baselineHash' => hash('sha256', json_encode($registry['baseline'], JSON_THROW_ON_ERROR))];
    }

    /**
     * Erase indexed own copies and neutral hints for one already sealed control property.
     * Registry membership survives failed saves/deletes; every retry re-observes native cache absence.
     * @param int $nativeId Exact positive physical scope under the caller's native EX/account/site leases.
     * @return array{version:int,nativeId:int,complete:bool,keys:int,warmupTargets:int,bindingHash:string} Actual bounded readback.
     * @throws \RuntimeException For missing baseline, unsafe scope, busy publisher or an uncertain effect.
     * @since 1.3.0
     */
    public static function erase(int $nativeId): array
    {
        self::positive($nativeId);
        Piwik::checkUserHasSuperUserAccess();
        if (PHP_SAPI !== 'cli' || !class_exists(\Piwik\Plugins\SpeoControl\ActiveErasure::class) || !\Piwik\Plugins\SpeoControl\ActiveErasure::isCurrentNativeScope($nativeId) || \Piwik\Plugins\SpeoControl\ActiveErasureJournal::site($nativeId) === null) {
            throw new \RuntimeException('original_sealed_erasure_copy_scope_required');
        }
        return self::locked('copy-writers.lock', LOCK_EX, static function () use ($nativeId): array {
            return self::locked('copy-index.lock', LOCK_EX, static function () use ($nativeId): array {
                $registry = self::read();
                if (!self::readiness()['legacyCopiesVerified']) {
                    throw new \RuntimeException('legacy_report_copies_unverified');
                }
                $sealKey = self::optionKey() . '.sealed.' . $nativeId;
                Option::set($sealKey, 'sealed-v1', 0);
                Option::clearCachedOption($sealKey);
                if (Option::get($sealKey) !== 'sealed-v1') {
                    throw new \RuntimeException('report_copy_seal_write_unconfirmed');
                }
                $registry['sealed'][(string) $nativeId] = true;
                self::write($registry);
                $keys = array_keys(array_filter($registry['keys'], static fn(int $site): bool => $site === $nativeId));
                $cache = Cache::getLazyCache();
                foreach ($keys as $key) {
                    // Native chained deletion may skip an expired file because contains() is false.
                    // Overwrite with a content-free live value first, then delete through the same native API.
                    if ($cache->save($key, '', 60) !== true || $cache->delete($key) !== true || $cache->contains($key) !== false) {
                        throw new \RuntimeException('report_copy_eviction_unconfirmed');
                    }
                }
                $targets = HotReportWarmup::eraseProperty($nativeId);
                foreach ($keys as $key) {
                    unset($registry['keys'][$key]);
                }
                self::write($registry);
                return ['version' => 1, 'nativeId' => $nativeId, 'complete' => true, 'keys' => count($keys), 'warmupTargets' => $targets, 'bindingHash' => self::binding()];
            });
        });
    }

    /**
     * Clear a separately reviewed finite inventory of own unindexed legacy keys through native cache APIs.
     * This command accepts operator authority, not browser fields or an implicit empty discovery.
     * @param array $intent Private original scan/source/drain receipt with exact logical keys.
     * @return array Durable source/store-bound baseline after every supplied native key is absent.
     * @throws \RuntimeException Outside native CLI/superuser/EX scope or for stale, incomplete or foreign receipt.
     * @since 1.3.0
     */
    public static function applyReviewedLegacyBaseline(array $intent): array
    {
        Piwik::checkUserHasSuperUserAccess();
        $keys = $intent['logicalKeys'] ?? null;
        if (
            PHP_SAPI !== 'cli' || !class_exists(\Piwik\Plugins\SpeoControl\RetentionDictionaryBarrier::class)
            || !\Piwik\Plugins\SpeoControl\RetentionDictionaryBarrier::cleanupActive()
            || ($intent['schemaVersion'] ?? null) !== 1 || ($intent['scope'] ?? '') !== 'reviewed-own-report-cache-keys-v1'
            || ($intent['bindingHash'] ?? '') !== self::binding() || ($intent['sourceHash'] ?? '') !== self::sourceHash()
            || ($intent['completeScan'] ?? null) !== true || ($intent['oldPublishersDrained'] ?? null) !== true
            || !is_string($intent['reviewReference'] ?? null) || trim($intent['reviewReference']) === '' || strlen($intent['reviewReference']) > 512
            || !is_int($intent['observedAt'] ?? null) || $intent['observedAt'] > time() + 5 || $intent['observedAt'] < time() - 600
            || !is_array($keys) || !array_is_list($keys) || count($keys) > self::MAX_KEYS || count(array_unique($keys, SORT_REGULAR)) !== count($keys)
        ) {
            throw new \RuntimeException('reviewed_legacy_cache_inventory_required');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || !self::validKey($key)) {
                throw new \RuntimeException('legacy_cache_key_family_refused');
            }
        }
        return self::locked('copy-writers.lock', LOCK_EX, static function () use ($intent, $keys): array {
            return self::locked('copy-index.lock', LOCK_EX, static function () use ($intent, $keys): array {
                $registry = self::read();
                $cache = Cache::getLazyCache();
                foreach ($keys as $key) {
                    // Reconcile even expired native File entries without decoding their former report contents.
                    if ($cache->save($key, '', 60) !== true || $cache->delete($key) !== true || $cache->contains($key) !== false) {
                        throw new \RuntimeException('legacy_cache_key_eviction_unconfirmed');
                    }
                }
                $registry['baseline'] = ['scope' => 'reviewed-own-report-cache-keys-v1', 'bindingHash' => self::binding(),
                    'createdAt' => gmdate('Y-m-d\TH:i:s\Z'), 'inventoryHash' => hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)),
                    'clearedKeys' => count($keys)];
                self::write($registry);
                return $registry['baseline'];
            });
        });
    }

    /**
     * Append one exact native cache key before a potential persistent write.
     * @param int $nativeId Already checked positive property.
     * @param string $key Own validated context-derived key, without URL/visitor contents.
     * @return void A full/corrupt index declines publication rather than dropping membership.
     * @since 1.3.0
     */
    private static function index(int $nativeId, string $key): void
    {
        self::locked('copy-index.lock', LOCK_EX, static function () use ($nativeId, $key): void {
            $registry = self::read();
            if (isset($registry['sealed'][(string) $nativeId]) || isset($registry['keys'][$key]) && $registry['keys'][$key] !== $nativeId || !isset($registry['keys'][$key]) && count($registry['keys']) >= self::MAX_KEYS) {
                throw new \RuntimeException('report_copy_index_scope_or_capacity');
            }
            if (($registry['keys'][$key] ?? null) === $nativeId) {
                return; // Already durably indexed; no native Option update/readback is needed.
            }
            $registry['keys'][$key] = $nativeId;
            self::write($registry);
        });
    }

    /**
     * Read bounded native nonautoload metadata freshly; it never contains report payloads.
     * @return array Existing authority or explicit absence with unverified historical copies.
     * @throws \RuntimeException For corrupt shape, foreign installation or unknown key ownership.
     * @since 1.3.0
     */
    private static function read(): array
    {
        $key = self::optionKey();
        Option::clearCachedOption($key);
        $json = Option::get($key);
        if ($json === false) {
            return ['schemaVersion' => 1, 'bindingHash' => self::binding(), 'baseline' => null, 'keys' => [], 'sealed' => []];
        }
        if (!is_string($json) || strlen($json) > 4194304) {
            throw new \RuntimeException('report_copy_index_invalid');
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_keys($data) !== ['schemaVersion', 'bindingHash', 'baseline', 'keys', 'sealed'] || $data['schemaVersion'] !== 1 || $data['bindingHash'] !== self::binding() || !is_array($data['keys']) || count($data['keys']) > self::MAX_KEYS || !is_array($data['sealed']) || count($data['sealed']) > 10000) {
            throw new \RuntimeException('report_copy_index_binding_invalid');
        }
        foreach ($data['keys'] as $cacheKey => $site) {
            if (!is_string($cacheKey) || !self::validKey($cacheKey) || !is_int($site) || $site < 1) {
                throw new \RuntimeException('report_copy_index_entry_invalid');
            }
        }
        foreach ($data['sealed'] as $site => $seal) {
            if (!ctype_digit((string) $site) || (int) $site < 1 || $seal !== true) {
                throw new \RuntimeException('report_copy_seal_invalid');
            }
        }
        return $data;
    }

    /**
     * Atomically replace and independently read back the original bounded Option value.
     * @param array $registry Current complete own key/seal state under the exclusive index lease.
     * @return void Unknown native storage results never become a baseline/completion receipt.
     * @since 1.3.0
     */
    private static function write(array $registry): void
    {
        $json = json_encode($registry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 4194304) {
            throw new \RuntimeException('report_copy_index_capacity');
        }
        Option::set(self::optionKey(), $json, 0);
        if (self::read() !== $registry) {
            throw new \RuntimeException('report_copy_index_write_unconfirmed');
        }
    }

    /**
     * Distinguish an actual reviewed finite-key cleanup receipt from an old or invented baseline marker.
     * @param mixed $baseline Original own Option field; never a public request or source default.
     * @return bool True only for the complete current-store receipt emitted after native absence checks.
     * @since 1.3.0
     */
    private static function baselineVerified(mixed $baseline): bool
    {
        return is_array($baseline) && array_keys($baseline) === ['scope', 'bindingHash', 'createdAt', 'inventoryHash', 'clearedKeys']
            && $baseline['scope'] === 'reviewed-own-report-cache-keys-v1' && $baseline['bindingHash'] === self::binding()
            && is_string($baseline['createdAt']) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $baseline['createdAt']) === 1
            && is_string($baseline['inventoryHash']) && preg_match('/\A[a-f0-9]{64}\z/D', $baseline['inventoryHash']) === 1
            && is_int($baseline['clearedKeys']) && $baseline['clearedKeys'] >= 0 && $baseline['clearedKeys'] <= self::MAX_KEYS;
    }

    /**
     * Bind physical cache configuration and permanent private lock location without exposing credentials.
     * @return string Current source-independent SHA-256; changed storage requires explicit reconciliation.
     * @since 1.3.0
     */
    private static function binding(): string
    {
        $config = Config::getInstance();
        $values = ['root' => realpath(PIWIK_DOCUMENT_ROOT), 'prefix' => Common::prefixTable(''), 'host' => gethostname(), 'storage' => Configuration::get()->storagePath(), 'development' => Development::isEnabled(), 'actualBackend' => self::backendTypes(StaticContainer::get('Matomo\\Cache\\Backend'))];
        foreach (['Cache', 'ChainedCache', 'FileCache', 'RedisCache', 'KeyPrefixCache', 'DefaultTimeoutCache'] as $section) {
            $values[$section] = $config->$section;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    /**
     * Observe the actual original container backend, including chained children, without private-property access.
     * @param object $backend Native public DI service used to construct the same LazyCache instance.
     * @return array Actual resolved backend types; a CLI development override differs from Redis/File.
     * @since 1.3.0
     */
    private static function backendTypes(object $backend): array
    {
        $types = ['class' => get_class($backend)];
        if ($backend instanceof \Matomo\Cache\Backend\Chained) {
            $types['children'] = array_map([self::class, 'backendTypes'], $backend->getBackends());
        }
        return $types;
    }

    /**
     * Bind the actual owned presentation/cache implementation without scanning customer data.
     * @return string Deterministic SHA-256 over owned PHP runtime sources only.
     * @since 1.3.0
     */
    private static function sourceHash(): string
    {
        $files = [];
        foreach (array_merge(glob(__DIR__ . '/*.php') ?: [], glob(__DIR__ . '/Commands/*.php') ?: []) as $path) {
            $files[substr($path, strlen(__DIR__) + 1)] = hash_file('sha256', $path);
        }
        ksort($files, SORT_STRING);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    /**
     * Retain authority across configuration changes so an old physical seal cannot disappear silently.
     * @return string Stable own namespace in the original native Option table.
     * @since 1.3.0
     */
    private static function optionKey(): string
    {
        return 'SpeoReportPerformance.copyLifecycle.v1.' . hash('sha256', Common::prefixTable(''));
    }

    /**
     * Take a private permanent-inode lease without waiting on foreground report rendering.
     * @template T
     * @param string $name Literal own writer/index lock basename.
     * @param int $mode LOCK_SH or LOCK_EX selected by source.
     * @param callable():T $callback Own effect/read under the lease.
     * @return T Exact callback result; busy state declines optional caching or blocks erasure.
     * @throws \RuntimeException For unsafe storage, another writer or inode replacement.
     * @since 1.3.0
     */
    private static function locked(string $name, int $mode, callable $callback): mixed
    {
        $directory = Configuration::get()->storagePath();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) || is_link($directory) || (fileperms($directory) & 0077) !== 0 || function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid() || !in_array($name, ['copy-writers.lock', 'copy-index.lock'], true)) {
            throw new \RuntimeException('private_report_copy_lock_required');
        }
        $path = $directory . '/' . $name;
        if (is_link($path)) {
            throw new \RuntimeException('report_copy_lock_alias_refused');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('report_copy_lock_unavailable');
        }
        try {
            chmod($path, 0600);
            $stat = fstat($handle);
            $named = stat($path);
            if ($stat === false || $named === false || $stat['ino'] !== $named['ino'] || $stat['dev'] !== $named['dev'] || ($stat['mode'] & 0170000) !== 0100000 || function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid() || !flock($handle, $mode | LOCK_NB)) {
                throw new \RuntimeException('report_copy_writer_busy');
            }
            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reject foreign/native cache families before they can be indexed or overwritten.
     * @param string $key Context-derived report key.
     * @return bool True only for the existing own revision/page namespace.
     * @since 1.3.0
     */
    private static function validKey(string $key): bool
    {
        return preg_match('/\Aspeo-flat-revision-v2_(?:base_)?[a-f0-9]{64}(?:_ui_numeric_[a-f0-9]{64})?\z/D', $key) === 1;
    }

    /**
     * Refuse wildcard, foreign-type and out-of-range property selectors.
     * @param int $nativeId Exact native property, never a browser authority.
     * @return void Invalid scope stops before native storage/cache access.
     * @since 1.3.0
     */
    private static function positive(int $nativeId): void
    {
        if ($nativeId < 1 || $nativeId > 2147483647) {
            throw new \InvalidArgumentException('positive_report_copy_property_required');
        }
    }
}
