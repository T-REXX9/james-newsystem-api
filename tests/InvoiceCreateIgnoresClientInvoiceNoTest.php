<?php

declare(strict_types=1);

/**
 * Contract: HTTP invoice create must not honor client invoice_no.
 * Manual numbers go through update_number + can_edit_invoice_number.
 */
$source = file_get_contents(__DIR__ . '/../src/Controllers/InvoiceController.php');
if ($source === false) {
    throw new RuntimeException('FAIL: unable to read InvoiceController.php');
}

if (!preg_match('/function create\s*\([^)]*\)[^{]*\{.*?unset\(\$body\[\'invoice_no\'\]\);/s', $source)) {
    throw new RuntimeException('FAIL: InvoiceController::create must unset client invoice_no');
}

echo "PASS: InvoiceController::create ignores client invoice_no\n";
