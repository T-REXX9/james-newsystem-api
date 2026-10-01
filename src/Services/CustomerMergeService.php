<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Support\Exceptions\HttpException;
use PDO;
use Throwable;

/**
 * Coordinates customer merges. Unknown customer-looking columns are surfaced
 * by the schema preflight and block execution until their meaning is proven.
 */
final class CustomerMergeService
{
    /**
     * Only references whose customer column and tenant column are both proven
     * by the current schema/codebase are executable. Never add a mapping here
     * merely because a column name looks plausible.
     *
     * @var array<string, array{column: string, mode: string, label: string}>
     */
    private const SUPPORTED_REFERENCES = [
        'tbldebit_memo_items' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Debit memo items'],
        'tbldelivery_receipt' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Delivery receipts'],
        'tbldelivery_receipt_items' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Delivery receipt items'],
        'tblinquiry' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Inquiries'],
        'tblinvoice_list' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Invoices'],
        'tblinvoice_items' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Invoice items'],
        'tblinvoice_itemrec' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Invoice item records'],
        'tblcollection_item' => ['column' => 'lcustomer', 'mode' => 'session', 'label' => 'Collection items'],
        'tblcredit_memo' => ['column' => 'lcustomer', 'mode' => 'session', 'label' => 'Credit memos'],
        'tblledger' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Ledger'],
        'tbltransaction' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Transactions'],
        'tbltransaction_item' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Transaction items'],
        'tbladjustment' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Adjustment entries'],
        'tblcontact_person' => ['column' => 'lrefno', 'mode' => 'session', 'label' => 'Contact persons'],
        'tblpatient_delivery_address' => ['column' => 'lsessionid', 'mode' => 'session', 'label' => 'Delivery addresses'],
        'tblpatient_assignment_history' => ['column' => 'lsessionid', 'mode' => 'session', 'label' => 'Assignment history'],
        'tblpatient_com_hist' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Communication history'],
        'tblpatient_notes' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Customer notes'],
        'tblpatient_terms' => ['column' => 'lpatient', 'mode' => 'session', 'label' => 'Customer terms'],
        'tblpatient_today_log' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Customer today log'],
        'tblcustomer_logs' => ['column' => 'lcustomer_id', 'mode' => 'session', 'label' => 'Customer logs'],
        'tblfile_attached' => ['column' => 'lpatientid', 'mode' => 'session', 'label' => 'Customer attachments'],
        'tblnotifications_log' => ['column' => 'lsessionid', 'mode' => 'session', 'label' => 'Notification history'],
        'tblnotifications' => ['column' => 'linv_session', 'mode' => 'json_contact', 'label' => 'Customer notification references'],
        'tblcredit_return_item' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Credit return items'],
        'tblcredits_memo_item' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Credit memo items'],
        'tblreturn_supplier_item' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Return-to-supplier items'],
        'tblpurchase_item' => ['column' => 'lpatient_id', 'mode' => 'session', 'label' => 'Purchase items'],
        'tblpo_list' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Purchase orders'],
        'tblpurchase_order' => ['column' => 'lcustomerid', 'mode' => 'session', 'label' => 'Receiving purchase orders'],
        'tblspecial_price' => ['column' => 'lpatient_refno', 'mode' => 'session', 'label' => 'Customer special prices'],
        'call_report_threads' => ['column' => 'contact_id', 'mode' => 'session', 'label' => 'Call report threads'],
        'call_report_contact_read_states' => ['column' => 'contact_id', 'mode' => 'session', 'label' => 'Call report read states'],
        'call_report_deletion_audits' => ['column' => 'contact_id', 'mode' => 'session', 'label' => 'Call report deletion audits'],
        'customer_requests' => ['column' => 'contact_id', 'mode' => 'session', 'label' => 'Customer requests'],
        'daily_call_claims' => ['column' => 'contact_id', 'mode' => 'session', 'label' => 'Daily call claims'],
        'incident_reports' => ['column' => 'contact_id', 'mode' => 'session', 'label' => 'Incident reports'],
        'incident_report_items' => ['column' => 'contact_id', 'mode' => 'session', 'label' => 'Incident report items'],
        'tblcall_logs_entry' => ['column' => 'lcustomer_id', 'mode' => 'session_or_lid', 'label' => 'Legacy call log entries'],
        'tblcall_logs_v2' => ['column' => 'lcustomer_id', 'mode' => 'patient_lid', 'label' => 'Call logs'],
        'tblcall_dial_requests' => ['column' => 'lcustomer_id', 'mode' => 'patient_lid', 'label' => 'Call dial requests'],
        'tblpatient_duplicate_request' => ['column' => 'lsessionid', 'mode' => 'session', 'label' => 'Legacy duplicate requests'],
    ];

    /**
     * These tables contain customer-looking references but do not expose a
     * proven tenant scope or a proven session-id mapping. They are intentionally
     * never updated by the merge. If present, execution is blocked explicitly.
     *
     * @var array<string, array{column: string, label: string}>
     */
    private const BLOCKED_REFERENCES = [];

    private const WORKFLOW_TABLES = [
        'customer_merge_requests',
        'customer_merge_transfer_log',
        'customer_merge_redirects',
        'tblduplicates_notification_batch',
    ];

    private const KNOWN_WORKFLOW_TABLES = [
        'tblpatient_duplicate_request',
        'customer_requests',
        'daily_call_claims',
        'incident_reports',
        'incident_report_items',
        'incident_return_actions',
        'call_report_threads',
        'call_report_contact_read_states',
        'call_report_deletion_audits',
    ];

    /** @var array<string, true> */
    private const NON_REFERENCE_COLUMNS = [
        'lcompany' => true,
        'lcompany_name' => true,
        'lcustomer_name' => true,
        'lcustomername' => true,
        'lcustomer_code' => true,
        'lpatient_name' => true,
        'lpatientname' => true,
        'lcontact_person' => true,
        'contact_name' => true,
        'contact_person' => true,
        'customer_name' => true,
        'customer_code' => true,
        'patient_name' => true,
        'patient_code' => true,
        'matching_fields' => true,
    ];

