<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class SpecialPriceRepository
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
    public function listSpecialPrices(int $mainId, string $search = '', int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $trimmedSearch = trim($search);

        $join = 'INNER JOIN tblinventory_item scope_item ON scope_item.lsession = sp.litem_refno AND scope_item.lmain_id = :main_id';
        $where = [];
        if ($trimmedSearch !== '') {
            $where[] = '(COALESCE(sp.litem_code, "") LIKE :search OR COALESCE(sp.lpart_no, "") LIKE :search OR COALESCE(sp.ldesc, "") LIKE :search)';
        }
        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) AS total
FROM (
    SELECT sp.lrefno
    FROM tblspecial_price sp
    {$join}
    {$whereSql}
    GROUP BY sp.lrefno
) AS grouped_prices
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
    COALESCE(grp.refno, '') AS refno,
    COALESCE(grp.item_session, '') AS item_session,
    COALESCE(grp.item_code, i.litemcode, '') AS item_code,
    COALESCE(grp.part_no, i.lpartno, '') AS part_no,
    COALESCE(grp.description, i.ldescription, '') AS description,
    COALESCE(grp.type, '') AS type,
    COALESCE(grp.amount, 0) AS amount
FROM (
    SELECT
        sp.lrefno AS refno,
        MAX(sp.litem_refno) AS item_session,
        MAX(sp.litem_code) AS item_code,
        MAX(sp.lpart_no) AS part_no,
        MAX(sp.ldesc) AS description,
        MAX(sp.ltype) AS type,
        MAX(sp.lamount) AS amount
    FROM tblspecial_price sp
    {$join}
    {$whereSql}
    GROUP BY sp.lrefno
    ORDER BY item_code ASC
    LIMIT :limit OFFSET :offset
) AS grp
LEFT JOIN tblinventory_item i ON i.lsession = grp.item_session
ORDER BY item_code ASC
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
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSpecialPrice(int $mainId, string $refno): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT
    COALESCE(sp.lrefno, '') AS refno,
    COALESCE(sp.litem_id, '') AS item_id,
    COALESCE(sp.litem_refno, '') AS item_session,
    COALESCE(sp.litem_code, '') AS item_code,
    COALESCE(sp.lpart_no, '') AS part_no,
    COALESCE(sp.ldesc, '') AS description,
    COALESCE(sp.ltype, '') AS type,
    COALESCE(sp.lamount, 0) AS amount
FROM tblspecial_price sp
WHERE sp.lrefno = :refno
  AND EXISTS (
    SELECT 1
    FROM tblinventory_item scope_item
    WHERE scope_item.lsession = sp.litem_refno
      AND scope_item.lmain_id = :main_id
  )
LIMIT 1
SQL
        );
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($record === false) {
            return null;
        }

        $customerStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT
    COALESCE(sp.lpatient_refno, '') AS patient_refno,
    COALESCE(p.lcompany, '') AS company,
    COALESCE(p.lpatient_code, '') AS patient_code,
    COALESCE((
        SELECT SUM(COALESCE(ldebit, 0)) - SUM(COALESCE(lcredit, 0))
        FROM tblledger
        WHERE lcustomerid = sp.lpatient_refno
    ), 0) AS balance
FROM tblspecial_price sp
LEFT JOIN tblpatient p
    ON p.lsessionid = sp.lpatient_refno
WHERE sp.lrefno = :refno
  AND EXISTS (
    SELECT 1
    FROM tblinventory_item scope_item
    WHERE scope_item.lsession = sp.litem_refno
      AND scope_item.lmain_id = :main_id
  )
  AND (
    sp.lfilterby = 'Customer'
    OR (
        COALESCE(sp.lfilterby, '') = ''
        AND COALESCE(sp.lpatient_refno, '') != ''
        AND COALESCE(sp.larea_code, '') = ''
        AND COALESCE(sp.lcategory, '') = ''
    )
  )
ORDER BY p.lcompany ASC, sp.lpatient_refno ASC
SQL
        );
        $customerStmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $customerStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $customerStmt->execute();

        $areaStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT
    COALESCE(sp.larea_code, '') AS area_code,
    COALESCE(r.provDesc, '') AS area_name
FROM tblspecial_price sp
LEFT JOIN refprovince r
    ON r.psgcCode = sp.larea_code
