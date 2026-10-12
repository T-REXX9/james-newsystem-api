<?php

declare(strict_types=1);
// Uses connection-local TEMPORARY tables cloned from the installed schema.
// No customer, sales, audit, or recovery rows in the persistent database are modified.
require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\AuthRepository;
use App\Repositories\CustomerRequestRepository;
use App\Repositories\CustomerDatabaseRepository;
use App\Repositories\LocalRecycleBinRepository;
use App\Repositories\ProductRepository;
use App\Repositories\NotificationsRepository;
use App\Controllers\CustomerWorkflowController;
use App\Controllers\CustomerDatabaseController;
use App\Controllers\DailyCallMonitoringController;
use App\Support\Exceptions\HttpException;
use App\Repositories\DailyCallMonitoringRepository;
use App\Repositories\CallReportRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\CallSystemRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
foreach (['customer_requests','tblpatient','tblcontact_person','tblpatient_terms','tblpatient_image','tblaccount','tblusertype','tblaudit_trail','tblnotifications','tblinventory_item','tblinquiry','tblinquiry_item','tblcredit_memo','tblcredit_return_item','tblpr_list','tblpo_list','tblpurchase_order'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
}
$pdo->exec('CREATE TEMPORARY TABLE tblpatient_created_at (lmain_id INT NOT NULL, lsessionid VARCHAR(64) NOT NULL, created_at DATETIME NULL DEFAULT NULL, PRIMARY KEY (lmain_id, lsessionid))');
$pdo->exec('CREATE TEMPORARY TABLE call_report_threads (id INT AUTO_INCREMENT PRIMARY KEY, main_id INT NOT NULL, contact_id VARCHAR(100) NOT NULL, call_log_entry_id INT NOT NULL, call_log_refno VARCHAR(100) NOT NULL, agent_user_id INT NOT NULL, agent_name VARCHAR(100) NOT NULL, outcome VARCHAR(40) NOT NULL, report_body TEXT NOT NULL, concern TEXT NULL, `action` TEXT NULL, report_deleted_at DATETIME NULL, created_at DATETIME NOT NULL, call_started_at DATETIME NULL, call_ended_at DATETIME NULL, duration_seconds INT NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TEMPORARY TABLE call_report_messages (id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, sender_user_id INT NOT NULL, sender_name VARCHAR(100) NOT NULL, sender_role VARCHAR(20) NOT NULL, body TEXT NOT NULL, attachment_url TEXT NULL, attachment_mime VARCHAR(100) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL)');
$pdo->exec('CREATE TEMPORARY TABLE call_report_read_states (thread_id INT NOT NULL, user_id INT NOT NULL, last_read_at DATETIME NULL, PRIMARY KEY (thread_id, user_id))');
$pdo->exec("ALTER TABLE customer_requests MODIFY kind ENUM('customer_update','discount','duplicate_prospect') NOT NULL");
$insert = static function (string $table, array $values) use ($pdo): void {
    foreach ($pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll() as $column) {
        $name = $column['Field'];
        if (array_key_exists($name, $values) || $column['Null'] === 'YES' || $column['Default'] !== null || str_contains($column['Extra'], 'auto_increment')) continue;
        $type = strtolower($column['Type']);
        $values[$name] = preg_match('/int|decimal|float|double|bit/', $type) ? 0 : (str_contains($type, 'date') ? '2026-01-01' : '');
        if (preg_match("/^enum\('([^']+)'/", $type, $matches)) $values[$name] = $matches[1];
    }
    $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($values));
};
$passed = 0;
$assert = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    $passed++; echo "PASS: {$label}\n";
};
$reject = static function (callable $fn, int $status, string $label) use ($assert): void {
    try { $fn(); } catch (HttpException $e) { $assert($e->statusCode() === $status, $label); return; }
    throw new RuntimeException("FAIL: {$label} was accepted");
};
$main = 900001; $agent = 900002; $outsider = 900003; $customer = 'LOCAL-WORKFLOW-TEST-CUSTOMER';
$insert('tblaccount', ['lid'=>$main,'ltype'=>'1','lstatus'=>1,'lfname'=>'Test','llname'=>'Owner']);
$insert('tblusertype', ['lid'=>987654,'ltype_name'=>'Sales Agent']);
$insert('tblaccount', ['lid'=>$agent,'ltype'=>'987654','lmother_id'=>$main,'lstatus'=>1,'lfname'=>'Test','llname'=>'Agent']);
$insert('tblaccount', ['lid'=>$outsider,'ltype'=>'1','lstatus'=>1,'lfname'=>'Other','llname'=>'Owner']);
$insert('tblpatient', ['lid'=>910001,'lmain_id'=>$main,'lsessionid'=>$customer,'lcompany'=>'Before','lstatus'=>1,'lphone'=>'09123456789','lmobile'=>'09123456789','lsales_person'=>(string)$agent]);
$insert('tblcontact_person', ['lid'=>920001,'lrefno'=>$customer,'lfname'=>'Original']);
$insert('tblpatient_terms', ['lid'=>920002,'lpatient'=>$customer]);
$insert('tblpatient_image', ['lid'=>920003,'lrefno'=>$customer]);
$claims = static fn(int $user, int $tenant) => ['__auth_claims'=>['sub'=>$user,'main_userid'=>$tenant]];
$ownerClaims = $claims($main, $main); $agentClaims = $claims($agent, $main);
$repo = new CustomerRequestRepository($db);
$controller = new CustomerWorkflowController($db, new AuthRepository($db));
$customerController = new CustomerDatabaseController(new CustomerDatabaseRepository($db), $db);
$params = ['contactId'=>$customer]; $query = ['main_id'=>$main];