    /** @var array<string, array{column: string, label: string}> */
    private const CUSTOMER_FIELDS = [
        'vat_type' => ['column' => 'lvat_type', 'label' => 'VAT type'],
        'terms' => ['column' => 'lterms', 'label' => 'credit terms'],
        'price_group' => ['column' => 'lprice_group', 'label' => 'pricing group'],
        'sales_person' => ['column' => 'lsales_person', 'label' => 'sales assignment'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, string> $fieldDecisions */
    public function preview(
        int $mainId,
        string $survivorId,
        string $duplicateId,
        string $finalName,
        string $reason,
        int $userId,
        string $idempotencyKey,
        array $fieldDecisions = []
    ): array {
        $this->requireWorkflowSchema();
        $this->validateInput($mainId, $survivorId, $duplicateId, $finalName, $reason, $idempotencyKey);
        $customers = $this->loadPair($mainId, $survivorId, $duplicateId, false);
        $inventory = $this->inventory($mainId, $survivorId, $duplicateId, $customers);
        $conflicts = $this->findConflicts($customers[0], $customers[1]);
        $blocking = $this->buildBlockingWarnings($conflicts, $fieldDecisions, $inventory['blocking']);
        $snapshot = $this->snapshot($customers, $inventory);
        $checksum = $this->checksum($snapshot);
        $mergeId = $this->savePreview(
            $mainId,
            $survivorId,
            $duplicateId,
            $finalName,
            $reason,
            $idempotencyKey,
            $userId,
            $fieldDecisions,
            $snapshot,
            $checksum
        );

        return [
            'merge_id' => $mergeId,
            'customers' => $customers,
            'survivor_session_id' => $survivorId,
            'duplicate_session_id' => $duplicateId,
            'final_company_name' => trim($finalName),
            'merge_reason' => trim($reason),
            'field_decisions' => $fieldDecisions,
            'inventory' => $inventory['entries'],
            'counts' => $inventory['counts'],
            'financial_totals' => $inventory['financial_totals'],
            'conflicts' => $conflicts,
            'blocking_warnings' => array_values(array_unique($blocking)),
            'executable' => $blocking === [],
            'preview_checksum' => $checksum,
            'generated_at' => date('c'),
            'requested_by' => $userId,
        ];
    }

    /** @param array<string, string> $fieldDecisions */
    public function execute(
        int $mainId,
        string $survivorId,
        string $duplicateId,
        string $finalName,
        string $reason,
        string $confirmation,
        string $idempotencyKey,
        int $userId,
        array $fieldDecisions = [],
        ?int $duplicateRequestId = null
    ): array {
        if ($confirmation !== 'MERGE CUSTOMER RECORDS') {
            throw new HttpException(422, 'Type MERGE CUSTOMER RECORDS to confirm this irreversible merge operation.');
        }
        $this->validateInput($mainId, $survivorId, $duplicateId, $finalName, $reason, $idempotencyKey);
        $this->requireWorkflowSchema();
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $request = $this->findMergeRequest($mainId, $idempotencyKey, true);
            if ($request === null) {
                throw new HttpException(409, 'Create a fresh merge preview before executing.');
            }
            if ($request['status'] === 'completed') {
                $this->assertPreviewMatchesRequest($request, $mainId, $survivorId, $duplicateId, $finalName, $reason, $fieldDecisions);
                $result = $this->decodeJsonObject((string) ($request['after_snapshot'] ?? ''), [
                    'merge_id' => (int) $request['id'],
                    'status' => 'completed',
                ]);
                $pdo->commit();
                return $result;
            }
            if ($request['status'] !== 'previewed') {
                throw new HttpException(409, 'This merge preview is no longer executable. Create a fresh preview.');
            }
            $this->assertPreviewMatchesRequest($request, $mainId, $survivorId, $duplicateId, $finalName, $reason, $fieldDecisions);
            $customers = $this->loadPair($mainId, $survivorId, $duplicateId, true);
            if ($duplicateRequestId !== null) {
                $this->lockAndValidateDuplicateRequest($duplicateRequestId, $mainId, $customers[0], $customers[1]);
            }
            $inventory = $this->inventory($mainId, $survivorId, $duplicateId, $customers);
            $conflicts = $this->findConflicts($customers[0], $customers[1]);
            $currentSnapshot = $this->snapshot($customers, $inventory);
            if ($this->checksum($currentSnapshot) !== (string) ($request['preview_checksum'] ?? '')) {
                throw new HttpException(409, 'Customer data or related-record counts changed after the preview. Create a fresh preview.');
            }
            $blocking = $this->buildBlockingWarnings($conflicts, $fieldDecisions, $inventory['blocking']);
            if ($blocking !== []) {
                throw new HttpException(409, 'Merge is blocked: ' . implode(' ', $blocking));
            }

            $mergeId = (int) $request['id'];
            $this->markExecuting($mergeId, $userId);
            $transfers = [];
            foreach ($inventory['entries'] as $entry) {
                if (!$entry['supported']) {
                    continue;
                }
                if ($entry['rows_found'] === 0) {
                    $this->logTransfer($mergeId, $entry, 0, 'skipped');
                    continue;
                }
                $updated = $this->transfer($entry, $customers[0], $customers[1], $mainId);
                $this->logTransfer($mergeId, $entry, $updated, 'transferred');
                $transfers[] = [
                    'table' => $entry['table'],
                    'column' => $entry['column'],
                    'rows_found' => $entry['rows_found'],
                    'rows_updated' => $updated,
                ];
            }
            $this->applyFieldDecisions($mainId, $survivorId, $customers[0], $customers[1], $fieldDecisions);
            $this->updateSurvivor($mainId, $survivorId, trim($finalName), $duplicateId);
            $this->retireDuplicate($mainId, $duplicateId, $survivorId, $userId, trim($reason), $mergeId);
            $this->createRedirect($mainId, $duplicateId, $survivorId, $mergeId, $userId);
            $duplicateRequestsMerged = $this->syncPendingDuplicateRequests($mainId, $survivorId, $duplicateId, (int) $customers[0]['lid'], (int) $customers[1]['lid'], $mergeId, $userId, $duplicateRequestId);
            $this->assertReconciled($mainId, $duplicateId, $inventory['entries'], (int) $customers[1]['lid']);
            $afterFinancialTotals = $this->financialTotals($mainId, $survivorId, $duplicateId);
            $this->assertFinancialTotalsUnchanged($inventory['financial_totals'], $afterFinancialTotals);

            $after = [
                'merge_id' => $mergeId,
                'status' => 'completed',
                'survivor_session_id' => $survivorId,
                'duplicate_session_id' => $duplicateId,
                'final_company_name' => trim($finalName),
                'field_decisions' => $fieldDecisions,
                'financial_totals' => $afterFinancialTotals,
                'transfers' => $transfers,
                'duplicate_requests_merged' => $duplicateRequestsMerged,
            ];
            $update = $pdo->prepare(
                'UPDATE customer_merge_requests
                 SET status = \'completed\', approved_by = :user_id, completed_at = NOW(), after_snapshot = :after
                 WHERE id = :id'
            );
            $update->execute([
                'user_id' => $userId,
                'after' => json_encode($after, JSON_THROW_ON_ERROR),
                'id' => $mergeId,
            ]);
            $this->writeRequiredMergeAudit($mainId, $userId, $mergeId, $survivorId, $duplicateId, trim($finalName), trim($reason), $transfers, $inventory['financial_totals'], $afterFinancialTotals, $duplicateRequestsMerged);
            $pdo->commit();
            return $after;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof HttpException) {
                throw $e;
            }
            throw new HttpException(500, 'Customer merge failed and was rolled back: ' . $e->getMessage());
        }
    }

