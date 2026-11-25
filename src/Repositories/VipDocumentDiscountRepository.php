<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\VipDocumentDiscount;
use App\Support\PostedSalesDocumentSql;
use App\Support\VipStanding;
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
        $customerIds = $this->customerSessionIds($mainId, $contactId);
        if ($customerIds === []) {
            return [];
        }
        $customerBindings = static function (string $prefix) use ($customerIds): array {
            $placeholders = [];
            $params = [];
            foreach ($customerIds as $index => $customerId) {
                $key = $prefix . '_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $customerId;
            }
            return [implode(', ', $placeholders), $params];
        };
        [$receiptCustomerIdList, $receiptCustomerParams] = $customerBindings('receipt_customer_id');
        [$receiptVipCustomerIdList, $receiptVipCustomerParams] = $customerBindings('receipt_vip_customer_id');
        [$invoiceCustomerIdList, $invoiceCustomerParams] = $customerBindings('invoice_customer_id');
        [$invoiceVipCustomerIdList, $invoiceVipCustomerParams] = $customerBindings('invoice_vip_customer_id');
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockingRead = $forUpdate && $pdo->inTransaction() && $driver !== 'sqlite' ? 'FOR UPDATE' : '';
        $invoicePosted = PostedSalesDocumentSql::invoiceIsPosted('invoice');
        $receiptPosted = PostedSalesDocumentSql::deliveryReceiptIsPosted('receipt');
        $stmt = $pdo->prepare(<<<SQL
SELECT document.sales_date, document.document_type, document.document_refno, document.customer_id,
       discount.lid AS vip_record_id, discount.lapplied, discount.ltier
FROM (
    SELECT receipt.lmain_id, receipt.lrefno AS document_refno, receipt.ldate AS sales_date,
           receipt.lcustomerid AS customer_id, 'order_slip' AS document_type
    FROM tbldelivery_receipt receipt
    WHERE receipt.lmain_id = :receipt_main_id
      AND (receipt.lcustomerid IN ({$receiptCustomerIdList}) OR EXISTS (
          SELECT 1 FROM tblvip_document_discount receipt_vip
          WHERE receipt_vip.lmain_id = receipt.lmain_id
            AND receipt_vip.ldocument_type = 'order_slip'
            AND receipt_vip.ldocument_refno = receipt.lrefno
            AND receipt_vip.lcustomerid IN ({$receiptVipCustomerIdList})
      ))
      AND {$receiptPosted}
      AND LOWER(TRIM(COALESCE(receipt.lstatus, ''))) <> 'cancelled'
    UNION ALL
    SELECT invoice.lmain_id, invoice.lrefno AS document_refno, invoice.ldate AS sales_date,
           invoice.lcustomerid AS customer_id, 'invoice' AS document_type
    FROM tblinvoice_list invoice
    WHERE invoice.lmain_id = :invoice_main_id
      AND (invoice.lcustomerid IN ({$invoiceCustomerIdList}) OR EXISTS (
          SELECT 1 FROM tblvip_document_discount invoice_vip
          WHERE invoice_vip.lmain_id = invoice.lmain_id
            AND invoice_vip.ldocument_type = 'invoice'
            AND invoice_vip.ldocument_refno = invoice.lrefno
            AND invoice_vip.lcustomerid IN ({$invoiceVipCustomerIdList})
      ))
      AND {$invoicePosted}
) document
LEFT JOIN tblvip_document_discount discount
  ON discount.lmain_id = document.lmain_id
 AND discount.ldocument_type = document.document_type
 AND discount.ldocument_refno = document.document_refno
WHERE NOT (document.document_type = :excluded_type AND document.document_refno = :excluded_refno)
{$lockingRead}
SQL);
        $stmt->execute([
            'receipt_main_id' => $mainId,
            'invoice_main_id' => $mainId,
            'excluded_type' => $excludedType,
            'excluded_refno' => $excludedRefno,
            ...$receiptCustomerParams,
            ...$receiptVipCustomerParams,
            ...$invoiceCustomerParams,
            ...$invoiceVipCustomerParams,
        ]);

        $config = null;
        $spendByBenefitMonth = [];
        $months = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $salesDate = trim((string) ($row['sales_date'] ?? ''));
            if (preg_match('/^(\d{4}-\d{2})/', $salesDate, $match) !== 1) {
                continue;
            }
            $monthKey = $match[1];
            if ($row['vip_record_id'] !== null) {
                if ((int) ($row['lapplied'] ?? 0) === 1
                    && strtolower(trim((string) ($row['ltier'] ?? ''))) === 'silver') {
                    $months[$monthKey] = true;
                }
                continue;
            }

            // Without an explicit VIP record, only infer redemption from a
            // legacy document owned by this exact customer ID.
            if (!in_array((string) ($row['customer_id'] ?? ''), $customerIds, true)) {
                continue;
            }

            // Older posted documents have no VIP metadata row. Treat them as a
            // redemption only when the customer qualified for Silver in that
            // benefit month; a Gold document never consumes Silver's one-time use.
            if (!array_key_exists($monthKey, $spendByBenefitMonth)) {
                $benefitMonth = new \DateTimeImmutable($monthKey . '-01');
                $previousMonth = $benefitMonth->modify('-1 month');
                $spendByBenefitMonth[$monthKey] = $this->postedSalesSpendForMonth(
                    $mainId,
                    $customerIds,
                    $previousMonth->format('Y-m-01'),
                    $benefitMonth->format('Y-m-01')
                );
            }
            $config ??= (new VipTierSettingsRepository($this->db))->getConfig($mainId);
            if (VipStanding::resolveLevel(
                $spendByBenefitMonth[$monthKey],
                (float) $config['one_time_discount_threshold'],
                (float) $config['unlimited_discount_threshold']
            ) === 'silver') {
                $months[$monthKey] = true;
            }
        }
        return array_keys($months);
    }

    private function postedSalesSpendForMonth(int $mainId, array $customerIds, string $monthStart, string $monthEnd): float
    {
        $params = [
            'main_id' => $mainId,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
        ];
        $customerPlaceholders = [];
        foreach ($customerIds as $index => $customerId) {
            $key = 'customer_id_' . $index;
            $customerPlaceholders[] = ':' . $key;
            $params[$key] = $customerId;
        }
        $customerIdList = implode(', ', $customerPlaceholders);
        $invoicePosted = PostedSalesDocumentSql::invoiceIsPosted('posted_invoice');
        $receiptPosted = PostedSalesDocumentSql::deliveryReceiptIsPosted('posted_receipt');
        $stmt = $this->db->pdo()->prepare(<<<SQL
SELECT COALESCE(SUM(COALESCE(ledger.ldebit, 0)), 0)
FROM tblledger ledger
WHERE ledger.lmainid = :main_id
  AND ledger.lcustomerid IN ({$customerIdList})
  AND LOWER(TRIM(COALESCE(ledger.ltype, ''))) = 'debit'
  AND LOWER(TRIM(COALESCE(ledger.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')
  AND ledger.ldatetime >= :month_start AND ledger.ldatetime < :month_end
  AND (
      (LOWER(TRIM(COALESCE(ledger.lref_name, ''))) = 'invoice' AND EXISTS (
          SELECT 1 FROM tblinvoice_list posted_invoice
          WHERE posted_invoice.lmain_id = ledger.lmainid AND posted_invoice.lrefno = ledger.lrefno
            AND {$invoicePosted}
      ))
      OR (LOWER(TRIM(COALESCE(ledger.lref_name, ''))) IN ('order slip', 'order_slip') AND EXISTS (
          SELECT 1 FROM tbldelivery_receipt posted_receipt
          WHERE posted_receipt.lmain_id = ledger.lmainid AND posted_receipt.lrefno = ledger.lrefno
            AND {$receiptPosted}
      ))
  )
SQL);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    /** @return list<string> Current customer ID plus all merged historical IDs. */
    private function customerSessionIds(int $mainId, string $contactId): array
    {
        $ids = [trim($contactId) => true];
        unset($ids['']);
        $pdo = $this->db->pdo();

        try {
            do {
                $params = ['main_id' => $mainId];
                $placeholders = [];
                foreach (array_keys($ids) as $index => $sessionId) {
                    $key = 'session_id_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $sessionId;
                }
                $stmt = $pdo->prepare(
                    'SELECT old_customer_session_id
                     FROM customer_merge_redirects
                     WHERE main_id = :main_id
                       AND surviving_customer_session_id IN (' . implode(', ', $placeholders) . ')'
                );
                $stmt->execute($params);
                $foundNewId = false;
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                    $oldId = trim((string) $oldId);
                    if ($oldId !== '' && !isset($ids[$oldId])) {
                        $ids[$oldId] = true;
                        $foundNewId = true;
                    }
                }
            } while ($foundNewId);
        } catch (\Throwable) {
            // Older tenants may not have customer merge workflow tables.
        }

        return array_keys($ids);
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
