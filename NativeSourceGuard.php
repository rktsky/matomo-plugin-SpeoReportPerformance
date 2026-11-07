<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

/** Capability checks; no release, source-byte or PHP patch allowlist. */
final class NativeSourceGuard
{
    public static function isReviewedPipeline(): bool
    {
        foreach (
            [
            \Piwik\API\Request::class => ['process', 'isCurrentApiRequestTheRootApiRequest', 'isCurrentApiRequestNestedInAnotherApiRequest'],
            \Piwik\DataTable::class => ['getRows', 'getEmptyClone', 'filter', 'queueFilter', 'getAllTableMetadata'],
            \Piwik\DataTable\Row::class => ['getColumns', 'getMetadata', 'getSubtable'],
            ] as $class => $methods
        ) {
            foreach ($methods as $method) {
                if (!self::publicMethod($class, $method)) {
                    return false;
                }
            }
        }
        return NativeProcessingAdapter::supported();
    }

    /** Unknown data-changing extensions require their own cache adapter. */
    public static function isReviewedCachePipeline(): bool
    {
        $endHandlers = [
            'Piwik\\Plugins\\PagePerformance\\PagePerformance',
            'Piwik\\Plugins\\GoogleAnalyticsImporter\\GoogleAnalyticsImporter',
        ];
        $renameHandlers = [
            'Piwik\\Plugins\\Referrers\\Referrers',
            'Piwik\\Plugins\\ScheduledReports\\ScheduledReports',
            'Piwik\\Plugins\\RssWidget\\RssWidget',
        ];
        foreach (\Piwik\Plugin\Manager::getInstance()->getPluginsLoadedAndActivated() as $plugin) {
            if ($plugin->getPluginName() === 'SpeoReportPerformance') {
                continue;
            }
            $hooks = $plugin->registerEvents();
            if (
                isset($hooks['Request.getRenamedModuleAndAction'])
                && (!in_array(get_class($plugin), $renameHandlers, true) || !self::validHandler($hooks['Request.getRenamedModuleAndAction'], $plugin))
            ) {
                return false;
            }
            if (
                isset($hooks['Actions.Archiving.addActionMetrics'])
                && !(get_class($plugin) === 'Piwik\\Plugins\\PagePerformance\\PagePerformance'
                    && self::validHandler($hooks['Actions.Archiving.addActionMetrics'], $plugin))
            ) {
                return false;
            }
            if (isset($hooks['API.Actions.getPageUrls.end'])) {
                return false;
            }
            if (
                isset($hooks['API.Request.dispatch.end'])
                && (!in_array(get_class($plugin), $endHandlers, true) || !self::validHandler($hooks['API.Request.dispatch.end'], $plugin))
            ) {
                return false;
            }
        }
        return true;
    }

    /** Callback spelling belongs to the registering plugin, not this adapter. */
    private static function validHandler(mixed $hook, object $plugin): bool
    {
        $method = self::handlerMethod($hook, $plugin);
        return $method !== null && is_callable([$plugin, $method]);
    }

    public static function publicMethod(string $class, string $method): bool
    {
        try {
            return (new \ReflectionMethod($class, $method))->isPublic();
        } catch (\ReflectionException $missing) {
            return false;
        }
    }

    /** Both documented registerEvents callback declarations are equivalent. */
    public static function handlerMethod(mixed $hook, object $plugin): ?string
    {
        if (is_array($hook) && array_key_exists('function', $hook)) {
            $hook = $hook['function'];
        }
        if (is_string($hook)) {
            return $hook;
        }
        if (is_array($hook) && count($hook) === 2 && ($hook[0] ?? null) === $plugin && is_string($hook[1] ?? null)) {
            return $hook[1];
        }
        return null;
    }

    /** Changes invalidate old entries automatically, never disable new ones. */
    public static function runtimeFingerprint(): string
    {
        static $fingerprint;
        if ($fingerprint !== null) {
            return $fingerprint;
        }
        $parts = ['matomo' => \Piwik\Version::VERSION, 'php' => PHP_VERSION];
        foreach (
            ['core/API/DataTablePostProcessor.php', 'core/API/DataTableManipulator/Flattener.php',
            'core/Plugin/Visualization.php', 'core/DataTable.php', 'plugins/Actions/API.php',
            'plugins/CoreVisualizations/Visualizations/HtmlTable.php'] as $file
        ) {
            $path = PIWIK_DOCUMENT_ROOT . '/' . $file;
            $parts[$file] = is_file($path) ? hash_file('sha256', $path) : 'not-present';
        }
        foreach (glob(__DIR__ . '/*.php') ?: [] as $path) {
            if (
                basename($path) === 'autoload-for-private-tests.php'
                || in_array(basename($path), ['ModernArchiveDecoder.php', 'PrimitiveArrayCodec.php'], true)
            ) {
                continue;
            }
            $parts['own/' . basename($path)] = hash_file('sha256', $path);
        }
        return $fingerprint = hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public static function isReviewedPrivacyManagerDecision(object $plugin, $hook): bool
    {
        return get_class($plugin) === 'Piwik\\Plugins\\PrivacyManager\\PrivacyManager'
            && self::validHandler($hook, $plugin);
    }
}
