<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

final class PurchaseWorkflowGuard
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function assertItemsAvailable(array $items): void
    {
        $itemCodes = [];
        $itemSessions = [];
        foreach ($items as $item) {
            $code = trim((string) ($item['item_code'] ?? ''));
            $session = trim((string) ($item['item_id'] ?? $item['item_session'] ?? ''));
            if ($code !== '') $itemCodes[$code] = true;
            if ($session !== '') $itemSessions[$session] = true;
        }
        if ($itemCodes === [] && $itemSessions === []) return;

        [$identifierSql, $params] = $this->buildIdentifierWhere(
            array_keys($itemCodes),
            array_keys($itemSessions),
            'pri.litem_code',
            'pri.litem_refno',
            'pr'
        );
        $prSql = <<<SQL
SELECT
    'PR' AS stage,
    COALESCE(pri.litem_code, '') AS item_code,
    COALESCE(pri.litem_refno, '') AS item_session,
    COALESCE(pri.lrefno, '') AS workflow_refno,
    COALESCE(prl.lprno, pri.lrefno, '') AS workflow_no
FROM tblpr_item pri
INNER JOIN tblpr_list prl ON prl.lrefno = pri.lrefno
WHERE ({$identifierSql})
  AND LOWER(COALESCE(prl.lstatus, 'pending')) NOT IN ('cancelled', 'canceled', 'rejected', 'disapproved', 'completed', 'closed')
  AND (
      TRIM(COALESCE(pri.lpo_refno, '')) = ''
      OR EXISTS (
          SELECT 1
          FROM tblpo_list active_po
          WHERE active_po.lrefno = pri.lpo_refno
            AND LOWER(COALESCE(active_po.ltransaction_status, 'pending')) NOT IN ('cancelled', 'canceled', 'rejected', 'disapproved', 'completed', 'closed')
            AND NOT EXISTS (
                SELECT 1
                FROM tblpurchase_order completed_rr
                WHERE completed_rr.lpo_refno = active_po.lrefno
                  AND LOWER(COALESCE(completed_rr.ltransaction_status, 'pending')) IN ('posted', 'received', 'delivered', 'completed')
            )
      )
  )
ORDER BY pri.lid DESC
LIMIT 1
SQL;
        $conflict = $this->fetchConflict($prSql, $params);

        if ($conflict === null) {
            [$identifierSql, $params] = $this->buildIdentifierWhere(
                array_keys($itemCodes),
                array_keys($itemSessions),
                'poi.litem_code',
                'poi.litem_refno',
                'po'
            );
            $poSql = <<<SQL
SELECT
    'PO' AS stage,
    COALESCE(poi.litem_code, '') AS item_code,
    COALESCE(poi.litem_refno, '') AS item_session,
    COALESCE(poi.lrefno, '') AS workflow_refno,
    COALESCE(pol.lpurchaseno, poi.lrefno, '') AS workflow_no
FROM tblpo_itemlist poi
INNER JOIN tblpo_list pol ON pol.lrefno = poi.lrefno
WHERE ({$identifierSql})
  AND LOWER(COALESCE(pol.ltransaction_status, 'pending')) NOT IN ('cancelled', 'canceled', 'rejected', 'disapproved', 'completed', 'closed')
  AND NOT EXISTS (
      SELECT 1
      FROM tblpurchase_order completed_rr
      WHERE completed_rr.lpo_refno = poi.lrefno
        AND LOWER(COALESCE(completed_rr.ltransaction_status, 'pending')) IN ('posted', 'received', 'delivered', 'completed')
  )
ORDER BY poi.lid DESC
LIMIT 1
SQL;
            $conflict = $this->fetchConflict($poSql, $params);
        }

        if ($conflict === null) return;

        $item = trim((string) ($conflict['item_code'] ?: $conflict['item_session']));
        $stage = (string) ($conflict['stage'] ?? 'purchasing');
        $workflowNo = (string) ($conflict['workflow_no'] ?? $conflict['workflow_refno'] ?? '');
        throw new RuntimeException(
            sprintf('%s already has an active %s workflow (%s). Complete or cancel it before creating another request.', $item, $stage, $workflowNo)
        );
    }

    /** @return array{0:string,1:array<string,string>} */
    private function buildIdentifierWhere(
        array $itemCodes,
        array $itemSessions,
        string $codeColumn,
        string $sessionColumn,
        string $prefix
    ): array {
        $parts = [];
        $params = [];
        if ($itemCodes !== []) {
            $tokens = [];
            foreach ($itemCodes as $index => $value) {
                $key = $prefix . '_code_' . $index;
                $tokens[] = ':' . $key;
                $params[$key] = $value;
            }
            $parts[] = $codeColumn . ' IN (' . implode(', ', $tokens) . ')';
        }
        if ($itemSessions !== []) {
            $tokens = [];
            foreach ($itemSessions as $index => $value) {
                $key = $prefix . '_session_' . $index;
                $tokens[] = ':' . $key;
                $params[$key] = $value;
            }
            $parts[] = $sessionColumn . ' IN (' . implode(', ', $tokens) . ')';
        }
        return [implode(' OR ', $parts), $params];
    }

    private function fetchConflict(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
