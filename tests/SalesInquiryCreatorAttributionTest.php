<?php

declare(strict_types=1);

// Sales Person is the customer's assigned agent; Prepared By is the creating account.
require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\SalesInquiryRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$repo = new SalesInquiryRepository($db);

$mainId = 1;
$creatorId = 64;

$creator = $pdo->prepare(
    "SELECT lid, TRIM(CONCAT(COALESCE(lfname, ''), ' ', COALESCE(llname, ''))) AS name
     FROM tblaccount WHERE lid = ? LIMIT 1"
);
$creator->execute([$creatorId]);
$creatorRow = $creator->fetch(PDO::FETCH_ASSOC);
if (!$creatorRow) {
    throw new RuntimeException('FAIL: creator account 64 not found');
}

$customer = $pdo->prepare(
    "SELECT p.lsessionid,
            p.lsales_person,
            TRIM(CONCAT(COALESCE(agent.lfname, ''), ' ', COALESCE(agent.llname, ''))) AS agent_name
     FROM tblpatient p
     INNER JOIN tblaccount agent ON agent.lid = CAST(p.lsales_person AS UNSIGNED)
     WHERE p.lmain_id = :main_id
       AND COALESCE(p.lsales_person, '') <> ''
       AND p.lsales_person <> :creator_id
     ORDER BY p.lsessionid ASC
     LIMIT 1"
);
$customer->execute([
    'main_id' => (string) $mainId,
    'creator_id' => (string) $creatorId,
]);
$customerRow = $customer->fetch(PDO::FETCH_ASSOC);
if (!$customerRow) {
    throw new RuntimeException('FAIL: no customer with a different default sales agent was found');
}

$customerSessionId = (string) $customerRow['lsessionid'];

$customerAgentId = (string) ($customerRow['lsales_person'] ?? '');
$customerAgentName = trim((string) ($customerRow['agent_name'] ?? ''));
if ($customerAgentId === '' || $customerAgentName === '' || $customerAgentId === (string) $creatorId) {
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

$ok = $salesPersonId === $customerAgentId
    && strcasecmp($salesPerson, $customerAgentName) === 0
    && (string) ($created['created_by'] ?? '') === (string) $creatorId
    && strcasecmp(trim((string) ($created['created_by_name'] ?? '')), $creatorName) === 0;

// The creator remains immutable while Sales Person keeps following the selected
// customer's assigned agent, regardless of fields submitted by the client.
$updated = $repo->updateInquiry($mainId, $inquiryRefno, [
    'sales_person' => 'APOSTOL ELLA',
    'sales_person_id' => $customerAgentId,
    'remarks' => 'creator attribution regression update',
]);
$updatePreservedAttribution = (string) ($updated['sales_person_id'] ?? '') === $customerAgentId
    && strcasecmp(trim((string) ($updated['sales_person'] ?? '')), $customerAgentName) === 0
    && (string) ($updated['created_by'] ?? '') === (string) $creatorId
    && strcasecmp(trim((string) ($updated['created_by_name'] ?? '')), $creatorName) === 0;

// Soft-cancel the temporary inquiry so the test does not leave active clutter.
if ($inquiryRefno !== '') {
    $repo->cancelInquiry($mainId, $inquiryRefno);
}

if (!$ok || !$updatePreservedAttribution) {
    throw new RuntimeException(
        'FAIL: expected salesperson=' . $customerAgentName . ' id=' . $customerAgentId
        . ' got salesperson=' . $salesPerson . ' id=' . $salesPersonId
        . ' inquiry=' . $inquiryNo
    );
}

echo "PASS: sales inquiry salesperson is the customer's assigned agent\n";
echo "PASS: sales inquiry creator is exposed separately and remains unchanged after an edit\n";
