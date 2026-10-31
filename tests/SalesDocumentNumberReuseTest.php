<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/SalesDocumentNumberReuse.php';

use App\Support\SalesDocumentNumberReuse;

$checks = [
    'order slip unpost stores its document type with its number' => SalesDocumentNumberReuse::remember('N-D39172', 'Order Slip') === 'OS|N-D39172',
    'invoice unpost stores its document type with its number' => SalesDocumentNumberReuse::remember('T-1234', 'Invoice') === 'INV|T-1234',
    'a historically misnumbered invoice-style slip is not blessed as an order slip' => SalesDocumentNumberReuse::remember('T-1234', 'Order Slip') === '',
    'an order slip number is not blessed as an invoice number' => SalesDocumentNumberReuse::remember('N-D39172', 'Invoice') === '',
    'a previously tagged invoice-style slip is not reused after unposting' => SalesDocumentNumberReuse::forTarget('OS|T-1234', 'Order Slip') === '',
    'a previously tagged order slip number is not reused as an invoice' => SalesDocumentNumberReuse::forTarget('INV|N-D39172', 'Invoice') === '',
    'an unposted invoice number is never reused for an order slip' => SalesDocumentNumberReuse::forTarget('INV|T-1234', 'Order Slip') === '',
    'an unposted order slip number is never reused for an invoice' => SalesDocumentNumberReuse::forTarget('OS|N-D39172', 'Invoice') === '',
    'an unposted order slip can reuse its own number' => SalesDocumentNumberReuse::forTarget('OS|N-D39172', 'Order Slip') === 'N-D39172',
    'an unposted invoice can reuse its own number' => SalesDocumentNumberReuse::forTarget('INV|T-1234', 'Invoice') === 'T-1234',
    'legacy N-D numbers are recognized as order slips' => SalesDocumentNumberReuse::forTarget('N-D39172', 'Order Slip') === 'N-D39172',
    'legacy invoice numbers are rejected for order slips' => SalesDocumentNumberReuse::forTarget('T-1234', 'Order Slip') === '',
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Sales document number reuse: ' . count($checks) . '/' . count($checks) . " passed\n";
