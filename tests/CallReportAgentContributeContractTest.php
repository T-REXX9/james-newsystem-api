<?php

declare(strict_types=1);

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n";
};

$repoSrc = (string) file_get_contents(dirname(__DIR__) . '/src/Repositories/CallReportRepository.php');

$assert(
    str_contains($repoSrc, 'function agentCanContributeToContact'),
    'Repository defines agent contribution helper'
);
$assert(
    str_contains($repoSrc, 'function hasActiveCallClaim'),
    'Repository checks active daily call claims'
);
$assert(
    str_contains($repoSrc, "status = 'in_progress'"),
    'Claim check requires an in-progress claim'
);
$assert(
    str_contains($repoSrc, 'agentCanContributeToContact('),
    'addReply uses agent contribution helper'
);
$assert(
    preg_match(
        '/\$agentUserId = \$senderRole === \'agent\'\s*\?\s*\$senderUserId/s',
        $repoSrc
    ) === 1,
    'New agent conversation shells are owned by the sending agent'
);
$assert(
    !preg_match(
        '/\$agentUserId = \$assignedAgentId > 0\s*\?\s*\$assignedAgentId\s*:\s*\(\$senderRole === \'agent\' \? \$senderUserId : \$senderUserId\)/s',
        $repoSrc
    ),
    'Removed assigned-agent ownership override for agent-created shells'
);

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
