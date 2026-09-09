<?php

declare(strict_types=1);

use App\Config;
use App\Controllers\CollectionController;
use App\Controllers\ContactsController;
use App\Controllers\CourierController;
use App\Controllers\MessagesController;
use App\Controllers\NotificationsController;
use App\Controllers\ProfilesController;
use App\Controllers\CustomerController;
use App\Controllers\CustomerDatabaseController;
use App\Controllers\CustomerGroupController;
use App\Controllers\AdjustmentEntryController;
use App\Controllers\ActivityLogController;
use App\Controllers\AccessGroupController;
use App\Controllers\DailyCallMonitoringController;
use App\Controllers\CallSystemController;
use App\Controllers\FastSlowInventoryReportController;
use App\Controllers\FreightChargesController;
use App\Controllers\HealthController;
use App\Controllers\InvoiceController;
use App\Controllers\InactiveActiveCustomersReportController;
use App\Controllers\IncidentItemsReportController;
use App\Controllers\InternalChatController;
use App\Controllers\InquiryReportController;
use App\Controllers\InventoryAuditController;
use App\Controllers\InventoryReportController;
use App\Controllers\OldNewCustomersReportController;
use App\Controllers\OrderSlipController;
use App\Controllers\AuthController;
use App\Controllers\ProductController;
use App\Controllers\PurchaseRequestController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\ReceivingStockController;
use App\Controllers\ReorderReportController;
use App\Controllers\RemarkTemplateController;
use App\Controllers\ReturnToSupplierController;
use App\Controllers\SupplierController;
use App\Controllers\SalesController;
use App\Controllers\SalesDevelopmentReportController;
use App\Controllers\SalesReturnController;
use App\Controllers\SalesReportController;
use App\Controllers\SalesReturnReportController;
use App\Controllers\SalesInquiryController;
use App\Controllers\SalesOrderController;
use App\Controllers\StockMovementController;
use App\Controllers\StockAdjustmentController;
use App\Controllers\StatementOfAccountController;
use App\Controllers\SuggestedStockReportController;
use App\Controllers\ApproverController;
use App\Controllers\AccountsReceivableController;
use App\Controllers\StaffController;
use App\Controllers\SpecialPriceController;
use App\Controllers\TeamController;
use App\Controllers\TransferStockController;
use App\Controllers\CampaignController;
use App\Controllers\CategoryController;
use App\Controllers\PromotionController;
use App\Controllers\LoyaltyDiscountController;
use App\Controllers\ProfitProtectionController;
use App\Controllers\VipTierSettingsController;
use App\Controllers\ServerMaintenanceController;
use App\Controllers\RolePermissionController;
use App\Services\DatabaseBackupService;
use App\Services\AutomaticBackupSettingsStore;
use App\Services\AutomaticBackupRunner;
use App\Services\AutomaticBackupNotifier;
use App\Services\BackupDestinationLister;
use App\Http\Router;
use App\Middleware\PermissionMiddleware;
use App\Security\TokenService;
use App\Services\InternalChatRealtimeNotifier;
use App\Support\Exceptions\HttpException;
use App\Support\Env;
use App\Support\InternalChatReactionStore;
use App\Support\InternalChatReplyStore;
use App\Support\InternalChatTypingStore;

require __DIR__ . '/Support/Env.php';
require __DIR__ . '/Support/CustomerLedgerCalculator.php';
require __DIR__ . '/Support/Exceptions/HttpException.php';
require __DIR__ . '/Support/InternalChatReactionStore.php';
require __DIR__ . '/Support/InternalChatReplyStore.php';
require __DIR__ . '/Support/InternalChatTypingStore.php';
require __DIR__ . '/Support/DailyCallClaimPolicy.php';
require __DIR__ . '/Support/PhoneNumberNormalizer.php';
require __DIR__ . '/Support/PurchaseReceivingPolicy.php';
require __DIR__ . '/Support/PurchasedItemMatcher.php';
require __DIR__ . '/Support/ReturnToSupplierStockPolicy.php';
require __DIR__ . '/Support/AuditTrailWriter.php';
require __DIR__ . '/Support/VipDocumentDiscount.php';
require __DIR__ . '/Support/VipStanding.php';
require __DIR__ . '/Config.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Http/Response.php';
require __DIR__ . '/Http/Router.php';
require __DIR__ . '/Repositories/CustomerRepository.php';
require __DIR__ . '/Repositories/CustomerDatabaseRepository.php';
require __DIR__ . '/Repositories/CustomerGroupRepository.php';
require __DIR__ . '/Repositories/AdjustmentEntryRepository.php';
require __DIR__ . '/Repositories/ApproverRepository.php';
require __DIR__ . '/Repositories/ActivityLogRepository.php';
require __DIR__ . '/Repositories/AccessGroupRepository.php';
require __DIR__ . '/Repositories/AccountsReceivableRepository.php';
require __DIR__ . '/Repositories/CollectionRepository.php';
require __DIR__ . '/Repositories/ContactsRepository.php';
require __DIR__ . '/Repositories/CourierRepository.php';
require __DIR__ . '/Repositories/MessagesRepository.php';
require __DIR__ . '/Repositories/NotificationsRepository.php';
require __DIR__ . '/Repositories/ProfilesRepository.php';
require __DIR__ . '/Repositories/DailyCallMonitoringRepository.php';
require __DIR__ . '/Repositories/CallReportRepository.php';
require __DIR__ . '/Repositories/CallSystemRepositoryInterface.php';
require __DIR__ . '/Repositories/CallSystemRepository.php';
require __DIR__ . '/Repositories/FastSlowInventoryReportRepository.php';
require __DIR__ . '/Repositories/FreightChargesRepository.php';
require __DIR__ . '/Repositories/AuthRepository.php';
require __DIR__ . '/Repositories/InternalChatRepository.php';
require __DIR__ . '/Repositories/ProductRepository.php';
require __DIR__ . '/Repositories/PurchaseRequestRepository.php';
require __DIR__ . '/Repositories/PurchaseOrderRepository.php';
require __DIR__ . '/Repositories/ReceivingStockRepository.php';
require __DIR__ . '/Repositories/ReorderReportRepository.php';
require __DIR__ . '/Repositories/RemarkTemplateRepository.php';
require __DIR__ . '/Repositories/ReturnToSupplierRepository.php';
require __DIR__ . '/Repositories/SupplierRepository.php';
require __DIR__ . '/Repositories/OrderSlipRepository.php';
require __DIR__ . '/Repositories/InvoiceRepository.php';
require __DIR__ . '/Repositories/InquiryReportRepository.php';
require __DIR__ . '/Repositories/InventoryAuditRepository.php';
require __DIR__ . '/Repositories/InventoryReportRepository.php';
require __DIR__ . '/Repositories/InactiveActiveCustomersReportRepository.php';
require __DIR__ . '/Repositories/IncidentItemsReportRepository.php';
require __DIR__ . '/Repositories/SalesRepository.php';
require __DIR__ . '/Repositories/SalesDevelopmentReportRepository.php';
require __DIR__ . '/Repositories/SalesReturnRepository.php';
require __DIR__ . '/Repositories/SalesReportRepository.php';
require __DIR__ . '/Repositories/SalesReturnReportRepository.php';
require __DIR__ . '/Repositories/SalesInquiryRepository.php';
require __DIR__ . '/Repositories/SalesOrderRepository.php';
require __DIR__ . '/Repositories/StockMovementRepository.php';
require __DIR__ . '/Repositories/StockAdjustmentRepository.php';
require __DIR__ . '/Repositories/StatementOfAccountRepository.php';
require __DIR__ . '/Repositories/SuggestedStockReportRepository.php';
require __DIR__ . '/Repositories/StaffRepository.php';
require __DIR__ . '/Repositories/SpecialPriceRepository.php';
require __DIR__ . '/Repositories/TeamRepository.php';
require __DIR__ . '/Repositories/TransferStockRepository.php';
require __DIR__ . '/Repositories/CampaignOutreachRepository.php';
require __DIR__ . '/Repositories/CampaignFeedbackRepository.php';
require __DIR__ . '/Repositories/CategoryRepository.php';
require __DIR__ . '/Repositories/MessageTemplateRepository.php';
require __DIR__ . '/Repositories/PromotionRepository.php';
require __DIR__ . '/Repositories/PromotionProductRepository.php';
require __DIR__ . '/Repositories/PromotionPostingRepository.php';
require __DIR__ . '/Repositories/LoyaltyDiscountRepository.php';
require __DIR__ . '/Repositories/ProfitProtectionRepository.php';
require __DIR__ . '/Repositories/VipTierSettingsRepository.php';
require __DIR__ . '/Repositories/VipDocumentDiscountRepository.php';
require __DIR__ . '/Repositories/RolePermissionRepository.php';
require __DIR__ . '/Services/InternalChatRealtimeNotifier.php';
require __DIR__ . '/Security/TokenService.php';
require __DIR__ . '/Middleware/PermissionMiddleware.php';
require __DIR__ . '/Controllers/HealthController.php';
require __DIR__ . '/Controllers/CustomerController.php';
require __DIR__ . '/Controllers/CustomerDatabaseController.php';
require __DIR__ . '/Controllers/CustomerGroupController.php';
require __DIR__ . '/Controllers/AdjustmentEntryController.php';
require __DIR__ . '/Controllers/ApproverController.php';
require __DIR__ . '/Controllers/ActivityLogController.php';
require __DIR__ . '/Controllers/AccessGroupController.php';
require __DIR__ . '/Controllers/AccountsReceivableController.php';
require __DIR__ . '/Controllers/CollectionController.php';
require __DIR__ . '/Controllers/ContactsController.php';
require __DIR__ . '/Controllers/CourierController.php';
require __DIR__ . '/Controllers/MessagesController.php';
require __DIR__ . '/Controllers/NotificationsController.php';
require __DIR__ . '/Controllers/ProfilesController.php';
require __DIR__ . '/Controllers/DailyCallMonitoringController.php';
require __DIR__ . '/Controllers/CallSystemController.php';
require __DIR__ . '/Controllers/FastSlowInventoryReportController.php';
require __DIR__ . '/Controllers/FreightChargesController.php';
require __DIR__ . '/Controllers/AuthController.php';
require __DIR__ . '/Controllers/InternalChatController.php';
require __DIR__ . '/Controllers/ProductController.php';
require __DIR__ . '/Controllers/PurchaseRequestController.php';
require __DIR__ . '/Controllers/PurchaseOrderController.php';
require __DIR__ . '/Controllers/ReceivingStockController.php';
require __DIR__ . '/Controllers/ReorderReportController.php';
require __DIR__ . '/Controllers/RemarkTemplateController.php';
require __DIR__ . '/Controllers/ReturnToSupplierController.php';
require __DIR__ . '/Controllers/SupplierController.php';
require __DIR__ . '/Controllers/OrderSlipController.php';
require __DIR__ . '/Controllers/InvoiceController.php';
require __DIR__ . '/Controllers/InactiveActiveCustomersReportController.php';
require __DIR__ . '/Controllers/IncidentItemsReportController.php';
require __DIR__ . '/Controllers/InquiryReportController.php';
require __DIR__ . '/Controllers/InventoryAuditController.php';
require __DIR__ . '/Controllers/InventoryReportController.php';
require __DIR__ . '/Controllers/OldNewCustomersReportController.php';
require __DIR__ . '/Controllers/SalesController.php';
require __DIR__ . '/Controllers/SalesDevelopmentReportController.php';
require __DIR__ . '/Controllers/SalesReturnController.php';
require __DIR__ . '/Controllers/SalesReportController.php';
require __DIR__ . '/Controllers/SalesReturnReportController.php';
require __DIR__ . '/Controllers/SalesInquiryController.php';
require __DIR__ . '/Controllers/SalesOrderController.php';
require __DIR__ . '/Controllers/StockMovementController.php';
require __DIR__ . '/Controllers/StockAdjustmentController.php';
require __DIR__ . '/Controllers/StatementOfAccountController.php';
require __DIR__ . '/Controllers/SuggestedStockReportController.php';
require __DIR__ . '/Controllers/StaffController.php';
require __DIR__ . '/Controllers/SpecialPriceController.php';
require __DIR__ . '/Controllers/TeamController.php';
require __DIR__ . '/Controllers/TransferStockController.php';
require __DIR__ . '/Controllers/CampaignController.php';
require __DIR__ . '/Controllers/CategoryController.php';
require __DIR__ . '/Controllers/PromotionController.php';
require __DIR__ . '/Controllers/LoyaltyDiscountController.php';
require __DIR__ . '/Controllers/ProfitProtectionController.php';
require __DIR__ . '/Controllers/VipTierSettingsController.php';
require __DIR__ . '/Controllers/ServerMaintenanceController.php';
require __DIR__ . '/Controllers/RolePermissionController.php';
require __DIR__ . '/Services/DatabaseBackupService.php';
require __DIR__ . '/Services/AutomaticBackupSettings.php';
require __DIR__ . '/Services/AutomaticBackupSettingsStore.php';
require __DIR__ . '/Services/AutomaticBackupOrganizer.php';
require __DIR__ . '/Services/AutomaticBackupRunner.php';
require __DIR__ . '/Services/AutomaticBackupNotifier.php';
require __DIR__ . '/Services/BackupDestinationLister.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'UTC'));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

