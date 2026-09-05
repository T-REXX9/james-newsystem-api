<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\VipDocumentDiscount;
use PDO;

final class VipDocumentDiscountRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function ensureTable(): void
    {
        try {
            $this->db->pdo()->exec(
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
        $computed = VipDocumentDiscount::compute($grandTotal, $tier, $percentage, $applied);
        $this->write($mainId, $documentType, $documentRefno, $contactId, $salesDate, $computed);
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

        $computed = VipDocumentDiscount::compute(
            $grandTotal,
            (string) $source['vip_tier'],
            (float) $source['vip_percentage'],
            ((int) $source['vip_applied']) > 0
        );
        $this->write($mainId, $destType, $destRefno, $contactId, $salesDate, $computed);
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
