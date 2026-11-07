<?php

/**
 * Native-class fixtures run with no Matomo application bootstrap or customer state.
 * The explicit opt-in and OS check are both required, even for a single fixture.
 */

declare(strict_types=1);

define(
    'SPEO_PLUGIN_ROOT',
    is_file(dirname(__DIR__) . '/plugin.json')
        ? dirname(__DIR__)
        : dirname(__DIR__) . '/src/SpeoReportPerformance'
);

function speoAssertIsolated(): void
{
    if (PHP_SAPI !== 'cli' || getenv('SPEO_TEST_ISOLATED') !== '1') {
        throw new RuntimeException(
            'Set SPEO_TEST_ISOLATED=1 and run in a separate network namespace or an offline container.'
        );
    }

    $self = @readlink('/proc/self/ns/net');
    $init = @readlink('/proc/1/ns/net');
    $separate = $self !== false && $init !== false && $self !== $init;
    $container = is_file('/.dockerenv') || is_file('/run/.containerenv');

    // /proc follows the current network namespace; a host sysfs mount may not.
    $devices = @file_get_contents('/proc/net/dev');
    $interfaces = [];
    if ($devices !== false) {
        preg_match_all('/^\s*([^:\s]+):/m', $devices, $matches);
        $interfaces = $matches[1];
    }
    $external = array_filter($interfaces, static fn(string $name): bool => $name !== 'lo');
    if ((!$separate && !$container) || $external !== [] || $interfaces === []) {
        throw new RuntimeException('Network isolation missing. Use Linux unshare --net or docker run --network none.');
    }
}

/** Defines paths before loading native classes; never loads Matomo bootstrap.php. */
function speoFixtureBootstrap(string $suite, bool $fakeDocumentRoot = false): void
{
    speoAssertIsolated();
    umask(0077);
    $matomo = getenv('MATOMO_ROOT');
    $matomo = $matomo === false ? false : realpath($matomo);
    if ($matomo === false || !is_file($matomo . '/vendor/autoload.php') || !is_file($matomo . '/core/Version.php')) {
        throw new RuntimeException(
            'MATOMO_ROOT must point to an extracted, original Matomo distribution with vendor dependencies.'
        );
    }
    $output = getenv('SPEO_TEST_OUTPUT');
    if ($output === false || $output === '') {
        $output = sys_get_temp_dir() . '/speo-tests-' . getmypid() . '-' . bin2hex(random_bytes(6));
        putenv('SPEO_TEST_OUTPUT=' . $output);
    }
    $runtime = $output . '/runtime/' . $suite . '-' . getmypid();
    if (!is_dir($runtime) && !mkdir($runtime, 0700, true)) {
        throw new RuntimeException('Cannot create private fixture runtime.');
    }
    define('PIWIK_INCLUDE_PATH', $matomo);
    define('PIWIK_DOCUMENT_ROOT', $fakeDocumentRoot ? $runtime . '/fixture/web' : $matomo);
    define('PIWIK_USER_PATH', $runtime . '/user');
    if (!mkdir(PIWIK_USER_PATH, 0700, true)) {
        throw new RuntimeException('Cannot create empty fixture user directory.');
    }
}

/** Keep generated artifacts out of the source tree and release archive. */
function speoFixtureReport(string $name, string $json): void
{
    $directory = (string) getenv('SPEO_TEST_OUTPUT');
    if ($directory === '' || (!is_dir($directory) && !mkdir($directory, 0700, true))) {
        throw new RuntimeException('Fixture output directory is unavailable.');
    }
    if (file_put_contents($directory . '/' . $name . '.json', $json) === false) {
        throw new RuntimeException('Cannot write fixture result.');
    }
}
