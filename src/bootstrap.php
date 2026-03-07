<?php

declare(strict_types=1);

use App\Config;
use App\Controllers\CollectionController;
use App\Controllers\ContactsController;
use App\Controllers\DealsController;
use App\Controllers\TasksController;
use App\Controllers\MessagesController;
use App\Controllers\ProfilesController;
use App\Controllers\CustomerController;
use App\Controllers\CustomerDatabaseController;
use App\Controllers\AdjustmentEntryController;
use App\Controllers\ActivityLogController;
use App\Controllers\DailyCallMonitoringController;
use App\Controllers\FastSlowInventoryReportController;
use App\Controllers\FreightChargesController;
use App\Controllers\HealthController;
use App\Controllers\InvoiceController;
use App\Controllers\InactiveActiveCustomersReportController;
use App\Controllers\InquiryReportController;
use App\Controllers\InventoryAuditController;
use App\Controllers\InventoryReportController;
use App\Controllers\OrderSlipController;
use App\Controllers\AuthController;
use App\Controllers\ProductController;
use App\Controllers\PurchaseRequestController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\ReceivingStockController;
use App\Controllers\ReorderReportController;
use App\Controllers\ReturnToSupplierController;
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
use App\Controllers\StaffController;
use App\Controllers\TeamController;
use App\Controllers\TransferStockController;
use App\Controllers\CampaignController;
use App\Controllers\PromotionController;
use App\Http\Router;
use App\Support\Env;

