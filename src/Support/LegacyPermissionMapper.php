<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class LegacyPermissionMapper
{
    /**
     * @var array<string, array<int, string>>
     */
    private const PAGE_NAME_TO_MODULE_IDS = [
        'product database' => ['warehouse-inventory-product-database'],
        'inventory' => ['warehouse-inventory-product-database'],
        'stock movement' => ['warehouse-inventory-stock-movement'],
        'transfer product' => ['warehouse-inventory-transfer-stock'],
        'transfer stock' => ['warehouse-inventory-transfer-stock'],
        'purchase request' => ['warehouse-purchasing-purchase-request'],
        'purchase order' => ['warehouse-purchasing-purchase-order'],
        'purchase order report' => ['warehouse-purchasing-purchase-order'],
        'receiving report' => ['warehouse-purchasing-receiving-stock'],
        'receiving delivery' => ['warehouse-purchasing-receiving-stock'],
        'return supplier' => ['warehouse-purchasing-return-to-supplier'],
        'reorder' => ['warehouse-reports-reorder-report'],
        'prospect' => ['sales-pipeline-board'],
        'prospect data' => ['sales-pipeline-board'],
        'customer database' => ['sales-database-customer-database'],
        'sales inquiry' => ['sales-transaction-sales-inquiry'],
        'sales order' => ['sales-transaction-sales-order'],
        'order slip' => ['sales-transaction-order-slip'],
        'sales invoice' => ['sales-transaction-invoice'],
        'invoice' => ['sales-transaction-invoice'],
        'report' => [
            'sales-reports-inquiry-report',
            'sales-reports-sales-report',
            'sales-reports-sales-development-report',
            'warehouse-reports-inventory-report',
            'warehouse-reports-reorder-report',
            'warehouse-reports-item-suggested-for-stock-report',
            'accounting-reports-accounting-overview',
            'accounting-reports-aging-report',
            'accounting-reports-collection-report',
            'accounting-reports-sales-return-report',
            'accounting-reports-accounts-receivable-report',
            'accounting-reports-freight-charges-report',
            'accounting-reports-purchase-history',
            'accounting-reports-inactive-active-customers',
            'accounting-reports-old-new-customers',
        ],
        'analytics' => ['sales-performance-management-dashboard'],
        'collection report' => ['accounting-transactions-daily-collection-entry'],
        'debit memo' => ['accounting-transactions-freight-charges-debit'],
        'freight charges' => ['accounting-transactions-freight-charges-debit'],
        'credit memo' => ['accounting-transactions-sales-return-credit'],
        'sales return' => ['accounting-transactions-sales-return-credit'],
        'adjustment' => ['accounting-transactions-adjustment-entry'],
        'statement of account' => ['accounting-accounting-statement-of-account'],
        'customer data' => ['maintenance-customer-customer-data'],
        'customer group' => ['maintenance-customer-customer-group'],
        'customer' => ['maintenance-customer-customer-data'],
        'supplier record' => ['maintenance-product-suppliers'],
        'special price' => ['maintenance-product-special-price'],
        'category' => ['maintenance-product-category-management'],
        'staff records' => ['maintenance-profile-staff'],
        'team' => ['maintenance-profile-team'],
        'approver' => ['maintenance-profile-approver'],
        'group access' => ['maintenance-profile-system-access'],
        'sms' => ['communication-text-menu-text-messages'],
        'call logs' => ['sales-transaction-daily-call-monitoring'],
        'call status' => ['sales-transaction-daily-call-monitoring'],
        'campaign' => ['sales-transaction-product-promotions'],
        'campaign template' => ['sales-transaction-product-promotions'],
        'auto responder' => ['communication-messaging-inbox'],
    ];

    /** @var array<int, array{names: array<int, string>}>|null */
    private ?array $pageCatalog = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, string>
     */
    public function getAccessRightsForGroup(int $mainId, int $groupId): array
    {
        $pageIds = $this->fetchPageIdsForGroup($mainId, $groupId);
        $moduleIds = ['home' => true];

        foreach ($pageIds as $pageId) {
            $page = $this->getPageCatalog()[$pageId] ?? null;
            if ($page === null) {
                continue;
            }

            foreach ($page['names'] as $name) {
                foreach ($this->resolveModuleIdsForPageName($name) as $moduleId) {
                    $moduleIds[$moduleId] = true;
                }
            }
        }

        return array_keys($moduleIds);
    }

    /**
     * @param array<int, string> $accessRights
     */
    public function syncGroupPermissions(int $mainId, int $groupId, array $accessRights): void
    {
        $pageIds = $this->resolvePageIdsForModules($accessRights);

        $deleteStmt = $this->pdo->prepare(
            'DELETE FROM tblweb_permission
             WHERE lmain_id = :main_id
               AND lgroup = :group_id'
        );
        $deleteStmt->execute([
            'main_id' => $mainId,
            'group_id' => $groupId,
        ]);

        if ($pageIds === []) {
            return;
        }

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO tblweb_permission (lpageno, lgroup, lstatus, lmain_id, ladd_action, ledit_action, ldelete_action)
             VALUES (:page_id, :group_id, 1, :main_id, 1, 1, 1)'
        );

        foreach ($pageIds as $pageId) {
            $insertStmt->execute([
                'page_id' => $pageId,
                'group_id' => $groupId,
                'main_id' => $mainId,
            ]);
        }
    }

    /**
     * @param array<int, string> $accessRights
     * @return array<int, int>
     */
    private function resolvePageIdsForModules(array $accessRights): array
    {
        $wantedNames = [];
        foreach ($accessRights as $moduleId) {
            if ($moduleId === 'home' || $moduleId === '*' || trim($moduleId) === '') {
                continue;
            }

            foreach ($this->moduleIdToPageNames()[$moduleId] ?? [] as $pageName) {
                $wantedNames[$pageName] = true;
            }
        }

        if ($wantedNames === []) {
            return [];
        }

        $pageIds = [];
        foreach ($this->getPageCatalog() as $pageId => $page) {
            foreach ($page['names'] as $name) {
                if (isset($wantedNames[$name])) {
                    $pageIds[$pageId] = true;
                    break;
                }
            }
        }

        return array_map('intval', array_keys($pageIds));
    }

    /**
     * @return array<int, array{names: array<int, string>}>
     */
    private function getPageCatalog(): array
    {
        if ($this->pageCatalog !== null) {
            return $this->pageCatalog;
        }

        $columns = $this->pdo->query(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tblweb_pagecateg'"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $columns = array_fill_keys(array_map('strtolower', $columns), true);
        $select = ['lid'];
        $select[] = isset($columns['lpage_name']) ? 'lpage_name' : "'' AS lpage_name";
        $select[] = isset($columns['lcateg_page']) ? 'lcateg_page' : "'' AS lcateg_page";

        $stmt = $this->pdo->query(sprintf('SELECT %s FROM tblweb_pagecateg', implode(', ', $select)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $catalog = [];
        foreach ($rows as $row) {
            $names = [];
            foreach (['lpage_name', 'lcateg_page'] as $field) {
                $value = strtolower(trim((string) ($row[$field] ?? '')));
                if ($value !== '') {
                    $names[$value] = $value;
                }
            }
            $catalog[(int) ($row['lid'] ?? 0)] = [
                'names' => array_values($names),
            ];
        }

        $this->pageCatalog = $catalog;
        return $catalog;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function moduleIdToPageNames(): array
    {
        static $reverse = null;
        if (is_array($reverse)) {
            return $reverse;
        }

        $reverse = [];
        foreach (self::PAGE_NAME_TO_MODULE_IDS as $pageName => $moduleIds) {
            foreach ($moduleIds as $moduleId) {
                $reverse[$moduleId] ??= [];
                $reverse[$moduleId][] = $pageName;
            }
        }

        return $reverse;
    }

    /**
     * @return array<int, int>
     */
    private function fetchPageIdsForGroup(int $mainId, int $groupId): array
    {
        $queries = [
            [
                'sql' => 'SELECT lpageno
                          FROM tblweb_permission
                          WHERE lmain_id = :main_id
                            AND lgroup = :group_id
                            AND lstatus = 1',
                'params' => ['main_id' => $mainId, 'group_id' => $groupId],
            ],
            [
                'sql' => 'SELECT lpageno
                          FROM tblweb_permission
                          WHERE lgroup = :group_id
                            AND lstatus = 1',
                'params' => ['group_id' => $groupId],
            ],
        ];

        foreach ($queries as $query) {
            $stmt = $this->pdo->prepare($query['sql']);
            $stmt->execute($query['params']);
            $pageIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($pageIds !== []) {
                return $pageIds;
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function resolveModuleIdsForPageName(string $name): array
    {
        if (isset(self::PAGE_NAME_TO_MODULE_IDS[$name])) {
            return self::PAGE_NAME_TO_MODULE_IDS[$name];
        }

        foreach (self::PAGE_NAME_TO_MODULE_IDS as $candidate => $moduleIds) {
            if (str_contains($name, $candidate) || str_contains($candidate, $name)) {
                return $moduleIds;
            }
        }

        return [];
    }
}
