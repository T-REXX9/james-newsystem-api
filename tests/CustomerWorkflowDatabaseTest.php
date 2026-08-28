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
use App\Controllers\CustomerWorkflowController;
use App\Support\Exceptions\HttpException;

$db = new Database(app_config());
$pdo = $db->pdo();
foreach (['customer_requests','local_recycle_bin','tblpatient','tblcontact_person','tblpatient_terms','tblpatient_image','tblaccount','tblusertype','tblaudit_trail','tblinventory_item','tblinquiry','tblinquiry_item','tblcredit_memo','tblcredit_return_item'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
}
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
$params = ['contactId'=>$customer]; $query = ['main_id'=>$main];

$created = $controller->createRequest($params, $query, $agentClaims + ['kind'=>'customer_update','payload'=>['company'=>'After']]);
$assert($repo->customer($main, $customer)['company'] === 'Before', 'submission does not bypass owner approval');
$assert(count($controller->requests($params, $query, $ownerClaims)) === 1, 'owner sees the persisted request');
$reject(fn() => $controller->reviewRequest($params + ['requestId'=>$created['id']], $query, $agentClaims + ['decision'=>'approved']), 403, 'agent cannot self-approve');
$reject(fn() => $controller->requests($params, ['main_id'=>$outsider], $agentClaims), 403, 'tenant query cannot override authenticated tenant');
$reject(fn() => $controller->requests($params, [], $claims($outsider, $outsider)), 404, 'other tenant cannot read customer requests');
$reject(fn() => $controller->requests($params, $query, []), 401, 'missing authentication rejected');
$controller->reviewRequest($params + ['requestId'=>$created['id']], $query, $ownerClaims + ['decision'=>'approved']);
$assert($repo->customer($main, $customer)['company'] === 'After', 'approval updates the local customer record');
$reject(fn() => $controller->reviewRequest($params + ['requestId'=>$created['id']], $query, $ownerClaims + ['decision'=>'approved']), 409, 'duplicate review is rejected');
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
$assert($customers->deleteCustomer($main,$customer),'customer deletion creates a recovery snapshot');
$entries=$recovery->list($main);
$assert(count($entries)===1 && !isset($entries[0]['snapshot']),'recovery list does not expose raw customer snapshots');
$reject(fn()=> $controller->recycleBin([], $query, $agentClaims),403,'agents cannot inspect recovery snapshots');
$reject(fn()=> $recovery->act($outsider,$outsider,(string)$entries[0]['id'],true),404,'other tenant cannot restore a snapshot');
$insert('tblpatient', ['lid'=>910002,'lmain_id'=>$main,'lsessionid'=>$customer,'lcompany'=>'Conflicting new customer','lstatus'=>1]);
$reject(fn()=> $recovery->act($main,$main,(string)$entries[0]['id'],true),409,'restore refuses to overwrite a reused customer reference');
$assert(count($recovery->list($main))===1,'failed restoration preserves its recovery snapshot');
$pdo->prepare('DELETE FROM tblpatient WHERE lid=?')->execute([910002]);
$recovery->act($main,$main,(string)$entries[0]['id'],true);
$assert($repo->customer($main,$customer)['company']==='Newer','customer is restored from actual saved data');
$assert(count($repo->customer($main,$customer)['contacts'])===1,'customer contact people are restored');
$assert(count($recovery->list($main))===0,'successful restore removes the recovery entry');
$insert('tblinventory_item',['lid'=>950001,'lmain_id'=>$main,'lsession'=>'LOCAL-PRODUCT-1','litemcode'=>'LOCAL-P1','lstatus'=>1,'lnot_inventory'=>0]);
(new ProductRepository($db))->deleteProduct($main,'LOCAL-PRODUCT-1');
$entry=$recovery->list($main)[0];
$recovery->act($main,$main,(string)$entry['id'],true);
$assert((int)$pdo->query('SELECT lstatus FROM tblinventory_item WHERE lid=950001')->fetchColumn()===1,'product restoration restores its previous enabled state');
(new ProductRepository($db))->deleteProduct($main,'LOCAL-PRODUCT-1');
$entry=$recovery->list($main)[0];
$recovery->act($main,$main,(string)$entry['id'],false);
$assert((int)$pdo->query('SELECT COUNT(*) FROM tblinventory_item WHERE lid=950001')->fetchColumn()===1,'discard preserves product transaction references');
$assert(count($recovery->list($main))===0,'discard permanently removes recovery data');
$logged=$controller->logActivity([], $query, $agentClaims + ['entity_type'=>'Authentication','entity_id'=>'session','action'=>'LOGOUT']);
$assert($logged['saved']===true,'frontend audit logging is persisted locally');
echo "{$passed} assertions passed; all writes were confined to temporary tables.\n";
