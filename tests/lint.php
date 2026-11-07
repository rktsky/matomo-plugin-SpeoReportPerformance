<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$failed = 0;
foreach ([SPEO_PLUGIN_ROOT, __DIR__] as $directory) {
    $files = new RecursiveDirectoryIterator($directory);
    $filter = new RecursiveCallbackFilterIterator($files, static function ($item): bool {
        if (!$item->isDir()) {
            return true;
        }
        return !in_array($item->getFilename(), ['.', '..', 'vendor', 'dist', '.git', '.cache', '.matomo', 'lab'], true);
    });
    $iterator = new RecursiveIteratorIterator($filter);
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $process = proc_open(
            [PHP_BINARY, '-l', $file->getPathname()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP linter.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            fwrite(STDERR, $stdout . $stderr);
            ++$failed;
        }
    }
}
echo $failed === 0 ? "PHP syntax checks passed.\n" : "PHP syntax checks failed.\n";
exit($failed === 0 ? 0 : 1);
