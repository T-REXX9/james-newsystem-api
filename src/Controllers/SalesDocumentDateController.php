<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Exceptions\HttpException;
use App\Support\SalesDocumentDateCascade;

final class SalesDocumentDateController
{
    public function __construct(private readonly Database $db)
    {
    }

    public function cascadeDate(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $salesDate = trim((string) ($body['sales_date'] ?? ''));
        if ($mainId <= 0 || $salesDate === '') {
            throw new HttpException(422, 'main_id and sales_date are required');
        }

        $anchors = [
            'inquiry_refno' => trim((string) ($body['inquiry_refno'] ?? '')),
            'sales_order_refno' => trim((string) ($body['sales_order_refno'] ?? '')),
            'order_slip_refno' => trim((string) ($body['order_slip_refno'] ?? '')),
            'invoice_refno' => trim((string) ($body['invoice_refno'] ?? '')),
        ];
        if (
            $anchors['inquiry_refno'] === ''
            && $anchors['sales_order_refno'] === ''
            && $anchors['order_slip_refno'] === ''
            && $anchors['invoice_refno'] === ''
        ) {
            throw new HttpException(422, 'At least one sales document reference is required');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            SalesDocumentDateCascade::apply($pdo, $mainId, $salesDate, $anchors);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'updated' => true,
            'sales_date' => substr($salesDate, 0, 10),
            'anchors' => $anchors,
        ];
    }
}
