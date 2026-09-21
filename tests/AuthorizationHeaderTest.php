<?php

declare(strict_types=1);

/**
 * Authorization header resolution across alternate CGI/server keys.
 *
 * Run: php api/tests/AuthorizationHeaderTest.php
 */

require __DIR__ . '/../src/Http/AuthorizationHeader.php';

use App\Http\AuthorizationHeader;

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
        return;
    }
    $failed++;
    echo "  [FAIL] {$message}\n";
};

echo "AuthorizationHeader\n";

$_SERVER = [];
$assert(AuthorizationHeader::value() === '', 'empty when no header present');
$assert(AuthorizationHeader::bearerToken() === null, 'no bearer token when header missing');

$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirect-token';
$assert(AuthorizationHeader::value() === 'Bearer redirect-token', 'reads REDIRECT_HTTP_AUTHORIZATION');
$assert(AuthorizationHeader::bearerToken() === 'redirect-token', 'parses bearer from redirect header');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer primary-token';
$assert(AuthorizationHeader::bearerToken() === 'primary-token', 'prefers HTTP_AUTHORIZATION');

$_SERVER['HTTP_AUTHORIZATION'] = 'Token not-bearer';
$assert(AuthorizationHeader::bearerToken() === null, 'rejects non-bearer schemes');

echo sprintf("AuthorizationHeader: %d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
