<?php
/** Real local child processes inside the fixture's isolated network namespace. */
declare(strict_types=1);

use Piwik\Plugins\SpeoReportPerformance\WarmupProcess;

require dirname(__DIR__) . '/bootstrap.php';
speoFixtureBootstrap('warmup-process', true);
require SPEO_PLUGIN_ROOT . '/WarmupProcess.php';
$cases = [];
function workerExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function workerCheck(string $name, callable $test): void
{
    global $cases;
    try { $test(); $cases[] = ['case' => $name, 'status' => 'pass']; }
    catch (Throwable $error) { $cases[] = ['case' => $name, 'status' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()]; }
}
workerCheck('argument-list-is-literal-and-standard-input-is-closed', function () {
    $literal = 'space ; $(echo unsafe) `echo unsafe` "quote"';
    $worker = new WarmupProcess([PHP_BINARY, '-r', 'echo json_encode([$argv[1], stream_get_contents(STDIN)]);', $literal]);
    $worker->setTimeout(2);
    workerExpect($worker->run() === 0 && $worker->isSuccessful(), 'Worker failed');
    workerExpect(json_decode($worker->getOutput(), true, 4, JSON_THROW_ON_ERROR) === [$literal, ''], 'Shell or input semantics changed');
});
workerCheck('standard-error-is-not-retained', function () {
    $worker = new WarmupProcess([PHP_BINARY, '-r', 'fwrite(STDERR, "private-trace-marker"); echo "{\"status\":\"warm\"}";']);
    $worker->setTimeout(2); $worker->run();
    workerExpect($worker->getOutput() === '{"status":"warm"}', 'Private standard-error output leaked');
});
workerCheck('nonzero-exit-is-preserved', function () {
    $worker = new WarmupProcess([PHP_BINARY, '-r', 'exit(7);']);
    $worker->setTimeout(2);
    workerExpect($worker->run() === 7 && !$worker->isSuccessful(), 'Failure exit code lost');
});
workerCheck('timeout-terminates-and-reaps-worker', function () {
    $worker = new WarmupProcess([PHP_BINARY, '-r', 'echo getmypid(), "\n"; sleep(5);']);
    $worker->setTimeout(0.1); $started = microtime(true); $stopped = false;
    try { $worker->run(); } catch (RuntimeException $error) { $stopped = true; }
    workerExpect($stopped && microtime(true) - $started < 1.5 && !$worker->isSuccessful(), 'Timeout failed to bound worker');
    $pid = (int)trim($worker->getOutput());
    if ($pid > 0 && function_exists('posix_kill')) { workerExpect(!posix_kill($pid, 0), 'Timed-out worker is still alive'); }
});
workerCheck('excess-output-is-bounded', function () {
    $worker = new WarmupProcess([PHP_BINARY, '-r', 'echo str_repeat("x", 1000000);']);
    $worker->setTimeout(2); $declined = false;
    try { $worker->run(); } catch (RuntimeException $error) { $declined = true; }
    workerExpect($declined && strlen($worker->getOutput()) <= 65536, 'Excess worker output grew without a bound');
});
$failed = count(array_filter($cases, static fn(array $case): bool => $case['status'] !== 'pass'));
$report = ['fixture' => 'warmup-process', 'application_bootstrap' => false, 'database_or_customer_reports' => false,
    'checks' => count($cases), 'passed' => count($cases) - $failed, 'failed' => $failed, 'results' => $cases];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
speoFixtureReport('warmup-process', $json); echo $json;
exit($failed ? 1 : 0);