WHERE sp.lrefno = :refno
  AND EXISTS (
    SELECT 1
    FROM tblinventory_item scope_item
    WHERE scope_item.lsession = sp.litem_refno
      AND scope_item.lmain_id = :main_id
  )
  AND sp.lfilterby = 'Area'
ORDER BY r.provDesc ASC, sp.larea_code ASC
SQL
        );
        $areaStmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $areaStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $areaStmt->execute();

        $categoryStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT
    COALESCE(sp.lcategory, '') AS category_id,
    COALESCE(c.lname, '') AS name
FROM tblspecial_price sp
LEFT JOIN tblcategory c
    ON CAST(c.lid AS CHAR) = COALESCE(sp.lcategory, '')
WHERE sp.lrefno = :refno
  AND EXISTS (
    SELECT 1
    FROM tblinventory_item scope_item
    WHERE scope_item.lsession = sp.litem_refno
      AND scope_item.lmain_id = :main_id
  )
  AND (
    sp.lfilterby = 'Category'
    OR (
        COALESCE(sp.lfilterby, '') = ''
        AND COALESCE(sp.lcategory, '') != ''
        AND COALESCE(sp.lpatient_refno, '') = ''
        AND COALESCE(sp.larea_code, '') = ''
    )
  )
ORDER BY c.lname ASC, sp.lcategory ASC
SQL
        );
        $categoryStmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $categoryStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $categoryStmt->execute();

        $record['customers'] = $customerStmt->fetchAll(PDO::FETCH_ASSOC);
        $record['areas'] = $areaStmt->fetchAll(PDO::FETCH_ASSOC);
        $record['categories'] = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function createSpecialPrice(int $mainId, array $payload): array
    {
        $itemSession = trim((string) ($payload['item_session'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));
        $amount = $payload['amount'] ?? null;

        if ($itemSession === '' || $type === '' || $amount === null || $amount === '') {
            throw new RuntimeException('item_session, type, and amount are required');
        }

        $itemStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT
    lid,
    lsession,
    COALESCE(litemcode, '') AS litemcode,
    COALESCE(lpartno, '') AS lpartno,
    COALESCE(ldescription, '') AS ldescription
FROM tblinventory_item
WHERE lsession = :item_session
  AND lmain_id = :main_id
LIMIT 1
SQL
        );
        $itemStmt->bindValue('item_session', $itemSession, PDO::PARAM_STR);
        $itemStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $itemStmt->execute();
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if ($item === false) {
            throw new RuntimeException('Product not found');
        }

        $refno = $itemSession;
        $duplicateStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*)
FROM tblspecial_price sp
WHERE sp.lrefno = :refno
  AND EXISTS (
    SELECT 1
    FROM tblinventory_item scope_item
    WHERE scope_item.lsession = sp.litem_refno
      AND scope_item.lmain_id = :main_id
  )
SQL
        );
        $duplicateStmt->execute([
            'refno' => $refno,
            'main_id' => $mainId,
        ]);
        if ((int) $duplicateStmt->fetchColumn() > 0) {
            throw new RuntimeException('A special price for this product already exists');
        }

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
INSERT INTO tblspecial_price (
    lrefno,
    litem_id,
    litem_refno,
    litem_code,
    lpart_no,
    ldesc,
    ltype,
    lamount,
    lpatient_refno
) VALUES (
    :refno,
    :item_id,
    :item_refno,
    :item_code,
    :part_no,
    :description,
    :type,
    :amount,
    ''
)
SQL
        );
        $stmt->execute([
            'refno' => $refno,
            'item_id' => $item['lid'],
            'item_refno' => $item['lsession'],
            'item_code' => $item['litemcode'],
            'part_no' => $item['lpartno'],
            'description' => $item['ldescription'],
            'type' => $type,
            'amount' => $amount,
        ]);

        return $this->getSpecialPrice($mainId, $refno) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateSpecialPrice(int $mainId, string $refno, array $payload): ?array
    {
        $existing = $this->getSpecialPrice($mainId, $refno);
        if ($existing === null) {
            return null;
        }

        $type = array_key_exists('type', $payload)
            ? trim((string) $payload['type'])
            : (string) $existing['type'];
        $amount = array_key_exists('amount', $payload)
            ? $payload['amount']
            : $existing['amount'];

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
UPDATE tblspecial_price
INNER JOIN tblinventory_item scope_item
    ON scope_item.lsession = tblspecial_price.litem_refno
   AND scope_item.lmain_id = :main_id
SET
    ltype = :type,
    lamount = :amount
WHERE lrefno = :refno
SQL
        );
        $stmt->execute([
            'type' => $type,
            'amount' => $amount,
            'refno' => $refno,
            'main_id' => $mainId,
        ]);

        return $this->getSpecialPrice($mainId, $refno);
    }

    public function deleteSpecialPrice(int $mainId, string $refno): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
