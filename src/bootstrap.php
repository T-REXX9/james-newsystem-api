<?php

declare(strict_types=1);

use App\Config;
use App\Controllers\CollectionController;
use App\Controllers\CustomerController;
use App\Controllers\CustomerDatabaseController;
use App\Controllers\DailyCallMonitoringController;
use App\Controllers\HealthController;
use App\Controllers\InvoiceController;
use App\Controllers\OrderSlipController;
use App\Controllers\AuthController;
use App\Controllers\ProductController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\ReceivingStockController;
use App\Controllers\SalesController;
use App\Controllers\SalesInquiryController;
use App\Controllers\SalesOrderController;
use App\Controllers\StockMovementController;
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
require __DIR__ . '/Repositories/CollectionRepository.php';
require __DIR__ . '/Repositories/DailyCallMonitoringRepository.php';
require __DIR__ . '/Repositories/AuthRepository.php';
require __DIR__ . '/Repositories/ProductRepository.php';
require __DIR__ . '/Repositories/PurchaseOrderRepository.php';
require __DIR__ . '/Repositories/ReceivingStockRepository.php';
require __DIR__ . '/Repositories/OrderSlipRepository.php';
require __DIR__ . '/Repositories/InvoiceRepository.php';
require __DIR__ . '/Repositories/SalesRepository.php';
require __DIR__ . '/Repositories/SalesInquiryRepository.php';
require __DIR__ . '/Repositories/SalesOrderRepository.php';
require __DIR__ . '/Repositories/StockMovementRepository.php';
require __DIR__ . '/Security/TokenService.php';
require __DIR__ . '/Controllers/HealthController.php';
require __DIR__ . '/Controllers/CustomerController.php';
require __DIR__ . '/Controllers/CustomerDatabaseController.php';
require __DIR__ . '/Controllers/CollectionController.php';
require __DIR__ . '/Controllers/DailyCallMonitoringController.php';
require __DIR__ . '/Controllers/AuthController.php';
require __DIR__ . '/Controllers/ProductController.php';
require __DIR__ . '/Controllers/PurchaseOrderController.php';
require __DIR__ . '/Controllers/ReceivingStockController.php';
require __DIR__ . '/Controllers/OrderSlipController.php';
require __DIR__ . '/Controllers/InvoiceController.php';
require __DIR__ . '/Controllers/SalesController.php';
require __DIR__ . '/Controllers/SalesInquiryController.php';
require __DIR__ . '/Controllers/SalesOrderController.php';
require __DIR__ . '/Controllers/StockMovementController.php';

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
    $collectionController = new CollectionController(new App\Repositories\CollectionRepository($db));
    $dailyCallMonitoringController = new DailyCallMonitoringController(new App\Repositories\DailyCallMonitoringRepository($db));
    $authController = new AuthController(
        new App\Repositories\AuthRepository($db),
        new App\Security\TokenService($config->authSecret, $config->authTokenTtlSeconds)
    );
    $productController = new ProductController(new App\Repositories\ProductRepository($db));
    $purchaseOrderController = new PurchaseOrderController(new App\Repositories\PurchaseOrderRepository($db));
    $receivingStockController = new ReceivingStockController(new App\Repositories\ReceivingStockRepository($db));
    $orderSlipController = new OrderSlipController(new App\Repositories\OrderSlipRepository($db));
    $invoiceController = new InvoiceController(new App\Repositories\InvoiceRepository($db));
    $salesController = new SalesController(new App\Repositories\SalesRepository($db));
    $salesInquiryController = new SalesInquiryController(new App\Repositories\SalesInquiryRepository($db));
    $salesOrderController = new SalesOrderController(new App\Repositories\SalesOrderRepository($db));
    $stockMovementController = new StockMovementController(new App\Repositories\StockMovementRepository($db));

    $router = new Router();
    $router->get('/api/v1/health', [$healthController, 'index']);
    $router->get('/api/v1/customers/{sessionId}', [$customerController, 'show']);
    $router->get('/api/v1/customers/{sessionId}/purchase-history', [$customerController, 'purchaseHistory']);
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
    $router->get('/api/v1/collections/{collectionRefno}', [$collectionController, 'show']);
    $router->get('/api/v1/collections/{collectionRefno}/items', [$collectionController, 'items']);
    $router->get('/api/v1/collections/{collectionRefno}/approver-logs', [$collectionController, 'approverLogs']);
    $router->post('/api/v1/collections/{collectionRefno}/items/post', [$collectionController, 'postItems']);
    $router->post('/api/v1/collections/{collectionRefno}/payments', [$collectionController, 'addPayment']);
    $router->post('/api/v1/collections/{collectionRefno}/actions/{action}', [$collectionController, 'action']);
    $router->patch('/api/v1/collection-items/{itemId}', [$collectionController, 'updateItem']);
    $router->delete('/api/v1/collection-items/{itemId}', [$collectionController, 'deleteItem']);
    $router->get('/api/v1/daily-call-monitoring/excel', [$dailyCallMonitoringController, 'excelRows']);
    $router->get('/api/v1/daily-call-monitoring/owner-snapshot', [$dailyCallMonitoringController, 'ownerSnapshot']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/purchase-history', [$dailyCallMonitoringController, 'customerPurchaseHistory']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/sales-reports', [$dailyCallMonitoringController, 'customerSalesReports']);
    $router->get('/api/v1/daily-call-monitoring/customers/{contactId}/incident-reports', [$dailyCallMonitoringController, 'customerIncidentReports']);
    $router->get('/api/v1/products', [$productController, 'list']);
    $router->get('/api/v1/products/{productSession}', [$productController, 'show']);
    $router->post('/api/v1/products', [$productController, 'create']);
    $router->patch('/api/v1/products/{productSession}', [$productController, 'update']);
    $router->post('/api/v1/products/bulk-update', [$productController, 'bulkUpdate']);
    $router->delete('/api/v1/products/{productSession}', [$productController, 'delete']);
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
    $router->get('/api/v1/stock-movements', [$stockMovementController, 'list']);
    $router->get('/api/v1/stock-movements/{logId}', [$stockMovementController, 'show']);
    $router->post('/api/v1/stock-movements', [$stockMovementController, 'create']);
    $router->patch('/api/v1/stock-movements/{logId}', [$stockMovementController, 'update']);
    $router->delete('/api/v1/stock-movements/{logId}', [$stockMovementController, 'delete']);
    $router->post('/api/v1/auth/login', [$authController, 'login']);
    $router->get('/api/v1/auth/me', [$authController, 'me']);
    $router->post('/api/v1/auth/logout', [$authController, 'logout']);
    $router->get('/api/v1/sales/flow/inquiry/{inquiryRefno}', [$salesController, 'flowByInquiry']);
    $router->get('/api/v1/sales/flow/so/{soRefno}', [$salesController, 'flowBySalesOrder']);
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

    return $router;
}
