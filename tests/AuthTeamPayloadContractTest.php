<?php

declare(strict_types=1);

$controller = file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
$repository = file_get_contents(__DIR__ . '/../src/Repositories/AuthRepository.php');

if ($controller === false || $repository === false) {
    fwrite(STDERR, "FAIL: Unable to read authentication sources.\n");
    exit(1);
}

if (!str_contains($controller, 'getTeamName($mainUserId, (int) ($user[\'lteam\'] ?? 0))')
    || !str_contains($controller, "'team' => \$teamName")) {
    fwrite(STDERR, "FAIL: Auth payload does not provide the authenticated user's team.\n");
    exit(1);
}

if (!str_contains($repository, 'WHERE lid = :team_id')
    || !str_contains($repository, 'AND lmain_id = :main_userid')) {
    fwrite(STDERR, "FAIL: Team lookup is not tenant scoped.\n");
    exit(1);
}

echo "Auth team payload contract passed.\n";