DELETE tblspecial_price
FROM tblspecial_price
INNER JOIN tblinventory_item scope_item
    ON scope_item.lsession = tblspecial_price.litem_refno
   AND scope_item.lmain_id = :main_id
WHERE tblspecial_price.lrefno = :refno
SQL
        );
        $stmt->execute([
            'refno' => $refno,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function addCustomer(int $mainId, string $refno, string $patientRefno): array
    {
        $base = $this->getSpecialPrice($mainId, $refno);
        if ($base === null) {
            throw new RuntimeException('Special price not found');
        }

        if (!$this->customerExists($mainId, $patientRefno)) {
            throw new RuntimeException('Customer not found for this tenant');
        }

        $dupCheck = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) FROM tblspecial_price
WHERE lrefno = :refno
  AND lpatient_refno = :patient_refno
  AND (
    lfilterby = 'Customer'
    OR (
        COALESCE(lfilterby, '') = ''
        AND COALESCE(larea_code, '') = ''
        AND COALESCE(lcategory, '') = ''
    )
  )
  AND EXISTS (
    SELECT 1
    FROM tblinventory_item scope_item
    WHERE scope_item.lsession = tblspecial_price.litem_refno
      AND scope_item.lmain_id = :main_id
  )
SQL
        );
        $dupCheck->execute([
            'refno' => $refno,
            'main_id' => $mainId,
            'patient_refno' => $patientRefno,
        ]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            throw new RuntimeException('This customer is already linked to this special price');
        }

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
INSERT INTO tblspecial_price (
    lrefno,
    litem_id,
    litem_refno,
    litem_code,
    lpart_no,
    ldesc,
    ltype,
    lamount,
    lpatient_refno,
    lfilterby
) VALUES (
    :refno,
    :item_id,
    :item_refno,
    :item_code,
    :part_no,
    :description,
    :type,
    :amount,
    :patient_refno,
    'Customer'
)
SQL
        );
        $stmt->execute([
            'refno' => $refno,
            'item_id' => $base['item_id'],
            'item_refno' => $base['item_session'],
            'item_code' => $base['item_code'],
            'part_no' => $base['part_no'],
            'description' => $base['description'],
            'type' => $base['type'],
            'amount' => $base['amount'],
            'patient_refno' => $patientRefno,
        ]);

        return $this->getSpecialPrice($mainId, $refno) ?? [];
    }

    public function removeCustomer(int $mainId, string $refno, string $patientRefno): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
DELETE tblspecial_price
FROM tblspecial_price
INNER JOIN tblinventory_item scope_item
    ON scope_item.lsession = tblspecial_price.litem_refno
   AND scope_item.lmain_id = :main_id
WHERE tblspecial_price.lrefno = :refno
  AND tblspecial_price.lpatient_refno = :patient_refno
  AND (
    tblspecial_price.lfilterby = 'Customer'
    OR (
        COALESCE(tblspecial_price.lfilterby, '') = ''
        AND COALESCE(tblspecial_price.larea_code, '') = ''
        AND COALESCE(tblspecial_price.lcategory, '') = ''
    )
  )
