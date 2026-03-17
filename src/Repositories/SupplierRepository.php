<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class SupplierRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listSuppliers(int $mainId, string $search = '', int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $trimmedSearch = trim($search);
        $params = [
            'main_id' => $mainId,
            'limit' => $perPage,
            'offset' => $offset,
        ];

        $where = [
            's.lmain_id = :main_id',
            'COALESCE(s.lstatus, 1) = 1',
        ];

        if ($trimmedSearch !== '') {
            $params['search'] = '%' . $trimmedSearch . '%';
            $where[] = '(COALESCE(s.lname, "") LIKE :search OR COALESCE(s.lcode, "") LIKE :search)';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) AS total
FROM tblsupplier s
WHERE {$whereSql}
SQL
        );
        $countStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        if ($trimmedSearch !== '') {
            $countStmt->bindValue('search', '%' . $trimmedSearch . '%', PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT
    CAST(s.lid AS CHAR) AS id,
    COALESCE(s.lcode, '') AS code,
    COALESCE(s.lname, '') AS name,
    COALESCE(s.laddress, '') AS address,
    TRIM(CONCAT(COALESCE(s.lc_fname, ''), ' ', COALESCE(s.lc_llname, ''))) AS contact_person,
    COALESCE(s.ltin, '') AS tin,
    COALESCE(s.lsupplier_remark, '') AS remarks,
    CAST(COALESCE(s.lstatus, 1) AS SIGNED) AS status
FROM tblsupplier s
WHERE {$whereSql}
ORDER BY s.lname ASC, s.lid ASC
LIMIT :limit OFFSET :offset
SQL
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        if ($trimmedSearch !== '') {
            $stmt->bindValue('search', '%' . $trimmedSearch . '%', PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'filters' => [
                    'search' => $trimmedSearch,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSupplier(int $mainId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT
    CAST(s.lid AS CHAR) AS id,
    COALESCE(s.lcode, '') AS code,
    COALESCE(s.lname, '') AS name,
    COALESCE(s.laddress, '') AS address,
    TRIM(CONCAT(COALESCE(s.lc_fname, ''), ' ', COALESCE(s.lc_llname, ''))) AS contact_person,
    COALESCE(s.ltin, '') AS tin,
    COALESCE(s.lsupplier_remark, '') AS remarks,
    CAST(COALESCE(s.lstatus, 1) AS SIGNED) AS status
FROM tblsupplier s
WHERE s.lmain_id = :main_id
  AND s.lid = :supplier_id
  AND COALESCE(s.lstatus, 1) = 1
LIMIT 1
SQL
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('supplier_id', $supplierId, PDO::PARAM_INT);
        $stmt->execute();

        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record === false ? null : $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function createSupplier(int $mainId, array $payload): array
    {
        ['first_name' => $firstName, 'last_name' => $lastName] = $this->splitContactPerson(
            (string) ($payload['contact_person'] ?? '')
        );

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
INSERT INTO tblsupplier (
    lrefno,
    lmain_id,
    ldatesince,
    lname,
    lcode,
    laddress,
    lc_fname,
    lc_llname,
    ltin,
    lsupplier_remark,
    lstatus
) VALUES (
    :refno,
    :main_id,
    :date_since,
    :name,
    :code,
    :address,
    :contact_first_name,
    :contact_last_name,
    :tin,
    :remarks,
    1
)
SQL
        );

        $stmt->execute([
            'refno' => date('ymdhis') . rand(1, 100000000),
            'main_id' => $mainId,
            'date_since' => date('m/d/Y'),
            'name' => trim((string) ($payload['name'] ?? '')),
            'code' => trim((string) ($payload['code'] ?? '')),
            'address' => trim((string) ($payload['address'] ?? '')),
            'contact_first_name' => $firstName,
            'contact_last_name' => $lastName,
            'tin' => trim((string) ($payload['tin'] ?? '')),
            'remarks' => trim((string) ($payload['remarks'] ?? '')),
        ]);

        $supplierId = (int) $this->db->pdo()->lastInsertId();
        return $this->getSupplier($mainId, $supplierId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateSupplier(int $mainId, int $supplierId, array $payload): ?array
    {
        $existing = $this->getSupplier($mainId, $supplierId);
        if ($existing === null) {
            return null;
        }

        $name = array_key_exists('name', $payload)
            ? trim((string) $payload['name'])
            : $existing['name'];

        $code = array_key_exists('code', $payload)
            ? trim((string) $payload['code'])
            : $existing['code'];

        $address = array_key_exists('address', $payload)
            ? trim((string) $payload['address'])
            : $existing['address'];

        $tin = array_key_exists('tin', $payload)
            ? trim((string) $payload['tin'])
            : $existing['tin'];

        $remarks = array_key_exists('remarks', $payload)
            ? trim((string) $payload['remarks'])
            : $existing['remarks'];

        if (array_key_exists('contact_person', $payload)) {
            ['first_name' => $firstName, 'last_name' => $lastName] = $this->splitContactPerson(
                (string) $payload['contact_person']
            );
        } else {
            $parts = preg_split('/\s+/', trim($existing['contact_person']), 2) ?: [];
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';
        }

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
UPDATE tblsupplier
SET
    lname = :name,
    lcode = :code,
    laddress = :address,
    lc_fname = :contact_first_name,
    lc_llname = :contact_last_name,
    ltin = :tin,
    lsupplier_remark = :remarks
WHERE lmain_id = :main_id
  AND lid = :supplier_id
  AND COALESCE(lstatus, 1) = 1
LIMIT 1
SQL
        );

        $stmt->execute([
            'main_id' => $mainId,
            'supplier_id' => $supplierId,
            'name' => $name,
            'code' => $code,
            'address' => $address,
            'contact_first_name' => $firstName,
            'contact_last_name' => $lastName,
            'tin' => $tin,
            'remarks' => $remarks,
        ]);

        return $this->getSupplier($mainId, $supplierId);
    }

    public function deleteSupplier(int $mainId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
UPDATE tblsupplier
SET lstatus = 0
WHERE lmain_id = :main_id
  AND lid = :supplier_id
  AND COALESCE(lstatus, 1) = 1
LIMIT 1
SQL
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('supplier_id', $supplierId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{first_name: string, last_name: string}
     */
    private function splitContactPerson(string $contactPerson): array
    {
        $contactPerson = trim($contactPerson);
        if ($contactPerson === '') {
            return [
                'first_name' => '',
                'last_name' => '',
            ];
        }

        $parts = preg_split('/\s+/', $contactPerson) ?: [];
        $firstName = trim((string) array_shift($parts));
        $lastName = trim(implode(' ', $parts));

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }
}
