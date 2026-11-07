<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

/** Small shell-free worker runner, independent of Matomo's bundled vendor versions. */
final class WarmupProcess
{
    private float $timeout = 120.0;
    private string $output = '';
    private int $exitCode = -1;

    public function __construct(private array $command)
    {
        if ($command === [] || count(array_filter($command, 'is_string')) !== count($command)) {
            throw new \InvalidArgumentException('Worker command must be an argument list');
        }
    }

    public function setTimeout(float $timeout): void
    {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('Positive worker timeout required');
        }
        $this->timeout = $timeout;
    }

    public function getOutput(): string
    {
        return $this->output;
    }
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function run(): int
    {
        if (PHP_SAPI !== 'cli' || !function_exists('proc_open')) {
            throw new \RuntimeException('CLI process support unavailable');
        }
        $pipes = [];
        $process = @proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Worker could not start');
        }
        $this->output = '';
        $this->exitCode = -1;
        $finished = false;
        try {
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + $this->timeout;
            do {
                $status = proc_get_status($process);
                foreach ([1, 2] as $index) {
                    $chunk = stream_get_contents($pipes[$index], 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Worker output unavailable');
                    }
                    if ($index === 1) {
                        if (strlen($this->output) + strlen($chunk) > 16384) {
                            $this->output = substr($this->output . $chunk, 0, 16384);
                            throw new \RuntimeException('Worker output limit exceeded');
                        }
                        $this->output .= $chunk;
                    }
                }
                // Console workers emit one tiny JSON result. Discard stderr and
                // bound stdout rather than retain accidental reports or traces.
                if (!$status['running']) {
                    $this->exitCode = (int)$status['exitcode'];
                    $finished = true;
                    break;
                }
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new \RuntimeException('Worker time limit exceeded');
                }
                usleep((int)(min($remaining, 0.01) * 1000000));
            } while (true);
        } finally {
            if (!$finished) {
                proc_terminate($process);
                usleep(10000);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $closedExitCode = proc_close($process);
            if ($finished && $this->exitCode === -1) {
                $this->exitCode = $closedExitCode;
            }
        }
        return $this->exitCode;
    }
}
