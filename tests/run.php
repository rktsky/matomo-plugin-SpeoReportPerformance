<?php

/** Each fixture owns its native service doubles and therefore needs a new process. */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
speoAssertIsolated();

$output = getenv('SPEO_TEST_OUTPUT');
if ($output === false || $output === '') {
    $output = sys_get_temp_dir() . '/speo-tests-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    putenv('SPEO_TEST_OUTPUT=' . $output);
}
if (!is_dir($output) && !mkdir($output, 0700, true)) {
    throw new RuntimeException('Cannot create test output directory.');
}

$fixtures = [
    ['native-api-source-contract.php'],
    ['ui-numeric-page-cache.php'],
    ['ui-base-preloader.php'],
    ['ui-pending-requests.php'],
    ['public-prepared-flat.php'],
    ['native-api-json.php'],
    ['root-memory-contract-review.php'],
    ['future-version-contract.php'],
    ['warm-request-key.php'],
    ['warmup.php', 'helper'],
    ['warmup.php', 'command'],
    ['configuration.php'],
    ['warmup-process.php'],
];
$failed = 0;
$results = [];
foreach ($fixtures as $fixture) {
    $name = implode(' ', $fixture);
    $fixturesRoot = is_file(SPEO_PLUGIN_ROOT . '/tests/native-api-json.php')
        ? SPEO_PLUGIN_ROOT . '/tests'
        : __DIR__ . '/Fixtures';
    $file = $fixturesRoot . '/' . array_shift($fixture);
    $command = [PHP_BINARY, '-d', 'memory_limit=2G', $file, ...$fixture];
    $started = microtime(true);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start fixture ' . $name);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    file_put_contents($output . '/' . str_replace(' ', '-', $name) . '.stdout', $stdout);
    file_put_contents($output . '/' . str_replace(' ', '-', $name) . '.stderr', $stderr);
    $failed += (int) ($status !== 0);
    $results[] = ['fixture' => $name, 'exit' => $status, 'seconds' => round(microtime(true) - $started, 3)];
    echo ($status === 0 ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if ($status !== 0) {
        fwrite(STDERR, $stdout . $stderr);
    }
}
$summary = ['php' => PHP_VERSION, 'matomo_root' => getenv('MATOMO_ROOT'), 'fixtures' => $results, 'failed' => $failed];
file_put_contents($output . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
echo 'Results: ' . $output . PHP_EOL;
exit($failed === 0 ? 0 : 1);
