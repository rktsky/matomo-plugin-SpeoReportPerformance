<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\Archive;
use Piwik\ArchiveProcessor\Rules;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\DataTable\Manager as TableManager;
use Piwik\Date;
use Piwik\Period;
use Piwik\Piwik;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Plugins\PrivacyManager\PrivacyManager;
use Piwik\Plugins\SitesManager\API as SitesAPI;
use Piwik\Segment;
use Piwik\Site;
use Piwik\Translation\Translator;

/**
 * Revision-bound CACHE CONTEXT only. No Redis access, report render, cache hit,
 * mutation, event registration or activation is implemented here.
 *
 * Scope: non-future retained months, no segment, native readonly archive Rules.
 * IDs+done states cover normal native ArchiveWriter publication/invalidation.
 * They do NOT prove blob-content identity after in-place edits or during purges.
 * Caller must supply a purge/change epoch or exclude such operations/periods.
 */
final class RevisionContext
{
    private const RECORD = 'Actions_actions_url';
    private const SCHEMA = 'speo-flat-revision-v2';
    private const OMITTABLE_TRANSPORT = ['random', 'uniqueId', 'widget', 'cb', 'viewDataTable', 'isFooterExpandedInDashboard', 'showtitle', 'forceView'];

    private function __construct(
        private int $idSite,
        private Period $period,
        private Segment $segment,
        private DataTable $root,
        private array $request,
        private array $vector,
        private array $environment,
        private array $timings
    ) {
    }

