<?php

declare(strict_types=1);

/**
 * Feedback loop for: System Access page action permissions (Post / Approve)
 * must be sufficient to post or approve. Staff granted can_post / can_approve
 * on System Access must not also need Maintenance Approver (tblapprover) listing.
 *
 * Symptom reports: "I allow staff to post but they still can't post" /
 * "I allow staff to approve but they still can't approve" — especially
 * Adjustment Entry, whose UI gates on can_post while the API still demanded
 * tblapprover via requireApproverAction.
 */

$source = file_get_contents(__DIR__ . '/../src/bootstrap.php');
if ($source === false) {
    throw new RuntimeException('FAIL: unable to read src/bootstrap.php');
}

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

if (!preg_match(
    '/\$requireApproverAction\s*=\s*static function.*?\$router\s*=\s*new Router/s',
    $source,
    $gateMatch
)) {
    throw new RuntimeException('FAIL: could not isolate requireApproverAction');
}

$gate = $gateMatch[0];

$assert(
    !str_contains($gate, 'tblapprover'),
    'requireApproverAction must not query tblapprover (System Access is authoritative)'
);

$assert(
    !str_contains($gate, 'Only approver accounts can approve records'),
    'requireApproverAction must not reject for missing Maintenance Approver listing'
);

$assert(
    preg_match(
        '/assertActionPermission\(\$claims,\s*\$actionPermission,\s*\$approverModules\[0\]/',
        $gate
    ) === 1,
    'requireApproverAction still asserts System Access action permissions for the page'
);

$assert(
    str_contains($gate, "'post'") && str_contains($gate, "'approve'"),
    'requireApproverAction still maps post and approve to System Access actions'
);

$assert(
    str_contains($source, "\$router->post('/api/v1/adjustment-entries/{refno}/actions/{action}', \$requireApproverAction([\$adjustmentEntryController, 'action'], ['Adjustment Entry', 'Adjustment']))"),
    'Adjustment Entry dynamic actions stay on requireApproverAction with Adjustment Entry page scope'
);

$assert(
    str_contains($source, "'Daily Collection Entry'")
        && preg_match("/collections\/\{collectionRefno\}\/actions\/\{action\}'.*Daily Collection Entry/s", $source) === 1
            || str_contains($source, "\$requireApproverAction([\$collectionController, 'action'], ['Daily Collection Entry', 'Collection'])"),
    'Collection actions use System Access label Daily Collection Entry'
);

$assert(
    str_contains($source, "\$requireApproverAction([\$dailyCallMonitoringController, 'reviewIncidentReport'], ['Daily Call Monitoring'"),
    'Incident review uses System Access label Daily Call Monitoring'
);

echo "PASS: SystemAccessPostApproveGateTest\n";
