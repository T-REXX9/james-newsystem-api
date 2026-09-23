<?php

declare(strict_types=1);

/**
 * Prospect-verification notification attribution contract.
 *
 * Run: php tests/CustomerProspectNotificationAttributionTest.php
 */

$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/CustomerDatabaseController.php');
$repository = (string) file_get_contents(__DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php');

$assertions = [
    'resolves the submitting account display name' => str_contains(
        $controller,
        '$actorName = $this->repo->getAccountDisplayName($userId);'
    ),
    'uses the submitting account and actual prospect name in the verification notification message' => str_contains(
        $controller,
        "'%s submitted prospective customer %s for verification.'"
    ),
    'includes the actual prospect name in the notification title' => str_contains(
        $controller,
        "'title' => sprintf('Prospective Customer for Verification - %s', \$prospectName)"
    ),
    'stores the prospect name in notification metadata' => str_contains(
        $controller,
        "'prospect_name' => \$prospectName"
    ),
    'stores the submitting account identity in notification metadata' => str_contains(
        $controller,
        "'actor_name' => \$actorName"
    ) && str_contains($controller, "'actor_id' => (string) \$userId"),
    'exposes account display-name lookup to the notification workflow' => str_contains(
        $repository,
        'public function getAccountDisplayName(int $accountId): string'
    ),
];

$failed = 0;
foreach ($assertions as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ": {$label}\n";
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