require __DIR__ . '/Support/Env.php';
require __DIR__ . '/Support/Exceptions/HttpException.php';
require __DIR__ . '/Config.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Http/Response.php';
require __DIR__ . '/Http/Router.php';
require __DIR__ . '/Repositories/CustomerRepository.php';
require __DIR__ . '/Repositories/CustomerDatabaseRepository.php';
require __DIR__ . '/Repositories/AdjustmentEntryRepository.php';
require __DIR__ . '/Repositories/ApproverRepository.php';
require __DIR__ . '/Repositories/ActivityLogRepository.php';
require __DIR__ . '/Repositories/CollectionRepository.php';
require __DIR__ . '/Repositories/ContactsRepository.php';
require __DIR__ . '/Repositories/DealsRepository.php';
require __DIR__ . '/Repositories/TasksRepository.php';
require __DIR__ . '/Repositories/MessagesRepository.php';
require __DIR__ . '/Repositories/ProfilesRepository.php';
require __DIR__ . '/Repositories/DailyCallMonitoringRepository.php';
require __DIR__ . '/Repositories/FastSlowInventoryReportRepository.php';
require __DIR__ . '/Repositories/FreightChargesRepository.php';
require __DIR__ . '/Repositories/AuthRepository.php';
require __DIR__ . '/Repositories/ProductRepository.php';
require __DIR__ . '/Repositories/PurchaseRequestRepository.php';
require __DIR__ . '/Repositories/PurchaseOrderRepository.php';
require __DIR__ . '/Repositories/ReceivingStockRepository.php';
require __DIR__ . '/Repositories/ReorderReportRepository.php';
require __DIR__ . '/Repositories/ReturnToSupplierRepository.php';
require __DIR__ . '/Repositories/OrderSlipRepository.php';
require __DIR__ . '/Repositories/InvoiceRepository.php';
require __DIR__ . '/Repositories/InquiryReportRepository.php';
require __DIR__ . '/Repositories/InventoryAuditRepository.php';
require __DIR__ . '/Repositories/InventoryReportRepository.php';
require __DIR__ . '/Repositories/InactiveActiveCustomersReportRepository.php';
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
require __DIR__ . '/Repositories/TeamRepository.php';
require __DIR__ . '/Repositories/TransferStockRepository.php';
require __DIR__ . '/Repositories/CampaignOutreachRepository.php';
require __DIR__ . '/Repositories/CampaignFeedbackRepository.php';
require __DIR__ . '/Repositories/MessageTemplateRepository.php';
require __DIR__ . '/Repositories/PromotionRepository.php';
require __DIR__ . '/Repositories/PromotionProductRepository.php';
require __DIR__ . '/Repositories/PromotionPostingRepository.php';
require __DIR__ . '/Security/TokenService.php';
require __DIR__ . '/Controllers/HealthController.php';
require __DIR__ . '/Controllers/CustomerController.php';
require __DIR__ . '/Controllers/CustomerDatabaseController.php';
require __DIR__ . '/Controllers/AdjustmentEntryController.php';
require __DIR__ . '/Controllers/ApproverController.php';
require __DIR__ . '/Controllers/ActivityLogController.php';
require __DIR__ . '/Controllers/CollectionController.php';
require __DIR__ . '/Controllers/ContactsController.php';
require __DIR__ . '/Controllers/DealsController.php';
require __DIR__ . '/Controllers/TasksController.php';
require __DIR__ . '/Controllers/MessagesController.php';
require __DIR__ . '/Controllers/ProfilesController.php';
require __DIR__ . '/Controllers/DailyCallMonitoringController.php';
require __DIR__ . '/Controllers/FastSlowInventoryReportController.php';
require __DIR__ . '/Controllers/FreightChargesController.php';
require __DIR__ . '/Controllers/AuthController.php';
require __DIR__ . '/Controllers/ProductController.php';
require __DIR__ . '/Controllers/PurchaseRequestController.php';
require __DIR__ . '/Controllers/PurchaseOrderController.php';
require __DIR__ . '/Controllers/ReceivingStockController.php';
require __DIR__ . '/Controllers/ReorderReportController.php';
require __DIR__ . '/Controllers/ReturnToSupplierController.php';
require __DIR__ . '/Controllers/OrderSlipController.php';
require __DIR__ . '/Controllers/InvoiceController.php';
require __DIR__ . '/Controllers/InactiveActiveCustomersReportController.php';
require __DIR__ . '/Controllers/InquiryReportController.php';
require __DIR__ . '/Controllers/InventoryAuditController.php';
require __DIR__ . '/Controllers/InventoryReportController.php';
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
require __DIR__ . '/Controllers/TeamController.php';
require __DIR__ . '/Controllers/TransferStockController.php';
require __DIR__ . '/Controllers/CampaignController.php';
require __DIR__ . '/Controllers/PromotionController.php';

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
    $adjustmentEntryController = new AdjustmentEntryController(new App\Repositories\AdjustmentEntryRepository($db));
    $approverController = new ApproverController(new App\Repositories\ApproverRepository($db));
    $activityLogController = new ActivityLogController(new App\Repositories\ActivityLogRepository($db));
    $collectionController = new CollectionController(new App\Repositories\CollectionRepository($db));
    $contactsController = new ContactsController(new App\Repositories\ContactsRepository($db));
    $dealsController = new DealsController(new App\Repositories\DealsRepository($db));
    $tasksController = new TasksController(new App\Repositories\TasksRepository($db));
    $messagesController = new MessagesController(new App\Repositories\MessagesRepository($db));
    $profilesController = new ProfilesController(new App\Repositories\ProfilesRepository($db));
    $dailyCallMonitoringController = new DailyCallMonitoringController(new App\Repositories\DailyCallMonitoringRepository($db));
    $fastSlowInventoryReportController = new FastSlowInventoryReportController(new App\Repositories\FastSlowInventoryReportRepository($db));
    $freightChargesController = new FreightChargesController(new App\Repositories\FreightChargesRepository($db));
    $authController = new AuthController(
        new App\Repositories\AuthRepository($db),
        new App\Security\TokenService($config->authSecret, $config->authTokenTtlSeconds)
    );
    $productController = new ProductController(new App\Repositories\ProductRepository($db));
    $purchaseRequestController = new PurchaseRequestController(new App\Repositories\PurchaseRequestRepository($db));
    $purchaseOrderController = new PurchaseOrderController(new App\Repositories\PurchaseOrderRepository($db));
    $receivingStockController = new ReceivingStockController(new App\Repositories\ReceivingStockRepository($db));
    $reorderReportController = new ReorderReportController(new App\Repositories\ReorderReportRepository($db));
    $returnToSupplierController = new ReturnToSupplierController(new App\Repositories\ReturnToSupplierRepository($db));
    $orderSlipController = new OrderSlipController(new App\Repositories\OrderSlipRepository($db));
    $invoiceController = new InvoiceController(new App\Repositories\InvoiceRepository($db));
    $inactiveActiveCustomersReportController = new InactiveActiveCustomersReportController(new App\Repositories\InactiveActiveCustomersReportRepository($db));
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
    $staffController = new StaffController(new App\Repositories\StaffRepository($db));
    $teamController = new TeamController(new App\Repositories\TeamRepository($db));
    $transferStockController = new TransferStockController(new App\Repositories\TransferStockRepository($db));
    $campaignController = new CampaignController(
        new App\Repositories\CampaignOutreachRepository($db),
        new App\Repositories\CampaignFeedbackRepository($db),
        new App\Repositories\MessageTemplateRepository($db)
    );
    $promotionController = new PromotionController(
        new App\Repositories\PromotionRepository($db),
        new App\Repositories\PromotionProductRepository($db),
        new App\Repositories\PromotionPostingRepository($db)
    );

    $router = new Router();
    $router->get('/api/v1/health', [$healthController, 'index']);
    $router->get('/api/v1/customers/{sessionId}', [$customerController, 'show']);
    $router->get('/api/v1/customers/{sessionId}/purchase-history', [$customerController, 'purchaseHistory']);
    $router->get('/api/v1/customers/{sessionId}/ledger', [$customerController, 'ledger']);
    $router->get('/api/v1/statements/customers', [$statementOfAccountController, 'customers']);
    $router->get('/api/v1/statements/of-account', [$statementOfAccountController, 'report']);
    $router->get('/api/v1/adjustment-entries', [$adjustmentEntryController, 'list']);
    $router->get('/api/v1/adjustment-entries/{refno}', [$adjustmentEntryController, 'show']);
    $router->post('/api/v1/adjustment-entries', [$adjustmentEntryController, 'create']);
    $router->patch('/api/v1/adjustment-entries/{refno}', [$adjustmentEntryController, 'update']);
    $router->delete('/api/v1/adjustment-entries/{refno}', [$adjustmentEntryController, 'delete']);
    $router->post('/api/v1/adjustment-entries/{refno}/actions/{action}', [$adjustmentEntryController, 'action']);
    $router->get('/api/v1/activity-logs', [$activityLogController, 'list']);
    $router->get('/api/v1/activity-logs/users', [$activityLogController, 'users']);
    $router->get('/api/v1/customer-database', [$customerDatabaseController, 'list']);
    $router->get('/api/v1/customer-database/{sessionId}', [$customerDatabaseController, 'show']);
    $router->post('/api/v1/customer-database', [$customerDatabaseController, 'create']);
    $router->patch('/api/v1/customer-database/{sessionId}', [$customerDatabaseController, 'update']);
    $router->delete('/api/v1/customer-database/{sessionId}', [$customerDatabaseController, 'delete']);
    $router->post('/api/v1/customer-database/{sessionId}/contacts', [$customerDatabaseController, 'addContact']);
    $router->patch('/api/v1/customer-database/contacts/{contactId}', [$customerDatabaseController, 'updateContact']);
    $router->delete('/api/v1/customer-database/contacts/{contactId}', [$customerDatabaseController, 'deleteContact']);
    $router->post('/api/v1/customer-database/{sessionId}/terms', [$customerDatabaseController, 'addTerm']);
    $router->patch('/api/v1/customer-database/terms/{termId}', [$customerDatabaseController, 'updateTerm']);
    $router->delete('/api/v1/customer-database/terms/{termId}', [$customerDatabaseController, 'deleteTerm']);
    $router->get('/api/v1/collections', [$collectionController, 'list']);
    $router->post('/api/v1/collections', [$collectionController, 'create']);
    $router->get('/api/v1/collections/unpaid', [$collectionController, 'unpaid']);
    $router->get('/api/v1/collections/summary', [$collectionController, 'summary']);
    $router->get('/api/v1/collections/{collectionRefno}', [$collectionController, 'show']);
    $router->get('/api/v1/collections/{collectionRefno}/items', [$collectionController, 'items']);
    $router->get('/api/v1/collections/{collectionRefno}/approver-logs', [$collectionController, 'approverLogs']);
    $router->post('/api/v1/collections/{collectionRefno}/items/post', [$collectionController, 'postItems']);
    $router->post('/api/v1/collections/{collectionRefno}/payments', [$collectionController, 'addPayment']);
    $router->post('/api/v1/collections/{collectionRefno}/actions/{action}', [$collectionController, 'action']);
    $router->patch('/api/v1/collection-items/{itemId}', [$collectionController, 'updateItem']);
    $router->delete('/api/v1/collection-items/{itemId}', [$collectionController, 'deleteItem']);
    $router->get('/api/v1/contacts', [$contactsController, 'list']);
    $router->get('/api/v1/contacts/{id}', [$contactsController, 'show']);
    $router->post('/api/v1/contacts', [$contactsController, 'create']);
    $router->patch('/api/v1/contacts/{id}', [$contactsController, 'update']);
    $router->delete('/api/v1/contacts/{id}', [$contactsController, 'delete']);
    $router->post('/api/v1/contacts/bulk-update', [$contactsController, 'bulkUpdate']);
    $router->get('/api/v1/deals', [$dealsController, 'list']);
    $router->get('/api/v1/deals/stage/{stageId}', [$dealsController, 'getByStage']);
    $router->get('/api/v1/deals/{id}', [$dealsController, 'show']);
    $router->post('/api/v1/deals', [$dealsController, 'create']);
    $router->patch('/api/v1/deals/{id}', [$dealsController, 'update']);
    $router->delete('/api/v1/deals/{id}', [$dealsController, 'delete']);
    $router->post('/api/v1/deals/{id}/restore', [$dealsController, 'restore']);
    $router->post('/api/v1/deals/{id}/move-stage', [$dealsController, 'moveToStage']);
    $router->post('/api/v1/deals/bulk-update', [$dealsController, 'bulkUpdate']);
    $router->get('/api/v1/tasks', [$tasksController, 'list']);
    $router->get('/api/v1/tasks/{id}', [$tasksController, 'show']);
    $router->post('/api/v1/tasks', [$tasksController, 'create']);
    $router->patch('/api/v1/tasks/{id}', [$tasksController, 'update']);
    $router->delete('/api/v1/tasks/{id}', [$tasksController, 'delete']);
    $router->post('/api/v1/tasks/{id}/restore', [$tasksController, 'restore']);
    $router->get('/api/v1/tasks/status/{status}', [$tasksController, 'getByStatus']);
    $router->post('/api/v1/tasks/bulk-update', [$tasksController, 'bulkUpdate']);
    $router->get('/api/v1/teams/{teamId}/messages', [$messagesController, 'list']);
    $router->get('/api/v1/messages/{id}', [$messagesController, 'show']);
    $router->post('/api/v1/teams/{teamId}/messages', [$messagesController, 'create']);
    $router->patch('/api/v1/messages/{id}', [$messagesController, 'update']);
    $router->delete('/api/v1/messages/{id}', [$messagesController, 'delete']);
    $router->get('/api/v1/teams/{teamId}/messages/sender/{senderId}', [$messagesController, 'getBySender']);
    $router->get('/api/v1/profiles', [$profilesController, 'list']);
    $router->get('/api/v1/profiles/sales-agents', [$profilesController, 'salesAgents']);
    $router->get('/api/v1/profiles/{id}', [$profilesController, 'show']);
    $router->patch('/api/v1/profiles/{id}', [$profilesController, 'update']);
    $router->post('/api/v1/profiles/{id}/deactivate', [$profilesController, 'deactivate']);
    $router->post('/api/v1/profiles/{id}/activate', [$profilesController, 'activate']);
    $router->post('/api/v1/profiles/{id}/role', [$profilesController, 'updateRole']);
    $router->get('/api/v1/daily-call-monitoring/excel', [$dailyCallMonitoringController, 'excelRows']);
    $router->get('/api/v1/daily-call-monitoring/owner-snapshot', [$dailyCallMonitoringController, 'ownerSnapshot']);
    $router->get('/api/v1/daily-call-monitoring/agent-snapshot', [$dailyCallMonitoringController, 'agentSnapshot']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/purchase-history', [$dailyCallMonitoringController, 'customerPurchaseHistory']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/sales-reports', [$dailyCallMonitoringController, 'customerSalesReports']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/incident-reports', [$dailyCallMonitoringController, 'customerIncidentReports']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/call-logs', [$dailyCallMonitoringController, 'callLogs']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/returns', [$dailyCallMonitoringController, 'returnRecords']);
    $router->post('/api/v1/daily-call-monitoring/call-logs', [$dailyCallMonitoringController, 'createCallLog']);
    $router->get('/api/v1/fast-slow-inventory-report', [$fastSlowInventoryReportController, 'report']);
    $router->get('/api/v1/freight-charges', [$freightChargesController, 'list']);
    $router->get('/api/v1/freight-charges/{refno}', [$freightChargesController, 'show']);
    $router->post('/api/v1/freight-charges', [$freightChargesController, 'create']);
    $router->patch('/api/v1/freight-charges/{refno}', [$freightChargesController, 'update']);
    $router->delete('/api/v1/freight-charges/{refno}', [$freightChargesController, 'delete']);
    $router->post('/api/v1/freight-charges/{refno}/actions/{action}', [$freightChargesController, 'action']);
    $router->get('/api/v1/suggested-stock-report/customers', [$suggestedStockReportController, 'customers']);
    $router->get('/api/v1/suggested-stock-report/summary', [$suggestedStockReportController, 'summary']);
    $router->get('/api/v1/suggested-stock-report/details', [$suggestedStockReportController, 'details']);
    $router->patch('/api/v1/suggested-stock-report/remark', [$suggestedStockReportController, 'updateRemark']);
    $router->get('/api/v1/suggested-stock-report/suppliers', [$suggestedStockReportController, 'suppliers']);
    $router->get('/api/v1/suggested-stock-report/purchase-orders', [$suggestedStockReportController, 'purchaseOrders']);
    $router->post('/api/v1/suggested-stock-report/purchase-orders', [$suggestedStockReportController, 'createPurchaseOrder']);
    $router->post('/api/v1/suggested-stock-report/purchase-orders/{purchaseRefno}/items', [$suggestedStockReportController, 'addPurchaseOrderItem']);
    $router->get('/api/v1/products', [$productController, 'list']);
    $router->get('/api/v1/products/{productSession}', [$productController, 'show']);
    $router->post('/api/v1/products', [$productController, 'create']);
    $router->patch('/api/v1/products/{productSession}', [$productController, 'update']);
    $router->post('/api/v1/products/bulk-update', [$productController, 'bulkUpdate']);
    $router->delete('/api/v1/products/{productSession}', [$productController, 'delete']);
    $router->get('/api/v1/purchase-requests', [$purchaseRequestController, 'list']);
    $router->get('/api/v1/purchase-requests/next-number', [$purchaseRequestController, 'nextNumber']);
    $router->get('/api/v1/purchase-requests/{prRefno}', [$purchaseRequestController, 'show']);
    $router->post('/api/v1/purchase-requests', [$purchaseRequestController, 'create']);
    $router->patch('/api/v1/purchase-requests/{prRefno}', [$purchaseRequestController, 'update']);
    $router->delete('/api/v1/purchase-requests/{prRefno}', [$purchaseRequestController, 'delete']);
    $router->post('/api/v1/purchase-requests/{prRefno}/items', [$purchaseRequestController, 'addItem']);
    $router->patch('/api/v1/purchase-request-items/{itemId}', [$purchaseRequestController, 'updateItem']);
    $router->delete('/api/v1/purchase-request-items/{itemId}', [$purchaseRequestController, 'deleteItem']);
    $router->post('/api/v1/purchase-requests/{prRefno}/actions/{action}', [$purchaseRequestController, 'action']);
    $router->get('/api/v1/purchase-orders', [$purchaseOrderController, 'list']);
    $router->get('/api/v1/purchase-orders/suppliers', [$purchaseOrderController, 'suppliers']);
    $router->get('/api/v1/purchase-orders/{purchaseRefno}', [$purchaseOrderController, 'show']);
    $router->post('/api/v1/purchase-orders', [$purchaseOrderController, 'create']);
    $router->patch('/api/v1/purchase-orders/{purchaseRefno}', [$purchaseOrderController, 'update']);
    $router->delete('/api/v1/purchase-orders/{purchaseRefno}', [$purchaseOrderController, 'delete']);
    $router->post('/api/v1/purchase-orders/{purchaseRefno}/items', [$purchaseOrderController, 'addItem']);
    $router->patch('/api/v1/purchase-order-items/{itemId}', [$purchaseOrderController, 'updateItem']);
    $router->delete('/api/v1/purchase-order-items/{itemId}', [$purchaseOrderController, 'deleteItem']);
    $router->get('/api/v1/receiving-stocks', [$receivingStockController, 'list']);
    $router->get('/api/v1/receiving-stocks/{receivingRefno}', [$receivingStockController, 'show']);
    $router->post('/api/v1/receiving-stocks', [$receivingStockController, 'create']);
    $router->patch('/api/v1/receiving-stocks/{receivingRefno}', [$receivingStockController, 'update']);
    $router->delete('/api/v1/receiving-stocks/{receivingRefno}', [$receivingStockController, 'delete']);
    $router->post('/api/v1/receiving-stocks/{receivingRefno}/items', [$receivingStockController, 'addItem']);
    $router->patch('/api/v1/receiving-stock-items/{itemId}', [$receivingStockController, 'updateItem']);
    $router->delete('/api/v1/receiving-stock-items/{itemId}', [$receivingStockController, 'deleteItem']);
    $router->post('/api/v1/receiving-stocks/{receivingRefno}/finalize', [$receivingStockController, 'finalize']);
    $router->get('/api/v1/reorder-report', [$reorderReportController, 'list']);
    $router->post('/api/v1/reorder-report/hide-items', [$reorderReportController, 'hideItems']);
    $router->get('/api/v1/return-to-suppliers', [$returnToSupplierController, 'list']);
    $router->get('/api/v1/return-to-suppliers/rr/search', [$returnToSupplierController, 'searchReceivingReports']);
    $router->get('/api/v1/return-to-suppliers/rr/{rrRefno}/items', [$returnToSupplierController, 'receivingReportItems']);
    $router->get('/api/v1/return-to-suppliers/{returnRefno}', [$returnToSupplierController, 'show']);
    $router->post('/api/v1/return-to-suppliers', [$returnToSupplierController, 'create']);
    $router->patch('/api/v1/return-to-suppliers/{returnRefno}', [$returnToSupplierController, 'update']);
    $router->delete('/api/v1/return-to-suppliers/{returnRefno}', [$returnToSupplierController, 'delete']);
    $router->get('/api/v1/return-to-suppliers/{returnRefno}/items', [$returnToSupplierController, 'items']);
    $router->post('/api/v1/return-to-suppliers/{returnRefno}/items', [$returnToSupplierController, 'addItem']);
    $router->patch('/api/v1/return-to-supplier-items/{itemId}', [$returnToSupplierController, 'updateItem']);
    $router->delete('/api/v1/return-to-supplier-items/{itemId}', [$returnToSupplierController, 'deleteItem']);
    $router->post('/api/v1/return-to-suppliers/{returnRefno}/actions/{action}', [$returnToSupplierController, 'action']);
    $router->get('/api/v1/order-slips', [$orderSlipController, 'list']);
    $router->get('/api/v1/order-slips/{orderSlipRefno}', [$orderSlipController, 'show']);
    $router->post('/api/v1/order-slips', [$orderSlipController, 'create']);
    $router->patch('/api/v1/order-slips/{orderSlipRefno}', [$orderSlipController, 'update']);
    $router->delete('/api/v1/order-slips/{orderSlipRefno}', [$orderSlipController, 'delete']);
    $router->post('/api/v1/order-slips/{orderSlipRefno}/items', [$orderSlipController, 'addItem']);
    $router->patch('/api/v1/order-slip-items/{itemId}', [$orderSlipController, 'updateItem']);
    $router->delete('/api/v1/order-slip-items/{itemId}', [$orderSlipController, 'deleteItem']);
    $router->post('/api/v1/order-slips/{orderSlipRefno}/actions/{action}', [$orderSlipController, 'action']);
    $router->get('/api/v1/invoices', [$invoiceController, 'list']);
    $router->get('/api/v1/invoices/{invoiceRefno}', [$invoiceController, 'show']);
    $router->post('/api/v1/invoices', [$invoiceController, 'create']);
    $router->patch('/api/v1/invoices/{invoiceRefno}', [$invoiceController, 'update']);
    $router->delete('/api/v1/invoices/{invoiceRefno}', [$invoiceController, 'delete']);
    $router->post('/api/v1/invoices/{invoiceRefno}/items', [$invoiceController, 'addItem']);
    $router->patch('/api/v1/invoice-items/{itemId}', [$invoiceController, 'updateItem']);
    $router->delete('/api/v1/invoice-items/{itemId}', [$invoiceController, 'deleteItem']);
    $router->post('/api/v1/invoices/{invoiceRefno}/actions/{action}', [$invoiceController, 'action']);
    $router->get('/api/v1/inquiry-reports/customers', [$inquiryReportController, 'customers']);
    $router->get('/api/v1/inquiry-reports', [$inquiryReportController, 'report']);
    $router->get('/api/v1/inactive-active-customers-report', [$inactiveActiveCustomersReportController, 'report']);
    $router->get('/api/v1/inventory-audits', [$inventoryAuditController, 'list']);
    $router->get('/api/v1/inventory-audits/filter-options', [$inventoryAuditController, 'filters']);
    $router->get('/api/v1/inventory-audits/adjustments/{adjustmentId}', [$inventoryAuditController, 'showAdjustment']);
    $router->post('/api/v1/inventory-audits/adjustments', [$inventoryAuditController, 'createAdjustment']);
    $router->patch('/api/v1/inventory-audits/adjustments/{adjustmentId}', [$inventoryAuditController, 'updateAdjustment']);
    $router->delete('/api/v1/inventory-audits/adjustments/{adjustmentId}', [$inventoryAuditController, 'deleteAdjustment']);
    $router->get('/api/v1/inventory-report/options', [$inventoryReportController, 'options']);
    $router->get('/api/v1/inventory-report', [$inventoryReportController, 'report']);
    $router->get('/api/v1/transfer-stocks', [$transferStockController, 'list']);
    $router->get('/api/v1/transfer-stocks/{transferRefno}', [$transferStockController, 'show']);
    $router->post('/api/v1/transfer-stocks', [$transferStockController, 'create']);
    $router->patch('/api/v1/transfer-stocks/{transferRefno}', [$transferStockController, 'update']);
    $router->delete('/api/v1/transfer-stocks/{transferRefno}', [$transferStockController, 'delete']);
    $router->post('/api/v1/transfer-stocks/{transferRefno}/items', [$transferStockController, 'addItem']);
    $router->patch('/api/v1/transfer-stock-items/{itemId}', [$transferStockController, 'updateItem']);
    $router->delete('/api/v1/transfer-stock-items/{itemId}', [$transferStockController, 'deleteItem']);
    $router->post('/api/v1/transfer-stocks/{transferRefno}/actions/{action}', [$transferStockController, 'action']);
    $router->get('/api/v1/stock-movements', [$stockMovementController, 'list']);
    $router->get('/api/v1/stock-movements/{logId}', [$stockMovementController, 'show']);
    $router->post('/api/v1/stock-movements', [$stockMovementController, 'create']);
    $router->patch('/api/v1/stock-movements/{logId}', [$stockMovementController, 'update']);
    $router->delete('/api/v1/stock-movements/{logId}', [$stockMovementController, 'delete']);
    $router->get('/api/v1/stock-adjustments', [$stockAdjustmentController, 'list']);
    $router->get('/api/v1/stock-adjustments/{refno}', [$stockAdjustmentController, 'show']);
    $router->post('/api/v1/stock-adjustments', [$stockAdjustmentController, 'create']);
    $router->post('/api/v1/stock-adjustments/{refno}/finalize', [$stockAdjustmentController, 'finalize']);
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
    $router->get('/api/v1/sales-returns/{refno}', [$salesReturnController, 'show']);
    $router->get('/api/v1/sales-returns/{refno}/items', [$salesReturnController, 'items']);
    $router->get('/api/v1/sales-inquiries', [$salesInquiryController, 'list']);
    $router->get('/api/v1/sales-inquiries/{inquiryRefno}', [$salesInquiryController, 'show']);
    $router->post('/api/v1/sales-inquiries', [$salesInquiryController, 'create']);
    $router->patch('/api/v1/sales-inquiries/{inquiryRefno}', [$salesInquiryController, 'update']);
    $router->delete('/api/v1/sales-inquiries/{inquiryRefno}', [$salesInquiryController, 'delete']);
    $router->post('/api/v1/sales-inquiries/{inquiryRefno}/items', [$salesInquiryController, 'addItem']);
    $router->patch('/api/v1/sales-inquiry-items/{itemId}', [$salesInquiryController, 'updateItem']);
    $router->delete('/api/v1/sales-inquiry-items/{itemId}', [$salesInquiryController, 'deleteItem']);
    $router->post('/api/v1/sales-inquiries/{inquiryRefno}/actions/{action}', [$salesInquiryController, 'action']);
    $router->get('/api/v1/sales-orders', [$salesOrderController, 'list']);
    $router->get('/api/v1/sales-orders/{salesRefno}', [$salesOrderController, 'show']);
    $router->post('/api/v1/sales-orders', [$salesOrderController, 'create']);
    $router->patch('/api/v1/sales-orders/{salesRefno}', [$salesOrderController, 'update']);
    $router->delete('/api/v1/sales-orders/{salesRefno}', [$salesOrderController, 'delete']);
    $router->post('/api/v1/sales-orders/{salesRefno}/items', [$salesOrderController, 'addItem']);
    $router->patch('/api/v1/sales-order-items/{itemId}', [$salesOrderController, 'updateItem']);
    $router->delete('/api/v1/sales-order-items/{itemId}', [$salesOrderController, 'deleteItem']);
    $router->post('/api/v1/sales-orders/{salesRefno}/actions/{action}', [$salesOrderController, 'action']);
    $router->post('/api/v1/sales-orders/{salesRefno}/convert/{documentType}', [$salesOrderController, 'convertDocument']);
    $router->get('/api/v1/approvers', [$approverController, 'list']);
    $router->get('/api/v1/approvers/staff', [$approverController, 'staff']);
    $router->get('/api/v1/approvers/{approverId}', [$approverController, 'show']);
    $router->post('/api/v1/approvers', [$approverController, 'create']);
    $router->patch('/api/v1/approvers/{approverId}', [$approverController, 'update']);
    $router->delete('/api/v1/approvers/{approverId}', [$approverController, 'delete']);
    $router->get('/api/v1/staff', [$staffController, 'list']);
    $router->post('/api/v1/staff', [$staffController, 'create']);
    $router->get('/api/v1/staff/roles', [$staffController, 'roles']);
    $router->get('/api/v1/staff/{staffId}', [$staffController, 'show']);
    $router->patch('/api/v1/staff/{staffId}', [$staffController, 'update']);
    $router->delete('/api/v1/staff/{staffId}', [$staffController, 'delete']);
    $router->get('/api/v1/teams', [$teamController, 'list']);
    $router->get('/api/v1/teams/{teamId}', [$teamController, 'show']);
    $router->post('/api/v1/teams', [$teamController, 'create']);
    $router->patch('/api/v1/teams/{teamId}', [$teamController, 'update']);
    $router->delete('/api/v1/teams/{teamId}', [$teamController, 'delete']);
    // Campaign Outreach
    $router->get('/api/v1/campaigns/{campaignId}/outreach', [$campaignController, 'listOutreach']);
    $router->get('/api/v1/campaigns/{campaignId}/outreach/{id}', [$campaignController, 'getOutreach']);
    $router->post('/api/v1/campaigns/{campaignId}/outreach', [$campaignController, 'createOutreach']);
    $router->patch('/api/v1/outreach/{id}', [$campaignController, 'updateOutreachStatus']);
    $router->post('/api/v1/outreach/{id}/response', [$campaignController, 'recordOutreachResponse']);
    $router->get('/api/v1/outreach/pending', [$campaignController, 'getPendingOutreach']);
    // Campaign Feedback
    $router->get('/api/v1/campaigns/{campaignId}/feedback', [$campaignController, 'listFeedback']);
    $router->post('/api/v1/campaigns/{campaignId}/feedback', [$campaignController, 'createFeedback']);
    $router->get('/api/v1/campaigns/{campaignId}/feedback/analysis', [$campaignController, 'analyzeFeedback']);
    // Campaign Stats
    $router->get('/api/v1/campaigns/{campaignId}/stats', [$campaignController, 'getStats']);
    // Message Templates
    $router->get('/api/v1/message-templates', [$campaignController, 'listTemplates']);
    $router->get('/api/v1/message-templates/{id}', [$campaignController, 'getTemplate']);
    $router->post('/api/v1/message-templates', [$campaignController, 'createTemplate']);
    $router->patch('/api/v1/message-templates/{id}', [$campaignController, 'updateTemplate']);
    $router->delete('/api/v1/message-templates/{id}', [$campaignController, 'deleteTemplate']);
    // Queue Processing
    $router->post('/api/v1/outreach/queue/process', [$campaignController, 'processOutreachQueue']);
    // Promotions
    $router->get('/api/v1/promotions', [$promotionController, 'listPromotions']);
    // Promotion Stats & Extended Operations (static routes before {promotionId})
    $router->get('/api/v1/promotions/stats/summary', [$promotionController, 'getStats']);
    $router->get('/api/v1/promotions/assigned/list', [$promotionController, 'getAssignedPromotions']);
    $router->get('/api/v1/promotions/status/{status}', [$promotionController, 'getPromotionsByStatus']);
    $router->get('/api/v1/promotions/active/list', [$promotionController, 'getActivePromotions']);
    $router->post('/api/v1/promotions', [$promotionController, 'createPromotion']);
    $router->get('/api/v1/promotions/{promotionId}', [$promotionController, 'getPromotion']);
    $router->patch('/api/v1/promotions/{promotionId}', [$promotionController, 'updatePromotion']);
    $router->delete('/api/v1/promotions/{promotionId}', [$promotionController, 'deletePromotion']);
    // Promotion Products
    $router->get('/api/v1/promotions/{promotionId}/products', [$promotionController, 'listProducts']);
    $router->get('/api/v1/promotion-products/{productId}', [$promotionController, 'getProduct']);
    $router->post('/api/v1/promotions/{promotionId}/products', [$promotionController, 'addProduct']);
    $router->patch('/api/v1/promotion-products/{productId}', [$promotionController, 'updateProduct']);
    $router->delete('/api/v1/promotion-products/{productId}', [$promotionController, 'deleteProduct']);
    // Promotion Postings
    $router->get('/api/v1/promotions/{promotionId}/postings', [$promotionController, 'listPostings']);
    $router->get('/api/v1/promotion-postings/{postingId}', [$promotionController, 'getPosting']);
    $router->post('/api/v1/promotions/{promotionId}/postings', [$promotionController, 'createPosting']);
    $router->patch('/api/v1/promotion-postings/{postingId}', [$promotionController, 'updatePosting']);
    $router->post('/api/v1/promotion-postings/{postingId}/review', [$promotionController, 'reviewPosting']);
    $router->delete('/api/v1/promotion-postings/{postingId}', [$promotionController, 'deletePosting']);
    $router->get('/api/v1/promotion-postings/review/pending', [$promotionController, 'getPendingReview']);
    // Promotion Extended Operations
    $router->post('/api/v1/promotions/{promotionId}/extend', [$promotionController, 'extendPromotion']);
    $router->post('/api/v1/promotions/{promotionId}/products/batch', [$promotionController, 'batchAddProducts']);
    $router->delete('/api/v1/promotions/{promotionId}/products/by-product/{productId}', [$promotionController, 'removeProductByProductId']);
    $router->post('/api/v1/promotions/upload-screenshot', [$promotionController, 'uploadScreenshot']);

    return $router;
}
