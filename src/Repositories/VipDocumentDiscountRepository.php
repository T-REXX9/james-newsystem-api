<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\VipDocumentDiscount;
use App\Support\PostedSalesDocumentSql;
use PDO;

final class VipDocumentDiscountRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function ensureTable(): void
    {
        $pdo = $this->db->pdo();
        $tableExistsSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1"
            : 'SELECT 1
               FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = :table_name
               LIMIT 1';
        $exists = $pdo->prepare($tableExistsSql);
        $exists->execute(['table_name' => 'tblvip_document_discount']);
        if ($exists->fetchColumn()) {
            return;
        }

        if ($pdo->inTransaction()) {
            throw new \RuntimeException(
                'VIP document discount storage is missing; apply the API database migrations before creating sales documents.'
            );
        }

        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS tblvip_document_discount (
                    lid INT AUTO_INCREMENT PRIMARY KEY,
                    lmain_id INT NOT NULL,
                    ldocument_type VARCHAR(32) NOT NULL,
                    ldocument_refno VARCHAR(64) NOT NULL,
                    lcustomerid VARCHAR(64) DEFAULT NULL,
                    lsales_date VARCHAR(32) DEFAULT NULL,
                    lapplied TINYINT NOT NULL DEFAULT 0,
                    ltier VARCHAR(16) NOT NULL DEFAULT \'regular\',
                    lpercentage DECIMAL(10,2) NOT NULL DEFAULT 0,
                    ldiscount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                    ltotal_to_pay DECIMAL(15,2) NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_vip_doc (ldocument_type, ldocument_refno)
                )'
            );
        } catch (\Throwable $e) {
            // Table may already exist.
        }
    }

    /**
     * @return array{
     *   vip_applied:int,
     *   vip_tier:string,
     *   vip_percentage:float,
     *   vip_discount_amount:float,
     *   total_to_pay:float
     * }|null
     */
    public function get(string $documentType, string $documentRefno): ?array
    {
        $this->ensureTable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT lapplied, ltier, lpercentage, ldiscount_amount, ltotal_to_pay
             FROM tblvip_document_discount
             WHERE ldocument_type = :type AND ldocument_refno = :refno
             LIMIT 1'
        );
        $stmt->execute([
            'type' => $documentType,
            'refno' => $documentRefno,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'vip_applied' => (int) ($row['lapplied'] ?? 0),
            'vip_tier' => (string) ($row['ltier'] ?? 'regular'),
            'vip_percentage' => (float) ($row['lpercentage'] ?? 0),
            'vip_discount_amount' => (float) ($row['ldiscount_amount'] ?? 0),
            'total_to_pay' => (float) ($row['ltotal_to_pay'] ?? 0),
        ];
    }

    /**
     * @param list<string> $documentRefnos
     * @return array<string, array{vip_applied:int,vip_tier:string,vip_percentage:float,vip_discount_amount:float,total_to_pay:float}>
     */
    public function mapForType(string $documentType, array $documentRefnos): array
    {
        $refs = array_values(array_filter(array_map('strval', $documentRefnos)));
        if ($refs === []) {
            return [];
        }
        $this->ensureTable();
        $placeholders = [];
        $params = ['type' => $documentType];
        foreach ($refs as $idx => $ref) {
            $key = 'r' . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = $ref;
        }
        $sql = 'SELECT ldocument_refno, lapplied, ltier, lpercentage, ldiscount_amount, ltotal_to_pay
                FROM tblvip_document_discount
                WHERE ldocument_type = :type AND ldocument_refno IN (' . implode(', ', $placeholders) . ')';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ref = (string) ($row['ldocument_refno'] ?? '');
            $out[$ref] = [
                'vip_applied' => (int) ($row['lapplied'] ?? 0),
                'vip_tier' => (string) ($row['ltier'] ?? 'regular'),
                'vip_percentage' => (float) ($row['lpercentage'] ?? 0),
                'vip_discount_amount' => (float) ($row['ldiscount_amount'] ?? 0),
                'total_to_pay' => (float) ($row['ltotal_to_pay'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function attach(array $row, string $documentType, string $documentRefno, float $grandTotal): array
    {
        $vip = $this->get($documentType, $documentRefno);
        if ($vip === null) {
            $row['vip_applied'] = 0;
            $row['vip_tier'] = 'regular';
            $row['vip_percentage'] = 0.0;
            $row['vip_discount_amount'] = 0.0;
            $row['total_to_pay'] = $grandTotal;
            return $row;
        }

        return array_merge($row, $vip);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsertFromPayload(
        int $mainId,
        string $documentType,
        string $documentRefno,
        string $contactId,
        string $salesDate,
        float $grandTotal,
        array $payload
    ): void {
        $this->ensureTable();
        $applied = (int) ($payload['vip_applied'] ?? 0) > 0;
        $tier = strtolower(trim((string) ($payload['vip_tier'] ?? 'regular')));
        $percentage = (float) ($payload['vip_percentage'] ?? 0);
        $this->writeWithSilverRedemptionGuard(
            $mainId,
            $documentType,
            $documentRefno,
            $contactId,
            $salesDate,
            $grandTotal,
            $tier,
            $percentage,
            $applied
        );
    }

    public function copyTo(
        int $mainId,
        string $sourceType,
        string $sourceRefno,
        string $destType,
        string $destRefno,
        string $contactId,
        string $salesDate,
        float $grandTotal
    ): void {
        $source = $this->get($sourceType, $sourceRefno);
        if ($source === null) {
            return;
        }

        $tier = (string) $source['vip_tier'];
        $percentage = (float) $source['vip_percentage'];
        $this->writeWithSilverRedemptionGuard(
            $mainId,
            $destType,
            $destRefno,
            $contactId,
            $salesDate,
            $grandTotal,
            $tier,
            $percentage,
            ((int) $source['vip_applied']) > 0
        );
    }

    /** @return list<string> Month keys for Silver discounts redeemed by actual documents. */
    public function redeemedSilverBenefitMonths(int $mainId, string $contactId): array
    {
        return $this->queryRedeemedSilverBenefitMonths($mainId, $contactId);
    }

    /** @return list<string> */
    private function queryRedeemedSilverBenefitMonths(
        int $mainId,
        string $contactId,
        string $excludedType = '',
        string $excludedRefno = '',
        bool $forUpdate = false
    ): array
    {
        $this->ensureTable();
        $pdo = $this->db->pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $invoicePosted = PostedSalesDocumentSql::invoiceIsPosted('invoice');
        $receiptPosted = PostedSalesDocumentSql::deliveryReceiptIsPosted('receipt');
        $lockingRead = $forUpdate && $pdo->inTransaction() && $driver !== 'sqlite' ? 'FOR UPDATE' : '';
        $excludeCurrentDocument = $excludedType !== '' && $excludedRefno !== ''
            ? 'AND NOT (discount.ldocument_type = :excluded_type AND discount.ldocument_refno = :excluded_refno)'
            : '';
        $stmt = $this->db->pdo()->prepare(<<<SQL
SELECT discount.lsales_date, discount.ldocument_type, discount.ldocument_refno
FROM tblvip_document_discount discount
LEFT JOIN tbldelivery_receipt receipt
  ON receipt.lmain_id = discount.lmain_id
 AND receipt.lrefno = discount.ldocument_refno
 AND receipt.lcustomerid = discount.lcustomerid
LEFT JOIN tblinvoice_list invoice
  ON invoice.lmain_id = discount.lmain_id
 AND invoice.lrefno = discount.ldocument_refno
 AND invoice.lcustomerid = discount.lcustomerid
WHERE discount.lmain_id = :main_id
  AND discount.lcustomerid = :customer_id
  AND discount.lapplied = 1
  AND LOWER(TRIM(COALESCE(discount.ltier, ''))) = 'silver'
  AND discount.ldocument_type IN ('order_slip', 'invoice')
  {$excludeCurrentDocument}
  AND (
      (discount.ldocument_type = 'order_slip'
       AND receipt.lrefno IS NOT NULL
       AND {$receiptPosted}
       AND LOWER(TRIM(COALESCE(receipt.lstatus, ''))) <> 'cancelled')
      OR (discount.ldocument_type = 'invoice'
          AND invoice.lrefno IS NOT NULL
          AND {$invoicePosted})
  )
{$lockingRead}
SQL);
        $params = ['main_id' => $mainId, 'customer_id' => $contactId];
        if ($excludeCurrentDocument !== '') {
            $params['excluded_type'] = $excludedType;
            $params['excluded_refno'] = $excludedRefno;
        }
        $stmt->execute($params);

        $months = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $salesDate = trim((string) ($row['lsales_date'] ?? ''));
            if (preg_match('/^(\d{4}-\d{2})/', $salesDate, $match) === 1) {
                $months[$match[1]] = true;
            }
        }
        return array_keys($months);
    }

    private function writeWithSilverRedemptionGuard(
        int $mainId,
        string $documentType,
        string $documentRefno,
        string $contactId,
        string $salesDate,
        float $grandTotal,
        string $tier,
        float $percentage,
        bool $applied
    ): void {
        $isRedeemableDocument = in_array($documentType, ['order_slip', 'invoice'], true);
        if (!$applied || strtolower($tier) !== 'silver' || !$isRedeemableDocument) {
            $this->write(
                $mainId,
                $documentType,
                $documentRefno,
                $contactId,
                $salesDate,
                VipDocumentDiscount::compute($grandTotal, $tier, $percentage, $applied)
            );
            return;
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $lockCustomer = $pdo->prepare(
                    'SELECT lsessionid FROM tblpatient
                     WHERE lmain_id = :main_id AND lsessionid = :customer_id
                     LIMIT 1 FOR UPDATE'
                );
                $lockCustomer->execute(['main_id' => $mainId, 'customer_id' => $contactId]);
                $lockCustomer->fetchColumn();
            }

            $benefitMonth = substr(trim($salesDate), 0, 7);
            $redeemed = in_array(
                $benefitMonth,
                $this->queryRedeemedSilverBenefitMonths(
                    $mainId,
                    $contactId,
                    $documentType,
                    $documentRefno,
                    true
                ),
                true
            );
            $computed = VipDocumentDiscount::compute($grandTotal, $tier, $percentage, !$redeemed);
            $this->write($mainId, $documentType, $documentRefno, $contactId, $salesDate, $computed);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array{applied:bool,tier:string,percentage:float,discount_amount:float,total_to_pay:float} $computed
     */
    private function write(
        int $mainId,
        string $documentType,
        string $documentRefno,
        string $contactId,
        string $salesDate,
        array $computed
    ): void {
        $this->ensureTable();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblvip_document_discount
                (lmain_id, ldocument_type, ldocument_refno, lcustomerid, lsales_date, lapplied, ltier, lpercentage, ldiscount_amount, ltotal_to_pay)
             VALUES
                (:main_id, :type, :refno, :customer_id, :sales_date, :applied, :tier, :percentage, :discount_amount, :total_to_pay)
             ON DUPLICATE KEY UPDATE
                lcustomerid = VALUES(lcustomerid),
                lsales_date = VALUES(lsales_date),
                lapplied = VALUES(lapplied),
                ltier = VALUES(ltier),
                lpercentage = VALUES(lpercentage),
                ldiscount_amount = VALUES(ldiscount_amount),
                ltotal_to_pay = VALUES(ltotal_to_pay)'
        );
        $stmt->execute([
            'main_id' => $mainId,
            'type' => $documentType,
            'refno' => $documentRefno,
            'customer_id' => $contactId,
            'sales_date' => $salesDate,
            'applied' => $computed['applied'] ? 1 : 0,
            'tier' => $computed['tier'],
            'percentage' => $computed['percentage'],
            'discount_amount' => $computed['discount_amount'],
            'total_to_pay' => $computed['total_to_pay'],
        ]);
    }
}
