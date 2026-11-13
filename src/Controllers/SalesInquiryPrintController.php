<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Exceptions\HttpException;
use PDO;

final class SalesInquiryPrintController
{
    public function __construct(private readonly Database $db) {}

    public function confirmInquiry(array $params, array $query, array $body): array
    {
        [$mainId] = $this->context($body);
        $ref = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($ref === '') throw new HttpException(422, 'inquiryRefno is required');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT COALESCE(IsCancel, 0) AS is_cancelled FROM tblinquiry WHERE lmain_id = :main_id AND lrefno = :ref FOR UPDATE');
            $check->execute(['main_id' => $mainId, 'ref' => $ref]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if ($existing === false) throw new HttpException(404, 'Sales inquiry not found');
            if ((int) $existing['is_cancelled'] > 0) throw new HttpException(409, 'A cancelled inquiry cannot be confirmed');

            $insert = $pdo->prepare('INSERT IGNORE INTO sales_inquiry_confirmations (main_id, inquiry_refno, confirmed_at) VALUES (:main_id, :ref, NOW())');
            $insert->execute(['main_id' => $mainId, 'ref' => $ref]);
            $read = $pdo->prepare('SELECT confirmed_at FROM sales_inquiry_confirmations WHERE main_id = :main_id AND inquiry_refno = :ref');
            $read->execute(['main_id' => $mainId, 'ref' => $ref]);
            $confirmedAt = (string) $read->fetchColumn();
            $pdo->commit();
            return ['confirmed' => true, 'confirmed_at' => $confirmedAt];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return array{int} */
    private function context(array $body): array
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $mainId = (int) ($claims['main_userid'] ?? 0);
        if ($mainId <= 0) throw new HttpException(401, 'Authenticated account required');
        return [$mainId];
    }
}