$created = $controller->createRequest($params, $query, $agentClaims + ['kind'=>'customer_update','payload'=>['company'=>'After']]);
$assert($repo->customer($main, $customer)['company'] === 'Before', 'submission does not bypass owner approval');
$assert(count($controller->requests($params, $query, $ownerClaims)) === 1, 'owner sees the persisted request');
$assert(count($controller->allRequests([], $query, $ownerClaims)) === 1, 'owner sees the centralized customer detail update queue');
$reject(fn() => $controller->allRequests([], $query, $agentClaims), 403, 'agent cannot view the centralized customer detail update queue');
$reject(fn() => $controller->reviewRequest($params + ['requestId'=>$created['id']], $query, $agentClaims + ['decision'=>'approved']), 403, 'agent cannot self-approve');
$reject(fn() => $controller->requests($params, ['main_id'=>$outsider], $agentClaims), 403, 'tenant query cannot override authenticated tenant');
$reject(fn() => $controller->requests($params, [], $claims($outsider, $outsider)), 404, 'other tenant cannot read customer requests');
$reject(fn() => $controller->requests($params, $query, []), 401, 'missing authentication rejected');
$controller->reviewRequest($params + ['requestId'=>$created['id']], $query, $ownerClaims + ['decision'=>'approved']);
$assert($repo->customer($main, $customer)['company'] === 'After', 'approval updates the local customer record');
$decisionNotifications = (new NotificationsRepository($db))->listByUser((string) $agent);
$decisionMetadata = $decisionNotifications[0]['metadata'] ?? [];
$assert(($decisionMetadata['conversation_type'] ?? '') === 'agent_sales_report', 'approval decision notification opens the agent sales report conversation');
$reject(fn() => $controller->reviewRequest($params + ['requestId'=>$created['id']], $query, $ownerClaims + ['decision'=>'approved']), 409, 'duplicate review is rejected');

