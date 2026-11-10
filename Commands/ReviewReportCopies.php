<?php

/**
 * Apply one separately reviewed finite inventory of historical own report copies.
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\SpeoControl\RetentionDictionaryBarrier;
use Piwik\Plugins\SpeoReportPerformance\CopyLifecycle;

/**
 * Native operator command only; it cannot enumerate keys, approve discovery or flush the shared cache.
 * @since 1.3.0
 */
final class ReviewReportCopies extends ConsoleCommand
{
    /**
     * Require a private already-reviewed inventory instead of accepting keys as shell/browser fields.
     * @return void Original native command metadata and one required file option.
     * @since 1.3.0
     */
    protected function configure(): void
    {
        $this->setName('speo:report-copy-baseline');
        $this->setDescription('Remove only the exact separately reviewed historical own cache keys.');
        $this->addRequiredValueOption('intent', null, 'Existing private mode0600 source/backend/drain-bound inventory outside the native webroot.');
    }

    /**
     * Hold the actual original Control EX boundary across exact-key eviction and native absence readback.
     * @return int Success only after the durable native baseline receipt was independently read back.
     * @throws \RuntimeException For unsafe private input, missing Control, a busy publisher or uncertain cache state.
     * @since 1.3.0
     */
    protected function doExecute(): int
    {
        $path = (string) $this->getInput()->getOption('intent');
        $real = realpath($path);
        if (
            !$real || is_link($path) || !is_file($real) || (fileperms($real) & 0077) !== 0
            || function_exists('posix_geteuid') && fileowner($real) !== posix_geteuid()
            || str_starts_with($real, PIWIK_INCLUDE_PATH . '/') || filesize($real) > 4194304
            || !class_exists(RetentionDictionaryBarrier::class)
        ) {
            throw new \RuntimeException('private_reviewed_copy_inventory_required');
        }
        $intent = json_decode((string) file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($intent)) {
            throw new \RuntimeException('reviewed_copy_inventory_object_required');
        }
        $window = RetentionDictionaryBarrier::cleanup(static fn(): array => CopyLifecycle::applyReviewedLegacyBaseline($intent));
        if (!$window['acquired']) {
            throw new \RuntimeException('native_copy_baseline_writer_busy');
        }
        $this->getOutput()->writeln(json_encode($window['result'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
