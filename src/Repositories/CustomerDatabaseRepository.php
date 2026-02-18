<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class CustomerDatabaseRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listCustomers(
        int $mainId,
        string $search = '',
        string $status = 'all',
        int $page = 1,
        int $perPage = 100,
        string $mode = 'full'
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [
            'main_id' => $mainId,
            'limit' => $perPage,
            'offset' => $offset,
        ];
        $where = ['p.lmain_id = :main_id'];

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus !== '' && $normalizedStatus !== 'all') {
            if ($normalizedStatus === 'active') {
                $where[] = 'COALESCE(p.lstatus, 1) = 1';
                $where[] = "COALESCE(p.lprofile_type, 'Old') <> 'Prospect'";
            } elseif ($normalizedStatus === 'inactive') {
                $where[] = 'COALESCE(p.lstatus, 1) = 0';
                $where[] = "COALESCE(p.lprofile_type, 'Old') <> 'Prospect'";
            } elseif ($normalizedStatus === 'prospect' || $normalizedStatus === 'prospective') {
                $where[] = "(COALESCE(p.lprofile_type, 'Old') = 'Prospect' OR COALESCE(p.lstatus, 1) = 3)";
            } elseif ($normalizedStatus === 'blacklisted') {
                $where[] = "COALESCE(p.ldebt_type, 'Good') = 'Bad'";
            }
        }

        $trimmedSearch = trim($search);
        $normalizedMode = strtolower(trim($mode));
        $isPickerMode = $normalizedMode === 'picker' || $normalizedMode === 'minimal';
        if ($trimmedSearch !== '') {
            $params['search_code'] = '%' . $trimmedSearch . '%';
            $params['search_company'] = '%' . $trimmedSearch . '%';
            $params['search_mobile'] = '%' . $trimmedSearch . '%';
            if ($isPickerMode) {
                $where[] = <<<SQL
(
    COALESCE(p.lpatient_code, '') LIKE :search_code
    OR COALESCE(p.lcompany, '') LIKE :search_company
    OR COALESCE(p.lmobile, '') LIKE :search_mobile
)
SQL;
            } else {
                $params['search_sales'] = '%' . $trimmedSearch . '%';
                $params['search_area'] = '%' . $trimmedSearch . '%';
                $params['search_tin'] = '%' . $trimmedSearch . '%';
                $params['search_contact'] = '%' . $trimmedSearch . '%';
                $where[] = <<<SQL
(
    COALESCE(p.lpatient_code, '') LIKE :search_code
    OR COALESCE(p.lcompany, '') LIKE :search_company
    OR TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) LIKE :search_sales
    OR COALESCE(p.larea, '') LIKE :search_area
    OR COALESCE(p.lmobile, '') LIKE :search_mobile
    OR COALESCE(p.ltin, '') LIKE :search_tin
    OR EXISTS (
        SELECT 1
        FROM tblcontact_person cp
        WHERE cp.lrefno = p.lsessionid
          AND CONCAT_WS(' ', COALESCE(cp.lfname, ''), COALESCE(cp.llname, ''), COALESCE(cp.lc_mobile, ''), COALESCE(cp.lemail, '')) LIKE :search_contact
    )
)
SQL;
            }
        }

        $whereSql = implode(' AND ', $where);
        $countSql = $isPickerMode
            ? "SELECT COUNT(*) AS total FROM tblpatient p WHERE {$whereSql}"
            : "SELECT COUNT(*) AS total FROM tblpatient p LEFT JOIN tblaccount acc ON acc.lid = p.lsales_person WHERE {$whereSql}";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = $isPickerMode ? <<<SQL
SELECT
    p.lid AS id,
    COALESCE(p.lsessionid, '') AS session_id,
    COALESCE(p.lpatient_code, '') AS customer_code,
    COALESCE(p.lcompany, '') AS company
FROM tblpatient p
WHERE {$whereSql}
ORDER BY p.lid DESC
LIMIT :limit OFFSET :offset
SQL
        : <<<SQL