$blacklistRequest = $controller->createRequest($params, $query, $agentClaims + [
    'kind' => 'customer_update',
    'payload' => ['status' => 1, 'debt_type' => 'Bad', 'notes' => 'Customer requested no further calls'],
]);
$controller->reviewRequest($params + ['requestId'=>$blacklistRequest['id']], $query, $ownerClaims + ['decision'=>'approved']);
$blacklistedCustomer = $repo->customer($main, $customer);
$assert((int) ($blacklistedCustomer['status'] ?? 0) === 1 && strtolower((string) ($blacklistedCustomer['debt_type'] ?? '')) === 'bad', 'approved Do Not Contact request persists blacklisted debt type');
$callSystemRepository = new CallSystemRepository($db);
$matchedCustomerId = $callSystemRepository->findCustomerIdByPhone($main, '09123456789');
$assert($matchedCustomerId !== null && $callSystemRepository->isCustomerDoNotContact($main, $matchedCustomerId), 'dial lookup recognizes approved Do Not Contact customer');
$insert('tblpatient', ['lid'=>910004,'lmain_id'=>$main,'lsessionid'=>'LOCAL-WORKFLOW-SHARED-PHONE','lcompany'=>'Shared Phone Active Customer','lstatus'=>1,'ldebt_type'=>'Good','lphone'=>'09123456789','lmobile'=>'09123456789']);
$preferredCustomerId = $callSystemRepository->findCustomerIdByPhone($main, '09123456789');
$assert($preferredCustomerId !== null && $callSystemRepository->isCustomerDoNotContact($main, $preferredCustomerId), 'unscoped shared-phone lookup prioritizes a Do Not Contact match');
$selectedActiveId = $callSystemRepository->findCustomerIdByPhone($main, '09123456789', 'LOCAL-WORKFLOW-SHARED-PHONE');
$assert($selectedActiveId === 910004 && !$callSystemRepository->isCustomerDoNotContact($main, $selectedActiveId), 'selected customer lookup remains scoped to that customer');
$pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = ? AND lid = ?')->execute([$main, 910004]);
$dailyCallController = new DailyCallMonitoringController(
    new DailyCallMonitoringRepository($db),
    new CallReportRepository($db),
    new CustomerDatabaseRepository($db),
    new CustomerRepository($db)
);
$reject(fn() => $dailyCallController->createSalesReportMessage($params, [], $agentClaims + ['main_id'=>$main, 'body'=>'Should be blocked']), 409, 'sales report message endpoint blocks blacklisted customers');
$pdo->prepare('INSERT INTO call_report_threads (main_id, contact_id, call_log_entry_id, call_log_refno, agent_user_id, agent_name, outcome, report_body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$main, $customer, 990001, 'QA-DNC-REPORT', $agent, 'Test Agent', 'note', 'Existing call report']);
$reject(fn() => $dailyCallController->createCallReportReply(['threadId'=>'1'], [], $agentClaims + ['main_id'=>$main, 'body'=>'Should be blocked']), 409, 'call report thread reply endpoint blocks blacklisted customers');

// A staff-created unverified prospect is implicitly assigned to its creator,
// and that assignment must be date-stamped for the Master User's Daily Call list.
$createdProspect = $customerController->create([], [], $agentClaims + [
    'main_id' => $main,
    'user_id' => $agent,
    'company' => 'LOCAL-STAFF-PROSPECT-' . $agent,
    'status' => 3,
    'profile_type' => 'Prospect',
    'verification' => 'Unverified',
    'refer_by' => 'Google Search',
]);
$createdProspectRow = $pdo->query(
    "SELECT lsales_person, ldate_assigned FROM tblpatient WHERE lmain_id = {$main} AND lsessionid = '"
    . addslashes((string) ($createdProspect['session_id'] ?? '')) . "' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: [];
$assert((string) ($createdProspectRow['lsales_person'] ?? '') === (string) $agent, 'staff-created prospect is assigned to its creator');
$assert(substr((string) ($createdProspectRow['ldate_assigned'] ?? ''), 0, 10) === date('Y-m-d'), 'staff-created prospect records today as assignment date');

$createdOwnerProspect = $customerController->create([], [], $ownerClaims + [
    'main_id' => $main,
    'user_id' => $main,
    'company' => 'LOCAL-OWNER-PROSPECT-' . $main,
    'status' => 3,
    'profile_type' => 'Prospect',
    'verification' => 'Unverified',
    'refer_by' => 'Google Search',
]);
$createdOwnerProspectRow = $pdo->query(
    "SELECT lsales_person, ldate_assigned FROM tblpatient WHERE lmain_id = {$main} AND lsessionid = '"
    . addslashes((string) ($createdOwnerProspect['session_id'] ?? '')) . "' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: [];
$assert((string) ($createdOwnerProspectRow['lsales_person'] ?? '') === (string) $main, 'Master User-created prospect is assigned to its creator');
$assert(substr((string) ($createdOwnerProspectRow['ldate_assigned'] ?? ''), 0, 10) === date('Y-m-d'), 'Master User-created prospect records today as assignment date');

// A staff member's duplicate prospect with a reason is created immediately as unverified.
$existingProspect = 'EXISTING-DUPLICATE-PROSPECT';
$insert('tblpatient', ['lid'=>910010,'lmain_id'=>$main,'lsessionid'=>$existingProspect,'lcompany'=>'Duplicate Prospect Co','lstatus'=>3,'lprofile_type'=>'Prospect','lverification'=>'Unverified']);
$duplicatePayload = $agentClaims + [
    'main_id' => $main,
    'user_id' => $agent,
    'company' => 'Duplicate Prospect Co',
    'status' => 3,
    'profile_type' => 'Prospect',
    'refer_by' => 'Google Search',
    'duplicate_override_reason' => 'Separate branch with a different contact number',
];
$createdDuplicate = $customerController->create([], [], $duplicatePayload);
$assert(!($createdDuplicate['pending_approval'] ?? false), 'staff duplicate prospect with a reason is created without approval request');
$assert((int) $pdo->query("SELECT COUNT(*) FROM tblpatient WHERE lmain_id = {$main} AND lcompany = 'Duplicate Prospect Co'")->fetchColumn() === 2, 'duplicate prospect with a reason is created immediately');
$reject(fn() => $customerController->create([], [], array_diff_key($duplicatePayload, ['duplicate_override_reason'=>true,'duplicate_override_confirmed'=>true])), 422, 'duplicate prospect requires a reason');
$createdDuplicateRow = $pdo->query(
    "SELECT lduplicate_override_reason, lstatus, lverification FROM tblpatient WHERE lmain_id = {$main} AND lsessionid = '"
    . addslashes((string) ($createdDuplicate['session_id'] ?? '')) . "' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: [];
$assert((string) ($createdDuplicateRow['lduplicate_override_reason'] ?? '') === 'Separate branch with a different contact number', 'created duplicate prospect retains the submitted reason');
$assert((int) ($createdDuplicateRow['lstatus'] ?? 0) === 3 && (string) ($createdDuplicateRow['lverification'] ?? '') === 'Unverified', 'created duplicate prospect remains unverified');

// A notification outage must not make a persisted request or decision look like a failure.
$pdo->exec('DROP TEMPORARY TABLE tblnotifications');
$notificationOutage = $controller->createRequest($params, $query, $agentClaims + ['kind'=>'customer_update','payload'=>['company'=>'After notification outage']]);
$assert(($notificationOutage['status'] ?? '') === 'pending', 'request submission succeeds when notifications are unavailable');
$notificationOutageDecision = $controller->reviewRequest($params + ['requestId'=>$notificationOutage['id']], $query, $ownerClaims + ['decision'=>'approved']);
$assert(($notificationOutageDecision['status'] ?? '') === 'approved' && $repo->customer($main, $customer)['company'] === 'After notification outage', 'approval succeeds when requester notification is unavailable');

// Profile types have legacy variants; every verified prospect must be protected.
$pdo->prepare('UPDATE tblpatient SET lprofile_type = ?, lverification = ? WHERE lmain_id = ? AND lsessionid = ?')->execute(['Prospective Client', 'Verified', $main, $customer]);
$reject(fn() => $repo->create($main, $customer, $agent, 'customer_update', ['debt_type'=>'Bad','notes'=>'Verified prospect cannot be blacklisted']), 422, 'verified prospective-client variants cannot be blacklisted');
$conflict = $repo->create($main, $customer, $agent, 'customer_update', ['company'=>'Stale']);
$pdo->prepare('UPDATE tblpatient SET lcompany=? WHERE lsessionid=?')->execute(['Newer', $customer]);
$reject(fn() => $repo->review($main, $customer, $conflict['id'], $main, 'approved', ''), 409, 'stale request cannot overwrite a newer edit');
$assert($repo->customer($main, $customer)['company'] === 'Newer', 'conflicting review leaves data intact');
$repo->review($main, $customer, $conflict['id'], $main, 'rejected', 'Please resubmit');
$discount = $repo->create($main, $customer, $agent, 'discount', ['discount_percentage'=>7,'reason'=>'Test discount request']);
$repo->review($main, $customer, $discount['id'], $main, 'approved', 'Authorized');
$assert(count(array_filter($repo->list($main, $customer), fn($r)=>$r['kind']==='discount' && $r['status']==='approved')) === 1, 'discount authorization persists');
$reject(fn()=> $repo->create($main, $customer, $agent, 'discount', ['discount_percentage'=>101,'reason'=>'Test discount request']),422,'out-of-range discount rejected');
$reject(fn()=> $repo->create($main, $customer, $agent, 'customer_update', ['lpassword'=>'bad']),422,'unsupported customer fields rejected');
$reject(fn()=> $repo->create($main, $customer, $agent, 'customer_update', ['phone'=>'1234567890123456']),422,'oversize phone customer-update rejected');
$reject(fn()=> $repo->create($main, $customer, $agent, 'customer_update', ['mobile'=>'1234567890123456']),422,'oversize mobile customer-update rejected');
$phoneRequest = $repo->create($main, $customer, $agent, 'customer_update', ['phone'=>'09171234567']);
$assert(($phoneRequest['status'] ?? '') === 'pending', 'valid phone customer-update is accepted');
$reject(fn()=> $repo->create($main, $customer, $agent, 'customer_update', ['contacts'=>[['id'=>'12345','first_name'=>'Other']]]),422,'foreign contact-person ID rejected');
$people = $repo->create($main, $customer, $agent, 'customer_update', ['contacts'=>[['id'=>'920001','first_name'=>'Changed']]]);
$repo->review($main,$customer,$people['id'],$main,'approved','');
$assert($repo->customer($main,$customer)['contacts'][0]['lfname']==='Changed','contact-person changes apply through approval');
$assert((int)$pdo->query('SELECT COUNT(*) FROM tblaudit_trail')->fetchColumn()>0,'reviews persist audit entries');

// Exact customer scoping, all-date return history, and list pagination use existing repositories.
$insert('tblinquiry',['lid'=>930001,'lmain_id'=>$main,'lcustomerid'=>$customer,'lrefno'=>'LOCAL-INQ-1','linqno'=>'LOCAL-I1']);
$insert('tblinquiry',['lid'=>930002,'lmain_id'=>$main,'lcustomerid'=>'OTHER','lrefno'=>'LOCAL-INQ-OTHER']);
$inquiries=$controller->inquiries($params, $query, $agentClaims);
$assert(count($inquiries['items'])===1 && $inquiries['items'][0]['inquiry_refno']==='LOCAL-INQ-1','inquiry history is scoped by exact customer ID');
$insert('tblcredit_memo',['lid'=>940001,'lmainid'=>$main,'lcustomer'=>$customer,'lrefno'=>'LOCAL-RETURN-1','lcredit_no'=>'LOCAL-R1','ldate'=>'2020-01-01']);
$insert('tblcredit_memo',['lid'=>940002,'lmainid'=>$main,'lcustomer'=>'OTHER','lrefno'=>'LOCAL-RETURN-OTHER','ldate'=>'2020-01-01']);
$returns=$controller->returns($params, $query, $agentClaims);
$assert(count($returns['items'])===1 && $returns['items'][0]['lrefno']==='LOCAL-RETURN-1','return history includes old dates and excludes other customers');

$recovery = new LocalRecycleBinRepository($db);
$customers = new CustomerDatabaseRepository($db);
$assert($customers->deleteCustomer($main,$customer),'customer deletion marks the row as deleted');
$entries=$recovery->list($main);
$assert(count($entries)===1 && ($entries[0]['item_type'] ?? '')==='contact','recycle bin includes soft-deleted customers');
$assert($customers->getCustomer($main,$customer)===null,'soft-deleted customer is hidden from normal detail lookups');
$assert((int)$pdo->query("SELECT COUNT(*) FROM tblcontact_person WHERE lrefno='{$customer}'")->fetchColumn()===1,'customer contact people remain attached after soft delete');
$reject(fn()=> $controller->recycleBin([], $query, $agentClaims),403,'agents cannot inspect deleted records');
$restoredCustomer=$controller->restoreRecycleBinItem(['type'=>'contact','itemId'=>$customer], $query, $ownerClaims);
$assert($restoredCustomer['restored']===true,'owners can restore soft-deleted customers');
$assert($customers->getCustomer($main,$customer)!==null,'restored customer is visible again');
$customers->deleteCustomer($main,$customer);
$insert('tblinventory_item',['lid'=>950001,'lmain_id'=>$main,'lsession'=>'LOCAL-PRODUCT-1','litemcode'=>'LOCAL-P1','lstatus'=>1,'lnot_inventory'=>0]);
(new ProductRepository($db))->deleteProduct($main,'LOCAL-PRODUCT-1');
$productEntries=array_filter($recovery->list($main), fn($entry)=>($entry['item_type'] ?? '')==='product');
$assert(count($productEntries)===1,'recycle bin includes soft-deleted products');
$assert((int)$pdo->query('SELECT lstatus FROM tblinventory_item WHERE lid=950001')->fetchColumn()===0,'product deletion marks the product inactive');
$assert((int)$pdo->query('SELECT ldeleted FROM tblinventory_item WHERE lid=950001')->fetchColumn()===1,'product deletion marks the product deleted');
$assert($recovery->restore($main,'product','LOCAL-PRODUCT-1'),'recycle bin restores soft-deleted products');
$assert((int)$pdo->query('SELECT ldeleted FROM tblinventory_item WHERE lid=950001')->fetchColumn()===0,'restored product clears the deleted flag');
$assert((int)$pdo->query('SELECT lnot_inventory FROM tblinventory_item WHERE lid=950001')->fetchColumn()===0,'restored product returns to inventory lists');
$insert('tblpr_list',['lid'=>960001,'lrefno'=>'LOCAL-PR-DELETED','lprno'=>'PR-DEL','lstatus'=>'Deleted','ldeleted'=>1,'ldeleted_at'=>'2026-08-30 08:00:00','ldelete_reason'=>'Test deleted PR']);
$insert('tblpo_list',['lid'=>960002,'lmain_id'=>$main,'lrefno'=>'LOCAL-PO-DELETED','lpurchaseno'=>'PO-DEL','ltransaction_status'=>'Deleted','ldeleted'=>1,'ldeleted_at'=>'2026-08-30 09:00:00','ldelete_reason'=>'Test deleted PO']);
$insert('tblpurchase_order',['lid'=>960003,'lmain_id'=>$main,'lrefno'=>'LOCAL-RR-DELETED','lpurchaseno'=>'RR-DEL','ltransaction_status'=>'Deleted','ldeleted'=>1,'ldeleted_at'=>'2026-08-30 10:00:00','ldelete_reason'=>'Test deleted RR']);
$deletedEntries=$recovery->list($main);
$assert(count(array_filter($deletedEntries, fn($entry)=>($entry['item_type'] ?? '')==='purchase_request'))===1,'recycle bin includes deleted purchase requests');
$assert(count(array_filter($deletedEntries, fn($entry)=>($entry['item_type'] ?? '')==='purchase_order'))===1,'recycle bin includes deleted purchase orders');
$assert(count(array_filter($deletedEntries, fn($entry)=>($entry['item_type'] ?? '')==='receiving_report'))===1,'recycle bin includes deleted receiving reports');
$assert(count(array_filter($deletedEntries, fn($entry)=>in_array($entry['item_type'] ?? '', ['purchase_request','purchase_order','receiving_report'], true)))===3,'soft-deleted procurement entries are listed');
$assert($recovery->restore($main,'purchase_request','LOCAL-PR-DELETED'),'recycle bin restores deleted purchase requests');
$assert($recovery->restore($main,'purchase_order','LOCAL-PO-DELETED'),'recycle bin restores deleted purchase orders');
$assert($recovery->restore($main,'receiving_report','LOCAL-RR-DELETED'),'recycle bin restores deleted receiving reports');
$assert(count(array_filter($recovery->list($main), fn($entry)=>in_array($entry['item_id'] ?? '', ['LOCAL-PR-DELETED','LOCAL-PO-DELETED','LOCAL-RR-DELETED'], true)))===0,'restored procurement entries leave the recycle bin');
$logged=$controller->logActivity([], $query, $agentClaims + ['entity_type'=>'Authentication','entity_id'=>'session','action'=>'LOGOUT']);
$assert($logged['saved']===true,'frontend audit logging is persisted locally');
echo "{$passed} assertions passed; all writes were confined to temporary tables.\n";