    private function validateInput(int $mainId, string $survivorId, string $duplicateId, string $finalName, string $reason, string $idempotencyKey): void
    {
        if ($mainId <= 0) {
            throw new HttpException(403, 'Invalid account scope.');
        }
        if (trim($survivorId) === '' || trim($duplicateId) === '' || trim($survivorId) === trim($duplicateId)) {
            throw new HttpException(422, 'Two distinct customer session IDs are required.');
        }
        if (trim($finalName) === '' || mb_strlen(trim($finalName)) > 255) {
            throw new HttpException(422, 'A final company name between 1 and 255 characters is required.');
        }
        if (trim($reason) === '' || mb_strlen(trim($reason)) > 2000) {
            throw new HttpException(422, 'A merge reason between 1 and 2,000 characters is required.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new HttpException(422, 'A valid idempotency key is required.');
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function loadPair(int $mainId, string $survivorId, string $duplicateId, bool $lock): array
    {
        $sql = 'SELECT * FROM tblpatient
                WHERE lmain_id = :main_id AND lsessionid IN (:survivor, :duplicate)
                  AND COALESCE(ldeleted, 0) = 0 ORDER BY lsessionid';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => $mainId, 'survivor' => $survivorId, 'duplicate' => $duplicateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) {
            throw new HttpException(404, 'Both live customers must exist in the authenticated company.');
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['lsessionid']] = $row;
        }
        return [$byId[$survivorId], $byId[$duplicateId]];
    }

    /** @param array<string, mixed> $survivor @param array<string, mixed> $duplicate */
    private function findConflicts(array $survivor, array $duplicate): array
    {
        $conflicts = [];
        $leftTin = trim((string) ($survivor['ltin'] ?? ''));
        $rightTin = trim((string) ($duplicate['ltin'] ?? ''));
        if ($leftTin !== '' && $rightTin !== '' && strcasecmp($leftTin, $rightTin) !== 0) {
            $conflicts['tin'] = 'Different TIN values';
        }
        foreach (self::CUSTOMER_FIELDS as $key => $field) {
            $left = trim((string) ($survivor[$field['column']] ?? ''));
            $right = trim((string) ($duplicate[$field['column']] ?? ''));
            if ($left !== '' && $right !== '' && strcasecmp($left, $right) !== 0) {
                $conflicts[$key] = $field['label'];
            }
        }
        return $conflicts;
    }

    /** @param array<string, string> $conflicts @param array<string, string> $fieldDecisions */
    private function buildBlockingWarnings(array $conflicts, array $fieldDecisions, array $inventoryBlocking): array
    {
        $warnings = $inventoryBlocking;
        foreach ($conflicts as $field => $label) {
            if ($field === 'tin') {
                $warnings[] = $label . ' require an explicit resolution before merging.';
                continue;
            }
            if (!in_array($fieldDecisions[$field] ?? '', ['survivor', 'duplicate'], true)) {
                $warnings[] = "Select the {$label} source before merging.";
            }
        }
        return $warnings;
    }

    private function snapshot(array $customers, array $inventory): array
    {
        return ['customers' => $customers, 'counts' => $inventory['counts'], 'financial_totals' => $inventory['financial_totals'], 'blocking' => $inventory['blocking']];
    }

    private function checksum(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, string> $fieldDecisions */
    private function savePreview(int $mainId, string $survivorId, string $duplicateId, string $finalName, string $reason, string $key, int $userId, array $fieldDecisions, array $snapshot, string $checksum): int
    {
        $pdo = $this->db->pdo();
        $existing = $this->findMergeRequest($mainId, $key, false);
        if ($existing !== null && $existing['status'] === 'completed') {
            return (int) $existing['id'];
        }
        if ($existing !== null && $existing['status'] === 'executing') {
            throw new HttpException(409, 'This merge is already being processed.');
        }
        if ($existing !== null && ((string) $existing['survivor_session_id'] !== $survivorId || (string) $existing['duplicate_session_id'] !== $duplicateId)) {
            throw new HttpException(409, 'The idempotency key is already assigned to another merge.');
        }
        $payload = [
            'survivor' => $survivorId,
            'duplicate' => $duplicateId,
            'name' => trim($finalName),
            'reason' => trim($reason),
            'key' => $key,
            'status' => 'previewed',
            'requested_by' => $userId,
            'field_decisions' => json_encode($fieldDecisions, JSON_THROW_ON_ERROR),
            'before_snapshot' => json_encode($snapshot['customers'], JSON_THROW_ON_ERROR),
            'preview_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'preview_checksum' => $checksum,
            'main_id' => $mainId,
        ];
        if ($existing === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO customer_merge_requests
                 (main_id, survivor_session_id, duplicate_session_id, final_company_name, merge_reason, idempotency_key,
                  status, requested_by, field_decisions, previewed_at, before_snapshot, preview_snapshot, preview_checksum)
                 VALUES (:main_id, :survivor, :duplicate, :name, :reason, :key, :status, :requested_by,
                         :field_decisions, NOW(), :before_snapshot, :preview_snapshot, :preview_checksum)'
            );
            $stmt->execute($payload);
            return (int) $pdo->lastInsertId();
        }
        $payload['id'] = (int) $existing['id'];
        $stmt = $pdo->prepare(
            'UPDATE customer_merge_requests
             SET survivor_session_id = :survivor, duplicate_session_id = :duplicate, final_company_name = :name,
                 merge_reason = :reason, status = :status, requested_by = :requested_by, field_decisions = :field_decisions,
                 previewed_at = NOW(), before_snapshot = :before_snapshot, preview_snapshot = :preview_snapshot,
                 preview_checksum = :preview_checksum, failure_message = NULL WHERE id = :id'
        );
        $stmt->execute($payload);
        return (int) $existing['id'];
    }

    /** @return array<string, mixed>|null */
    private function findMergeRequest(int $mainId, string $key, bool $lock): ?array
    {
        $sql = 'SELECT * FROM customer_merge_requests WHERE main_id = :main_id AND idempotency_key = :key LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => $mainId, 'key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $request @param array<string, string> $fieldDecisions */
    private function assertPreviewMatchesRequest(array $request, int $mainId, string $survivorId, string $duplicateId, string $finalName, string $reason, array $fieldDecisions): void
    {
        $same = (int) $request['main_id'] === $mainId
            && (string) $request['survivor_session_id'] === $survivorId
            && (string) $request['duplicate_session_id'] === $duplicateId
            && (string) $request['final_company_name'] === trim($finalName)
            && (string) $request['merge_reason'] === trim($reason)
            && $this->decodeJsonObject((string) ($request['field_decisions'] ?? '{}'), []) === $fieldDecisions;
        if (!$same) {
            throw new HttpException(409, 'The execute request does not match the saved preview. Create a fresh preview.');
        }
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $survivor @param array<string, mixed> $duplicate */
    private function transfer(array $entry, array $survivor, array $duplicate, int $mainId): int
    {
        $table = $entry['table'];
        $column = $entry['column'];
        $mode = (string) ($entry['mode'] ?? 'session');
        $scopeColumn = $entry['scope_column'] ?? null;
        $scope = $this->tenantScopePredicate($table, $column, $mode, is_string($scopeColumn) ? $scopeColumn : null);
        if ($mode === 'json_contact') {
            $sql = "UPDATE `{$table}` SET `{$column}` = JSON_SET(`{$column}`, '$.contact_id', :target) WHERE JSON_VALID(`{$column}`) AND JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, '$.contact_id')) = :source{$scope}";
            $params = ['target' => (string) $survivor['lsessionid'], 'source' => (string) $duplicate['lsessionid']];
        } elseif ($mode === 'patient_lid') {
            $sql = "UPDATE `{$table}` SET `{$column}` = :target WHERE `{$column}` = :source{$scope}";
            $params = ['target' => (int) $survivor['lid'], 'source' => (int) $duplicate['lid']];
        } elseif ($mode === 'session_or_lid') {
            $sql = "UPDATE `{$table}` SET `{$column}` = CASE WHEN `{$column}` = :duplicate_lid_case THEN :survivor_lid ELSE :survivor END WHERE `{$column}` IN (:duplicate, :duplicate_lid_where){$scope}";
            $params = [
                'survivor' => (string) $survivor['lsessionid'],
                'duplicate' => (string) $duplicate['lsessionid'],
                'survivor_lid' => (string) $survivor['lid'],
                'duplicate_lid_case' => (string) $duplicate['lid'],
                'duplicate_lid_where' => (string) $duplicate['lid'],
            ];
        } else {
            $sql = "UPDATE `{$table}` SET `{$column}` = :target WHERE `{$column}` = :source{$scope}";
            $params = ['target' => (string) $survivor['lsessionid'], 'source' => (string) $duplicate['lsessionid']];
        }
        $params[$scopeColumn === null ? 'scope_main_id' : 'main_id'] = $mainId;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @param array<string, mixed> $survivor @param array<string, mixed> $duplicate @param array<string, string> $decisions */
    private function applyFieldDecisions(int $mainId, string $survivorId, array $survivor, array $duplicate, array $decisions): void
    {
        $sets = [];
        $params = ['main_id' => $mainId, 'survivor_id' => $survivorId];
        foreach (self::CUSTOMER_FIELDS as $key => $field) {
            if (!isset($decisions[$key])) {
                continue;
            }
            $sets[] = "`{$field['column']}` = :{$key}";
            $params[$key] = $decisions[$key] === 'duplicate' ? $duplicate[$field['column']] : $survivor[$field['column']];
        }
        if ($sets === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('UPDATE tblpatient SET ' . implode(', ', $sets) . ' WHERE lmain_id = :main_id AND lsessionid = :survivor_id');
        $stmt->execute($params);
    }

    /** @param array<string, mixed> $survivor @param array<string, mixed> $duplicate */
    private function lockAndValidateDuplicateRequest(int $requestId, int $mainId, array $survivor, array $duplicate): void
    {
        if (!$this->tableExists('tblpatient_duplicate_request')) {
            throw new HttpException(503, 'The duplicate-request workflow table is not available.');
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tblpatient_duplicate_request
             WHERE lid = :id AND lmain_id = :main_id AND lstatus = \'pending\'
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $requestId, 'main_id' => $mainId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) {
            throw new HttpException(409, 'The duplicate request is no longer pending in this account.');
        }

        $survivorLid = (int) ($survivor['lid'] ?? 0);
        $duplicateLid = (int) ($duplicate['lid'] ?? 0);
        $sessionMatches = (string) ($request['lsessionid'] ?? '') === (string) ($duplicate['lsessionid'] ?? '');
        $idMatches = in_array($survivorLid, [(int) ($request['lexisting_prospect_id'] ?? 0), (int) ($request['lnew_prospect_id'] ?? 0)], true)
            && in_array($duplicateLid, [(int) ($request['lexisting_prospect_id'] ?? 0), (int) ($request['lnew_prospect_id'] ?? 0)], true);
        if (!$sessionMatches && !$idMatches) {
            throw new HttpException(409, 'The approval request does not match the selected customer pair.');
        }
    }

    private function syncPendingDuplicateRequests(
        int $mainId,
        string $survivorId,
        string $duplicateId,
        int $survivorLid,
        int $duplicateLid,
        int $mergeId,
        int $userId,
        ?int $specificRequestId
    ): int {
        $merged = 0;
        $pdo = $this->db->pdo();
        if ($this->tableExists('tblpatient_duplicate_request')
            && $this->columnExists('tblpatient_duplicate_request', 'lstatus')) {
            $matches = [];
            if ($this->columnExists('tblpatient_duplicate_request', 'lsessionid')) {
                $matches[] = 'lsessionid = :duplicate_session';
            }
            $hasExistingId = $this->columnExists('tblpatient_duplicate_request', 'lexisting_prospect_id');
            $hasNewId = $this->columnExists('tblpatient_duplicate_request', 'lnew_prospect_id');
            if ($hasExistingId && $hasNewId) {
                $matches[] = '((lexisting_prospect_id = :survivor_lid AND lnew_prospect_id = :duplicate_lid)
                              OR (lexisting_prospect_id = :duplicate_lid_2 AND lnew_prospect_id = :survivor_lid_2))';
            } elseif ($hasExistingId) {
                $matches[] = 'lexisting_prospect_id = :duplicate_lid';
            } elseif ($hasNewId) {
                $matches[] = 'lnew_prospect_id = :duplicate_lid';
            }
            if ($matches !== []) {
                $sql = 'UPDATE tblpatient_duplicate_request
                        SET lstatus = \'merged\', lapproved_by = :approved_by,
                            lapproved_by_name = \'Master User\', lapproved_at = NOW(), lmerge_id = :merge_id
                        WHERE lmain_id = :main_id AND lstatus = \'pending\'
                          AND (' . implode(' OR ', $matches) . ')';
                $params = [
                    'approved_by' => $userId,
                    'merge_id' => $mergeId,
                    'main_id' => $mainId,
                    'duplicate_session' => $duplicateId,
                    'duplicate_lid' => $duplicateLid,
                    'duplicate_lid_2' => $duplicateLid,
                    'survivor_lid' => $survivorLid,
                    'survivor_lid_2' => $survivorLid,
                ];
                if ($specificRequestId !== null) {
                    $sql .= ' OR (lid = :specific_id AND lmain_id = :specific_main_id AND lstatus = \'pending\')';
                    $params['specific_id'] = $specificRequestId;
                    $params['specific_main_id'] = $mainId;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $merged += $stmt->rowCount();
            }
        }

        // The newer customer_requests queue has no "merged" enum. Rejected
        // with an explicit note is its durable cancellation state.
        if ($this->tableExists('customer_requests')
            && $this->columnExists('customer_requests', 'kind')
            && $this->columnExists('customer_requests', 'contact_id')
            && $this->columnExists('customer_requests', 'status')) {
            $stmt = $pdo->prepare(
                'UPDATE customer_requests
                 SET status = \'rejected\', reviewed_by = :reviewed_by, reviewed_at = NOW(),
                     review_note = :note
                 WHERE main_id = :main_id AND contact_id = :duplicate AND kind = \'duplicate_prospect\' AND status = \'pending\''
            );
            $stmt->execute([
                'reviewed_by' => $userId,
                'note' => 'Cancelled by customer merge ' . $mergeId . ' into ' . $survivorId,
                'main_id' => $mainId,
                'duplicate' => $duplicateId,
            ]);
            $merged += $stmt->rowCount();
        }

        return $merged;
    }

    /** @param array<int, array<string, mixed>> $transfers */
    private function writeRequiredMergeAudit(
        int $mainId,
        int $userId,
        int $mergeId,
        string $survivorId,
        string $duplicateId,
        string $finalName,
        string $reason,
        array $transfers,
        array $beforeTotals,
        array $afterTotals,
        int $duplicateRequestsMerged
    ): void {
        // Full transfer/totals detail is already persisted transactionally in
        // customer_merge_requests and customer_merge_transfer_log. The legacy
        // audit column is VARCHAR(500), so keep its required row concise.
        $details = substr(sprintf(
            'merge=%d survivor=%s duplicate=%s final=%s requests=%d reason=%s',
            $mergeId,
            $survivorId,
            $duplicateId,
            $finalName,
            $duplicateRequestsMerged,
            $reason
        ), 0, 500);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblaudit_trail
                (lmain_id, luser_id, lpage, laction, lrefno, lreason, lold_status, lnew_status, ldatetime)
             VALUES (:main_id, :user_id, \'Customer Merge\', \'Merge\', :refno, :reason, :old_status, :new_status, NOW())'
        );
        $stmt->execute([
            'main_id' => $mainId,
            'user_id' => $userId,
            'refno' => 'merge:' . $mergeId,
            'reason' => $details,
            'old_status' => $duplicateId,
            'new_status' => $survivorId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(500, 'Required merge audit could not be recorded.');
        }
    }

    private function updateSurvivor(int $mainId, string $id, string $name, string $duplicateId): void
    {
        $check = $this->db->pdo()->prepare(
            'SELECT 1 FROM tblpatient WHERE lmain_id = :main_id AND COALESCE(ldeleted, 0) = 0
             AND lsessionid NOT IN (:survivor, :duplicate)
             AND LOWER(TRIM(COALESCE(lcompany, ""))) = LOWER(TRIM(:name)) LIMIT 1'
        );
        $check->execute(['main_id' => $mainId, 'survivor' => $id, 'duplicate' => $duplicateId, 'name' => $name]);
        if ($check->fetchColumn()) {
            throw new HttpException(409, 'The final company name is already used by another live customer.');
        }
        $stmt = $this->db->pdo()->prepare('UPDATE tblpatient SET lcompany = :name WHERE lmain_id = :main_id AND lsessionid = :id AND COALESCE(ldeleted, 0) = 0');
        $stmt->execute(['name' => $name, 'main_id' => $mainId, 'id' => $id]);
        $exists = $this->db->pdo()->prepare('SELECT 1 FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :id AND COALESCE(ldeleted, 0) = 0 LIMIT 1');
        $exists->execute(['main_id' => $mainId, 'id' => $id]);
        if (!$exists->fetchColumn()) {
            throw new HttpException(409, 'The surviving customer could not be updated.');
        }
    }

    private function retireDuplicate(int $mainId, string $id, string $survivor, int $userId, string $reason, int $mergeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblpatient SET ldeleted = 1, ldeleted_at = NOW(), ldeleted_by = :deleted_by,
             ldelete_reason = :reason, lstatus = 0, lmerged_into_sessionid = :survivor,
             lmerged_at = NOW(), lmerged_by = :merged_by, lmerge_id = :merge_id
             WHERE lmain_id = :main_id AND lsessionid = :id AND COALESCE(ldeleted, 0) = 0'
        );
        $stmt->execute(['deleted_by' => $userId, 'merged_by' => $userId, 'reason' => 'Merged into ' . $survivor . ': ' . $reason, 'survivor' => $survivor, 'merge_id' => $mergeId, 'main_id' => $mainId, 'id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, 'The duplicate customer could not be retired.');
        }
    }

    private function createRedirect(int $mainId, string $duplicateId, string $survivorId, int $mergeId, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO customer_merge_redirects (main_id, old_customer_session_id, surviving_customer_session_id, merge_id, merged_by) VALUES (:main_id, :old_id, :new_id, :merge_id, :user_id)');
        $stmt->execute(['main_id' => $mainId, 'old_id' => $duplicateId, 'new_id' => $survivorId, 'merge_id' => $mergeId, 'user_id' => $userId]);
    }

    /** @param array<string, mixed> $entry */
    private function logTransfer(int $mergeId, array $entry, int $updated, string $status): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO customer_merge_transfer_log (merge_id, table_name, customer_reference_column, source_customer_id, target_customer_id, rows_found, rows_updated, transfer_status) VALUES (:merge_id, :table_name, :column, :source, :target, :found, :updated, :status)');
        $stmt->execute(['merge_id' => $mergeId, 'table_name' => $entry['table'], 'column' => $entry['column'], 'source' => $entry['source_value'], 'target' => $entry['target_value'], 'found' => (int) $entry['rows_found'], 'updated' => $updated, 'status' => $status]);
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function assertReconciled(int $mainId, string $duplicateId, array $entries, int $duplicateLid): void
    {
        foreach ($entries as $entry) {
            if (!$entry['supported']) {
                continue;
            }
            if ($this->countReferenceRows($entry, $duplicateId, $mainId, $duplicateLid) > 0) {
                throw new HttpException(500, "Reconciliation failed for {$entry['table']}.{$entry['column']}.");
            }
        }
    }

    /** @param array<int, array<string, mixed>> $customers */
    private function inventory(int $mainId, string $survivorId, string $duplicateId, array $customers): array
    {
        $survivor = $customers[0];
        $duplicate = $customers[1];
        $entries = [];
        $blocking = [];
        $supportedKeys = [];
        foreach (self::SUPPORTED_REFERENCES as $table => $reference) {
            $supportedKeys[$table . '.' . $reference['column']] = true;
            if (!$this->tableExists($table) || !$this->columnExists($table, $reference['column'])) {
                continue;
            }
            $scopeColumn = $this->tenantScopeColumn($table);
            if ($scopeColumn === null && !$this->tableExists('tblpatient')) {
                $entries[] = ['table' => $table, 'column' => $reference['column'], 'mode' => $reference['mode'], 'scope_column' => null, 'label' => $reference['label'], 'source_value' => '', 'target_value' => '', 'rows_found' => null, 'supported' => false, 'status' => 'blocked'];
                $blocking[] = "{$table}.{$reference['column']} has no provable tenant scope.";
                continue;
            }
            $sourceValue = $reference['mode'] === 'patient_lid' ? (string) $duplicate['lid'] : $duplicateId;
            $targetValue = $reference['mode'] === 'patient_lid' ? (string) $survivor['lid'] : $survivorId;
            $rows = $this->countReferenceRows(['table' => $table, 'column' => $reference['column'], 'mode' => $reference['mode'], 'scope_column' => $scopeColumn], $duplicateId, $mainId, (int) $duplicate['lid']);
            $entries[] = ['table' => $table, 'column' => $reference['column'], 'mode' => $reference['mode'], 'scope_column' => $scopeColumn, 'label' => $reference['label'], 'source_value' => $sourceValue, 'target_value' => $targetValue, 'rows_found' => $rows, 'supported' => true, 'status' => 'supported'];
        }
        foreach (self::BLOCKED_REFERENCES as $table => $reference) {
            if (!$this->tableExists($table) || !$this->columnExists($table, $reference['column'])) {
                continue;
            }
            $entries[] = ['table' => $table, 'column' => $reference['column'], 'mode' => 'unknown', 'scope_column' => null, 'label' => $reference['label'], 'source_value' => '', 'target_value' => '', 'rows_found' => null, 'supported' => false, 'status' => 'blocked'];
            $blocking[] = "{$table}.{$reference['column']} cannot be transferred safely because its tenant scope or identity mapping is not proven.";
            $supportedKeys[$table . '.' . $reference['column']] = true;
        }
        foreach ($this->unknownReferenceColumns($supportedKeys) as $candidate) {
            $candidate['scope_column'] = $this->tenantScopeColumn($candidate['table']);
            $candidate['rows_found'] = $this->countReferenceRows($candidate, $duplicateId, $mainId, (int) $duplicate['lid']);
            $candidate['supported'] = false;
            $candidate['status'] = $candidate['rows_found'] > 0 ? 'blocked' : 'unclassified-empty';
            $entries[] = $candidate;
            if ($candidate['rows_found'] > 0) {
                $scopeWarning = $this->tenantScopeColumn($candidate['table']) === null ? ' and has no proven tenant scope' : '';
                $blocking[] = "Unclassified customer reference {$candidate['table']}.{$candidate['column']} has {$candidate['rows_found']} source row(s){$scopeWarning}; merge coverage is incomplete.";
            }
        }
        if ($this->tableExists('customer_merge_redirects')) {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM customer_merge_redirects WHERE main_id = :main_id AND old_customer_session_id IN (:survivor, :duplicate) LIMIT 1');
            $stmt->execute(['main_id' => $mainId, 'survivor' => $survivorId, 'duplicate' => $duplicateId]);
            if ($stmt->fetchColumn()) {
                $blocking[] = 'One of the customers has already been merged.';
            }
        }
        return ['entries' => $entries, 'blocking' => array_values(array_unique($blocking)), 'counts' => array_column($entries, 'rows_found', 'table'), 'financial_totals' => $this->financialTotals($mainId, $survivorId, $duplicateId)];
    }

    /** @param array<string, bool> $supportedKeys @return array<int, array<string, string|null>> */
    private function unknownReferenceColumns(array $supportedKeys): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name
             FROM INFORMATION_SCHEMA.COLUMNS c
             INNER JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
               AND c.COLUMN_NAME REGEXP '(^|_)(customer|contact|patient|session|client)(_|$)|^l(customer|contact|patient|session|client)(id|_id)?$'"
        );
        $unknown = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['table_name'];
            $column = (string) $row['column_name'];
            if ($table === 'tblpatient'
                || in_array($table, self::WORKFLOW_TABLES, true)
                || in_array($table, self::KNOWN_WORKFLOW_TABLES, true)
                || isset($supportedKeys[$table . '.' . $column])
                || isset(self::NON_REFERENCE_COLUMNS[strtolower($column)])) {
                continue;
            }
            $unknown[] = ['table' => $table, 'column' => $column, 'scope_column' => null, 'label' => 'Unclassified schema reference', 'source_value' => '', 'target_value' => ''];
        }
        return $unknown;
    }

    private function countReferenceRows(array $entry, string $duplicateId, int $mainId, ?int $duplicateLid = null): int
    {
        $table = $entry['table'];
        $column = $entry['column'];
        $mode = (string) ($entry['mode'] ?? 'unknown');
        $scopeColumn = $entry['scope_column'] ?? null;
        $where = "`{$column}` = :source";
        $params = ['source' => $mode === 'patient_lid' ? ($duplicateLid ?? 0) : $duplicateId];
        if ($mode === 'json_contact') {
            $where = "JSON_VALID(`{$column}`) AND JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, '$.contact_id')) = :source";
            $params = ['source' => $duplicateId];
        } elseif ($mode === 'session_or_lid') {
            $where = "`{$column}` IN (:session_source, :lid_source)";
            $params = ['session_source' => $duplicateId, 'lid_source' => (string) ($duplicateLid ?? 0)];
        } elseif ($mode === 'unknown' && $duplicateLid !== null && in_array($this->columnType($table, $column), ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
            $params = ['source' => $duplicateLid];
        } elseif ($mode === 'unknown') {
            $where = "CAST(`{$column}` AS CHAR) IN (:session_source, :lid_source)";
            $params = ['session_source' => $duplicateId, 'lid_source' => (string) ($duplicateLid ?? 0)];
        }
        $scope = $this->tenantScopePredicate($table, $column, $mode, is_string($scopeColumn) && $scopeColumn !== '' ? $scopeColumn : null);
        $params[$scopeColumn === null ? 'scope_main_id' : 'main_id'] = $mainId;
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}{$scope}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function financialTotals(int $mainId, string $survivorId, string $duplicateId): array
    {
        $totals = [
            'invoice_value' => null,
            'payment_value' => null,
            'collection_value' => null,
            'debit_memo_value' => null,
            'credit_memo_value' => null,
            'return_value' => null,
            'ledger_debit' => null,
            'ledger_credit' => null,
            'ledger_balance' => null,
        ];
        $pdo = $this->db->pdo();
        if ($this->tableExists('tblinvoice_itemrec')
            && $this->columnExists('tblinvoice_itemrec', 'linvoice_refno')
            && $this->columnExists('tblinvoice_itemrec', 'lqty')
            && $this->columnExists('tblinvoice_itemrec', 'lprice')
            && $this->tableExists('tblinvoice_list')
            && $this->columnExists('tblinvoice_list', 'lcustomerid')
            && $this->columnExists('tblinvoice_list', 'lrefno')
            && $this->tenantScopeColumn('tblinvoice_list') !== null) {
            $scopeColumn = $this->tenantScopeColumn('tblinvoice_list');
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(item.lqty, 0) * COALESCE(item.lprice, 0)), 0) FROM tblinvoice_list header INNER JOIN tblinvoice_itemrec item ON item.linvoice_refno = header.lrefno WHERE header.lcustomerid IN (:survivor, :duplicate) AND header.`{$scopeColumn}` = :main_id");
            $stmt->execute(['survivor' => $survivorId, 'duplicate' => $duplicateId, 'main_id' => $mainId]);
            $totals['invoice_value'] = (float) $stmt->fetchColumn();
        }
        if ($this->tableExists('tblledger')
            && $this->columnExists('tblledger', 'lcustomerid')
            && $this->columnExists('tblledger', 'ldebit')
            && $this->columnExists('tblledger', 'lcredit')
            && $this->tenantScopeColumn('tblledger') !== null) {
            $scopeColumn = $this->tenantScopeColumn('tblledger');
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(ldebit, 0)), 0), COALESCE(SUM(COALESCE(lcredit, 0)), 0), COALESCE(SUM(COALESCE(ldebit, 0) - COALESCE(lcredit, 0)), 0) FROM tblledger WHERE lcustomerid IN (:survivor, :duplicate) AND `{$scopeColumn}` = :main_id");
            $stmt->execute(['survivor' => $survivorId, 'duplicate' => $duplicateId, 'main_id' => $mainId]);
            $row = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
            $totals['ledger_debit'] = (float) $row[0];
            $totals['ledger_credit'] = (float) $row[1];
            $totals['ledger_balance'] = (float) $row[2];
        }
        if ($this->tableExists('tbldebit_memo_items')
            && $this->columnExists('tbldebit_memo_items', 'lcustomerid')
            && $this->columnExists('tbldebit_memo_items', 'lamount')) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(COALESCE(item.lamount, 0)), 0)
                 FROM tbldebit_memo_items item
                 WHERE item.lcustomerid IN (:survivor, :duplicate)
                   AND EXISTS (
                       SELECT 1 FROM tblpatient p
                       WHERE p.lmain_id = :main_id AND p.lsessionid = item.lcustomerid
                   )'
            );
            $stmt->execute(['survivor' => $survivorId, 'duplicate' => $duplicateId, 'main_id' => $mainId]);
            $totals['debit_memo_value'] = (float) $stmt->fetchColumn();
        }
        if ($this->tableExists('tblcollection_item')
            && $this->columnExists('tblcollection_item', 'lcustomer')
            && $this->columnExists('tblcollection_item', 'lamt')) {
            $scopeColumn = $this->tenantScopeColumn('tblcollection_item');
            $scope = $scopeColumn !== null ? " AND `{$scopeColumn}` = :main_id" : '';
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(lamt, 0)), 0) FROM tblcollection_item WHERE lcustomer IN (:survivor, :duplicate){$scope}");
            $params = ['survivor' => $survivorId, 'duplicate' => $duplicateId];
            if ($scopeColumn !== null) {
                $params['main_id'] = $mainId;
            }
            $stmt->execute($params);
            $totals['collection_value'] = (float) $stmt->fetchColumn();
        }
        if ($this->tableExists('tblcredit_memo')
            && $this->columnExists('tblcredit_memo', 'lcustomer')
            && $this->tableExists('tblcredits_memo_item')
            && $this->columnExists('tblcredits_memo_item', 'lrefno')
            && $this->columnExists('tblcredits_memo_item', 'lprice')
            && $this->columnExists('tblcredits_memo_item', 'lqty')) {
            $scopeColumn = $this->tenantScopeColumn('tblcredit_memo');
            $scope = $scopeColumn !== null ? " AND header.`{$scopeColumn}` = :main_id" : '';
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(item.lprice, 0) * COALESCE(item.lqty, 0)), 0) FROM tblcredit_memo header INNER JOIN tblcredits_memo_item item ON item.lrefno = header.lrefno WHERE header.lcustomer IN (:survivor, :duplicate){$scope}");
            $params = ['survivor' => $survivorId, 'duplicate' => $duplicateId];
            if ($scopeColumn !== null) {
                $params['main_id'] = $mainId;
            }
            $stmt->execute($params);
            $totals['credit_memo_value'] = (float) $stmt->fetchColumn();
        }
        if ($this->tableExists('tblcredit_return_item')
            && $this->columnExists('tblcredit_return_item', 'lpatient_id')
            && $this->columnExists('tblcredit_return_item', 'lprice')
            && $this->columnExists('tblcredit_return_item', 'lqty')) {
            $entry = ['table' => 'tblcredit_return_item', 'column' => 'lpatient_id', 'mode' => 'session', 'scope_column' => $this->tenantScopeColumn('tblcredit_return_item')];
            $scope = $this->tenantScopePredicate('tblcredit_return_item', 'lpatient_id', 'session', $entry['scope_column']);
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(lprice, 0) * COALESCE(lqty, 0)), 0) FROM tblcredit_return_item WHERE lpatient_id IN (:survivor, :duplicate){$scope}");
            $params = ['survivor' => $survivorId, 'duplicate' => $duplicateId, $entry['scope_column'] === null ? 'scope_main_id' : 'main_id' => $mainId];
            $stmt->execute($params);
            $totals['return_value'] = (float) $stmt->fetchColumn();
        }
        return $totals;
    }

    private function assertFinancialTotalsUnchanged(array $before, array $after): void
    {
        foreach ($before as $key => $beforeValue) {
            $afterValue = $after[$key] ?? null;
            if ($beforeValue === null || $afterValue === null) {
                continue;
            }
            if (abs((float) $beforeValue - (float) $afterValue) > 0.005) {
                throw new HttpException(500, "Financial reconciliation failed for {$key}.");
            }
        }
    }

    private function requireWorkflowSchema(): void
    {
        foreach (['customer_merge_requests', 'customer_merge_transfer_log', 'customer_merge_redirects'] as $table) {
            if (!$this->tableExists($table)) {
                throw new HttpException(503, 'Customer merge migration has not been applied.');
            }
        }
        $requiredColumns = [
            'customer_merge_requests' => ['field_decisions', 'preview_snapshot', 'preview_checksum', 'after_snapshot'],
            'customer_merge_transfer_log' => ['table_name', 'customer_reference_column', 'rows_found', 'rows_updated'],
            'customer_merge_redirects' => ['old_customer_session_id', 'surviving_customer_session_id'],
            'tblpatient' => ['ldeleted', 'ldeleted_at', 'ldeleted_by', 'ldelete_reason', 'lmerged_into_sessionid', 'lmerged_at', 'lmerged_by', 'lmerge_id'],
            'tblaudit_trail' => ['lreason', 'lold_status', 'lnew_status'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (!$this->columnExists($table, $column)) {
                    throw new HttpException(503, "Customer merge migration is incomplete: {$table}.{$column} is missing.");
                }
            }
        }
        if ($this->tableExists('tblpatient_duplicate_request')
            && (!$this->columnExists('tblpatient_duplicate_request', 'lmerge_id')
                || !$this->columnExists('tblpatient_duplicate_request', 'lstatus'))) {
            throw new HttpException(503, 'Customer merge migration is incomplete for tblpatient_duplicate_request.');
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (bool) $stmt->fetchColumn();
    }

    private function tenantScopeColumn(string $table): ?string
    {
        foreach (['lmain_id', 'lmainid', 'main_id'] as $scopeColumn) {
            if ($this->columnExists($table, $scopeColumn)) {
                return $scopeColumn;
            }
        }
        return null;
    }

    private function tenantScopePredicate(string $table, string $column, string $mode, ?string $scopeColumn): string
    {
        if ($scopeColumn !== null) {
            return " AND `{$scopeColumn}` = :main_id";
        }
        if (!$this->tableExists('tblpatient')) {
            throw new HttpException(409, "Merge is blocked because {$table}.{$column} has no provable tenant scope.");
        }

        $customerMatch = match ($mode) {
            'patient_lid' => "p_scope.lid = `{$table}`.`{$column}`",
            'session_or_lid' => "(p_scope.lsessionid = `{$table}`.`{$column}` OR CAST(p_scope.lid AS CHAR) = CAST(`{$table}`.`{$column}` AS CHAR))",
            default => "p_scope.lsessionid = `{$table}`.`{$column}`",
        };
        return " AND EXISTS (SELECT 1 FROM tblpatient p_scope WHERE p_scope.lmain_id = :scope_main_id AND {$customerMatch})";
    }

    private function columnType(string $table, string $column): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return strtolower((string) ($stmt->fetchColumn() ?: ''));
    }

    private function markExecuting(int $mergeId, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE customer_merge_requests SET status = \'executing\', approved_by = :user_id, executing_at = NOW() WHERE id = :id AND status = \'previewed\'');
        $stmt->execute(['user_id' => $userId, 'id' => $mergeId]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, 'This merge is already being processed.');
        }
    }

    /** @param mixed $fallback */
    private function decodeJsonObject(string $value, mixed $fallback): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : (is_array($fallback) ? $fallback : []);
    }
}
