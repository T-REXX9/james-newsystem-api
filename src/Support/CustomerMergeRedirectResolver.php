<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class CustomerMergeRedirectResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{session_id: string, redirected: bool, redirect: array<string, mixed>|null} */
    public function resolve(int $mainId, string $sessionId): array
    {
        $original = trim($sessionId);
        $current = $original;
        $lastRedirect = null;
        $seen = [];

        try {
            while ($current !== '' && !isset($seen[$current])) {
                $seen[$current] = true;
                $stmt = $this->pdo->prepare(
                    'SELECT old_customer_session_id, surviving_customer_session_id, merge_id, merged_at, merged_by
                     FROM customer_merge_redirects
                     WHERE main_id = :main_id AND old_customer_session_id = :old_id
                     LIMIT 1'
                );
                $stmt->execute(['main_id' => $mainId, 'old_id' => $current]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    break;
                }
                $lastRedirect = $row;
                $current = trim((string) ($row['surviving_customer_session_id'] ?? ''));
            }
        } catch (Throwable) {
            // The merge migration may not be installed on older tenants. In
            // that case the original session remains the only safe value.
            return ['session_id' => $original, 'redirected' => false, 'redirect' => null];
        }

        return [
            'session_id' => $current !== '' ? $current : $original,
            'redirected' => $current !== $original,
            'redirect' => $lastRedirect,
        ];
    }
}