SQL
        );
        $stmt->execute([
            'refno' => $refno,
            'patient_refno' => $patientRefno,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function addArea(int $mainId, string $refno, string $areaCode): array
    {
        $base = $this->getSpecialPrice($mainId, $refno);
        if ($base === null) {
            throw new RuntimeException('Special price not found');
        }

        if (!$this->areaExists($areaCode)) {
            throw new RuntimeException('Area not found');
        }

        $dupCheck = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) FROM tblspecial_price
WHERE lrefno = :refno
  AND larea_code = :area_code
  AND (
    lfilterby = 'Area'
    OR (
        COALESCE(lfilterby, '') = ''
        AND COALESCE(lpatient_refno, '') = ''
        AND COALESCE(lcategory, '') = ''
    )
  )
  AND EXISTS (
    SELECT 1
    FROM tblinventory_item scope_item
    WHERE scope_item.lsession = tblspecial_price.litem_refno
      AND scope_item.lmain_id = :main_id
  )
SQL
        );
        $dupCheck->execute([
            'refno' => $refno,
            'main_id' => $mainId,
            'area_code' => $areaCode,
        ]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            throw new RuntimeException('This area is already linked to this special price');
        }

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
INSERT INTO tblspecial_price (
    lrefno,
    litem_id,
    litem_refno,
    litem_code,
    lpart_no,
    ldesc,
    ltype,
    lamount,
    larea_code,
    lfilterby
) VALUES (
    :refno,
    :item_id,
    :item_refno,
    :item_code,
    :part_no,
    :description,
    :type,
    :amount,
    :area_code,
    'Area'
)
SQL
        );
        $stmt->execute([
            'refno' => $refno,
            'item_id' => $base['item_id'],
            'item_refno' => $base['item_session'],
            'item_code' => $base['item_code'],
            'part_no' => $base['part_no'],
            'description' => $base['description'],
            'type' => $base['type'],
            'amount' => $base['amount'],
            'area_code' => $areaCode,
        ]);

        return $this->getSpecialPrice($mainId, $refno) ?? [];
    }

    public function removeArea(int $mainId, string $refno, string $areaCode): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
DELETE tblspecial_price
FROM tblspecial_price
INNER JOIN tblinventory_item scope_item
    ON scope_item.lsession = tblspecial_price.litem_refno
   AND scope_item.lmain_id = :main_id
WHERE tblspecial_price.lrefno = :refno
  AND tblspecial_price.larea_code = :area_code
  AND (
    tblspecial_price.lfilterby = 'Area'
    OR (
        COALESCE(tblspecial_price.lfilterby, '') = ''
        AND COALESCE(tblspecial_price.lpatient_refno, '') = ''
        AND COALESCE(tblspecial_price.lcategory, '') = ''
    )
  )