SELECT
    p.lid AS id,
    COALESCE(p.lsessionid, '') AS session_id,
    COALESCE(p.lpatient_code, '') AS customer_code,
    COALESCE(p.lcompany, '') AS company,
    COALESCE(p.lprofile_type, 'Old') AS profile_type,
    COALESCE(p.lstatus, 1) AS status,
    COALESCE(p.ltransaction_type, '') AS transaction_type,
    COALESCE(p.lsales_person, '') AS sales_person_id,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS sales_person_name,
    COALESCE(p.lrefer_by, '') AS refer_by,
    COALESCE(p.laddress, '') AS address,
    COALESCE(p.ldelivery_address, '') AS delivery_address,
    COALESCE(p.larea, '') AS area,
    COALESCE(p.lcity, '') AS city,
    COALESCE(p.lprovince, '') AS province,
    COALESCE(p.ltin, '') AS tin,
    COALESCE(p.lprice_group, '') AS price_group,
    COALESCE(p.lbusiness_line, '') AS business_line,
    COALESCE(p.lterms, '') AS terms,
    COALESCE(p.lvat_type, '') AS vat_type,
    COALESCE(p.lvat_percent, 0) AS vat_percent,
    COALESCE(p.ldealer_since, NULL) AS dealer_since,
    COALESCE(p.ldealer_quota, 0) AS dealer_quota,
    COALESCE(p.lcredit, 0) AS credit_limit,
    COALESCE(p.ldebt_type, 'Good') AS debt_type,
    COALESCE(p.lnotes, '') AS notes,
    COALESCE(p.ldatereg, '') AS date_registered,
    (SELECT COUNT(*) FROM tblcontact_person cp WHERE cp.lrefno = p.lsessionid) AS contact_count,
    (SELECT COUNT(*) FROM tblpatient_terms pt WHERE pt.lpatient = p.lsessionid) AS term_count
FROM tblpatient p
LEFT JOIN tblaccount acc
    ON acc.lid = p.lsales_person
