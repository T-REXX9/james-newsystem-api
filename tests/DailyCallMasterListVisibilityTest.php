<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\DailyCallMonitoringController;
use App\Database;
use App\Repositories\CallReportRepository;
use App\Repositories\DailyCallMonitoringRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$mainId = 1;

$agentStatement = $pdo->prepare(
    'SELECT a.lid
     FROM tblaccount a
     WHERE a.lmother_id = :main_id
       AND CAST(COALESCE(a.ltype, 0) AS SIGNED) NOT IN (1)
       AND COALESCE(a.lteam, 0) = 0
       AND COALESCE(a.lstatus, 0) = 1
     ORDER BY a.lid
     LIMIT 1'
);
$agentStatement->execute(['main_id' => $mainId]);
$viewerId = (int) $agentStatement->fetchColumn();
if ($viewerId <= 0) {
    throw new RuntimeException('No active unassigned sales agent fixture found');
}

$unassignedStatement = $pdo->prepare(
    'SELECT p.lcompany
     FROM tblpatient p
     WHERE p.lmain_id = :main_id
       AND COALESCE(p.ldeleted, 0) = 0
       AND COALESCE(p.lsales_person, 0) = 0
       AND COALESCE(p.lsales_team, 0) = 0
       AND TRIM(COALESCE(p.lcompany, \'\')) <> \'\'
     ORDER BY p.lid DESC
     LIMIT 1'
);
$unassignedStatement->execute(['main_id' => $mainId]);
$unassignedCompany = (string) $unassignedStatement->fetchColumn();
if ($unassignedCompany === '') {
    throw new RuntimeException('No unassigned customer fixture found');
}

$controller = new DailyCallMonitoringController(
    new DailyCallMonitoringRepository($db),
    new CallReportRepository($db)
);
$claims = ['__auth_claims' => ['sub' => $viewerId, 'main_userid' => $mainId]];
$allRows = $controller->masterList([], [], $claims);
$items = $allRows['items'] ?? [];

foreach ($items as $item) {
    $agentMatches = (string) ($item['assignedAgentId'] ?? '') === (string) $viewerId;
    $teamIsVisible = trim((string) ($item['assignedTeamId'] ?? '')) !== '';
    if (!$agentMatches && !$teamIsVisible) {
        throw new RuntimeException('FAIL: unassigned sales agent can view an unassigned customer');
    }
}

$searchRows = $controller->masterList([], ['search' => $unassignedCompany], $claims);
if (($searchRows['items'] ?? []) !== []) {
    throw new RuntimeException('FAIL: search exposes an unassigned customer to a sales agent');
}

echo "PASS: unassigned sales agents only receive assigned/team customers and cannot search unassigned customers\n";