    /**
     * The parent hook must first verify method, conflicting hooks, privacy
     * rounding=false, supported response pipeline, and purge/cache-epoch policy.
     * Request contains final named arguments over the raw request.
     * Permission errors deliberately propagate instead of becoming cache hits.
     */
    public static function prepare(array $request, ?array $postProcessingRequest = null): ?self
    {
        if ($postProcessingRequest !== null) {
            // Unreviewed pre-dispatch rewrites keep the complete native path.
            if (
                (int) ($postProcessingRequest['idSite'] ?? 0) !== (int) ($request['idSite'] ?? 0)
                || ($postProcessingRequest['period'] ?? null) !== ($request['period'] ?? null)
                || ($postProcessingRequest['date'] ?? null) !== ($request['date'] ?? null)
                || !in_array($postProcessingRequest['flat'] ?? false, [true, 1, '1'], true)
            ) {
                return null;
            }
        }
        if (
            ($request['period'] ?? null) !== 'month' || NativeArchiveReader::requestReason($request) !== null
            || !in_array($request['segment'] ?? false, [null, false, ''], true)
        ) {
            return null;
        }
        $limit = $request['filter_limit'] ?? null;
        if (
            !((is_int($limit) && $limit >= 1 && $limit <= 1000)
            || (is_string($limit) && preg_match('/\A[1-9][0-9]{0,3}\z/D', $limit) === 1 && (int) $limit <= 1000))
        ) {
            return null;
        }
        $idSite = self::assertViewAccess($request);
        if (!self::sourceCompatible()) {
            return null;
        }

        $manager = TableManager::getInstance();
        $firstId = $manager->getMostRecentTableId();
        $started = hrtime(true);
        try {
            // INI setting names are part of Matomo's documented public API.
            if ((int) (Config::getInstance()->General['datatable_archiving_maximum_rows_actions_flat'] ?? 0) > 0) {
                return null;
            }
            $archive = Archive::build($idSite, 'month', $request['date'], false);
            if (!is_object($archive) || !is_callable([$archive, 'getDataTable']) || !is_callable([$archive, 'getParams'])) {
                return null;
            }
            $params = $archive->getParams();
            $periods = $params->getPeriods();
            $sites = $params->getIdSites();
            if (count($periods) !== 1 || count($sites) !== 1 || (int) reset($sites) !== $idSite) {
                return null;
            }
            $period = reset($periods);
            $segment = $params->getSegment();
            if (
                !$segment->isEmpty() || $period->getLabel() !== 'month'
                || Rules::isArchivingEnabledFor($sites, $segment, 'month')
            ) {
                return null;
            }
            $today = Date::factory('now', Site::getTimezoneFor($idSite));
            $start = $period->getDateStart();
            if ($start->toString('Y-m') > $today->toString('Y-m')) {
                return null;
            }
            if (PrivacyManager::shouldReportBePurged((int) $start->toString('Y'), (int) $start->toString('m'))) {
                return null;
            }
            $purgeSettings = PrivacyManager::getPurgeDataSettings();

            $phase = hrtime(true);
            $vector = self::readVector($idSite, $period, $segment);
            if ($vector === []) {
                return null;
            }
            $timings['revision_ms'] = (hrtime(true) - $phase) / 1e6;

            $phase = hrtime(true);
            if (is_callable([$archive, 'forceFetchingWithoutLaunchingArchiving'])) {
                $archive->forceFetchingWithoutLaunchingArchiving();
            }
            $root = $archive->getDataTable(self::RECORD); // Public root-only archive API.
            if (get_class($root) !== DataTable::class) {
                return null;
            }
            $timings['root_only_ms'] = (hrtime(true) - $phase) / 1e6;

            $phase = hrtime(true);
            $translator = StaticContainer::get(Translator::class);
            $language = $translator->getCurrentLanguage();
            if (!is_string($language) || $language === '') {
                $manager->deleteAll($firstId);
                return null;
            }
            $plugins = [];
            foreach (PluginManager::getInstance()->getPluginsLoadedAndActivated() as $plugin) {
                $plugins[$plugin->getPluginName()] = ['version' => $plugin->getVersion(), 'class' => get_class($plugin)];
            }
            $environment = [
                'schema' => self::SCHEMA,
                'runtime_namespace' => NativeSourceGuard::runtimeFingerprint(),
                'revision_provider_sha256' => hash_file('sha256', __DIR__ . '/ArchiveRevisionProvider.php'),
                'source_sha256' => hash_file('sha256', __FILE__),
                'reader_sha256' => hash_file('sha256', __DIR__ . '/NativeArchiveReader.php'),
                'flat_snapshot_sha256' => hash_file('sha256', __DIR__ . '/FlatTableSnapshot.php'),
                'principal_sha256' => hash('sha256', Piwik::getCurrentUserLogin()),
                'general_sha256' => self::fingerprint(Config::getInstance()->General),
                'purge_settings_sha256' => self::fingerprint($purgeSettings),
                'site_sha256' => self::fingerprint(SitesAPI::getInstance()->getSiteFromId($idSite)),
                'site_urls_sha256' => self::fingerprint(SitesAPI::getInstance()->getSiteUrlsFromId($idSite)),
                'plugins_sha256' => self::fingerprint($plugins),
                'language' => $language,
                'translations_sha256' => self::fingerprint($translator->getAllTranslations()),
                'aggregation_sha256' => self::fingerprint(\Piwik\Plugins\Actions\Metrics::getColumnsAggregationOperation()),
                'raw_flat' => \Piwik\Common::getRequestVar('flat', 0, 'int'),
                'raw_method' => \Piwik\Common::getRequestVar('method', '', 'string'),
            ];
            $safeRequest = self::safeRequestForKey($postProcessingRequest ?? $request);
            $safeRequest['idSite'] = $idSite;
            $safeRequest['period'] = 'month';
            $safeRequest['segment'] = false;
            $safeRequest['date'] = $start->toString();
            $safeRequest = self::canonicalize($safeRequest);
            $timings['environment_ms'] = (hrtime(true) - $phase) / 1e6;
            $timings['prepare_total_ms'] = (hrtime(true) - $started) / 1e6;
            return new self($idSite, $period, $segment, $root, $safeRequest, $vector, $environment, $timings);
        } catch (\Throwable $error) {
            $manager->deleteAll($firstId);
            return null;
        }
    }

