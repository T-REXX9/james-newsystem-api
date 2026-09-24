<?php

declare(strict_types=1);

// Contract test for tenant-scoped workflow recipients.  This protects the
// conversion notification path from resolving every active Owner account.
$notifications = file_get_contents(__DIR__ . '/../src/Repositories/NotificationsRepository.php');
$salesInquiries = file_get_contents(__DIR__ . '/../src/Repositories/SalesInquiryRepository.php');
$controller = file_get_contents(__DIR__ . '/../src/Controllers/NotificationsController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/bootstrap.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(
    str_contains($notifications, '?int $mainId = null')
        && str_contains($notifications, 'AND (a.lid = :main_id OR a.lmother_id = :main_id_2)'),
    'workflow recipient resolution scopes active accounts to the supplied company'
);
$assert(
    str_contains($notifications, '$mainId > 0 ? $mainId : null'),
    'workflow dispatch forwards its main_id to recipient resolution'
);
$assert(
    str_contains($salesInquiries, "'main_id' => (string) \$mainId"),
    'sales inquiry conversion supplies its company scope to notification dispatch'
);
$assert(
    str_contains($controller, '$body[\'main_id\'] = (string) $authenticatedMainId')
        && str_contains($bootstrap, 'workflow-dispatch\', $requireBearerAuthWithClaims'),
    'public workflow dispatch binds company scope to verified authentication claims'
);

echo "Notification tenant recipient scope contract passed.\n";
