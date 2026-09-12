<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Exceptions/HttpException.php';
require __DIR__ . '/../src/Support/SalesReportAttachmentStore.php';

use App\Support\Exceptions\HttpException;
use App\Support\SalesReportAttachmentStore;

$passed = 0;
$failed = 0;
$errors = [];

function assert_true(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

function expect_http(callable $fn, int $status, string $message, int &$passed, int &$failed, array &$errors): void
{
    try {
        $fn();
        $failed++;
        $errors[] = "{$message} (expected HttpException {$status})";
        echo "  FAIL {$message}\n";
    } catch (HttpException $e) {
        assert_true($e->statusCode() === $status, $message, $passed, $failed, $errors);
    }
}

echo "==========================================================\n";
echo " Sales Report Attachment Store Test\n";
echo "==========================================================\n\n";

$pngTiny = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$decoded = SalesReportAttachmentStore::decodeImageData($pngTiny);
assert_true($decoded['mime'] === 'image/png', 'Decodes PNG data URL mime', $passed, $failed, $errors);
assert_true($decoded['ext'] === 'png', 'Decodes PNG data URL extension', $passed, $failed, $errors);
assert_true($decoded['binary'] !== '', 'Decodes PNG binary', $passed, $failed, $errors);

expect_http(
    static fn () => SalesReportAttachmentStore::decodeImageData('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'),
    422,
    'Rejects disallowed GIF mime',
    $passed,
    $failed,
    $errors
);

expect_http(
    static fn () => SalesReportAttachmentStore::decodeImageData('not-base64!!!'),
    422,
    'Rejects invalid image payload',
    $passed,
    $failed,
    $errors
);

expect_http(
    static fn () => SalesReportAttachmentStore::assertImageMime('application/pdf'),
    422,
    'Rejects non-image attachment mime',
    $passed,
    $failed,
    $errors
);

SalesReportAttachmentStore::assertBelongsToContact('shop1_1234_abcd.png', 'shop1');
assert_true(true, 'Accepts attachment filename prefixed by contact id', $passed, $failed, $errors);

expect_http(
    static fn () => SalesReportAttachmentStore::assertBelongsToContact('other_1234_abcd.png', 'shop1'),
    403,
    'Rejects attachment filename for a different contact',
    $passed,
    $failed,
    $errors
);

assert_true(
    SalesReportAttachmentStore::sanitizeFilename('../etc/passwd') === 'passwd',
    'Normalizes path-like input to basename',
    $passed,
    $failed,
    $errors
);
expect_http(
    static fn () => SalesReportAttachmentStore::sanitizeFilename('bad name.png'),
    422,
    'Rejects filenames with spaces',
    $passed,
    $failed,
    $errors
);

$path = SalesReportAttachmentStore::buildApiPath('contact-1', 'contact-1_1_abcd.jpg');
assert_true(
    $path === '/api/v1/daily-call-monitoring/customers/contact-1/sales-report-attachments/contact-1_1_abcd.jpg',
    'Builds authenticated API attachment path',
    $passed,
    $failed,
    $errors
);

$controllerSrc = (string) file_get_contents(dirname(__DIR__) . '/src/Controllers/DailyCallMonitoringController.php');
$bootstrapSrc = (string) file_get_contents(dirname(__DIR__) . '/src/bootstrap.php');
$repoSrc = (string) file_get_contents(dirname(__DIR__) . '/src/Repositories/CallReportRepository.php');

assert_true(str_contains($controllerSrc, 'function downloadSalesReportAttachment'), 'Controller exposes authenticated attachment download', $passed, $failed, $errors);
assert_true(str_contains($controllerSrc, 'function salesReportUnreadCounts'), 'Controller exposes unread counts endpoint', $passed, $failed, $errors);
assert_true(str_contains($bootstrapSrc, 'sales-report-attachments/{filename}'), 'Router registers attachment download route', $passed, $failed, $errors);
assert_true(str_contains($bootstrapSrc, 'sales-report-unread-counts'), 'Router registers unread counts route', $passed, $failed, $errors);
assert_true(str_contains($repoSrc, 'SalesReportAttachmentStore'), 'Repository stores attachments privately via SalesReportAttachmentStore', $passed, $failed, $errors);
assert_true(!str_contains($repoSrc, 'public/uploads/call-report-attachments'), 'Repository no longer writes public upload URLs for new attachments', $passed, $failed, $errors);

echo "\n==========================================================\n";
echo " Results: {$passed} passed, {$failed} failed\n";
echo "==========================================================\n";

if ($failed > 0) {
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

echo "\nAll checks passed.\n";