function app_config(): Config
{
    static $config = null;
    if ($config instanceof Config) {
        return $config;
    }

    $config = new Config(
        (string) Env::get('APP_ENV', 'production'),
        filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
        (string) Env::get('APP_ALLOWED_ORIGIN', '*'),
        (string) Env::get('AUTH_SECRET', (string) Env::get('APP_KEY', 'change-me-in-env')),
        (int) Env::get('AUTH_TOKEN_TTL_SECONDS', 28800),
        (string) Env::get('DB_HOST', '127.0.0.1'),
        (int) Env::get('DB_PORT', 3306),
        (string) Env::get('DB_NAME', ''),
        (string) Env::get('DB_USER', ''),
        (string) Env::get('DB_PASS', '')
    );

    return $config;
}

function app_router(): Router
{
    static $router = null;
    if ($router instanceof Router) {
        return $router;
    }

    $config = app_config();
    $db = new App\Database($config);

    $healthController = new HealthController();
    $customerController = new CustomerController(new App\Repositories\CustomerRepository($db));
    $customerDatabaseController = new CustomerDatabaseController(new App\Repositories\CustomerDatabaseRepository($db));
    $customerGroupController = new CustomerGroupController(new App\Repositories\CustomerGroupRepository($db));
    $adjustmentEntryController = new AdjustmentEntryController(new App\Repositories\AdjustmentEntryRepository($db));
    $approverController = new ApproverController(new App\Repositories\ApproverRepository($db));
    $activityLogController = new ActivityLogController(new App\Repositories\ActivityLogRepository($db));
    $tokenService = new TokenService($config->authSecret, $config->authTokenTtlSeconds);
    $internalChatRealtimeNotifier = new InternalChatRealtimeNotifier(
        (string) Env::get('INTERNAL_CHAT_SOCKET_NOTIFY_URL', 'http://127.0.0.1:8082/internal-chat/events'),
        (string) Env::get('INTERNAL_CHAT_SOCKET_SECRET', $config->authSecret)
    );
    $internalChatReactionStore = new InternalChatReactionStore($db);
    $internalChatReplyStore = new InternalChatReplyStore($db);
    $internalChatTypingStore = new InternalChatTypingStore($db);
    $rolePermissionRepo = new App\Repositories\RolePermissionRepository($db);
    $authRepo = new App\Repositories\AuthRepository($db);
    $permissionMiddleware = new PermissionMiddleware($tokenService, $rolePermissionRepo);
    $accessGroupController = new AccessGroupController(new App\Repositories\AccessGroupRepository($db), $rolePermissionRepo);
    $accountsReceivableController = new AccountsReceivableController(new App\Repositories\AccountsReceivableRepository($db));
    $collectionController = new CollectionController(new App\Repositories\CollectionRepository($db));
    $contactsController = new ContactsController(new App\Repositories\ContactsRepository($db));
    $courierController = new CourierController(new App\Repositories\CourierRepository($db));
    $messagesController = new MessagesController(new App\Repositories\MessagesRepository($db));
    $notificationsController = new NotificationsController(new App\Repositories\NotificationsRepository($db));
    $profilesController = new ProfilesController(new App\Repositories\ProfilesRepository($db));
    $internalChatController = new InternalChatController(
        new App\Repositories\InternalChatRepository($db, $internalChatReactionStore, $internalChatReplyStore),
        $tokenService,
        $internalChatRealtimeNotifier,
        $internalChatReactionStore,
        $internalChatTypingStore
    );
    $dailyCallMonitoringController = new DailyCallMonitoringController(
        new App\Repositories\DailyCallMonitoringRepository($db),
        new App\Repositories\CallReportRepository($db)
    );
    $callSystemController = new CallSystemController(
        new App\Repositories\CallSystemRepository($db),
        $internalChatRealtimeNotifier
    );
    $fastSlowInventoryReportController = new FastSlowInventoryReportController(new App\Repositories\FastSlowInventoryReportRepository($db));
    $freightChargesController = new FreightChargesController(new App\Repositories\FreightChargesRepository($db));
    $authController = new AuthController(
        $authRepo,
        $tokenService,
        $rolePermissionRepo
    );
    $productController = new ProductController(new App\Repositories\ProductRepository($db));
    $purchaseRequestController = new PurchaseRequestController(new App\Repositories\PurchaseRequestRepository($db));
    $purchaseOrderController = new PurchaseOrderController(new App\Repositories\PurchaseOrderRepository($db));
    $receivingStockController = new ReceivingStockController(new App\Repositories\ReceivingStockRepository($db));
    $reorderReportController = new ReorderReportController(new App\Repositories\ReorderReportRepository($db));
    $remarkTemplateController = new RemarkTemplateController(new App\Repositories\RemarkTemplateRepository($db));
    $returnToSupplierController = new ReturnToSupplierController(new App\Repositories\ReturnToSupplierRepository($db));
    $supplierController = new SupplierController(new App\Repositories\SupplierRepository($db));
    $orderSlipController = new OrderSlipController(new App\Repositories\OrderSlipRepository($db));
    $invoiceController = new InvoiceController(new App\Repositories\InvoiceRepository($db));
    $inactiveActiveCustomersReportController = new InactiveActiveCustomersReportController(new App\Repositories\InactiveActiveCustomersReportRepository($db));
    $incidentItemsReportController = new IncidentItemsReportController(new App\Repositories\IncidentItemsReportRepository($db));
    $oldNewCustomersReportController = new OldNewCustomersReportController(new App\Repositories\OldNewCustomersReportRepository($db));
    $inquiryReportController = new InquiryReportController(new App\Repositories\InquiryReportRepository($db));
    $inventoryAuditController = new InventoryAuditController(new App\Repositories\InventoryAuditRepository($db));
    $inventoryReportController = new InventoryReportController(new App\Repositories\InventoryReportRepository($db));
    $salesController = new SalesController(new App\Repositories\SalesRepository($db));
    $salesDevelopmentReportController = new SalesDevelopmentReportController(new App\Repositories\SalesDevelopmentReportRepository($db));
    $salesReturnController = new SalesReturnController(new App\Repositories\SalesReturnRepository($db));
    $salesReportController = new SalesReportController(new App\Repositories\SalesReportRepository($db));
    $salesReturnReportController = new SalesReturnReportController(new App\Repositories\SalesReturnReportRepository($db));
    $salesInquiryController = new SalesInquiryController(new App\Repositories\SalesInquiryRepository($db));
    $salesOrderController = new SalesOrderController(new App\Repositories\SalesOrderRepository($db));
    $stockMovementController = new StockMovementController(new App\Repositories\StockMovementRepository($db));
    $stockAdjustmentController = new StockAdjustmentController(new App\Repositories\StockAdjustmentRepository($db));
    $statementOfAccountController = new StatementOfAccountController(new App\Repositories\StatementOfAccountRepository($db));
    $suggestedStockReportController = new SuggestedStockReportController(new App\Repositories\SuggestedStockReportRepository($db));
    $staffController = new StaffController(new App\Repositories\StaffRepository($db), $authRepo, $rolePermissionRepo);
    $rolePermissionController = new RolePermissionController($rolePermissionRepo, $permissionMiddleware);
    $teamController = new TeamController(new App\Repositories\TeamRepository($db));
    $specialPriceController = new SpecialPriceController(
        new App\Repositories\SpecialPriceRepository($db),
        $tokenService
    );
    $transferStockController = new TransferStockController(new App\Repositories\TransferStockRepository($db));
    $campaignController = new CampaignController(
        new App\Repositories\CampaignOutreachRepository($db),
        new App\Repositories\CampaignFeedbackRepository($db),
        new App\Repositories\MessageTemplateRepository($db)
    );
    $categoryController = new CategoryController(new App\Repositories\CategoryRepository($db));
    $promotionController = new PromotionController(
        new App\Repositories\PromotionRepository($db),
        new App\Repositories\PromotionProductRepository($db),
        new App\Repositories\PromotionPostingRepository($db)
    );
    $loyaltyDiscountController = new LoyaltyDiscountController(new App\Repositories\LoyaltyDiscountRepository($db));
    $profitProtectionController = new ProfitProtectionController(new App\Repositories\ProfitProtectionRepository($db));
    $vipTierSettingsController = new VipTierSettingsController(new App\Repositories\VipTierSettingsRepository($db));
    $serverMaintenanceBackupDir = dirname(__DIR__) . '/storage/database-backups';
    $automaticBackupStore = new AutomaticBackupSettingsStore(
        dirname(__DIR__) . '/storage/automatic-backup-settings.json'
    );
    $databaseBackupService = new DatabaseBackupService($config);
    $automaticBackupNotifier = new AutomaticBackupNotifier($db);
    $automaticBackupRunner = new AutomaticBackupRunner(
        $automaticBackupStore,
        $databaseBackupService->databaseName(),
        static fn (): array => $databaseBackupService->createFullDumpGzipFile($serverMaintenanceBackupDir),
        static function (string $title, string $message) use ($automaticBackupNotifier): void {
            $automaticBackupNotifier->notifyMasters($title, $message);
        },
        static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
    );
    $serverMaintenanceController = new ServerMaintenanceController(
        $databaseBackupService,
        $serverMaintenanceBackupDir,
        $automaticBackupStore,
        new BackupDestinationLister(BackupDestinationLister::defaultRoots()),
        $automaticBackupRunner
    );

    $requireBearerAuth = static function (callable $handler) use ($tokenService, $authRepo): callable {
        return static function (array $params = [], array $query = [], array $body = []) use ($handler, $tokenService, $authRepo) {
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
            if (!is_string($header) || trim($header) === '') {
                throw new HttpException(401, 'Authorization header is required');
            }

            if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
                throw new HttpException(401, 'Bearer token is required');
            }

            $claims = $tokenService->verify((string) $matches[1]);
            if (!$authRepo->isSessionCurrent($claims)) {
                throw new HttpException(401, 'Session expired. Please sign in again.');
            }
            return $handler($params, $query, $body);
        };
    };

    $requireBearerAuthWithClaims = static function (callable $handler) use ($tokenService, $authRepo): callable {
        return static function (array $params = [], array $query = [], array $body = []) use ($handler, $tokenService, $authRepo) {
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
            if (!is_string($header) || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
                throw new HttpException(401, 'Bearer token is required');
            }
            $body['__auth_claims'] = $tokenService->verify((string) $matches[1]);
            if (!$authRepo->isSessionCurrent($body['__auth_claims'])) {
                throw new HttpException(401, 'Session expired. Please sign in again.');
            }
            return $handler($params, $query, $body);
        };
    };

    $requireMasterUser = static function (callable $handler) use ($requireBearerAuthWithClaims): callable {
        return $requireBearerAuthWithClaims(static function (array $params = [], array $query = [], array $body = []) use ($handler): array {
            $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
            if ((string) ($claims['user_type'] ?? '') !== '1') {
                throw new HttpException(403, 'Only the Master User can perform this action');
            }

            $mainId = (int) ($claims['main_userid'] ?? 0);
            if ($mainId <= 0) {
                throw new HttpException(403, 'Invalid account scope');
            }
            $body['main_id'] = $mainId;
            $query['main_id'] = (string) $mainId;
            return $handler($params, $query, $body);
        });
    };

    $requireActionAuth = static function (callable $handler, string $page, string $action) use ($requireBearerAuthWithClaims, $permissionMiddleware): callable {
        return $requireBearerAuthWithClaims(static function (array $params = [], array $query = [], array $body = []) use ($handler, $page, $action, $permissionMiddleware): array {
            $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
            $mainId = (int) ($claims['main_userid'] ?? 0);
            if ($mainId <= 0) {
                throw new HttpException(403, 'Invalid account scope');
            }

            // Keep tenant and actor identity bound to the verified session.
            $body['main_id'] = $mainId;
            $query['main_id'] = (string) $mainId;
            $body['user_id'] = (int) ($claims['sub'] ?? 0);
            $permissionMiddleware->assertActionPermission($claims, $action, $page);
            return $handler($params, $query, $body);
        });
    };

    $requireCustomerUpdateAuth = static function (callable $handler) use ($requireBearerAuthWithClaims, $requireActionAuth, $requireMasterUser): callable {
        return $requireBearerAuthWithClaims(static function (array $params = [], array $query = [], array $body = []) use ($handler, $requireActionAuth, $requireMasterUser): array {
            $updates = is_array($body['updates'] ?? null) ? $body['updates'] : $body;
            $assignmentFields = ['sales_person_id', 'salesman', 'salesPerson', 'assignedAgent'];
            $hasAssignment = is_array($updates) && array_intersect($assignmentFields, array_keys($updates)) !== [];
            if (!$hasAssignment && is_array($updates)) {
                foreach ($updates as $update) {
                    if (is_array($update) && array_intersect($assignmentFields, array_keys($update)) !== []) {
                        $hasAssignment = true;
                        break;
                    }
                }
            }
            if ($hasAssignment) {
                return $requireMasterUser($handler)($params, $query, $body);
            }
            return $requireActionAuth($handler, 'Customer Database', 'edit')($params, $query, $body);
        });
    };

    // Approval is a separate capability from module access. Every existing
    // approval/post action must derive the actor from the verified token and
    // match the existing Maintenance → Approver list.
    $requireApproverAction = static function (callable $handler, array $approverModules, bool $approvalOnly = false, ?string $fixedActionPermission = null) use ($requireBearerAuthWithClaims, $permissionMiddleware, $db): callable {
        return static function (array $params = [], array $query = [], array $body = []) use ($handler, $approverModules, $db, $approvalOnly, $fixedActionPermission, $requireBearerAuthWithClaims, $permissionMiddleware): array {
            $action = strtolower(trim((string) ($params['action'] ?? $body['action'] ?? $body['status'] ?? $body['decision'] ?? '')));
            $approvalActions = ['approve', 'approved', 'approverecord', 'post', 'postrecord', 'posttoledger', 'finalize', 'review', 'reject', 'rejected', 'disapprove', 'disapproved', 'disapproverecord', 'submitted', 'unpost'];
            $requiresApproval = $approvalOnly || in_array($action, $approvalActions, true);

            return $requireBearerAuthWithClaims(static function (array $authParams = [], array $authQuery = [], array $authBody = []) use ($handler, $approverModules, $db, $action, $fixedActionPermission, $permissionMiddleware, $requiresApproval): array {
                $claims = is_array($authBody['__auth_claims'] ?? null) ? $authBody['__auth_claims'] : [];
                $userId = (int) ($claims['sub'] ?? 0);
                $mainId = (int) ($claims['main_userid'] ?? $authBody['main_id'] ?? 0);
                if ($userId <= 0 || $mainId <= 0) {
                    throw new HttpException(403, 'An authenticated approver is required');
                }
                if ((int) ($claims['main_userid'] ?? $mainId) !== $mainId) {
                    throw new HttpException(403, 'Invalid account scope');
                }

                // Never trust actor or tenant fields supplied by the client.
                // Keep legacy field names populated for existing controllers,
                // but make every one of them come from the verified claims.
                $authBody['main_id'] = $mainId;
                $authBody['user_id'] = $userId;
                $authBody['staff_id'] = (string) $userId;
                $authBody['reviewed_by'] = (string) $userId;
                $authBody['approved_by'] = (string) $userId;

                $actionPermission = $fixedActionPermission;
                if ($actionPermission === null && in_array($action, ['post', 'posted', 'postrecord', 'posttoledger', 'finalize', 'submitted'], true)) {
                    $actionPermission = 'post';
                } elseif ($actionPermission === null && $action === 'unpost') {
                    $actionPermission = 'unpost';
                } elseif ($actionPermission === null && in_array($action, ['cancel', 'cancelled', 'cancelrecord', 'delete', 'deleted'], true)) {
                    $actionPermission = 'delete';
                } elseif ($actionPermission === null && in_array($action, ['submitrecord', 'edit', 'editrecord', 'update'], true)) {
                    $actionPermission = 'edit';
                } elseif ($actionPermission === null && in_array($action, ['convert', 'convert-to-order', 'convertsales'], true)) {
                    $actionPermission = 'add';
                }
                if ($actionPermission !== null) {
                    $permissionMiddleware->assertActionPermission($claims, $actionPermission, $approverModules[0] ?? null);
                }

                if ($requiresApproval && (string) ($claims['user_type'] ?? '') !== '1') {
                    $modules = array_values(array_unique(array_map('strval', $approverModules)));
                    $placeholders = implode(',', array_fill(0, count($modules), '?'));
                    $statement = $db->pdo()->prepare(
                        "SELECT 1 FROM tblapprover WHERE lmain_id = ? AND lstaff_id = ? AND UPPER(COALESCE(ltrans_type, '')) IN ({$placeholders}) LIMIT 1"
                    );
                    $statement->execute(array_merge([$mainId, $userId], array_map('strtoupper', $modules)));
                    if (!$statement->fetchColumn()) {
                        throw new HttpException(403, 'Only approver accounts can approve records');
                    }
                }

                return $handler($authParams, $authQuery, $authBody);
            })($params, $query, $body);
        };
    };

    $customerWorkflowController = new App\Controllers\CustomerWorkflowController($db, $authRepo);
    $router = new Router();
    $router->get('/api/v1/health', [$healthController, 'index']);
    $router->get('/api/v1/recycle-bin', $requireBearerAuthWithClaims([$customerWorkflowController, 'recycleBin']));
    $router->post('/api/v1/recycle-bin/{type}/{itemId}/restore', $requireActionAuth([$customerWorkflowController, 'restoreRecycleBinItem'], 'Recycle Bin', 'edit'));
    $router->post('/api/v1/activity-logs', $requireBearerAuthWithClaims([$customerWorkflowController, 'logActivity']));
    $router->get('/api/v1/customer-workflows/{contactId}/inquiries', $requireBearerAuthWithClaims([$customerWorkflowController, 'inquiries']));
    $router->get('/api/v1/customer-workflows/{contactId}/returns', $requireBearerAuthWithClaims([$customerWorkflowController, 'returns']));
    $router->get('/api/v1/customer-workflows/requests', $requireBearerAuthWithClaims([$customerWorkflowController, 'allRequests']));
    $router->get('/api/v1/customer-workflows/{contactId}/requests', $requireBearerAuthWithClaims([$customerWorkflowController, 'requests']));
    $router->post('/api/v1/customer-workflows/{contactId}/requests', $requireActionAuth([$customerWorkflowController, 'createRequest'], 'Customer', 'add'));
    $router->post('/api/v1/customer-workflows/{contactId}/requests/{requestId}/review', $requireApproverAction([$customerWorkflowController, 'reviewRequest'], ['Customer Request', 'Customer', 'CR']));
    $router->get('/api/v1/customers/{sessionId}', [$customerController, 'show']);
    $router->get('/api/v1/customers/{sessionId}/purchase-history', [$customerController, 'purchaseHistory']);
    $router->get('/api/v1/customers/{sessionId}/purchased-items', [$customerController, 'purchasedItems']);
    $router->get('/api/v1/customers/{sessionId}/ledger', [$customerController, 'ledger']);
    $router->get('/api/v1/statements/customers', [$statementOfAccountController, 'customers']);
    $router->get('/api/v1/statements/of-account', [$statementOfAccountController, 'report']);
    $router->get('/api/v1/accounts-receivable', [$accountsReceivableController, 'report']);
    $router->get('/api/v1/adjustment-entries', [$adjustmentEntryController, 'list']);
    $router->get('/api/v1/adjustment-entries/{refno}', [$adjustmentEntryController, 'show']);
    $router->post('/api/v1/adjustment-entries', $requireActionAuth([$adjustmentEntryController, 'create'], 'Adjustment Entry', 'add'));
    $router->patch('/api/v1/adjustment-entries/{refno}', $requireActionAuth([$adjustmentEntryController, 'update'], 'Adjustment Entry', 'edit'));
    $router->delete('/api/v1/adjustment-entries/{refno}', $requireActionAuth([$adjustmentEntryController, 'delete'], 'Adjustment Entry', 'delete'));
    $router->post('/api/v1/adjustment-entries/{refno}/actions/{action}', $requireApproverAction([$adjustmentEntryController, 'action'], ['Adjustment Entry', 'Adjustment']));
    $router->get('/api/v1/activity-logs', [$activityLogController, 'list']);
    $router->get('/api/v1/activity-logs/users', [$activityLogController, 'users']);
    $router->get('/api/v1/customer-database', [$customerDatabaseController, 'list']);
    $router->get('/api/v1/customer-database/province-summary', [$customerDatabaseController, 'provinceSummary']);
    $router->get('/api/v1/customer-groups', [$customerGroupController, 'list']);
    $router->get('/api/v1/customer-groups/{groupId}', [$customerGroupController, 'show']);
    $router->post('/api/v1/customer-groups', $requireActionAuth([$customerGroupController, 'create'], 'Customer', 'add'));
    $router->patch('/api/v1/customer-groups/{groupId}', $requireActionAuth([$customerGroupController, 'update'], 'Customer', 'edit'));
    $router->delete('/api/v1/customer-groups/{groupId}', $requireActionAuth([$customerGroupController, 'delete'], 'Customer', 'delete'));
    $router->get('/api/v1/customer-database/{sessionId}', [$customerDatabaseController, 'show']);
    $router->post('/api/v1/customer-database', $requireActionAuth([$customerDatabaseController, 'create'], 'Customer Database', 'add'));
    $router->patch('/api/v1/customer-database/bulk', $requireCustomerUpdateAuth([$customerDatabaseController, 'bulkUpdate']));
    $router->patch('/api/v1/customer-database/{sessionId}', $requireCustomerUpdateAuth([$customerDatabaseController, 'update']));
    $router->delete('/api/v1/customer-database/{sessionId}', $requireActionAuth([$customerDatabaseController, 'delete'], 'Customer Database', 'delete'));
    $router->post('/api/v1/customer-database/{sessionId}/contacts', $requireActionAuth([$customerDatabaseController, 'addContact'], 'Customer Database', 'add'));
    $router->patch('/api/v1/customer-database/contacts/{contactId}', $requireActionAuth([$customerDatabaseController, 'updateContact'], 'Customer Database', 'edit'));
    $router->delete('/api/v1/customer-database/contacts/{contactId}', $requireActionAuth([$customerDatabaseController, 'deleteContact'], 'Customer Database', 'delete'));
    $router->get('/api/v1/customer-database/{sessionId}/terms', [$customerDatabaseController, 'listTerms']);
    $router->post('/api/v1/customer-database/{sessionId}/terms', $requireActionAuth([$customerDatabaseController, 'addTerm'], 'Customer Database', 'add'));
    $router->patch('/api/v1/customer-database/terms/{termId}', $requireActionAuth([$customerDatabaseController, 'updateTerm'], 'Customer Database', 'edit'));
    $router->delete('/api/v1/customer-database/terms/{termId}', $requireActionAuth([$customerDatabaseController, 'deleteTerm'], 'Customer Database', 'delete'));
    $router->get('/api/v1/collections', [$collectionController, 'list']);
    $router->post('/api/v1/collections', $requireActionAuth([$collectionController, 'create'], 'Collection', 'add'));
    $router->get('/api/v1/collections/unpaid', [$collectionController, 'unpaid']);
    $router->get('/api/v1/collections/summary', [$collectionController, 'summary']);
    $router->delete('/api/v1/collections/{collectionRefno}', $requireActionAuth([$collectionController, 'delete'], 'Collection', 'delete'));
    $router->get('/api/v1/collections/{collectionRefno}', [$collectionController, 'show']);
    $router->get('/api/v1/collections/{collectionRefno}/items', [$collectionController, 'items']);
    $router->get('/api/v1/collections/{collectionRefno}/approver-logs', [$collectionController, 'approverLogs']);
    $router->post('/api/v1/collections/{collectionRefno}/items/post', $requireActionAuth([$collectionController, 'postItems'], 'Collection', 'post'));
    $router->post('/api/v1/collections/{collectionRefno}/payments', $requireActionAuth([$collectionController, 'addPayment'], 'Collection', 'add'));
    $router->post('/api/v1/collections/{collectionRefno}/actions/{action}', $requireApproverAction([$collectionController, 'action'], ['Collection']));
    $router->patch('/api/v1/collection-items/{itemId}', $requireActionAuth([$collectionController, 'updateItem'], 'Collection', 'edit'));
    $router->delete('/api/v1/collection-items/{itemId}', $requireActionAuth([$collectionController, 'deleteItem'], 'Collection', 'delete'));
    $router->get('/api/v1/contacts', [$contactsController, 'list']);
    $router->get('/api/v1/contacts/{id}', [$contactsController, 'show']);
    $router->post('/api/v1/contacts', $requireActionAuth([$contactsController, 'create'], 'Customer Database', 'add'));
    $router->patch('/api/v1/contacts/{id}', $requireCustomerUpdateAuth([$contactsController, 'update']));
    $router->delete('/api/v1/contacts/{id}', $requireActionAuth([$contactsController, 'delete'], 'Customer Database', 'delete'));
    $router->post('/api/v1/contacts/bulk-update', $requireCustomerUpdateAuth([$contactsController, 'bulkUpdate']));
    $router->get('/api/v1/teams/{teamId}/messages', [$messagesController, 'list']);
    $router->get('/api/v1/messages/{id}', [$messagesController, 'show']);
    $router->post('/api/v1/teams/{teamId}/messages', [$messagesController, 'create']);
    $router->patch('/api/v1/messages/{id}', [$messagesController, 'update']);
    $router->delete('/api/v1/messages/{id}', [$messagesController, 'delete']);
    $router->get('/api/v1/teams/{teamId}/messages/sender/{senderId}', [$messagesController, 'getBySender']);
    $router->get('/api/v1/internal-chat/participants', [$internalChatController, 'participants']);
    $router->get('/api/v1/internal-chat/conversations', [$internalChatController, 'conversations']);
    $router->post('/api/v1/internal-chat/groups', [$internalChatController, 'createGroup']);
    $router->get('/api/v1/internal-chat/groups/{groupId}', [$internalChatController, 'groupDetails']);
    $router->patch('/api/v1/internal-chat/groups/{groupId}', [$internalChatController, 'renameGroup']);
    $router->post('/api/v1/internal-chat/groups/{groupId}/members', [$internalChatController, 'addGroupMembers']);
    $router->delete('/api/v1/internal-chat/groups/{groupId}/members/{userId}', [$internalChatController, 'removeGroupMember']);
    $router->get('/api/v1/internal-chat/conversations/{conversationKey}/messages', [$internalChatController, 'messages']);
    $router->get('/api/v1/internal-chat/conversations/{conversationKey}/typing', [$internalChatController, 'typingState']);
    $router->post('/api/v1/internal-chat/messages', [$internalChatController, 'send']);
    $router->post('/api/v1/internal-chat/messages/{messageId}/reaction', [$internalChatController, 'toggleReaction']);
    $router->post('/api/v1/internal-chat/conversations/{conversationKey}/read', [$internalChatController, 'markConversationRead']);
    $router->post('/api/v1/internal-chat/conversations/{conversationKey}/typing', [$internalChatController, 'updateTyping']);
    $router->get('/api/v1/internal-chat/unread-count', [$internalChatController, 'unreadCount']);
    $router->get('/api/v1/internal-chat/stream', [$internalChatController, 'stream']);
    $router->get('/api/v1/notifications', $requireBearerAuth([$notificationsController, 'list']));
    $router->get('/api/v1/notifications/unread-count', $requireBearerAuth([$notificationsController, 'unreadCount']));
    $router->post('/api/v1/notifications', $requireBearerAuth([$notificationsController, 'create']));
    $router->patch('/api/v1/notifications/{id}/read', $requireBearerAuth([$notificationsController, 'markAsRead']));
    $router->post('/api/v1/notifications/mark-all-read', $requireBearerAuth([$notificationsController, 'markAllAsRead']));
    $router->post('/api/v1/notifications/mark-by-entity-read', $requireBearerAuth([$notificationsController, 'markByEntityRead']));
    $router->delete('/api/v1/notifications/{id}', $requireBearerAuth([$notificationsController, 'delete']));
    $router->post('/api/v1/notifications/workflow-dispatch', $requireBearerAuth([$notificationsController, 'workflowDispatch']));
    $router->post('/api/v1/notifications/inventory-alerts/scan', $requireBearerAuth([$notificationsController, 'scanInventoryAlerts']));
    $router->get('/api/v1/profiles', [$profilesController, 'list']);
    $router->get('/api/v1/profiles/sales-agents', [$profilesController, 'salesAgents']);
    $router->get('/api/v1/profiles/{id}', [$profilesController, 'show']);
    $router->patch('/api/v1/profiles/{id}', $requireMasterUser([$profilesController, 'update']));
    $router->post('/api/v1/profiles/{id}/deactivate', $requireMasterUser([$profilesController, 'deactivate']));
    $router->post('/api/v1/profiles/{id}/activate', $requireMasterUser([$profilesController, 'activate']));
    $router->post('/api/v1/profiles/{id}/role', $requireMasterUser([$profilesController, 'updateRole']));
    $router->get('/api/v1/daily-call-monitoring/excel', [$dailyCallMonitoringController, 'excelRows']);
    $router->get('/api/v1/daily-call-monitoring/master-list', [$dailyCallMonitoringController, 'masterList']);
    $router->get('/api/v1/daily-call-monitoring/sales-performance-dashboard', [$dailyCallMonitoringController, 'salesPerformanceDashboard']);
    $router->get('/api/v1/daily-call-monitoring/owner-snapshot', [$dailyCallMonitoringController, 'ownerSnapshot']);
    $router->get('/api/v1/daily-call-monitoring/agent-snapshot', [$dailyCallMonitoringController, 'agentSnapshot']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/purchase-history', [$dailyCallMonitoringController, 'customerPurchaseHistory']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/sales-reports', [$dailyCallMonitoringController, 'customerSalesReports']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/incident-reports', [$dailyCallMonitoringController, 'customerIncidentReports']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/call-logs', [$dailyCallMonitoringController, 'callLogs']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/customer-logs', [$dailyCallMonitoringController, 'customerLogs']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/returns', [$dailyCallMonitoringController, 'returnRecords']);
    $router->post('/api/v1/daily-call-monitoring/call-claims', $requireBearerAuthWithClaims([$dailyCallMonitoringController, 'claimCall']));
    $router->post('/api/v1/daily-call-monitoring/call-claims/{contactId}/release', $requireBearerAuthWithClaims([$dailyCallMonitoringController, 'releaseCallClaim']));
    $router->post('/api/v1/daily-call-monitoring/call-logs', $requireBearerAuthWithClaims([$dailyCallMonitoringController, 'createCallLog']));
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/call-report-threads', $requireBearerAuthWithClaims([$dailyCallMonitoringController, 'callReportThreads']));
    $router->post('/api/v1/daily-call-monitoring/call-report-threads/{threadId}/messages', $requireBearerAuthWithClaims([$dailyCallMonitoringController, 'createCallReportReply']));
    $router->patch('/api/v1/daily-call-monitoring/call-report-threads/{threadId}/read', $requireBearerAuthWithClaims([$dailyCallMonitoringController, 'markCallReportThreadRead']));
    $router->post('/api/v1/daily-call-monitoring/incident-reports', $requireActionAuth([$dailyCallMonitoringController, 'createIncidentReport'], 'Incident Report', 'add'));
    $router->patch('/api/v1/daily-call-monitoring/incident-reports/{reportId}/decision', $requireApproverAction([$dailyCallMonitoringController, 'reviewIncidentReport'], ['Incident Report', 'Incident', 'IR', 'Sales Return', 'SR']));
    $router->post('/api/v1/call-system/devices/register', $requireBearerAuthWithClaims([$callSystemController, 'registerDevice']));
    $router->post('/api/v1/call-system/devices/heartbeat', $requireBearerAuthWithClaims([$callSystemController, 'heartbeat']));
    $router->post('/api/v1/call-system/call-logs', $requireBearerAuthWithClaims([$callSystemController, 'createCallLog']));
    $router->post('/api/v1/call-system/dial-requests', $requireBearerAuthWithClaims([$callSystemController, 'createDialRequest']));
    $router->post('/api/v1/call-system/dial-requests/poll', $requireBearerAuthWithClaims([$callSystemController, 'listPendingDialRequests']));
    $router->patch('/api/v1/call-system/dial-requests/{requestId}/status', $requireBearerAuthWithClaims([$callSystemController, 'updateDialRequestStatus']));
    $router->get('/api/v1/call-system/devices', $requireBearerAuthWithClaims([$callSystemController, 'listDevices']));
    $router->get('/api/v1/call-system/call-logs', $requireBearerAuthWithClaims([$callSystemController, 'listCallLogs']));
    $router->get('/api/v1/call-system/call-records', $requireBearerAuthWithClaims([$callSystemController, 'listCallRecords']));
    $router->get('/api/v1/call-system/auto-reply-settings', $requireBearerAuthWithClaims([$callSystemController, 'getAutoReplySettings']));
    $router->post('/api/v1/call-system/auto-reply-settings', $requireMasterUser([$callSystemController, 'saveAutoReplySettings']));
    $router->get('/api/v1/call-system/auto-reply-audit', $requireBearerAuthWithClaims([$callSystemController, 'listAutoReplyAudit']));
    $router->post('/api/v1/daily-call-monitoring/customer-logs', $requireActionAuth([$dailyCallMonitoringController, 'createCustomerLog'], 'Customer', 'edit'));
    $router->get('/api/v1/fast-slow-inventory-report', [$fastSlowInventoryReportController, 'report']);
    $router->get('/api/v1/incident-items-report', $requireBearerAuthWithClaims([$incidentItemsReportController, 'report']));
    $router->get('/api/v1/incident-items-report/incidents', $requireBearerAuthWithClaims([$incidentItemsReportController, 'listItemIncidents']));
    $router->get('/api/v1/incident-items-report/incidents/{reportId}', $requireBearerAuthWithClaims([$incidentItemsReportController, 'showIncident']));
    $router->post('/api/v1/incident-report-items', $requireActionAuth([$incidentItemsReportController, 'create'], 'Incident Report', 'add'));
    $router->get('/api/v1/freight-charges', [$freightChargesController, 'list']);
    $router->get('/api/v1/freight-charges/report', [$freightChargesController, 'report']);
    $router->get('/api/v1/freight-charges/{refno}', [$freightChargesController, 'show']);
    $router->post('/api/v1/freight-charges', $requireActionAuth([$freightChargesController, 'create'], 'Freight Charges', 'add'));
    $router->patch('/api/v1/freight-charges/{refno}', $requireActionAuth([$freightChargesController, 'update'], 'Freight Charges', 'edit'));
    $router->delete('/api/v1/freight-charges/{refno}', $requireActionAuth([$freightChargesController, 'delete'], 'Freight Charges', 'delete'));
    $router->post('/api/v1/freight-charges/{refno}/actions/{action}', $requireApproverAction([$freightChargesController, 'action'], ['Freight Charges', 'Freight']));
    $router->get('/api/v1/suggested-stock-report/customers', [$suggestedStockReportController, 'customers']);
    $router->get('/api/v1/suggested-stock-report/summary', [$suggestedStockReportController, 'summary']);
    $router->get('/api/v1/suggested-stock-report/details', [$suggestedStockReportController, 'details']);
    $router->patch('/api/v1/suggested-stock-report/remark', $requireActionAuth([$suggestedStockReportController, 'updateRemark'], 'Suggested Stock Report', 'edit'));
    $router->post('/api/v1/suggested-stock-report/clear-not-listed', $requireActionAuth([$suggestedStockReportController, 'clearNotListed'], 'Suggested Stock Report', 'edit'));
    $router->post('/api/v1/suggested-stock-report/kiv', $requireActionAuth([$suggestedStockReportController, 'addToKiv'], 'Suggested Stock Report', 'add'));
    $router->post('/api/v1/suggested-stock-report/kiv/remove', $requireActionAuth([$suggestedStockReportController, 'removeFromKiv'], 'Suggested Stock Report', 'delete'));
    $router->post('/api/v1/suggested-stock-report/added-to-pr', $requireActionAuth([$suggestedStockReportController, 'markAddedToPurchaseRequest'], 'Suggested Stock Report', 'edit'));
    $router->get('/api/v1/suggested-stock-report/suppliers', [$suggestedStockReportController, 'suppliers']);
    $router->get('/api/v1/suggested-stock-report/purchase-orders', [$suggestedStockReportController, 'purchaseOrders']);
    $router->post('/api/v1/suggested-stock-report/purchase-orders', $requireActionAuth([$suggestedStockReportController, 'createPurchaseOrder'], 'Purchase Order', 'add'));
    $router->post('/api/v1/suggested-stock-report/purchase-orders/{purchaseRefno}/items', $requireActionAuth([$suggestedStockReportController, 'addPurchaseOrderItem'], 'Purchase Order', 'add'));
    $router->get('/api/v1/products', [$productController, 'list']);
    $router->get('/api/v1/products/{productSession}', [$productController, 'show']);
    $router->post('/api/v1/products', $requireActionAuth([$productController, 'create'], 'Product', 'add'));
    $router->patch('/api/v1/products/{productSession}', $requireActionAuth([$productController, 'update'], 'Product', 'edit'));
    $router->post('/api/v1/products/bulk-update', $requireActionAuth([$productController, 'bulkUpdate'], 'Product', 'edit'));
    $router->delete('/api/v1/products/{productSession}', $requireActionAuth([$productController, 'delete'], 'Product', 'delete'));
    $router->get('/api/v1/purchase-requests', [$purchaseRequestController, 'list']);
    $router->get('/api/v1/purchase-requests/next-number', [$purchaseRequestController, 'nextNumber']);
    $router->get('/api/v1/purchase-requests/{prRefno}', [$purchaseRequestController, 'show']);
    $router->post('/api/v1/purchase-requests', $requireActionAuth([$purchaseRequestController, 'create'], 'Purchase Request', 'add'));
    $router->patch('/api/v1/purchase-requests/{prRefno}', $requireActionAuth([$purchaseRequestController, 'update'], 'Purchase Request', 'edit'));
    $router->delete('/api/v1/purchase-requests/{prRefno}', $requireActionAuth([$purchaseRequestController, 'delete'], 'Purchase Request', 'delete'));
    $router->post('/api/v1/purchase-requests/{prRefno}/items', $requireActionAuth([$purchaseRequestController, 'addItem'], 'Purchase Request', 'add'));
    $router->patch('/api/v1/purchase-request-items/{itemId}', $requireActionAuth([$purchaseRequestController, 'updateItem'], 'Purchase Request', 'edit'));
    $router->delete('/api/v1/purchase-request-items/{itemId}', $requireActionAuth([$purchaseRequestController, 'deleteItem'], 'Purchase Request', 'delete'));
    $router->post('/api/v1/purchase-requests/{prRefno}/actions/{action}', $requireApproverAction([$purchaseRequestController, 'action'], ['Purchase Request', 'PR']));
    $router->get('/api/v1/purchase-orders', [$purchaseOrderController, 'list']);
    $router->get('/api/v1/purchase-orders/suppliers', [$purchaseOrderController, 'suppliers']);
    $router->get('/api/v1/purchase-orders/{purchaseRefno}', [$purchaseOrderController, 'show']);
    $router->post('/api/v1/purchase-orders', $requireActionAuth([$purchaseOrderController, 'create'], 'Purchase Order', 'add'));
    $router->patch('/api/v1/purchase-orders/{purchaseRefno}', $requireActionAuth([$purchaseOrderController, 'update'], 'Purchase Order', 'edit'));
    $router->delete('/api/v1/purchase-orders/{purchaseRefno}', $requireActionAuth([$purchaseOrderController, 'delete'], 'Purchase Order', 'delete'));
    $router->post('/api/v1/purchase-orders/{purchaseRefno}/items', $requireActionAuth([$purchaseOrderController, 'addItem'], 'Purchase Order', 'add'));
    $router->post('/api/v1/purchase-orders/{purchaseRefno}/actions/unpost', $requireActionAuth([$purchaseOrderController, 'unpost'], 'Purchase Order', 'unpost'));
    $router->get('/api/v1/suppliers', [$supplierController, 'list']);
    $router->get('/api/v1/suppliers/{supplierId}', [$supplierController, 'show']);
    $router->post('/api/v1/suppliers', $requireActionAuth([$supplierController, 'create'], 'Supplier', 'add'));
    $router->patch('/api/v1/suppliers/{supplierId}', $requireActionAuth([$supplierController, 'update'], 'Supplier', 'edit'));
    $router->delete('/api/v1/suppliers/{supplierId}', $requireActionAuth([$supplierController, 'delete'], 'Supplier', 'delete'));
    $router->patch('/api/v1/purchase-order-items/{itemId}', $requireActionAuth([$purchaseOrderController, 'updateItem'], 'Purchase Order', 'edit'));
    $router->delete('/api/v1/purchase-order-items/{itemId}', $requireActionAuth([$purchaseOrderController, 'deleteItem'], 'Purchase Order', 'delete'));
    $router->get('/api/v1/receiving-stocks', [$receivingStockController, 'list']);
    $router->get('/api/v1/receiving-stocks/purchase-orders/eligible', [$receivingStockController, 'eligiblePurchaseOrders']);
    $router->get('/api/v1/receiving-stocks/{receivingRefno}', [$receivingStockController, 'show']);
    $router->post('/api/v1/receiving-stocks', $requireActionAuth([$receivingStockController, 'create'], 'Receiving Stock', 'add'));
    $router->patch('/api/v1/receiving-stocks/{receivingRefno}', $requireActionAuth([$receivingStockController, 'update'], 'Receiving Stock', 'edit'));
    $router->delete('/api/v1/receiving-stocks/{receivingRefno}', $requireActionAuth([$receivingStockController, 'delete'], 'Receiving Stock', 'delete'));
    $router->post('/api/v1/receiving-stocks/{receivingRefno}/items', $requireActionAuth([$receivingStockController, 'addItem'], 'Receiving Stock', 'add'));
    $router->patch('/api/v1/receiving-stock-items/{itemId}', $requireActionAuth([$receivingStockController, 'updateItem'], 'Receiving Stock', 'edit'));
    $router->delete('/api/v1/receiving-stock-items/{itemId}', $requireActionAuth([$receivingStockController, 'deleteItem'], 'Receiving Stock', 'delete'));
    $router->post('/api/v1/receiving-stocks/{receivingRefno}/finalize', $requireApproverAction([$receivingStockController, 'finalize'], ['Receiving Stock', 'RR'], true, 'post'));
    $router->post('/api/v1/receiving-stocks/{receivingRefno}/actions/unpost', $requireActionAuth([$receivingStockController, 'unpost'], 'Receiving Stock', 'unpost'));
    $router->get('/api/v1/reorder-report', [$reorderReportController, 'list']);
    $router->post('/api/v1/reorder-report/hide-items', $requireActionAuth([$reorderReportController, 'hideItems'], 'Reorder Report', 'edit'));
    $router->post('/api/v1/reorder-report/restore-items', $requireActionAuth([$reorderReportController, 'restoreItems'], 'Reorder Report', 'edit'));
    $router->get('/api/v1/return-to-suppliers', [$returnToSupplierController, 'list']);
    $router->get('/api/v1/return-to-suppliers/rr/search', [$returnToSupplierController, 'searchReceivingReports']);
    $router->get('/api/v1/return-to-suppliers/rr/{rrRefno}/items', [$returnToSupplierController, 'receivingReportItems']);
    $router->get('/api/v1/return-to-suppliers/{returnRefno}', [$returnToSupplierController, 'show']);
    $router->post('/api/v1/return-to-suppliers', $requireActionAuth([$returnToSupplierController, 'create'], 'Return to Supplier', 'add'));
    $router->patch('/api/v1/return-to-suppliers/{returnRefno}', $requireActionAuth([$returnToSupplierController, 'update'], 'Return to Supplier', 'edit'));
    $router->delete('/api/v1/return-to-suppliers/{returnRefno}', $requireActionAuth([$returnToSupplierController, 'delete'], 'Return to Supplier', 'delete'));
    $router->get('/api/v1/return-to-suppliers/{returnRefno}/items', [$returnToSupplierController, 'items']);
    $router->post('/api/v1/return-to-suppliers/{returnRefno}/items', $requireActionAuth([$returnToSupplierController, 'addItem'], 'Return to Supplier', 'add'));
    $router->patch('/api/v1/return-to-supplier-items/{itemId}', $requireActionAuth([$returnToSupplierController, 'updateItem'], 'Return to Supplier', 'edit'));
    $router->delete('/api/v1/return-to-supplier-items/{itemId}', $requireActionAuth([$returnToSupplierController, 'deleteItem'], 'Return to Supplier', 'delete'));
    $router->post('/api/v1/return-to-suppliers/{returnRefno}/actions/{action}', $requireApproverAction([$returnToSupplierController, 'action'], ['Return to Supplier', 'RTS']));
    $router->get('/api/v1/order-slips', [$orderSlipController, 'list']);
    $router->get('/api/v1/order-slips/{orderSlipRefno}', [$orderSlipController, 'show']);
    $router->post('/api/v1/order-slips', $requireActionAuth([$orderSlipController, 'create'], 'Order Slip', 'add'));
    $router->patch('/api/v1/order-slips/{orderSlipRefno}', $requireActionAuth([$orderSlipController, 'update'], 'Order Slip', 'edit'));
    $router->delete('/api/v1/order-slips/{orderSlipRefno}', $requireActionAuth([$orderSlipController, 'delete'], 'Order Slip', 'delete'));
    $router->post('/api/v1/order-slips/{orderSlipRefno}/items', $requireActionAuth([$orderSlipController, 'addItem'], 'Order Slip', 'add'));
    $router->patch('/api/v1/order-slip-items/{itemId}', $requireActionAuth([$orderSlipController, 'updateItem'], 'Order Slip', 'edit'));
    $router->delete('/api/v1/order-slip-items/{itemId}', $requireActionAuth([$orderSlipController, 'deleteItem'], 'Order Slip', 'delete'));
    $router->post('/api/v1/order-slips/{orderSlipRefno}/actions/{action}', $requireApproverAction([$orderSlipController, 'action'], ['Order Slip', 'OS']));
    $router->get('/api/v1/invoices', [$invoiceController, 'list']);
    $router->get('/api/v1/invoices/{invoiceRefno}', [$invoiceController, 'show']);
    $router->post('/api/v1/invoices', $requireActionAuth([$invoiceController, 'create'], 'Invoice', 'add'));
    $router->patch('/api/v1/invoices/{invoiceRefno}', $requireActionAuth([$invoiceController, 'update'], 'Invoice', 'edit'));
    $router->delete('/api/v1/invoices/{invoiceRefno}', $requireActionAuth([$invoiceController, 'delete'], 'Invoice', 'delete'));
    $router->post('/api/v1/invoices/{invoiceRefno}/items', $requireActionAuth([$invoiceController, 'addItem'], 'Invoice', 'add'));
    $router->patch('/api/v1/invoice-items/{itemId}', $requireActionAuth([$invoiceController, 'updateItem'], 'Invoice', 'edit'));
    $router->delete('/api/v1/invoice-items/{itemId}', $requireActionAuth([$invoiceController, 'deleteItem'], 'Invoice', 'delete'));
    $router->post('/api/v1/invoices/{invoiceRefno}/actions/{action}', $requireApproverAction([$invoiceController, 'action'], ['Invoice', 'SI']));
    $router->get('/api/v1/inquiry-reports/customers', [$inquiryReportController, 'customers']);
    $router->get('/api/v1/inquiry-reports', [$inquiryReportController, 'report']);
    $router->get('/api/v1/inactive-active-customers-report', [$inactiveActiveCustomersReportController, 'report']);
    $router->get('/api/v1/old-new-customers-report', [$oldNewCustomersReportController, 'report']);
    $router->get('/api/v1/inventory-audits', [$inventoryAuditController, 'list']);
    $router->get('/api/v1/inventory-audits/filter-options', [$inventoryAuditController, 'filters']);
    $router->get('/api/v1/inventory-audits/adjustments/{adjustmentId}', [$inventoryAuditController, 'showAdjustment']);
    $router->post('/api/v1/inventory-audits/adjustments', $requireActionAuth([$inventoryAuditController, 'createAdjustment'], 'Inventory Audit', 'add'));
    $router->patch('/api/v1/inventory-audits/adjustments/{adjustmentId}', $requireActionAuth([$inventoryAuditController, 'updateAdjustment'], 'Inventory Audit', 'edit'));
    $router->delete('/api/v1/inventory-audits/adjustments/{adjustmentId}', $requireActionAuth([$inventoryAuditController, 'deleteAdjustment'], 'Inventory Audit', 'delete'));
    $router->get('/api/v1/inventory-audits/stock-adjustments', [$inventoryAuditController, 'listStockAdjustments']);
    $router->post('/api/v1/inventory-audits/stock-adjustments', $requireActionAuth([$inventoryAuditController, 'createStockAdjustment'], 'Inventory Audit', 'add'));
    $router->get('/api/v1/inventory-audits/stock-adjustments/{refno}', [$inventoryAuditController, 'showStockAdjustment']);
    $router->patch('/api/v1/inventory-audits/stock-adjustments/{refno}/date', $requireActionAuth([$inventoryAuditController, 'updateStockAdjustmentDate'], 'Inventory Audit', 'edit'));
    $router->post('/api/v1/inventory-audits/stock-adjustments/{refno}/counts', $requireActionAuth([$inventoryAuditController, 'saveStockAdjustmentCounts'], 'Inventory Audit', 'edit'));
    $router->post('/api/v1/inventory-audits/stock-adjustments/{refno}/post', $requireApproverAction([$inventoryAuditController, 'postStockAdjustment'], ['Inventory Audit', 'IA'], true, 'post'));
    $router->delete('/api/v1/inventory-audits/stock-adjustments/{refno}/items/{itemSession}', $requireActionAuth([$inventoryAuditController, 'deleteStockAdjustmentItem'], 'Inventory Audit', 'delete'));
    $router->delete('/api/v1/inventory-audits/stock-adjustments/{refno}', $requireActionAuth([$inventoryAuditController, 'deleteStockAdjustment'], 'Inventory Audit', 'delete'));
    $router->get('/api/v1/inventory-report/options', [$inventoryReportController, 'options']);
    $router->get('/api/v1/inventory-report', [$inventoryReportController, 'report']);
    $router->get('/api/v1/transfer-stocks', [$transferStockController, 'list']);
    $router->get('/api/v1/transfer-stocks/{transferRefno}', [$transferStockController, 'show']);
    $router->post('/api/v1/transfer-stocks', $requireActionAuth([$transferStockController, 'create'], 'Transfer Stock', 'add'));
    $router->patch('/api/v1/transfer-stocks/{transferRefno}', $requireActionAuth([$transferStockController, 'update'], 'Transfer Stock', 'edit'));
    $router->delete('/api/v1/transfer-stocks/{transferRefno}', $requireActionAuth([$transferStockController, 'delete'], 'Transfer Stock', 'delete'));
    $router->post('/api/v1/transfer-stocks/{transferRefno}/items', $requireActionAuth([$transferStockController, 'addItem'], 'Transfer Stock', 'add'));
    $router->patch('/api/v1/transfer-stock-items/{itemId}', $requireActionAuth([$transferStockController, 'updateItem'], 'Transfer Stock', 'edit'));
    $router->delete('/api/v1/transfer-stock-items/{itemId}', $requireActionAuth([$transferStockController, 'deleteItem'], 'Transfer Stock', 'delete'));
    $router->post('/api/v1/transfer-stocks/{transferRefno}/actions/{action}', $requireApproverAction([$transferStockController, 'action'], ['Transfer Stock', 'TS']));
    $router->get('/api/v1/stock-movements', [$stockMovementController, 'list']);
    $router->get('/api/v1/stock-movements/{logId}', [$stockMovementController, 'show']);
    $router->post('/api/v1/stock-movements', $requireActionAuth([$stockMovementController, 'create'], 'Stock Movement', 'add'));
    $router->patch('/api/v1/stock-movements/{logId}', $requireActionAuth([$stockMovementController, 'update'], 'Stock Movement', 'edit'));
    $router->delete('/api/v1/stock-movements/{logId}', $requireActionAuth([$stockMovementController, 'delete'], 'Stock Movement', 'delete'));
    $router->get('/api/v1/stock-adjustments', [$stockAdjustmentController, 'list']);
    $router->get('/api/v1/stock-adjustments/{refno}', [$stockAdjustmentController, 'show']);
    $router->post('/api/v1/stock-adjustments', $requireActionAuth([$stockAdjustmentController, 'create'], 'Stock Adjustment', 'add'));
    $router->post('/api/v1/stock-adjustments/{refno}/finalize', $requireApproverAction([$stockAdjustmentController, 'finalize'], ['Stock Adjustment', 'SA'], true, 'post'));
    $router->post('/api/v1/auth/login', [$authController, 'login']);
    $router->get('/api/v1/auth/me', [$authController, 'me']);
    $router->post('/api/v1/auth/logout', [$authController, 'logout']);
    $router->get('/api/v1/sales/flow/inquiry/{inquiryRefno}', [$salesController, 'flowByInquiry']);
    $router->get('/api/v1/sales/flow/so/{soRefno}', [$salesController, 'flowBySalesOrder']);
    $router->get('/api/v1/sales-reports/customers', [$salesReportController, 'customers']);
    $router->get('/api/v1/sales-reports', [$salesReportController, 'report']);
    $router->get('/api/v1/sales-reports/transactions/{transactionRefno}/items', [$salesReportController, 'transactionItems']);
    $router->get('/api/v1/sales-return-report/options', [$salesReturnReportController, 'options']);
    $router->get('/api/v1/sales-return-report', [$salesReturnReportController, 'report']);
    $router->get('/api/v1/sales-development-report', [$salesDevelopmentReportController, 'report']);
    $router->get('/api/v1/sales-development-report/summary', [$salesDevelopmentReportController, 'summary']);
    $router->get('/api/v1/sales-returns', [$salesReturnController, 'list']);
    $router->get('/api/v1/sales-returns/source-documents', [$salesReturnController, 'sourceDocuments']);
    $router->post('/api/v1/sales-returns', $requireActionAuth([$salesReturnController, 'create'], 'Sales Return', 'add'));
    $router->get('/api/v1/sales-returns/{refno}', [$salesReturnController, 'show']);
    $router->patch('/api/v1/sales-returns/{refno}', $requireActionAuth([$salesReturnController, 'update'], 'Sales Return', 'edit'));
    $router->get('/api/v1/sales-returns/{refno}/items', [$salesReturnController, 'items']);
    $router->get('/api/v1/sales-returns/{refno}/source-items', [$salesReturnController, 'sourceItems']);
    $router->post('/api/v1/sales-returns/{refno}/items', $requireActionAuth([$salesReturnController, 'addItem'], 'Sales Return', 'add'));
    $router->delete('/api/v1/sales-return-items/{itemId}', $requireActionAuth([$salesReturnController, 'deleteItem'], 'Sales Return', 'delete'));
    $router->post('/api/v1/sales-returns/{refno}/actions/post', $requireApproverAction([$salesReturnController, 'postAction'], ['Sales Return', 'SR'], true, 'post'));
    $router->post('/api/v1/sales-returns/{refno}/actions/unpost', $requireActionAuth([$salesReturnController, 'unpostAction'], 'Sales Return', 'unpost'));
    $router->get('/api/v1/sales-inquiries', [$salesInquiryController, 'list']);
    $router->get('/api/v1/sales-inquiries/{inquiryRefno}', [$salesInquiryController, 'show']);
    $router->post('/api/v1/sales-inquiries', $requireActionAuth([$salesInquiryController, 'create'], 'Sales Inquiry', 'add'));
    $router->patch('/api/v1/sales-inquiries/{inquiryRefno}', $requireActionAuth([$salesInquiryController, 'update'], 'Sales Inquiry', 'edit'));
    $router->delete('/api/v1/sales-inquiries/{inquiryRefno}', $requireActionAuth([$salesInquiryController, 'delete'], 'Sales Inquiry', 'delete'));
    $router->post('/api/v1/sales-inquiries/{inquiryRefno}/items', $requireActionAuth([$salesInquiryController, 'addItem'], 'Sales Inquiry', 'add'));
    $router->patch('/api/v1/sales-inquiry-items/{itemId}', $requireActionAuth([$salesInquiryController, 'updateItem'], 'Sales Inquiry', 'edit'));
    $router->delete('/api/v1/sales-inquiry-items/{itemId}', $requireActionAuth([$salesInquiryController, 'deleteItem'], 'Sales Inquiry', 'delete'));
    $router->post('/api/v1/sales-inquiries/{inquiryRefno}/actions/{action}', $requireApproverAction([$salesInquiryController, 'action'], ['Sales Inquiry', 'SI']));
    $router->get('/api/v1/sales-orders', [$salesOrderController, 'list']);
    $router->get('/api/v1/sales-orders/{salesRefno}', [$salesOrderController, 'show']);
    $router->post('/api/v1/sales-orders', $requireActionAuth([$salesOrderController, 'create'], 'Sales Order', 'add'));
    $router->patch('/api/v1/sales-orders/{salesRefno}', $requireActionAuth([$salesOrderController, 'update'], 'Sales Order', 'edit'));
    $router->delete('/api/v1/sales-orders/{salesRefno}', $requireActionAuth([$salesOrderController, 'delete'], 'Sales Order', 'delete'));
    $router->post('/api/v1/sales-orders/{salesRefno}/items', $requireActionAuth([$salesOrderController, 'addItem'], 'Sales Order', 'add'));
    $router->patch('/api/v1/sales-order-items/{itemId}', $requireActionAuth([$salesOrderController, 'updateItem'], 'Sales Order', 'edit'));
    $router->delete('/api/v1/sales-order-items/{itemId}', $requireActionAuth([$salesOrderController, 'deleteItem'], 'Sales Order', 'delete'));
    $router->post('/api/v1/sales-orders/{salesRefno}/actions/{action}', $requireApproverAction([$salesOrderController, 'action'], ['Sales Order', 'SO']));
    $router->post('/api/v1/sales-orders/{salesRefno}/convert/{documentType}', $requireActionAuth([$salesOrderController, 'convertDocument'], 'Sales Order', 'add'));
    $router->get('/api/v1/approvers', [$approverController, 'list']);
    $router->get('/api/v1/approvers/staff', [$approverController, 'staff']);
    $router->get('/api/v1/approvers/{approverId}', [$approverController, 'show']);
    $router->post('/api/v1/approvers', $requireMasterUser([$approverController, 'create']));
    $router->patch('/api/v1/approvers/{approverId}', $requireMasterUser([$approverController, 'update']));
    $router->delete('/api/v1/approvers/{approverId}', $requireMasterUser([$approverController, 'delete']));
    $router->get('/api/v1/access-groups', [$accessGroupController, 'list']);
    $router->post('/api/v1/access-groups', $requireMasterUser([$accessGroupController, 'create']));
    $router->patch('/api/v1/access-groups/{id}', $requireMasterUser([$accessGroupController, 'update']));
    $router->delete('/api/v1/access-groups/{id}', $requireMasterUser([$accessGroupController, 'delete']));
    $router->get('/api/v1/roles', [$rolePermissionController, 'list']);
    $router->post('/api/v1/roles', $requireMasterUser([$rolePermissionController, 'create']));
    $router->get('/api/v1/roles/{roleId}/permissions', [$rolePermissionController, 'show']);
    $router->patch('/api/v1/roles/{roleId}/permissions', $requireMasterUser([$rolePermissionController, 'update']));
    $router->get('/api/v1/staff', $requireBearerAuthWithClaims([$staffController, 'list']));
    $router->post('/api/v1/staff', $requireMasterUser([$staffController, 'create']));
    $router->get('/api/v1/staff/roles', $requireBearerAuthWithClaims([$staffController, 'roles']));
    $router->get('/api/v1/staff/{staffId}', $requireBearerAuthWithClaims([$staffController, 'show']));
    $router->patch('/api/v1/staff/{staffId}', $requireMasterUser([$staffController, 'update']));
    $router->post('/api/v1/staff/{staffId}/password', $requireMasterUser([$staffController, 'changePassword']));
    $router->delete('/api/v1/staff/{staffId}', $requireMasterUser([$staffController, 'delete']));
    $router->get('/api/v1/teams', [$teamController, 'list']);
    $router->get('/api/v1/teams/{teamId}', [$teamController, 'show']);
    $router->post('/api/v1/teams', $requireActionAuth([$teamController, 'create'], 'Team', 'add'));
    $router->patch('/api/v1/teams/{teamId}', $requireActionAuth([$teamController, 'update'], 'Team', 'edit'));
    $router->delete('/api/v1/teams/{teamId}', $requireActionAuth([$teamController, 'delete'], 'Team', 'delete'));
    $router->get('/api/v1/couriers', [$courierController, 'list']);
    $router->get('/api/v1/couriers/{courierId}', [$courierController, 'show']);
    $router->post('/api/v1/couriers', $requireActionAuth([$courierController, 'create'], 'Courier', 'add'));
    $router->patch('/api/v1/couriers/{courierId}', $requireActionAuth([$courierController, 'update'], 'Courier', 'edit'));
    $router->delete('/api/v1/couriers/{courierId}', $requireActionAuth([$courierController, 'delete'], 'Courier', 'delete'));
    $router->get('/api/v1/categories', [$categoryController, 'list']);
    $router->get('/api/v1/categories/{categoryId}', [$categoryController, 'show']);
    $router->post('/api/v1/categories', $requireActionAuth([$categoryController, 'create'], 'Category', 'add'));
    $router->patch('/api/v1/categories/{categoryId}', $requireActionAuth([$categoryController, 'update'], 'Category', 'edit'));
    $router->delete('/api/v1/categories/{categoryId}', $requireActionAuth([$categoryController, 'delete'], 'Category', 'delete'));
    $router->get('/api/v1/remark-templates', [$remarkTemplateController, 'list']);
    $router->get('/api/v1/remark-templates/{remarkTemplateId}', [$remarkTemplateController, 'show']);
    $router->post('/api/v1/remark-templates', $requireActionAuth([$remarkTemplateController, 'create'], 'Remark Templates', 'add'));
    $router->patch('/api/v1/remark-templates/{remarkTemplateId}', $requireActionAuth([$remarkTemplateController, 'update'], 'Remark Templates', 'edit'));
    $router->delete('/api/v1/remark-templates/{remarkTemplateId}', $requireActionAuth([$remarkTemplateController, 'delete'], 'Remark Templates', 'delete'));
    $router->get('/api/v1/special-prices/products', $requireBearerAuth([$specialPriceController, 'products']));
    $router->get('/api/v1/special-prices/customers', $requireBearerAuth([$specialPriceController, 'customers']));
    $router->get('/api/v1/special-prices/areas', $requireBearerAuth([$specialPriceController, 'areas']));
    $router->get('/api/v1/special-prices/categories', $requireBearerAuth([$specialPriceController, 'categories']));
    $router->get('/api/v1/special-prices', $requireBearerAuth([$specialPriceController, 'list']));
    $router->post('/api/v1/special-prices', $requireActionAuth([$specialPriceController, 'create'], 'Special Price', 'add'));
    $router->get('/api/v1/special-prices/{refno}', $requireBearerAuth([$specialPriceController, 'show']));
    $router->patch('/api/v1/special-prices/{refno}', $requireActionAuth([$specialPriceController, 'update'], 'Special Price', 'edit'));
    $router->delete('/api/v1/special-prices/{refno}', $requireActionAuth([$specialPriceController, 'delete'], 'Special Price', 'delete'));
    $router->post('/api/v1/special-prices/{refno}/customers', $requireActionAuth([$specialPriceController, 'addCustomer'], 'Special Price', 'add'));
    $router->delete('/api/v1/special-prices/{refno}/customers/{patientRefno}', $requireActionAuth([$specialPriceController, 'removeCustomer'], 'Special Price', 'delete'));
    $router->post('/api/v1/special-prices/{refno}/areas', $requireActionAuth([$specialPriceController, 'addArea'], 'Special Price', 'add'));
    $router->delete('/api/v1/special-prices/{refno}/areas/{areaCode}', $requireActionAuth([$specialPriceController, 'removeArea'], 'Special Price', 'delete'));
    $router->post('/api/v1/special-prices/{refno}/categories', $requireActionAuth([$specialPriceController, 'addCategory'], 'Special Price', 'add'));
    $router->delete('/api/v1/special-prices/{refno}/categories/{categoryId}', $requireActionAuth([$specialPriceController, 'removeCategory'], 'Special Price', 'delete'));
    // Campaign Outreach
    $router->get('/api/v1/campaigns/{campaignId}/outreach', [$campaignController, 'listOutreach']);
    $router->get('/api/v1/campaigns/{campaignId}/outreach/{id}', [$campaignController, 'getOutreach']);
    $router->post('/api/v1/campaigns/{campaignId}/outreach', $requireActionAuth([$campaignController, 'createOutreach'], 'Campaign', 'add'));
    $router->patch('/api/v1/outreach/{id}', $requireActionAuth([$campaignController, 'updateOutreachStatus'], 'Campaign', 'edit'));
    $router->post('/api/v1/outreach/{id}/response', $requireActionAuth([$campaignController, 'recordOutreachResponse'], 'Campaign', 'edit'));
    $router->get('/api/v1/outreach/pending', [$campaignController, 'getPendingOutreach']);
    // Campaign Feedback
    $router->get('/api/v1/campaigns/{campaignId}/feedback', [$campaignController, 'listFeedback']);
    $router->post('/api/v1/campaigns/{campaignId}/feedback', $requireActionAuth([$campaignController, 'createFeedback'], 'Campaign', 'add'));
    $router->get('/api/v1/campaigns/{campaignId}/feedback/analysis', [$campaignController, 'analyzeFeedback']);
    // Campaign Stats
    $router->get('/api/v1/campaigns/{campaignId}/stats', [$campaignController, 'getStats']);
    // Message Templates
    $router->get('/api/v1/message-templates', [$campaignController, 'listTemplates']);
    $router->get('/api/v1/message-templates/{id}', [$campaignController, 'getTemplate']);
    $router->post('/api/v1/message-templates', $requireActionAuth([$campaignController, 'createTemplate'], 'Message Templates', 'add'));

    // SMS Gateway (Authenticated by Gateway Device ID)
    $smsGatewayController = new \App\Controllers\SmsGatewayController($db);
    $router->post('/api/v1/sms-gateway/queue', $requireBearerAuth([$smsGatewayController, 'queue']));
    $router->get('/api/v1/sms-gateway/devices', $requireBearerAuth([$smsGatewayController, 'getDevices']));
    $router->get('/api/v1/sms-gateway/history', $requireBearerAuth([$smsGatewayController, 'getHistory']));
    $router->post('/api/v1/sms-gateway/register-device', [$smsGatewayController, 'registerDevice']);
    $router->post('/api/v1/sms-gateway/fetch-jobs', [$smsGatewayController, 'fetchJobs']);
    $router->post('/api/v1/sms-gateway/report-status', [$smsGatewayController, 'reportStatus']);
    $router->patch('/api/v1/message-templates/{id}', $requireActionAuth([$campaignController, 'updateTemplate'], 'Message Templates', 'edit'));
    $router->delete('/api/v1/message-templates/{id}', $requireActionAuth([$campaignController, 'deleteTemplate'], 'Message Templates', 'delete'));
    // Queue Processing
    $router->post('/api/v1/outreach/queue/process', $requireActionAuth([$campaignController, 'processOutreachQueue'], 'Campaign', 'edit'));
    // Promotions
    $router->get('/api/v1/promotions', [$promotionController, 'listPromotions']);
    // Promotion Stats & Extended Operations (static routes before {promotionId})
    $router->get('/api/v1/promotions/stats/summary', [$promotionController, 'getStats']);
    $router->get('/api/v1/promotions/assigned/list', [$promotionController, 'getAssignedPromotions']);
    $router->get('/api/v1/promotions/status/{status}', [$promotionController, 'getPromotionsByStatus']);
    $router->get('/api/v1/promotions/active/list', [$promotionController, 'getActivePromotions']);
    $router->post('/api/v1/promotions', $requireActionAuth([$promotionController, 'createPromotion'], 'Promotion', 'add'));
    $router->get('/api/v1/promotions/{promotionId}', [$promotionController, 'getPromotion']);
    $router->patch('/api/v1/promotions/{promotionId}', $requireActionAuth([$promotionController, 'updatePromotion'], 'Promotion', 'edit'));
    $router->delete('/api/v1/promotions/{promotionId}', $requireActionAuth([$promotionController, 'deletePromotion'], 'Promotion', 'delete'));
    // Promotion Products
    $router->get('/api/v1/promotions/{promotionId}/products', [$promotionController, 'listProducts']);
    $router->get('/api/v1/promotion-products/{productId}', [$promotionController, 'getProduct']);
    $router->post('/api/v1/promotions/{promotionId}/products', $requireActionAuth([$promotionController, 'addProduct'], 'Promotion', 'add'));
    $router->patch('/api/v1/promotion-products/{productId}', $requireActionAuth([$promotionController, 'updateProduct'], 'Promotion', 'edit'));
    $router->delete('/api/v1/promotion-products/{productId}', $requireActionAuth([$promotionController, 'deleteProduct'], 'Promotion', 'delete'));
    // Promotion Postings
    $router->get('/api/v1/promotions/{promotionId}/postings', [$promotionController, 'listPostings']);
    $router->get('/api/v1/promotion-postings/{postingId}', [$promotionController, 'getPosting']);
    $router->post('/api/v1/promotions/{promotionId}/postings', $requireActionAuth([$promotionController, 'createPosting'], 'Promotion', 'add'));
    $router->patch('/api/v1/promotion-postings/{postingId}', $requireActionAuth([$promotionController, 'updatePosting'], 'Promotion', 'edit'));
    $router->post('/api/v1/promotion-postings/{postingId}/review', $requireApproverAction([$promotionController, 'reviewPosting'], ['Promotion', 'Promotion Posting']));
    $router->delete('/api/v1/promotion-postings/{postingId}', $requireActionAuth([$promotionController, 'deletePosting'], 'Promotion', 'delete'));
    $router->get('/api/v1/promotion-postings/review/pending', [$promotionController, 'getPendingReview']);
    // Promotion Extended Operations
    $router->post('/api/v1/promotions/{promotionId}/extend', $requireActionAuth([$promotionController, 'extendPromotion'], 'Promotion', 'edit'));
    $router->post('/api/v1/promotions/{promotionId}/products/batch', $requireActionAuth([$promotionController, 'batchAddProducts'], 'Promotion', 'add'));
    $router->delete('/api/v1/promotions/{promotionId}/products/by-product/{productId}', $requireActionAuth([$promotionController, 'removeProductByProductId'], 'Promotion', 'delete'));
    $router->post('/api/v1/promotions/upload-screenshot', $requireActionAuth([$promotionController, 'uploadScreenshot'], 'Promotion', 'edit'));
    // Loyalty Discounts
    $router->get('/api/v1/loyalty-discounts', [$loyaltyDiscountController, 'list']);
    $router->get('/api/v1/loyalty-discounts/stats', [$loyaltyDiscountController, 'stats']);
    $router->get('/api/v1/loyalty-discounts/customer/{customerId}/active-discount', [$loyaltyDiscountController, 'customerActiveDiscount']);
    $router->post('/api/v1/loyalty-discounts', $requireActionAuth([$loyaltyDiscountController, 'create'], 'Loyalty Discounts', 'add'));
    $router->patch('/api/v1/loyalty-discounts/{ruleId}', $requireActionAuth([$loyaltyDiscountController, 'update'], 'Loyalty Discounts', 'edit'));
    $router->patch('/api/v1/loyalty-discounts/{ruleId}/status', $requireActionAuth([$loyaltyDiscountController, 'updateStatus'], 'Loyalty Discounts', 'edit'));
    $router->delete('/api/v1/loyalty-discounts/{ruleId}', $requireActionAuth([$loyaltyDiscountController, 'delete'], 'Loyalty Discounts', 'delete'));
    // Profit Protection
    $router->get('/api/v1/profit-protection/threshold', [$profitProtectionController, 'threshold']);
    $router->patch('/api/v1/profit-protection/threshold', $requireActionAuth([$profitProtectionController, 'updateThreshold'], 'Profit Protection', 'edit'));
    // VIP Tier Settings
    $router->get('/api/v1/vip-tier-settings', $requireBearerAuthWithClaims([$vipTierSettingsController, 'index']));
    $router->patch('/api/v1/vip-tier-settings', $requireMasterUser([$vipTierSettingsController, 'update']));
    // Server Maintenance (Master User only)
    $router->get('/api/v1/server-maintenance/status', $requireBearerAuthWithClaims([$serverMaintenanceController, 'status']));
    $router->get('/api/v1/server-maintenance/database-backup', $requireBearerAuthWithClaims([$serverMaintenanceController, 'downloadDatabaseBackup']));
    $router->get('/api/v1/server-maintenance/automatic-backup', $requireBearerAuthWithClaims([$serverMaintenanceController, 'getAutomaticBackup']));
    $router->patch('/api/v1/server-maintenance/automatic-backup', $requireMasterUser([$serverMaintenanceController, 'updateAutomaticBackup']));
    $router->get('/api/v1/server-maintenance/backup-destinations', $requireBearerAuthWithClaims([$serverMaintenanceController, 'listBackupDestinations']));
    $router->post('/api/v1/server-maintenance/automatic-backup/run', $requireMasterUser([$serverMaintenanceController, 'runAutomaticBackup']));
    $router->post('/api/v1/profit-protection/validate-items', $requireBearerAuthWithClaims([$profitProtectionController, 'validateItems']));
    $router->post('/api/v1/profit-protection/overrides', $requireActionAuth([$profitProtectionController, 'createOverride'], 'Profit Protection', 'add'));
    $router->get('/api/v1/profit-protection/overrides', [$profitProtectionController, 'listOverrides']);
    $router->get('/api/v1/profit-protection/override-stats', [$profitProtectionController, 'overrideStats']);
    $router->post('/api/v1/profit-protection/admin-overrides', $requireMasterUser([$profitProtectionController, 'createAdminOverride']));
    $router->get('/api/v1/profit-protection/admin-overrides', [$profitProtectionController, 'listAdminOverrides']);

    return $router;
}
