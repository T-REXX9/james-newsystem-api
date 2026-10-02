<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/065_create_daily_call_bookmarks.sql');
$repository = file_get_contents($root . '/src/Repositories/DailyCallMonitoringRepository.php');
$controller = file_get_contents($root . '/src/Controllers/DailyCallMonitoringController.php');
$bootstrap = file_get_contents($root . '/src/bootstrap.php');

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$assert(
    str_contains($migration, 'PRIMARY KEY (main_id, agent_user_id)')
        && str_contains($migration, 'contact_id VARCHAR(64) NOT NULL'),
    'schema stores one customer bookmark per agent and account'
);
$assert(
    str_contains($repository, "'bookmarked_contact_id' => \$this->getDailyCallBookmark(\$mainId, \$viewerUserId)")
        && str_contains($repository, 'ON DUPLICATE KEY UPDATE contact_id = VALUES(contact_id)'),
    'agent snapshot returns the saved bookmark and updates the agent stop point'
);
$assert(
    str_contains($bootstrap, "\$router->patch('/api/v1/daily-call-monitoring/call-bookmark'")
        && str_contains($controller, "\$claims['main_userid'] ?? 0")
        && str_contains($controller, 'assertCustomerViewAccess($mainId, $contactId, $userId)'),
    'bookmark route requires authenticated account scope and customer access'
);

echo "Daily Call bookmark contract: 3/3 passed\n";
