<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Cascades a sales-chain document date both ways across
 * Sales Inquiry ↔ Sales Order ↔ Order Slip ↔ Invoice.
 */
final class SalesDocumentDateCascade
{
    public static function apply(PDO $pdo, int $mainId, string $documentDateYmd, array $anchors): void
    {
        $ymd = substr(trim($documentDateYmd), 0, 10);
        if ($mainId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return;
        }

        $inquiryRefno = trim((string) ($anchors['inquiry_refno'] ?? ''));
        $salesOrderRefno = trim((string) ($anchors['sales_order_refno'] ?? ''));
        $orderSlipRefno = trim((string) ($anchors['order_slip_refno'] ?? ''));
        $invoiceRefno = trim((string) ($anchors['invoice_refno'] ?? ''));

        if ($salesOrderRefno === '' && $inquiryRefno !== '') {
            $stmt = $pdo->prepare(
                'SELECT lrefno FROM tbltransaction
                 WHERE lmain_id = :main_id AND linquiry_refno = :inquiry_refno
                 LIMIT 1'
            );
            $stmt->execute(['main_id' => (string) $mainId, 'inquiry_refno' => $inquiryRefno]);
            $salesOrderRefno = trim((string) ($stmt->fetchColumn() ?: ''));
        }

        if ($inquiryRefno === '' && $salesOrderRefno !== '') {
            $stmt = $pdo->prepare(
                'SELECT linquiry_refno FROM tbltransaction
                 WHERE lmain_id = :main_id AND lrefno = :sales_refno LIMIT 1'
            );
            $stmt->execute(['main_id' => (string) $mainId, 'sales_refno' => $salesOrderRefno]);
            $inquiryRefno = trim((string) ($stmt->fetchColumn() ?: ''));
        }

        if ($salesOrderRefno === '' && $orderSlipRefno !== '') {
            $stmt = $pdo->prepare(
                'SELECT lsales_refno FROM tbldelivery_receipt
                 WHERE lmain_id = :main_id AND lrefno = :os_refno LIMIT 1'
            );
            $stmt->execute(['main_id' => (string) $mainId, 'os_refno' => $orderSlipRefno]);
            $salesOrderRefno = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($inquiryRefno === '' && $salesOrderRefno !== '') {
                $stmt = $pdo->prepare(
                    'SELECT linquiry_refno FROM tbltransaction
                     WHERE lmain_id = :main_id AND lrefno = :sales_refno LIMIT 1'
                );
                $stmt->execute(['main_id' => (string) $mainId, 'sales_refno' => $salesOrderRefno]);
                $inquiryRefno = trim((string) ($stmt->fetchColumn() ?: ''));
            }
        }

        if ($salesOrderRefno === '' && $invoiceRefno !== '') {
            $stmt = $pdo->prepare(
                'SELECT lsales_refno FROM tblinvoice_list
                 WHERE lmain_id = :main_id AND lrefno = :invoice_refno LIMIT 1'
            );
            $stmt->execute(['main_id' => (string) $mainId, 'invoice_refno' => $invoiceRefno]);
            $salesOrderRefno = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($inquiryRefno === '' && $salesOrderRefno !== '') {
                $stmt = $pdo->prepare(
                    'SELECT linquiry_refno FROM tbltransaction
                     WHERE lmain_id = :main_id AND lrefno = :sales_refno LIMIT 1'
                );
                $stmt->execute(['main_id' => (string) $mainId, 'sales_refno' => $salesOrderRefno]);
                $inquiryRefno = trim((string) ($stmt->fetchColumn() ?: ''));
            }
        }

        if ($inquiryRefno !== '') {
            $upd = $pdo->prepare(
                'UPDATE tblinquiry SET ldate = :ldate
                 WHERE lmain_id = :main_id AND lrefno = :refno'
            );
            $upd->execute(['ldate' => $ymd, 'main_id' => (string) $mainId, 'refno' => $inquiryRefno]);
        }

        if ($salesOrderRefno !== '') {
            $upd = $pdo->prepare(
                'UPDATE tbltransaction SET ldate = :ldate
                 WHERE lmain_id = :main_id AND lrefno = :refno'
            );
            $upd->execute(['ldate' => $ymd, 'main_id' => (string) $mainId, 'refno' => $salesOrderRefno]);

            $updOs = $pdo->prepare(
                'UPDATE tbldelivery_receipt SET ldate = :ldate
                 WHERE lmain_id = :main_id AND lsales_refno = :sales_refno'
            );
            $updOs->execute(['ldate' => $ymd, 'main_id' => (string) $mainId, 'sales_refno' => $salesOrderRefno]);

            $updInv = $pdo->prepare(
                'UPDATE tblinvoice_list SET ldate = :ldate
                 WHERE lmain_id = :main_id AND lsales_refno = :sales_refno'
            );
            $updInv->execute(['ldate' => $ymd, 'main_id' => (string) $mainId, 'sales_refno' => $salesOrderRefno]);
        }

        if ($orderSlipRefno !== '') {
            $upd = $pdo->prepare(
                'UPDATE tbldelivery_receipt SET ldate = :ldate
                 WHERE lmain_id = :main_id AND lrefno = :refno'
            );
            $upd->execute(['ldate' => $ymd, 'main_id' => (string) $mainId, 'refno' => $orderSlipRefno]);
        }

        if ($invoiceRefno !== '') {
            $upd = $pdo->prepare(
                'UPDATE tblinvoice_list SET ldate = :ldate
                 WHERE lmain_id = :main_id AND lrefno = :refno'
            );
            $upd->execute(['ldate' => $ymd, 'main_id' => (string) $mainId, 'refno' => $invoiceRefno]);
        }
    }
}
