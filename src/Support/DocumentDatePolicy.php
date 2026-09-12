<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Document-date write rules for Backdated posting (TASK-70).
 */
final class DocumentDatePolicy
{
    /**
     * @return array{ok: true}|array{ok: false, reason: string}
     */
    public static function validateWrite(
        bool $hasBackdatedPosting,
        string $proposedYmd,
        ?string $previousYmd,
        string $todayYmd
    ): array {
        $proposed = substr($proposedYmd, 0, 10);
        $today = substr($todayYmd, 0, 10);
        $previous = $previousYmd !== null && $previousYmd !== '' ? substr($previousYmd, 0, 10) : null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $proposed)) {
            return ['ok' => false, 'reason' => 'Document date is required.'];
        }
        if ($proposed > $today) {
            return ['ok' => false, 'reason' => 'Document date cannot be in the future.'];
        }
        if ($previous !== null && $proposed === $previous) {
            return ['ok' => true];
        }
        if ($hasBackdatedPosting) {
            return ['ok' => true];
        }
        if ($proposed < $today) {
            return ['ok' => false, 'reason' => 'Backdated posting permission is required to use a past document date.'];
        }
        if ($previous !== null && $previous < $today && $proposed !== $previous) {
            return ['ok' => false, 'reason' => 'Backdated posting permission is required to change a past document date.'];
        }

        return ['ok' => true];
    }

    public static function todayYmd(?\DateTimeInterface $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        return $now->format('Y-m-d');
    }
}
