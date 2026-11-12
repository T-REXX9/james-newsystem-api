<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\HttpException;
use PDO;

final class SalesInquiryEditGuard
{
    public static function assertCanEdit(PDO $pdo, int $mainId, string $inquiryRefno, int $actorId, bool $isMasterUser): void
    {
        $confirmed = $pdo->prepare('SELECT 1 FROM sales_inquiry_confirmations WHERE main_id = :main_id AND inquiry_refno = :ref LIMIT 1');
        $confirmed->execute(['main_id' => $mainId, 'ref' => $inquiryRefno]);
        if ($confirmed->fetchColumn() === false) {
            return;
        }

        $approved = $pdo->prepare("SELECT 1 FROM sales_inquiry_edit_requests WHERE main_id = :main_id AND inquiry_refno = :ref AND requested_by = :user_id AND status = 'approved' LIMIT 1");
        $approved->execute(['main_id' => $mainId, 'ref' => $inquiryRefno, 'user_id' => $actorId]);
        if (!SalesInquiryEditPolicy::canEditConfirmedInquiry($isMasterUser, $approved->fetchColumn() !== false)) {
            throw new HttpException(403, 'This confirmed inquiry is read-only. Request edit permission from the Master User.');
        }
    }
}
