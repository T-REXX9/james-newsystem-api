<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class ReceivingDateSql
{
    public static function documentDate(string $receivingAlias): string
    {
        self::assertAlias($receivingAlias);

        return <<<SQL
CASE
    WHEN {$receivingAlias}.ldate IS NOT NULL
      AND TRIM(CAST({$receivingAlias}.ldate AS CHAR)) <> ''
      AND CAST({$receivingAlias}.ldate AS CHAR) NOT LIKE '0000-00-00%'
      AND {$receivingAlias}.ldate >= '2000-01-01'
        THEN {$receivingAlias}.ldate
    WHEN {$receivingAlias}.ldate_recieved IS NOT NULL
      AND TRIM(CAST({$receivingAlias}.ldate_recieved AS CHAR)) <> ''
      AND CAST({$receivingAlias}.ldate_recieved AS CHAR) NOT LIKE '0000-00-00%'
      AND {$receivingAlias}.ldate_recieved >= '2000-01-01'
        THEN {$receivingAlias}.ldate_recieved
    ELSE NULL
END
SQL;
    }

    public static function effectiveDate(string $logAlias, string $receivingAlias): string
    {
        self::assertAlias($logAlias);
        $documentDate = self::documentDate($receivingAlias);

        return <<<SQL
CASE
    WHEN ({$documentDate}) IS NOT NULL THEN ({$documentDate})
    WHEN {$logAlias}.ldateadded IS NOT NULL
      AND TRIM(CAST({$logAlias}.ldateadded AS CHAR)) <> ''
      AND CAST({$logAlias}.ldateadded AS CHAR) NOT LIKE '0000-00-00%'
      AND {$logAlias}.ldateadded >= '2000-01-01'
        THEN {$logAlias}.ldateadded
    ELSE NULL
END
SQL;
    }

    private static function assertAlias(string $alias): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Invalid SQL alias');
        }
    }
}
