<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ActivityLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function list(
        int $mainId,
        string $search = '',
        string $userId = '',
        string $action = '',
        string $module = '',
        string $dateFrom = '',
        string $dateTo = '',
        int $page = 1,
        int $perPage = 100,
        bool $includeTotal = false
    ): array {
        $page = max(1, $page);
        $perPage = min(300, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $limitWithProbe = $perPage + 1;

        $params = [
            'main_id' => $mainId,
        ];

        $where = ['log.lmain_id = :main_id'];

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $searchValue = '%' . $trimmedSearch . '%';
            $params['search_page'] = $searchValue;
            $params['search_action'] = $searchValue;
            $params['search_refno'] = $searchValue;
            $params['search_first_name'] = $searchValue;
            $params['search_last_name'] = $searchValue;
            $where[] = '(
                COALESCE(log.lpage, "") LIKE :search_page OR
                COALESCE(log.laction, "") LIKE :search_action OR
                COALESCE(log.lrefno, "") LIKE :search_refno OR
                COALESCE(acc.lfname, "") LIKE :search_first_name OR
                COALESCE(acc.llname, "") LIKE :search_last_name
            )';
        }

        $trimmedUser = trim($userId);
        if ($trimmedUser !== '' && strtolower($trimmedUser) !== 'all') {
            $params['user_id'] = $trimmedUser;
            $where[] = 'log.luser_id = :user_id';
        }

        $trimmedAction = trim($action);
        if ($trimmedAction !== '' && strtolower($trimmedAction) !== 'all') {
            $params['action'] = $trimmedAction;
            $where[] = 'log.laction = :action';
        }

        $trimmedModule = trim($module);
        if ($trimmedModule !== '' && strtolower($trimmedModule) !== 'all') {
            $params['module'] = $trimmedModule;
            $where[] = $this->pageModuleSql() . ' = :module';
        }

        $dateFromNormalized = $this->normalizeDate($dateFrom);
        $dateToNormalized = $this->normalizeDate($dateTo);
        if ($dateFromNormalized !== '') {
            $params['date_from'] = $dateFromNormalized . ' 00:00:00';
            $where[] = 'log.ldatetime >= :date_from';
        }
        if ($dateToNormalized !== '') {
            $params['date_to'] = $dateToNormalized . ' 23:59:59';
            $where[] = 'log.ldatetime <= :date_to';
        }

        $whereSql = implode(' AND ', $where);
        $total = null;
        $totalPages = null;
        if ($includeTotal) {
            $countSql = <<<SQL
SELECT COUNT(*)
FROM tblaudit_trail log
LEFT JOIN tblaccount acc ON acc.lid = log.luser_id
WHERE {$whereSql}
SQL;

            $countStmt = $this->db->pdo()->prepare($countSql);
            foreach ($params as $key => $value) {
                if ($key === 'main_id') {
                    $countStmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                    continue;
                }
                if ($key === 'user_id') {
                    $countStmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                    continue;
                }
                $countStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
            $countStmt->execute();
            $total = (int) ($countStmt->fetchColumn() ?: 0);
            $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        }

        $sql = <<<SQL
SELECT
    log.lid,
    COALESCE(log.lmain_id, '') AS lmain_id,
    COALESCE(log.luser_id, '') AS luser_id,
    COALESCE(log.lpage, '') AS lpage,
    COALESCE(log.laction, '') AS laction,
    COALESCE(log.lrefno, '') AS lrefno,
    CASE
        WHEN log.lrefno REGEXP '^(DM|CM|INQ|NSO|N-D|RR|PO|PR|SA|TR|INV|DCR|COD|T|SO|OS|DR|RS|RTS|AJ|TT)[A-Z0-9-]*[0-9][A-Z0-9-]*$' THEN log.lrefno
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'freight charges' THEN COALESCE(
            NULLIF((
                SELECT NULLIF(TRIM(debit_memo.ldm_no), '')
                FROM tbldebit_memo debit_memo
                WHERE CAST(COALESCE(debit_memo.lmain_id, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(debit_memo.lrefno AS BINARY) = CAST(log.lrefno AS BINARY)
                ORDER BY debit_memo.lid DESC
                LIMIT 1
            ), ''),
            NULLIF((
                SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(audit.lreason, 'DM No: ', -1), ';', 1)
                FROM tblaudit_trail audit
                WHERE audit.lmain_id = log.lmain_id
                  AND CAST(audit.lrefno AS BINARY) = CAST(log.lrefno AS BINARY)
                  AND LOWER(TRIM(COALESCE(audit.lpage, ''))) = 'freight charges'
                  AND audit.lreason LIKE 'DM No: %'
                ORDER BY audit.lid DESC
                LIMIT 1
            ), ''),
            CASE WHEN log.lrefno REGEXP '^DM[A-Z0-9-]*[0-9][A-Z0-9-]*$' THEN log.lrefno ELSE NULL END,
            'DM No. unavailable'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'daily collection entry' THEN COALESCE(
            NULLIF((
                SELECT NULLIF(TRIM(collection.lcolection_no), '')
                FROM tblcollection collection
                WHERE CAST(COALESCE(collection.lmain_id, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(collection.lrefno AS BINARY) = CAST(log.lrefno AS BINARY)
                ORDER BY collection.lid DESC
                LIMIT 1
            ), ''),
            CASE WHEN log.lrefno REGEXP '^DCR-?[A-Z0-9-]*[0-9][A-Z0-9-]*$' THEN log.lrefno ELSE NULL END,
            NULLIF(REGEXP_SUBSTR(CONCAT_WS(' ', log.lreason, log.laction), 'DCR-?[A-Z0-9-]*[0-9][A-Z0-9-]*'), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'adjustment entry' THEN COALESCE(
            NULLIF((
                SELECT NULLIF(TRIM(adjustment.lno), '')
                FROM tbladjustment adjustment
                WHERE CAST(adjustment.lrefno AS BINARY) = CAST(log.lrefno AS BINARY)
                ORDER BY adjustment.lid DESC
                LIMIT 1
            ), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'order slip' THEN COALESCE(
            NULLIF((
                SELECT NULLIF(TRIM(order_slip.linvoice_no), '')
                FROM tbldelivery_receipt order_slip
                WHERE CAST(COALESCE(order_slip.lmain_id, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(order_slip.lrefno AS BINARY) = CAST(log.lrefno AS BINARY)
                ORDER BY order_slip.lid DESC
                LIMIT 1
            ), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'sales inquiry' THEN COALESCE(
            NULLIF((
                SELECT NULLIF(TRIM(inquiry.linqno), '')
                FROM tblinquiry inquiry
                WHERE CAST(COALESCE(inquiry.lmain_id, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(inquiry.lrefno AS BINARY) = CAST(log.lrefno AS BINARY)
                ORDER BY inquiry.lid DESC
                LIMIT 1
            ), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'sales order' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(sales_order.lsaleno), '') FROM tbltransaction sales_order
                WHERE sales_order.lmain_id = log.lmain_id AND CAST(sales_order.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY sales_order.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'transfer product' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(transfer.ltransfer_no), '') FROM tblbranchinventory_transferlist transfer
                WHERE transfer.lmain_id = log.lmain_id AND CAST(transfer.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY transfer.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) IN ('collection report', 'daily collection entry') THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(collection.lcolection_no), '') FROM tblcollection collection
                WHERE CAST(COALESCE(collection.lmain_id, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(collection.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY collection.lid DESC LIMIT 1), ''),
            CASE WHEN log.lrefno REGEXP '^DCR-?[A-Z0-9-]*[0-9][A-Z0-9-]*$' THEN log.lrefno ELSE NULL END,
            NULLIF(REGEXP_SUBSTR(CONCAT_WS(' ', log.lreason, log.laction), 'DCR-?[A-Z0-9-]*[0-9][A-Z0-9-]*'), ''),
            CASE WHEN log.laction REGEXP 'collection report (of:|:) [0-9]+$' THEN CONCAT('DCR-', REGEXP_SUBSTR(log.laction, '[0-9]+$')) ELSE NULL END,
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) IN ('customer database', 'daily call monitoring dashboard') THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(customer.lpatient_code), '') FROM tblpatient customer
                WHERE customer.lmain_id = log.lmain_id AND CAST(customer.lsessionid AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY customer.lid DESC LIMIT 1), ''),
            NULLIF((SELECT NULLIF(TRIM(customer.lcompany), '') FROM tblpatient customer
                WHERE customer.lmain_id = log.lmain_id AND CAST(customer.lsessionid AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY customer.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'invoice' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(invoice.linvoice_no), '') FROM tblinvoice_list invoice
                WHERE invoice.lmain_id = log.lmain_id AND CAST(invoice.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY invoice.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'product database' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(item.litemcode), '') FROM tblinventory_item item
                WHERE item.lmain_id = log.lmain_id AND CAST(item.lsession AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY item.lid DESC LIMIT 1), ''),
            NULLIF((SELECT NULLIF(TRIM(item.lpartno), '') FROM tblinventory_item item
                WHERE item.lmain_id = log.lmain_id AND CAST(item.lsession AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY item.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'receiving report' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(receiving.lpurchaseno), '') FROM tblpurchase_order receiving
                WHERE receiving.lmain_id = log.lmain_id AND CAST(receiving.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY receiving.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'sales return entry' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(sales_return.lcredit_no), '') FROM tblcredit_memo sales_return
                WHERE CAST(COALESCE(sales_return.lmainid, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(sales_return.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY sales_return.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'purchase order' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(purchase_order.lpurchaseno), '') FROM tblpo_list purchase_order
                WHERE purchase_order.lmain_id = log.lmain_id AND CAST(purchase_order.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY purchase_order.lid DESC LIMIT 1), ''),
            NULLIF((SELECT NULLIF(TRIM(purchase_order.lpurchaseno), '') FROM tblpo_list purchase_order
                WHERE purchase_order.lmain_id = log.lmain_id AND CAST(purchase_order.lpr_refno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY purchase_order.lid DESC LIMIT 1), ''),
            NULLIF((SELECT NULLIF(TRIM(purchase_order.lpurchaseno), '') FROM tblpurchase_order purchase_order
                WHERE purchase_order.lmain_id = log.lmain_id AND CAST(purchase_order.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY purchase_order.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'purchase request' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(request.lprno), '') FROM tblpr_list request
                WHERE CAST(request.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY request.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'inventory audit' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(stock_adjustment.ladjustment_number), '') FROM tblstock_adjustment stock_adjustment
                WHERE stock_adjustment.lmain_id = log.lmain_id AND CAST(stock_adjustment.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY stock_adjustment.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'stock adjustment' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(stock_adjustment.ladjustment_number), '') FROM tblstock_adjustment stock_adjustment
                WHERE stock_adjustment.lmain_id = log.lmain_id AND CAST(stock_adjustment.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY stock_adjustment.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'return to supplier' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(return_supplier.lcredit_no), '') FROM tblreturn_supplier return_supplier
                WHERE CAST(COALESCE(return_supplier.lmainid, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(return_supplier.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY return_supplier.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'transfer stock' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(transfer.ltransfer_no), '') FROM tblbranchinventory_transferlist transfer
                WHERE transfer.lmain_id = log.lmain_id AND CAST(transfer.lrefno AS BINARY) = CAST(log.lrefno AS BINARY) ORDER BY transfer.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'customer requests' THEN COALESCE(
            NULLIF((SELECT COALESCE(NULLIF(TRIM(customer.lpatient_code), ''), NULLIF(TRIM(customer.lcompany), ''), NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(request.payload, '$.company'))), ''))
                FROM customer_requests request
                LEFT JOIN tblpatient customer ON customer.lmain_id = request.main_id
                    AND CAST(customer.lsessionid AS BINARY) = CAST(request.contact_id AS BINARY)
                WHERE request.main_id = log.lmain_id AND CAST(request.id AS BINARY) = CAST(log.lrefno AS BINARY)
                ORDER BY customer.lid DESC LIMIT 1), ''),
            '-'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'agent sales report'
          AND log.lrefno LIKE 'call-report-deletion-audit:%' THEN COALESCE(
            NULLIF((SELECT NULLIF(TRIM(customer.lcompany), '')
                FROM call_report_deletion_audits deletion_audit
                LEFT JOIN tblpatient customer ON customer.lmain_id = deletion_audit.main_id
                    AND (CAST(customer.lsessionid AS BINARY) = CAST(deletion_audit.contact_id AS BINARY) OR CAST(customer.lid AS CHAR) = CAST(deletion_audit.contact_id AS CHAR))
                WHERE deletion_audit.main_id = log.lmain_id
                  AND deletion_audit.id = CAST(SUBSTRING_INDEX(log.lrefno, ':', -1) AS UNSIGNED)
                ORDER BY customer.lid DESC LIMIT 1), ''),
            'Deleted sales report'
        )
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'customer merge' THEN COALESCE(
            NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(log.lreason, 'final=', -1), ' requests=', 1)), ''),
            '-'
        )
        ELSE COALESCE(
            NULLIF(REGEXP_SUBSTR(CONCAT_WS(' ', log.lreason, log.laction), '(DM|CM|INQ|NSO|N-D|RR|PO|PR|SA|TR|INV|DCR|COD|T|SO|OS|DR|RS|RTS|AJ|TT)[A-Z0-9-]*[0-9][A-Z0-9-]*'), ''),
            CASE
                WHEN log.lrefno REGEXP '^(DM|CM|INQ|NSO|N-D|RR|PO|PR|SA|TR|INV|DCR|COD|T|SO|OS|DR|RS|RTS|AJ|TT)[A-Z0-9-]*[0-9][A-Z0-9-]*$' THEN log.lrefno
                ELSE '-'
            END
        )
    END AS display_refno,
    CASE
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'customer merge' THEN CONCAT(
            'Merged customer: ',
            COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(log.lreason, 'final=', -1), ' requests=', 1)), ''), 'Customer'),
            CASE WHEN NULLIF(TRIM(SUBSTRING_INDEX(log.lreason, ' reason=', -1)), '') IS NOT NULL
                THEN CONCAT(' · Reason: ', TRIM(SUBSTRING_INDEX(log.lreason, ' reason=', -1))) ELSE '' END
        )
        ELSE COALESCE(log.lreason, '')
    END AS lreason,
    CASE
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'customer merge' THEN ''
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'sales inquiry' AND LOWER(TRIM(COALESCE(log.laction, ''))) = 'replace' THEN COALESCE(
            (SELECT NULLIF(TRIM(inquiry.linqno), '') FROM tblinquiry inquiry
                WHERE CAST(COALESCE(inquiry.lmain_id, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(inquiry.lrefno AS BINARY) = CAST(log.lold_status AS BINARY) ORDER BY inquiry.lid DESC LIMIT 1),
            '-'
        )
        ELSE COALESCE(log.lold_status, '')
    END AS lold_status,
    CASE
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'customer merge' THEN ''
        WHEN LOWER(TRIM(COALESCE(log.lpage, ''))) = 'sales inquiry' AND LOWER(TRIM(COALESCE(log.laction, ''))) = 'replace' THEN COALESCE(
            (SELECT NULLIF(TRIM(inquiry.linqno), '') FROM tblinquiry inquiry
                WHERE CAST(COALESCE(inquiry.lmain_id, 0) AS SIGNED) = CAST(COALESCE(log.lmain_id, 0) AS SIGNED)
                  AND CAST(inquiry.lrefno AS BINARY) = CAST(log.lnew_status AS BINARY) ORDER BY inquiry.lid DESC LIMIT 1),
            '-'
        )
        ELSE COALESCE(log.lnew_status, '')
    END AS lnew_status,
    COALESCE(log.ldatetime, '') AS ldatetime,
    COALESCE(acc.lfname, '') AS userfname,
    COALESCE(acc.llname, '') AS userlname
FROM tblaudit_trail log
LEFT JOIN tblaccount acc ON acc.lid = log.luser_id
WHERE {$whereSql}
ORDER BY log.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            if ($key === 'user_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limitWithProbe, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            array_pop($rows);
        }

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $hasMore,
                'filters' => [
                    'search' => $trimmedSearch,
                    'user_id' => $trimmedUser,
                    'action' => $trimmedAction,
                    'module' => $trimmedModule,
                    'date_from' => $dateFromNormalized,
                    'date_to' => $dateToNormalized,
                ],
            ],
        ];
    }

    public function users(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    CAST(acc.lid AS CHAR) AS user_id,
    COALESCE(acc.lfname, '') AS first_name,
    COALESCE(acc.llname, '') AS last_name
FROM tblaudit_trail log
INNER JOIN tblaccount acc ON acc.lid = log.luser_id
WHERE log.lmain_id = :main_id
  AND acc.lmother_id = :main_id_staff
  AND COALESCE(acc.lstatus, 0) = 1
  AND acc.ltype <> 1
GROUP BY acc.lid, acc.lfname, acc.llname
ORDER BY acc.lfname ASC, acc.llname ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('main_id_staff', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function modules(int $mainId): array
    {
        $moduleSql = $this->pageModuleSql();
        $stmt = $this->db->pdo()->prepare(<<<SQL
SELECT DISTINCT {$moduleSql} AS module
FROM tblaudit_trail log
WHERE log.lmain_id = :main_id AND TRIM(COALESCE(log.lpage, '')) <> ''
ORDER BY module ASC
SQL);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'module');
    }

    private function pageModuleSql(): string
    {
        $page = "LOWER(TRIM(COALESCE(log.lpage, '')))";
        return "CASE
            WHEN {$page} LIKE '%accounting%' OR {$page} LIKE '%collection%' OR {$page} LIKE '%ledger%'
              OR {$page} LIKE '%receivable%' OR {$page} LIKE '%statement of account%' OR {$page} LIKE '%adjustment%'
              OR {$page} LIKE '%freight charge%' OR {$page} LIKE '%sales return%' OR {$page} LIKE '%aging report%'
                THEN 'Accounting'
            WHEN {$page} LIKE '%warehouse%' OR {$page} LIKE '%inventory%' OR {$page} LIKE '%stock%'
              OR {$page} LIKE '%purchase%' OR {$page} LIKE '%supplier%' OR {$page} LIKE '%receiving%'
                THEN 'Warehouse'
            WHEN {$page} LIKE '%sales%' OR {$page} LIKE '%inquiry%' OR {$page} LIKE '%prospect%'
              OR {$page} LIKE '%customer%' OR {$page} LIKE '%invoice%' OR {$page} LIKE '%order slip%'
              OR {$page} LIKE '%daily call%' OR {$page} LIKE '%promotion%'
                THEN 'Sales'
            WHEN {$page} LIKE '%task%' OR {$page} LIKE '%calendar%' OR {$page} LIKE '%message%'
              OR {$page} LIKE '%notification%' OR {$page} LIKE '%communication%'
                THEN 'Communication'
            WHEN {$page} LIKE '%maintenance%' OR {$page} LIKE '%profile%' OR {$page} LIKE '%staff%'
              OR {$page} LIKE '%permission%' OR {$page} LIKE '%activity log%' OR {$page} LIKE '%setting%'
                THEN 'Maintenance'
            WHEN {$page} LIKE '%dashboard%' OR {$page} = 'home'
                THEN 'Dashboards'
            ELSE 'Other'
        END";
    }

    public function deletionDetail(int $mainId, int $auditId): ?array
    {
        if ($auditId <= 0) return null;

        $stmt = $this->db->pdo()->prepare(<<<'SQL'
SELECT
    deletion_audit.id AS deletion_audit_id,
    deletion_audit.contact_id AS deletion_contact_id,
    COALESCE((
        SELECT NULLIF(TRIM(customer.lcompany), '')
        FROM tblpatient customer
        WHERE customer.lmain_id = deletion_audit.main_id
          AND (
              BINARY customer.lsessionid = BINARY deletion_audit.contact_id
              OR BINARY CAST(customer.lid AS CHAR) = BINARY deletion_audit.contact_id
          )
        ORDER BY customer.lid DESC
        LIMIT 1
    ), '') AS deletion_customer_name,
    deletion_audit.thread_id AS deletion_thread_id,
    deletion_audit.message_id AS deletion_message_id,
    deletion_audit.record_type AS deletion_record_type,
    deletion_audit.deleted_by_user_id AS deletion_actor_user_id,
    deletion_audit.deleted_by_name AS deletion_actor_name,
    deletion_audit.deleted_by_role AS deletion_actor_role,
    deletion_audit.delete_reason AS deletion_reason,
    deletion_audit.original_payload AS deletion_original_payload,
    deletion_audit.deleted_at AS deletion_deleted_at
FROM call_report_deletion_audits deletion_audit
WHERE deletion_audit.main_id = :main_id AND deletion_audit.id = :audit_id
LIMIT 1
SQL);
        $stmt->execute(['main_id' => $mainId, 'audit_id' => $auditId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function normalizeDate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        $ts = strtotime($trimmed);
        if ($ts === false) {
            return '';
        }
        return date('Y-m-d', $ts);
    }
}
