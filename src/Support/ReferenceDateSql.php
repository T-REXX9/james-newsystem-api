<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class ReferenceDateSql
{
    public static function createdAtFromReference(string $referenceExpression): string
    {
        self::assertColumnExpression($referenceExpression);
        $reference = $referenceExpression;
        $parsed = "STR_TO_DATE(LEFT({$reference}, 14), '%Y%m%d%H%i%s')";

        return <<<SQL
CASE
    WHEN CAST({$reference} AS CHAR) REGEXP '^[0-9]{14}[0-9]*$'
      AND {$parsed} IS NOT NULL
      AND DATE_FORMAT({$parsed}, '%Y%m%d%H%i%s') = LEFT(CAST({$reference} AS CHAR), 14)
        THEN {$parsed}
    ELSE NULL
END
SQL;
    }

    public static function createdAt(string $referenceExpression, string $createdAtExpression = ''): string
    {
        $referenceCreatedAt = self::createdAtFromReference($referenceExpression);
        if ($createdAtExpression === '') {
            return $referenceCreatedAt;
        }

        self::assertColumnExpression($createdAtExpression);

        return <<<SQL
CASE
    WHEN {$createdAtExpression} IS NOT NULL
      AND TRIM(CAST({$createdAtExpression} AS CHAR)) <> ''
      AND CAST({$createdAtExpression} AS CHAR) NOT LIKE '0000-00-00%'
        THEN {$createdAtExpression}
    ELSE ({$referenceCreatedAt})
END
SQL;
    }

    public static function createdAtSource(string $referenceExpression, string $createdAtExpression = ''): string
    {
        $referenceCreatedAt = self::createdAtFromReference($referenceExpression);
        if ($createdAtExpression === '') {
            return "CASE WHEN ({$referenceCreatedAt}) IS NOT NULL THEN 'Timestamp encoded in database reference' ELSE NULL END";
        }

        self::assertColumnExpression($createdAtExpression);

        return <<<SQL
CASE
    WHEN {$createdAtExpression} IS NOT NULL
      AND TRIM(CAST({$createdAtExpression} AS CHAR)) <> ''
      AND CAST({$createdAtExpression} AS CHAR) NOT LIKE '0000-00-00%'
        THEN 'Database created_at'
    WHEN ({$referenceCreatedAt}) IS NOT NULL THEN 'Timestamp encoded in database reference'
    ELSE NULL
END
SQL;
    }

    public static function validDate(string $dateExpression): string
    {
        self::assertColumnExpression($dateExpression);

        return <<<SQL
CASE
    WHEN {$dateExpression} IS NULL
      OR TRIM(CAST({$dateExpression} AS CHAR)) = ''
      OR CAST({$dateExpression} AS CHAR) LIKE '0000-00-00%'
        THEN NULL
    ELSE {$dateExpression}
END
SQL;
    }

    public static function isBackdated(string $documentDateExpression, string $createdAtExpression): string
    {
        return <<<SQL
CASE
    WHEN ({$documentDateExpression}) IS NULL OR ({$createdAtExpression}) IS NULL THEN NULL
    WHEN DATE({$documentDateExpression}) < DATE({$createdAtExpression}) THEN 1
    ELSE 0
END
SQL;
    }

    private static function assertColumnExpression(string $expression): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $expression)) {
            throw new InvalidArgumentException('Invalid SQL column expression');
        }
    }
}
