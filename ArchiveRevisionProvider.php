<?php

/** Speo Report Performance. Copyright (c) 2026 Rocketsky. GPL-3.0-or-later. */

declare(strict_types=1);

namespace Piwik\Plugins\SpeoReportPerformance;

use Piwik\Common;
use Piwik\Db;
use Piwik\Period;

/**
 * Own read-only revision adapter over the documented archive_numeric schema.
 * Does not duplicate ArchiveSelector's private selection/segment algorithms.
 * Every publication/invalidation flag in this site's month is included; an
 * unrelated segment may cause an extra miss, never an incorrectly fresh hit.
 */
final class ArchiveRevisionProvider
{
    public static function read(int $idSite, Period $period): array
    {
        $start = $period->getDateStart();
        $table = Common::prefixTable('archive_numeric_' . $start->toString('Y_m'));
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) {
            throw new \UnexpectedValueException('Unsupported archive identifier');
        }
        $rows = Db::fetchAll(
            'SELECT idarchive, name, value, ts_archived FROM `' . $table . '`'
            . ' WHERE idsite = ? AND period = ? AND date1 = ? AND date2 = ? AND name LIKE ?'
            . ' ORDER BY idarchive, name, ts_archived, value',
            [$idSite, $period->getId(), $start->toString(), $period->getDateEnd()->toString(), 'done%']
        );
        if (!is_array($rows) || count($rows) > 100000) {
            throw new \UnexpectedValueException('Unsupported archive revision');
        }
        $revision = [];
        foreach ($rows as $row) {
            if (
                !is_array($row) || !isset($row['idarchive'], $row['name'], $row['value'], $row['ts_archived'])
                || !ctype_digit((string) $row['idarchive']) || (int) $row['idarchive'] < 1
                || !is_string($row['name']) || !str_starts_with($row['name'], 'done')
                || !is_scalar($row['value']) || !is_numeric($row['value']) || !is_finite((float) $row['value'])
                || !is_string($row['ts_archived'])
            ) {
                throw new \UnexpectedValueException('Unsupported archive revision row');
            }
            $revision[] = [(string) $row['idarchive'], $row['name'], (string) $row['value'], $row['ts_archived']];
        }
        return $revision;
    }
}
