<?php

declare(strict_types=1);

use App\Config;
use App\Controllers\CollectionController;
use App\Controllers\CustomerController;
use App\Controllers\DailyCallMonitoringController;
use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\SalesController;
use App\Http\Router;
use App\Support\Env;

require __DIR__ . '/Support/Env.php';
require __DIR__ . '/Support/Exceptions/HttpException.php';
require __DIR__ . '/Config.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Http/Response.php';
require __DIR__ . '/Http/Router.php';
require __DIR__ . '/Repositories/CustomerRepository.php';
require __DIR__ . '/Repositories/CollectionRepository.php';
require __DIR__ . '/Repositories/DailyCallMonitoringRepository.php';
require __DIR__ . '/Repositories/AuthRepository.php';
require __DIR__ . '/Repositories/SalesRepository.php';
require __DIR__ . '/Security/TokenService.php';
require __DIR__ . '/Controllers/HealthController.php';
require __DIR__ . '/Controllers/CustomerController.php';
require __DIR__ . '/Controllers/CollectionController.php';
require __DIR__ . '/Controllers/DailyCallMonitoringController.php';
require __DIR__ . '/Controllers/AuthController.php';
require __DIR__ . '/Controllers/SalesController.php';

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
    $collectionController = new CollectionController(new App\Repositories\CollectionRepository($db));
    $dailyCallMonitoringController = new DailyCallMonitoringController(new App\Repositories\DailyCallMonitoringRepository($db));
    $authController = new AuthController(
        new App\Repositories\AuthRepository($db),
        new App\Security\TokenService($config->authSecret, $config->authTokenTtlSeconds)
    );
    $salesController = new SalesController(new App\Repositories\SalesRepository($db));

    $router = new Router();
    $router->get('/api/v1/health', [$healthController, 'index']);
    $router->get('/api/v1/customers/{sessionId}', [$customerController, 'show']);
    $router->get('/api/v1/customers/{sessionId}/purchase-history', [$customerController, 'purchaseHistory']);
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
    $router->post('/api/v1/auth/login', [$authController, 'login']);
    $router->get('/api/v1/auth/me', [$authController, 'me']);
    $router->post('/api/v1/auth/logout', [$authController, 'logout']);
    $router->get('/api/v1/sales/flow/inquiry/{inquiryRefno}', [$salesController, 'flowByInquiry']);
    $router->get('/api/v1/sales/flow/so/{soRefno}', [$salesController, 'flowBySalesOrder']);

    return $router;
}
