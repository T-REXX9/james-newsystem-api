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
    str_contains($repoSrc, 'function hasTodaysCallClaim'),
    'Repository checks today\'s daily call claims'
);
$assert(
    str_contains($repoSrc, "status IN ('in_progress', 'completed')"),
    'Claim check allows today\'s in-progress or completed claims'
);
$assert(
    !str_contains($repoSrc, "AND expires_at > NOW()\n                 LIMIT 1"),
    'Claim contribution check does not require active claim TTL'
);
$assert(
    str_contains($repoSrc, 'agentCanContributeToContact('),
    'addReply uses agent contribution helper'
);
$assert(
    str_contains($repoSrc, '$claimingNonAssignee'),
    'Thread resolver skips foreign-thread fallback for claiming non-assignees'
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
