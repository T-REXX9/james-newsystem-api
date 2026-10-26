<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Controllers\DailyCallMonitoringController;
use App\Database;
use App\Repositories\CallReportRepository;
use App\Repositories\CustomerDatabaseRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\DailyCallMonitoringRepository;
use App\Support\Exceptions\HttpException;

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/src/Controllers/DailyCallMonitoringController.php');
$bulkStart = strpos($controller, 'public function setDoNotContactFlagsBulk');
$bulkEnd = strpos($controller, 'public function customerPurchaseHistory', $bulkStart ?: 0);
$bulkMethod = $bulkStart === false || $bulkEnd === false ? '' : substr($controller, $bulkStart, $bulkEnd - $bulkStart);
$assert = static function (bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};

$assert($bulkMethod !== '', 'bulk endpoint method is present');
$assert(
    strpos($bulkMethod, "(string) (\$claims['user_type'] ?? '') !== '1'") < strpos($bulkMethod, "\$this->repo->setDoNotContactFlagsBulk"),
    'bulk request rejects non-Master-User claims before repository writes'
);
$assert(
    str_contains($bulkMethod, 'Only a Master User can update Do Not Call flags')
        && str_contains($bulkMethod, "\$claims['main_userid'] ?? 0")
        && str_contains($bulkMethod, 'assertCustomerViewAccess($mainId, $contactId, $userId)'),
    'bulk endpoint enforces master role, tenant scope, and access to each selected customer'
);
$assert(
    str_contains($bulkMethod, 'setDoNotContactFlagsBulk($mainId, $contactIds, $userId, $reason)'),
    'bulk endpoint applies one validated reason to the full selected customer set'
);
$assert(
    str_contains($controller, 'private function validatedDoNotContactReason')
        && str_contains($controller, "'Other reasons'")
        && str_contains($controller, "'reason_option'"),
    'single and bulk APIs validate dropdown options and require a reason'
);

$config = new Config('test', false, '*', 'test-secret', 60, '127.0.0.1', 3306, 'unused', 'unused', 'unused');
$db = new Database($config);
$controllerInstance = new DailyCallMonitoringController(
    new DailyCallMonitoringRepository($db),
    new CallReportRepository($db),
    new CustomerDatabaseRepository($db),
    new CustomerRepository($db)
);
try {
    $controllerInstance->setDoNotContactFlagsBulk([], [], [
        'main_id' => 10,
        '__auth_claims' => ['sub' => 20, 'main_userid' => 10, 'user_type' => '2'],
        'contact_ids' => ['customer-1'],
        'reason' => 'Not interested',
    ]);
    $assert(false, 'non-Master-User bulk request is denied');
} catch (HttpException $error) {
    $assert($error->statusCode() === 403, 'non-Master-User bulk request is denied before database access');
}
try {
    $controllerInstance->setDoNotContactFlagsBulk([], [], [
        'main_id' => 10,
        '__auth_claims' => ['sub' => 20, 'main_userid' => 10, 'user_type' => '1'],
        'contact_ids' => ['customer-1'],
        'reason_option' => 'Unknown option',
        'reason' => 'Unknown option',
    ]);
    $assert(false, 'invalid bulk dropdown option is denied');
} catch (HttpException $error) {
    $assert($error->statusCode() === 422, 'invalid bulk dropdown option is denied before database access');
}
try {
    $controllerInstance->setDoNotContactFlagsBulk([], [], [
        'main_id' => 10,
        '__auth_claims' => ['sub' => 20, 'main_userid' => 10, 'user_type' => '1'],
        'contact_ids' => ['customer-1'],
        'reason_option' => ['Not interested'],
        'reason' => ['Not interested'],
    ]);
    $assert(false, 'non-string bulk dropdown payload is denied');
} catch (HttpException $error) {
    $assert($error->statusCode() === 422, 'non-string bulk dropdown payload is denied without PHP coercion warnings');
}

echo "Daily Call Do Not Call bulk authorization contract passed\n";
