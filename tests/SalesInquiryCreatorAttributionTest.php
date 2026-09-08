<?php

declare(strict_types=1);

// Creating a sales inquiry must attribute Sales Person to the creating account,
// never the customer's default assigned agent (accountability bug: test@... -> APOSTOL ELLA).
require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\SalesInquiryRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$repo = new SalesInquiryRepository($db);

$mainId = 1;
$creatorId = 64;
$customerSessionId = '30506727420200807132632';

$creator = $pdo->prepare(
    "SELECT lid, TRIM(CONCAT(COALESCE(lfname, ''), ' ', COALESCE(llname, ''))) AS name
     FROM tblaccount WHERE lid = ? LIMIT 1"
);
$creator->execute([$creatorId]);
$creatorRow = $creator->fetch(PDO::FETCH_ASSOC);
if (!$creatorRow) {
    throw new RuntimeException('FAIL: creator account 64 not found');
}

$customer = $pdo->prepare('SELECT lsessionid, lsales_person FROM tblpatient WHERE lsessionid = ? LIMIT 1');
$customer->execute([$customerSessionId]);
$customerRow = $customer->fetch(PDO::FETCH_ASSOC);
if (!$customerRow) {
    throw new RuntimeException('FAIL: customer fixture not found');
}

$customerAgentId = (string) ($customerRow['lsales_person'] ?? '');
if ($customerAgentId === '' || $customerAgentId === (string) $creatorId) {
    throw new RuntimeException('FAIL: fixture customer must have a different default sales agent');
}

$created = $repo->createInquiry($mainId, $creatorId, [
    'contact_id' => $customerSessionId,
    // Hostile payload: pretend the form still sent the customer's agent.
    'sales_person' => 'APOSTOL ELLA',
    'sales_person_id' => $customerAgentId,
    'items' => [[
        'item_id' => 'test-item',
        'part_no' => 'TEST-PART',
        'item_code' => 'TEST-CODE',
        'description' => 'Attribution regression item',
        'location' => '',
        'qty' => 1,
        'unit_price' => 1,
        'remark' => '',
    ]],
]);

$inquiryRefno = (string) ($created['inquiry_refno'] ?? '');
$inquiryNo = (string) ($created['inquiry_no'] ?? '');
$salesPerson = trim((string) ($created['sales_person'] ?? ''));
$salesPersonId = (string) ($created['sales_person_id'] ?? '');
$creatorName = trim((string) ($creatorRow['name'] ?? ''));

$ok = $salesPersonId === (string) $creatorId
    && strcasecmp($salesPerson, $creatorName) === 0
    && $salesPersonId !== $customerAgentId;

// Soft-cancel the temporary inquiry so the test does not leave active clutter.
if ($inquiryRefno !== '') {
    $repo->cancelInquiry($mainId, $inquiryRefno);
}

if (!$ok) {
    throw new RuntimeException(
        'FAIL: expected salesperson=' . $creatorName . ' id=' . $creatorId
        . ' got salesperson=' . $salesPerson . ' id=' . $salesPersonId
        . ' inquiry=' . $inquiryNo
    );
}

echo "PASS: sales inquiry salesperson is the creating user, not the customer agent\n";
