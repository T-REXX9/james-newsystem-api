<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class SalesRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function getFlowByInquiryRef(string $inquiryRefno): ?array
    {
        $inqSql = <<<SQL
SELECT
    lrefno,
    linqno,
    lcustomerid,
    lsubmitstat,
    lso_refno,
    lso_no,
    ldate,
    ltime
FROM tblinquiry
WHERE lrefno = :refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($inqSql);
        $stmt->execute(['refno' => $inquiryRefno]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inquiry) {
            return null;
        }

        $salesOrder = null;
        $delivery = null;
        $invoice = null;

        if (!empty($inquiry['lso_refno'])) {
            $salesOrder = $this->getSalesOrder((string) $inquiry['lso_refno']);
        }

        if ($salesOrder !== null) {
            if (!empty($salesOrder['ldr_refno'])) {
                $delivery = $this->getDelivery((string) $salesOrder['ldr_refno']);
            }
            if (!empty($salesOrder['invoice_refno'])) {
                $invoice = $this->getInvoice((string) $salesOrder['invoice_refno']);
            }
        }

        return [
            'inquiry' => $inquiry,
            'sales_order' => $salesOrder,
            'order_slip' => $delivery,
            'invoice' => $invoice,
            'item_counts' => [
                'inquiry_items' => $this->countItems('tblinquiry_item', 'linq_refno', (string) $inquiry['lrefno']),
                'sales_items' => $salesOrder ? $this->countItems('tbltransaction_item', 'lrefno', (string) $salesOrder['lrefno']) : 0,
                'order_slip_items' => $delivery ? $this->countItems('tbldelivery_receipt_items', 'lor_refno', (string) $delivery['lrefno']) : 0,
                'invoice_items' => $invoice ? $this->countItems('tblinvoice_itemrec', 'linvoice_refno', (string) $invoice['lrefno']) : 0,
            ],
        ];
    }

    public function getFlowBySalesOrderRef(string $soRefno): ?array
    {
        $salesOrder = $this->getSalesOrder($soRefno);
        if ($salesOrder === null) {
            return null;
        }

        $inquiry = null;
        $delivery = null;
        $invoice = null;

        if (!empty($salesOrder['linquiry_refno'])) {
            $inquiry = $this->getInquiry((string) $salesOrder['linquiry_refno']);
        }
        if (!empty($salesOrder['ldr_refno'])) {
            $delivery = $this->getDelivery((string) $salesOrder['ldr_refno']);
        }
        if (!empty($salesOrder['invoice_refno'])) {
            $invoice = $this->getInvoice((string) $salesOrder['invoice_refno']);
        }

        return [
            'sales_order' => $salesOrder,
            'inquiry' => $inquiry,
            'order_slip' => $delivery,
            'invoice' => $invoice,
            'item_counts' => [
                'sales_items' => $this->countItems('tbltransaction_item', 'lrefno', (string) $salesOrder['lrefno']),
                'order_slip_items' => $delivery ? $this->countItems('tbldelivery_receipt_items', 'lor_refno', (string) $delivery['lrefno']) : 0,
                'invoice_items' => $invoice ? $this->countItems('tblinvoice_itemrec', 'linvoice_refno', (string) $invoice['lrefno']) : 0,
            ],
        ];
    }

    private function getInquiry(string $refno): ?array
    {
        $sql = <<<SQL
SELECT
    lrefno,
    linqno,
    lcustomerid,
    lsubmitstat,
    lso_refno,
    lso_no,
    ldate,
    ltime
FROM tblinquiry
WHERE lrefno = :refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['refno' => $refno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getSalesOrder(string $refno): ?array
    {
        $sql = <<<SQL
SELECT
    lrefno,
    lsaleno,
    lcustomerid,
    lsubmitstat,
    ltransaction_status,
    lcancel,
    linquiry_refno,
    linquiry_no,
    ldr_refno,
    ldr_no,
    invoice_refno,
    invoice_no,
    ldate,
    ltime
FROM tbltransaction
WHERE lrefno = :refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['refno' => $refno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getDelivery(string $refno): ?array
    {
        $sql = <<<SQL
SELECT
    lrefno,
    linvoice_no,
    lsales_refno,
    lsales_no,
    lcustomerid,
    lstatus,
    lcancel,
    ldate,
    ldatetime
FROM tbldelivery_receipt
WHERE lrefno = :refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['refno' => $refno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getInvoice(string $refno): ?array
    {
        $sql = <<<SQL
SELECT
    lrefno,
    linvoice_no,
    lsales_refno,
    lsales_no,
    lcustomerid,
    lstatus,
    lcancel_invoice,
    ldate,
    ldatetime
FROM tblinvoice_list
WHERE lrefno = :refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['refno' => $refno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function countItems(string $table, string $field, string $refno): int
    {
        $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$field} = :refno";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['refno' => $refno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['total'] ?? 0);
    }
}