WHERE {$whereSql}
ORDER BY p.lid DESC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'filters' => [
                    'search' => $trimmedSearch,
                    'status' => $normalizedStatus === '' ? 'all' : $normalizedStatus,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCustomer(int $mainId, string $sessionId): ?array
    {
        $sql = <<<SQL
SELECT
    p.lid AS id,
    COALESCE(p.lsessionid, '') AS session_id,
    COALESCE(p.lpatient_code, '') AS customer_code,
    COALESCE(p.lcompany, '') AS company,
    COALESCE(p.lprofile_type, 'Old') AS profile_type,
    COALESCE(p.lstatus, 1) AS status,
    COALESCE(p.ltransaction_type, '') AS transaction_type,
    COALESCE(p.lsales_person, '') AS sales_person_id,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS sales_person_name,
    COALESCE(p.lrefer_by, '') AS refer_by,
    COALESCE(p.laddress, '') AS address,
    COALESCE(p.ldelivery_address, '') AS delivery_address,
    COALESCE(p.larea, '') AS area,
    COALESCE(p.lcity, '') AS city,
    COALESCE(p.lprovince, '') AS province,
    COALESCE(p.ltin, '') AS tin,
    COALESCE(p.lprice_group, '') AS price_group,
    COALESCE(p.lbusiness_line, '') AS business_line,
    COALESCE(p.lterms, '') AS terms,
    COALESCE(p.lvat_type, '') AS vat_type,
    COALESCE(p.lvat_percent, 0) AS vat_percent,
    COALESCE(p.ldealer_since, NULL) AS dealer_since,
    COALESCE(p.ldealer_quota, 0) AS dealer_quota,
    COALESCE(p.lcredit, 0) AS credit_limit,
    COALESCE(p.ldebt_type, 'Good') AS debt_type,
    COALESCE(p.lnotes, '') AS notes,
    COALESCE(p.ldatereg, '') AS date_registered
FROM tblpatient p
LEFT JOIN tblaccount acc
    ON acc.lid = p.lsales_person
WHERE p.lmain_id = :main_id
  AND p.lsessionid = :session_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('session_id', $sessionId, PDO::PARAM_STR);
        $stmt->execute();
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer === false) {
            return null;
        }

        $customer['contacts'] = $this->listContacts($sessionId);
        $customer['terms_history'] = $this->listTerms($sessionId);

        return $customer;
    }

    public function createCustomer(int $mainId, int $userId, array $payload): array
    {
        $company = trim((string) ($payload['company'] ?? ''));
        if ($company === '') {
            throw new RuntimeException('company is required');
        }

        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = $this->generateSessionId($mainId);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO tblpatient
                (lmain_id, lencoded_by, lremarks, ldatereg, ldatetime, lpatient_today, lsessionid, lcompany, lsales_person, lrefer_by, laddress, ldelivery_address, larea, ltin, lprice_group, lbusiness_line, lterms, ltransaction_type, lvat_type, lvat_percent, ldealer_since, ldealer_quota, lcredit, lstatus, lnotes, lprovince, lcity, ldebt_type, lprofile_type)
                VALUES
                (:main_id, :encoded_by, "New Patient", :datereg, NOW(), CURDATE(), :session_id, :company, :sales_person, :refer_by, :address, :delivery_address, :area, :tin, :price_group, :business_line, :terms, :transaction_type, :vat_type, :vat_percent, :dealer_since, :dealer_quota, :credit, :status, :notes, :province, :city, :debt_type, :profile_type)'
            );
            $insert->execute([
                'main_id' => $mainId,
                'encoded_by' => $userId,
                'datereg' => date('Y-m-d H:i:s'),
                'session_id' => $sessionId,
                'company' => $company,
                'sales_person' => (string) ($payload['sales_person_id'] ?? ''),
                'refer_by' => (string) ($payload['refer_by'] ?? ''),
                'address' => (string) ($payload['address'] ?? ''),
                'delivery_address' => (string) (($payload['delivery_address'] ?? '') !== '' ? $payload['delivery_address'] : ($payload['address'] ?? '')),
                'area' => (string) ($payload['area'] ?? ''),
                'tin' => (string) ($payload['tin'] ?? ''),
                'price_group' => (string) ($payload['price_group'] ?? ''),
                'business_line' => (string) ($payload['business_line'] ?? ''),
                'terms' => (string) ($payload['terms'] ?? ''),
                'transaction_type' => (string) (($payload['transaction_type'] ?? '') !== '' ? $payload['transaction_type'] : 'Order Slip'),
                'vat_type' => (string) (($payload['vat_type'] ?? '') !== '' ? $payload['vat_type'] : 'Zero-Rated'),
                'vat_percent' => isset($payload['vat_percent']) ? ((float) $payload['vat_percent']) : 0.12,
                'dealer_since' => $this->normalizeDateNullable((string) ($payload['dealer_since'] ?? '')),
                'dealer_quota' => isset($payload['dealer_quota']) ? (float) $payload['dealer_quota'] : 0,
                'credit' => isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : 0,
                'status' => isset($payload['status']) ? (int) $payload['status'] : 1,
                'notes' => (string) ($payload['notes'] ?? ''),
                'province' => (string) ($payload['province'] ?? ''),
                'city' => (string) ($payload['city'] ?? ''),
                'debt_type' => (string) (($payload['debt_type'] ?? '') !== '' ? $payload['debt_type'] : 'Good'),
                'profile_type' => (string) (($payload['profile_type'] ?? '') !== '' ? $payload['profile_type'] : 'Old'),
            ]);

            $initialTerms = trim((string) ($payload['terms'] ?? ''));
            if ($initialTerms !== '') {
                $this->insertTerm($pdo, $sessionId, [
                    'name' => $initialTerms,
                    'class_code' => (string) ($payload['price_group'] ?? ''),
                    'since' => date('Y-m-d'),
                    'quota' => 0,
                ]);
            }

            $contacts = is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];
            foreach ($contacts as $contact) {
                if (!is_array($contact)) {
                    continue;
                }
                $name = trim((string) ($contact['name'] ?? $contact['first_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $this->insertContact($pdo, $mainId, $sessionId, $contact);
            }

            $pdo->commit();
            return $this->getCustomer($mainId, $sessionId) ?? [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateCustomer(int $mainId, string $sessionId, array $payload): ?array
    {
        $existing = $this->getCustomer($mainId, $sessionId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblpatient
SET
    lcompany = :company,
    lsales_person = :sales_person,
    lrefer_by = :refer_by,
    laddress = :address,
    ldelivery_address = :delivery_address,
    larea = :area,
    ltin = :tin,
    lprice_group = :price_group,
    lbusiness_line = :business_line,
    lterms = :terms,
    ltransaction_type = :transaction_type,
    lvat_type = :vat_type,
    lvat_percent = :vat_percent,
    ldealer_since = :dealer_since,
    ldealer_quota = :dealer_quota,
    lcredit = :credit,
    lstatus = :status,
    lnotes = :notes,
    lprovince = :province,
    lcity = :city,
    ldebt_type = :debt_type,
    lprofile_type = :profile_type
WHERE lmain_id = :main_id
  AND lsessionid = :session_id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'company' => (string) ($payload['company'] ?? $existing['company'] ?? ''),
            'sales_person' => (string) ($payload['sales_person_id'] ?? $existing['sales_person_id'] ?? ''),
            'refer_by' => (string) ($payload['refer_by'] ?? $existing['refer_by'] ?? ''),
            'address' => (string) ($payload['address'] ?? $existing['address'] ?? ''),
            'delivery_address' => (string) ($payload['delivery_address'] ?? $existing['delivery_address'] ?? ''),
            'area' => (string) ($payload['area'] ?? $existing['area'] ?? ''),
            'tin' => (string) ($payload['tin'] ?? $existing['tin'] ?? ''),
            'price_group' => (string) ($payload['price_group'] ?? $existing['price_group'] ?? ''),
            'business_line' => (string) ($payload['business_line'] ?? $existing['business_line'] ?? ''),
            'terms' => (string) ($payload['terms'] ?? $existing['terms'] ?? ''),
            'transaction_type' => (string) ($payload['transaction_type'] ?? $existing['transaction_type'] ?? ''),
            'vat_type' => (string) ($payload['vat_type'] ?? $existing['vat_type'] ?? ''),
            'vat_percent' => isset($payload['vat_percent']) ? ((float) $payload['vat_percent']) : (float) ($existing['vat_percent'] ?? 0),
            'dealer_since' => $this->normalizeDateNullable((string) ($payload['dealer_since'] ?? $existing['dealer_since'] ?? '')),
            'dealer_quota' => isset($payload['dealer_quota']) ? (float) $payload['dealer_quota'] : (float) ($existing['dealer_quota'] ?? 0),
            'credit' => isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : (float) ($existing['credit_limit'] ?? 0),
            'status' => isset($payload['status']) ? (int) $payload['status'] : (int) ($existing['status'] ?? 1),
            'notes' => (string) ($payload['notes'] ?? $existing['notes'] ?? ''),
            'province' => (string) ($payload['province'] ?? $existing['province'] ?? ''),
            'city' => (string) ($payload['city'] ?? $existing['city'] ?? ''),
            'debt_type' => (string) ($payload['debt_type'] ?? $existing['debt_type'] ?? 'Good'),
            'profile_type' => (string) ($payload['profile_type'] ?? $existing['profile_type'] ?? 'Old'),
            'main_id' => $mainId,
            'session_id' => $sessionId,
        ]);

        return $this->getCustomer($mainId, $sessionId);
    }

    public function deleteCustomer(int $mainId, string $sessionId): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT lid FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id LIMIT 1');
            $check->execute([
                'main_id' => $mainId,
                'session_id' => $sessionId,
            ]);
            if ($check->fetch(PDO::FETCH_ASSOC) === false) {
                $pdo->rollBack();
                return false;
            }

            $delPatient = $pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id');
            $delPatient->execute([
                'main_id' => $mainId,
                'session_id' => $sessionId,
            ]);

            $delContacts = $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno = :session_id');
            $delContacts->execute(['session_id' => $sessionId]);

            $delTerms = $pdo->prepare('DELETE FROM tblpatient_terms WHERE lpatient = :session_id');
            $delTerms->execute(['session_id' => $sessionId]);

            $delImages = $pdo->prepare('DELETE FROM tblpatient_image WHERE lrefno = :session_id');
            $delImages->execute(['session_id' => $sessionId]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function addContact(int $mainId, string $sessionId, array $payload): array
    {
        $exists = $this->getCustomer($mainId, $sessionId);
        if ($exists === null) {
            throw new RuntimeException('Customer not found');
        }

        $pdo = $this->db->pdo();
        $id = $this->insertContact($pdo, $mainId, $sessionId, $payload);

        $stmt = $pdo->prepare('SELECT * FROM tblcontact_person WHERE lid = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateContact(int $mainId, int $contactId, array $payload): ?array
    {
        $existing = $this->getContactById($mainId, $contactId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblcontact_person
SET
    lfname = :first_name,
    lmname = :middle_name,
    llname = :last_name,
    lposition = :position,
    lc_phone = :phone,
    lc_mobile = :mobile,
    lemail = :email,
    laddress = :address,
    lbday = :birthday
WHERE lid = :id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'first_name' => (string) ($payload['first_name'] ?? $existing['lfname'] ?? ''),
            'middle_name' => (string) ($payload['middle_name'] ?? $existing['lmname'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? $existing['llname'] ?? ''),
            'position' => (string) ($payload['position'] ?? $existing['lposition'] ?? ''),
            'phone' => (string) ($payload['phone'] ?? $existing['lc_phone'] ?? ''),
            'mobile' => (string) ($payload['mobile'] ?? $existing['lc_mobile'] ?? ''),
            'email' => (string) ($payload['email'] ?? $existing['lemail'] ?? ''),
            'address' => (string) ($payload['address'] ?? $existing['laddress'] ?? ''),
            'birthday' => $this->normalizeDateNullable((string) ($payload['birthday'] ?? $existing['lbday'] ?? '')),
            'id' => $contactId,
        ]);

        return $this->getContactById($mainId, $contactId);
    }

    public function deleteContact(int $mainId, int $contactId): bool
    {
        $existing = $this->getContactById($mainId, $contactId);
        if ($existing === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM tblcontact_person WHERE lid = :id');
        $stmt->execute(['id' => $contactId]);
        return true;
    }

    public function addTerm(int $mainId, string $sessionId, array $payload): array
    {
        $exists = $this->getCustomer($mainId, $sessionId);
        if ($exists === null) {
            throw new RuntimeException('Customer not found');
        }

        $pdo = $this->db->pdo();
        $id = $this->insertTerm($pdo, $sessionId, $payload);
        $stmt = $pdo->prepare('SELECT * FROM tblpatient_terms WHERE lid = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateTerm(int $mainId, int $termId, array $payload): ?array
    {
        $existing = $this->getTermById($mainId, $termId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblpatient_terms
SET
    lname = :name,
    lclass_code = :class_code,
    lsince = :since_date,
    lquota = :quota
WHERE lid = :id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => (string) ($payload['name'] ?? $existing['lname'] ?? ''),
            'class_code' => (string) ($payload['class_code'] ?? $existing['lclass_code'] ?? ''),
            'since_date' => $this->normalizeDateNullable((string) ($payload['since'] ?? $existing['lsince'] ?? '')),
            'quota' => isset($payload['quota']) ? (float) $payload['quota'] : (float) ($existing['lquota'] ?? 0),
            'id' => $termId,
        ]);

        return $this->getTermById($mainId, $termId);
    }

    public function deleteTerm(int $mainId, int $termId): bool
    {
        $existing = $this->getTermById($mainId, $termId);
        if ($existing === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM tblpatient_terms WHERE lid = :id');
        $stmt->execute(['id' => $termId]);
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listContacts(string $sessionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid AS id, lsessionid AS session_id, lfname, lmname, llname, lposition, lc_phone, lc_mobile, lemail, laddress, lbday
             FROM tblcontact_person
             WHERE lrefno = :session_id
             ORDER BY lid ASC'
        );
        $stmt->execute(['session_id' => $sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listTerms(string $sessionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid AS id, lrefno AS term_refno, lmonth, lname, lsince, lclass_code, lquota, lstatus
             FROM tblpatient_terms
             WHERE lpatient = :session_id
             ORDER BY lsince DESC, lid DESC'
        );
        $stmt->execute(['session_id' => $sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getContactById(int $mainId, int $contactId): ?array
    {
        $sql = <<<SQL
SELECT cp.*
FROM tblcontact_person cp
INNER JOIN tblpatient p ON p.lsessionid = cp.lrefno
WHERE p.lmain_id = :main_id
  AND cp.lid = :id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'id' => $contactId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getTermById(int $mainId, int $termId): ?array
    {
        $sql = <<<SQL
SELECT t.*
FROM tblpatient_terms t
INNER JOIN tblpatient p ON p.lsessionid = t.lpatient
WHERE p.lmain_id = :main_id
  AND t.lid = :id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'id' => $termId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function insertContact(PDO $pdo, int $mainId, string $sessionId, array $payload): int
    {
        $firstName = trim((string) ($payload['first_name'] ?? $payload['name'] ?? ''));
        if ($firstName === '') {
            throw new RuntimeException('contact first_name is required');
        }

        $contactSession = date('ymdhis') . random_int(1000, 99999999);
        $stmt = $pdo->prepare(
            'INSERT INTO tblcontact_person
             (lmainid, lrefno, lfname, lmname, llname, lgender, lbday, laddress, lc_mobile, lc_phone, lemail, lposition, lsessionid)
             VALUES
             (:main_id, :refno, :first_name, :middle_name, :last_name, :gender, :birthday, :address, :mobile, :phone, :email, :position, :session_id)'
        );
        $stmt->execute([
            'main_id' => (string) $mainId,
            'refno' => $sessionId,
            'first_name' => $firstName,
            'middle_name' => (string) ($payload['middle_name'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? ''),
            'gender' => (string) ($payload['gender'] ?? ''),
            'birthday' => $this->normalizeDateNullable((string) ($payload['birthday'] ?? '')),
            'address' => (string) ($payload['address'] ?? ''),
            'mobile' => (string) ($payload['mobile'] ?? ''),
            'phone' => (string) ($payload['phone'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'position' => (string) ($payload['position'] ?? ''),
            'session_id' => $contactSession,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function insertTerm(PDO $pdo, string $sessionId, array $payload): int
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('term name is required');
        }

        $termRefno = date('ymdhis') . random_int(1000, 99999999);
        $stmt = $pdo->prepare(
            'INSERT INTO tblpatient_terms
             (lpatient, lrefno, lmonth, lname, lstatus, lsince, lclass_code, lquota)
             VALUES
             (:patient, :refno, :months, :name, :status, :since_date, :class_code, :quota)'
        );
        $stmt->execute([
            'patient' => $sessionId,
            'refno' => $termRefno,
            'months' => (string) ($payload['months'] ?? ''),
            'name' => $name,
            'status' => isset($payload['status']) ? (int) $payload['status'] : 0,
            'since_date' => $this->normalizeDateNullable((string) ($payload['since'] ?? date('Y-m-d'))),
            'class_code' => (string) ($payload['class_code'] ?? ''),
            'quota' => isset($payload['quota']) ? (float) $payload['quota'] : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function generateSessionId(int $mainId): string
    {
        return (string) random_int(10, 15) . date('YmdHis') . (string) random_int(1, 10000) . (string) $mainId;
    }

    private function normalizeDateNullable(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            throw new RuntimeException('invalid date format');
        }
        return date('Y-m-d', $timestamp);
    }

    private function bindParams(\PDOStatement $stmt, array $params, bool $bindLimitOffset): void
    {
        foreach ($params as $key => $value) {
            if (($key === 'limit' || $key === 'offset') && !$bindLimitOffset) {
                continue;
            }

            if (($key === 'limit' || $key === 'offset') && $bindLimitOffset) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }

            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }
}
