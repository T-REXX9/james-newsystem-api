<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Repositories/TransferStockRepository.php';
require __DIR__ . '/../src/Support/Exceptions/HttpException.php';
require __DIR__ . '/../src/Controllers/TransferStockController.php';

use App\Controllers\TransferStockController;
use App\Support\Exceptions\HttpException;

$passed = 0;
$failed = 0;
$controller = (new ReflectionClass(TransferStockController::class))->newInstanceWithoutConstructor();

$cases = [
    ['create', [], [], []],
    ['update', [], [], []],
    ['delete', [], [], []],
    ['addItem', [], [], []],
    ['updateItem', [], [], []],
    ['deleteItem', [], [], []],
    ['action', [], [], []],
];

foreach ($cases as [$method, $params, $query, $body]) {
    try {
        $controller->{$method}($params, $query, $body);
        $failed++;
        echo "  FAIL {$method} should reject disabled transfers\n";
    } catch (HttpException $exception) {
        $correctStatus = $exception->statusCode() === 410;
        $clearReason = str_contains(strtolower($exception->getMessage()), 'centralized quantity');
        if ($correctStatus && $clearReason) {
            $passed++;
            echo "  PASS {$method} rejects disabled transfers\n";
        } else {
            $failed++;
            echo "  FAIL {$method} returned the wrong disabled-transfer response\n";
        }
    }
}

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