SQL
        );
        $stmt->execute([
            'refno' => $refno,
            'area_code' => $areaCode,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function addCategory(int $mainId, string $refno, string $categoryId): array
    {
        $base = $this->getSpecialPrice($mainId, $refno);
        if ($base === null) {
            throw new RuntimeException('Special price not found');
        }

        if (!$this->categoryExists($mainId, $categoryId)) {
            throw new RuntimeException('Category not found for this tenant');
        }

        $dupCheck = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) FROM tblspecial_price
WHERE lrefno = :refno
  AND lcategory = :category_id
  AND (
    lfilterby = 'Category'
    OR (
        COALESCE(lfilterby, '') = ''
        AND COALESCE(lpatient_refno, '') = ''
        AND COALESCE(larea_code, '') = ''
    )
  )
  AND EXISTS (
    SELECT 1
    FROM tblinventory_item scope_item
    WHERE scope_item.lsession = tblspecial_price.litem_refno
      AND scope_item.lmain_id = :main_id
  )
SQL
        );
        $dupCheck->execute([
            'refno' => $refno,
            'main_id' => $mainId,
            'category_id' => $categoryId,
        ]);
        if ((int) $dupCheck->fetchColumn() > 0) {
            throw new RuntimeException('This category is already linked to this special price');
        }

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
INSERT INTO tblspecial_price (
    lrefno,
    litem_id,
    litem_refno,
    litem_code,
    lpart_no,
    ldesc,
    ltype,
    lamount,
    lcategory,
    lfilterby
) VALUES (
    :refno,
    :item_id,
    :item_refno,
    :item_code,
    :part_no,
    :description,
    :type,
    :amount,
    :category_id,
    'Category'
)
SQL
        );
        $stmt->execute([
            'refno' => $refno,
            'item_id' => $base['item_id'],
            'item_refno' => $base['item_session'],
            'item_code' => $base['item_code'],
            'part_no' => $base['part_no'],
            'description' => $base['description'],
            'type' => $base['type'],
            'amount' => $base['amount'],
            'category_id' => $categoryId,
        ]);

        return $this->getSpecialPrice($mainId, $refno) ?? [];
    }

    public function removeCategory(int $mainId, string $refno, string $categoryId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
DELETE tblspecial_price
FROM tblspecial_price
INNER JOIN tblinventory_item scope_item
    ON scope_item.lsession = tblspecial_price.litem_refno
   AND scope_item.lmain_id = :main_id
WHERE tblspecial_price.lrefno = :refno
  AND tblspecial_price.lcategory = :category_id
  AND (
    tblspecial_price.lfilterby = 'Category'
    OR (
        COALESCE(tblspecial_price.lfilterby, '') = ''
        AND COALESCE(tblspecial_price.lpatient_refno, '') = ''
        AND COALESCE(tblspecial_price.larea_code, '') = ''
    )
  )
SQL
        );
        $stmt->execute([
            'refno' => $refno,
            'category_id' => $categoryId,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listProducts(int $mainId, string $search = '', int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $trimmedSearch = trim($search);

        $where = [
            'lmain_id = :main_id',
            'COALESCE(lnot_inventory, 0) = 0',
            'NOT EXISTS (
                SELECT 1
                FROM tblspecial_price sp
                WHERE sp.lrefno = tblinventory_item.lsession
            )',
        ];
        if ($trimmedSearch !== '') {
            $where[] = '(COALESCE(litemcode, "") LIKE :search OR COALESCE(lpartno, "") LIKE :search OR COALESCE(ldescription, "") LIKE :search)';
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) AS total
FROM tblinventory_item
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
    COALESCE(lsession, '') AS lsession,
    COALESCE(litemcode, '') AS litemcode,
    COALESCE(lpartno, '') AS lpartno,
    COALESCE(ldescription, '') AS ldescription
FROM tblinventory_item
WHERE {$whereSql}
ORDER BY litemcode ASC
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
            ],
        ];
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listCustomers(int $mainId, string $search = '', int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $trimmedSearch = trim($search);

        $where = ['lmain_id = :main_id'];
        if ($trimmedSearch !== '') {
            $where[] = '(COALESCE(lcompany, "") LIKE :search OR COALESCE(lpatient_code, "") LIKE :search)';
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) AS total
FROM tblpatient
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
    COALESCE(lsessionid, '') AS lsessionid,
    COALESCE(lcompany, '') AS lcompany,
    COALESCE(lpatient_code, '') AS lpatient_code
FROM tblpatient
WHERE {$whereSql}
ORDER BY lcompany ASC
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
            ],
        ];
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listAreas(string $search = '', int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $trimmedSearch = trim($search);

        $where = [];
        if ($trimmedSearch !== '') {
            $where[] = 'COALESCE(provDesc, "") LIKE :search';
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) AS total
FROM refprovince
{$whereSql}
SQL
        );
        if ($trimmedSearch !== '') {
            $countStmt->bindValue('search', '%' . $trimmedSearch . '%', PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $stmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT
    COALESCE(psgcCode, '') AS code,
    COALESCE(provDesc, '') AS name
FROM refprovince
{$whereSql}
ORDER BY provDesc ASC
LIMIT :limit OFFSET :offset
SQL
        );
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
            ],
        ];
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listCategories(int $mainId, string $search = '', int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $trimmedSearch = trim($search);

        $where = ['lmain_id = :main_id'];
        if ($trimmedSearch !== '') {
            $where[] = 'COALESCE(lname, "") LIKE :search';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT COUNT(*) AS total
FROM tblcategory
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
    COALESCE(lid, '') AS id,
    COALESCE(lname, '') AS name
FROM tblcategory
WHERE {$whereSql}
ORDER BY lname ASC
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
            ],
        ];
    }

    private function generateRefno(): string
    {
        return date('Ymdhis') . rand(1, 1000000);
    }

    private function customerExists(int $mainId, string $patientRefno): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT 1
FROM tblpatient
WHERE lmain_id = :main_id
  AND lsessionid = :patient_refno
LIMIT 1
SQL
        );
        $stmt->execute([
            'main_id' => $mainId,
            'patient_refno' => $patientRefno,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function areaExists(string $areaCode): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT 1
FROM refprovince
WHERE psgcCode = :area_code
LIMIT 1
SQL
        );
        $stmt->execute(['area_code' => $areaCode]);

        return $stmt->fetchColumn() !== false;
    }

    private function categoryExists(int $mainId, string $categoryId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT 1
FROM tblcategory
WHERE lmain_id = :main_id
  AND lid = :category_id
LIMIT 1
SQL
        );
        $stmt->execute([
            'main_id' => $mainId,
            'category_id' => $categoryId,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