    /** Native permission helper, also usable before any external cache read. */
    public static function assertViewAccess(array $request): int
    {
        $site = $request['idSite'] ?? null;
        if (
            !((is_int($site) && $site > 0) || (is_string($site) && preg_match('/\A[1-9][0-9]*\z/D', $site) === 1
            && (string) (int) $site === $site))
        ) {
            throw new \InvalidArgumentException('Expected one site');
        }
        $idSite = (int) $site;
        Piwik::checkUserHasViewAccess($idSite);
        return $idSite;
    }

    /**
     * Call immediately before accepting a hit AND after miss computation before
     * publication. A changed/invalid vector means no hit/publication. This reads
     * the real selector again, not Archive's instance ID cache or Redis.
     */
    public function revisionStillCurrent(): bool
    {
        self::assertViewAccess(['idSite' => $this->idSite]);
        try {
            if (Rules::isArchivingEnabledFor([$this->idSite], $this->segment, 'month')) {
                return false;
            }
            $start = $this->period->getDateStart();
            if (
                PrivacyManager::shouldReportBePurged((int) $start->toString('Y'), (int) $start->toString('m'))
                || self::fingerprint(PrivacyManager::getPurgeDataSettings()) !== $this->environment['purge_settings_sha256']
            ) {
                return false;
            }
            return $this->vector === self::readVector($this->idSite, $this->period, $this->segment);
        } catch (\Throwable $error) {
            return false;
        }
    }

    /**
     * Primitive values only: safe to hash, never log raw request tokens.
     * TTL is for memory retention only. Caller supplies its purge/change epoch.
     * Omissions are restricted to reviewed UI/transport-only values.
     */
    public function keyMaterial(array $omitTransportKeys = [], string $changeEpoch = ''): array
    {
        $request = $this->request;
        foreach ($omitTransportKeys as $name) {
            if (!in_array($name, self::OMITTABLE_TRANSPORT, true)) {
                throw new \InvalidArgumentException('Unsupported cache-key omission');
            }
            unset($request[$name]);
        }
        return ['environment' => $this->environment, 'request' => $request,
            'revision' => $this->vector, 'change_epoch' => $changeEpoch];
    }

    public function getRootTable(): DataTable
    {
        return $this->root;
    }
    /**
     * Identify the exact native property for owned cache-copy indexing.
     * @return int Original positive ID already checked by assertViewAccess().
     * @since 1.3.0
     */
    public function getNativeId(): int
    {
        return $this->idSite;
    }

    public function getTimings(): array
    {
        return $this->timings;
    }
    public function getKey(): string
    {
        // These fields control the surrounding UI/renderer, never the processed
        // report table. Keep viewDataTable and all data/filter parameters keyed.
        $omit = ['random', 'uniqueId', 'widget', 'cb', 'isFooterExpandedInDashboard', 'showtitle', 'forceView'];
        return self::SCHEMA . '_' . self::fingerprint($this->keyMaterial($omit));
    }
    public function getBaseKey(): string
    {
        $material = $this->keyMaterial(['random', 'uniqueId', 'widget', 'cb', 'isFooterExpandedInDashboard', 'showtitle', 'forceView']);
        unset($material['request']['totalRows'], $material['request']['search_recursive'], $material['request']['viewDataTable']);
        // This neutral, unsegmented input is identical for users allowed to
        // view the site under the pinned native Actions/Goals/end-hook path.
        // Permission is still checked on every hit; finished pages stay private.
        unset($material['environment']['principal_sha256']);
        if (array_key_exists('idSubtable', $this->request)) {
            // Native totals reload the first level whenever this key exists,
            // even false/null. That subrequest can consume generic filters.
            return self::SCHEMA . '_base_' . self::fingerprint($material);
        }
        // These operations occur AFTER native hierarchy loading and flattening.
        // Totals/keep_totals_row stay keyed: their native calculation is earlier.
        foreach (
            ['filter_limit', 'filter_offset', 'filter_sort_column', 'filter_sort_order',
            'filter_pattern', 'filter_column', 'filter_pattern_recursive', 'filter_column_recursive',
            'filter_excludelowpop', 'filter_excludelowpop_value', 'filter_truncate', 'keep_summary_row',
            'hideColumns', 'showColumns', 'showMetadata',
            'translateColumnNames', 'fetch_archive_state', 'disable_generic_filters'] as $name
        ) {
            unset($material['request'][$name]);
        }
        return self::SCHEMA . '_base_' . self::fingerprint($material);
    }

    public function getLanguage(): string
    {
        return $this->environment['language'];
    }

    /** Token-free parameters sufficient to rebuild exactly the same base. */
    public function getWarmRequest(): array
    {
        // Presence alone changes native totals and therefore base keying.
        // Do not persist search/column settings or warm a different filtered
        // base by replacing those settings with the ordinary warm defaults.
        if (array_key_exists('idSubtable', $this->request)) {
            return [];
        }
        $request = $this->request;
        foreach (
            ['random', 'uniqueId', 'widget', 'cb', 'isFooterExpandedInDashboard', 'showtitle', 'forceView', 'totalRows', 'search_recursive', 'viewDataTable',
            'filter_limit', 'filter_offset', 'filter_sort_column', 'filter_sort_order',
            'filter_pattern', 'filter_column', 'filter_pattern_recursive', 'filter_column_recursive',
            'filter_excludelowpop', 'filter_excludelowpop_value', 'filter_truncate', 'keep_summary_row',
            'hideColumns', 'showColumns', 'showMetadata', 'translateColumnNames', 'fetch_archive_state',
            'disable_generic_filters'] as $name
        ) {
            unset($request[$name]);
        }
        $request['filter_limit'] = 500;
        $request['filter_offset'] = 0;
        $request['filter_sort_column'] = 'nb_hits';
        $request['filter_sort_order'] = 'desc';
        return $request;
    }
    public function getFreshRoot(): DataTable
    {
        return $this->root;
    }
    public function isCurrent(): bool
    {
        return $this->revisionStillCurrent();
    }

    /**
     * Root-only archived_date can be older than a newer partial CHILD blob.
     * For the same immutable revision retain the full miss's native archived_date.
     * Fresh root metadata is not a complete replacement for cached flat metadata:
     * retain separately reviewed totals/dimensions/aggregation primitive metadata.
     */
    public function getFreshRootMetadata(): array
    {
        return $this->root->getAllTableMetadata();
    }

    private static function readVector(int $idSite, Period $period, Segment $segment): array
    {
        return ArchiveRevisionProvider::read($idSite, $period);
    }

    public static function fingerprint(mixed $value): string
    {
        return hash('sha256', serialize(self::canonicalize($value)));
    }

    public static function safeRequestForKey(array $request): array
    {
        unset($request['token_auth']);
        return self::canonicalize($request);
    }

    /** No objects, closures, resources or unbounded reference structures. */
    public static function canonicalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new \UnexpectedValueException('Cache context too deep');
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $result[$key] = self::canonicalize($child, $depth + 1);
            }
            if (!array_is_list($result)) {
                ksort($result, SORT_STRING);
            }
            return $result;
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        throw new \UnexpectedValueException('Cache context is not primitive');
    }

    private static function sourceCompatible(): bool
    {
        return NativeSourceGuard::publicMethod(Archive::class, 'build')
            && NativeSourceGuard::publicMethod(Archive::class, 'getDataTable')
            && is_callable([PrivacyManager::class, 'getPurgeDataSettings'])
            && is_callable([PrivacyManager::class, 'shouldReportBePurged']);
    }
}
