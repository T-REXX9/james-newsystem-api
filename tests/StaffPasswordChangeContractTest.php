<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/src/Controllers/StaffController.php');
$authRepository = file_get_contents($root . '/src/Repositories/AuthRepository.php');
$authController = file_get_contents($root . '/src/Controllers/AuthController.php');
$bootstrap = file_get_contents($root . '/src/bootstrap.php');
$migration = file_get_contents($root . '/migrations/041_add_account_session_version.sql');

if ($controller === false || $authRepository === false || $authController === false || $bootstrap === false || $migration === false) {
    fwrite(STDERR, "FAIL: unable to read staff password change files\n");
    exit(1);
}

$checks = [
    'password endpoint is Master User protected' => str_contains($bootstrap, "'/api/v1/staff/{staffId}/password', \$requireMasterUser"),
    'password endpoint validates minimum length' => str_contains($controller, "strlen(\$password) < 8"),
    'password is stored using the legacy authentication hash' => str_contains($authRepository, 'hashLegacyPassword($password)'),
    'password change increments the session version' => str_contains($authRepository, 'lsession_version = COALESCE(lsession_version, 0) + 1'),
    'password change removes registered devices' => str_contains($authRepository, 'DELETE FROM tblcall_devices WHERE lagent_id'),
    'issued tokens carry the session version' => str_contains($authController, "'session_version' =>"),
    'protected requests reject stale session versions' => str_contains($bootstrap, 'isSessionCurrent($body[\'__auth_claims\'])')
        && str_contains($authController, 'isSessionCurrent($claims)'),
    'migration creates the session version column' => str_contains($migration, 'lsession_version'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

if ($failed !== []) {
    fwrite(STDERR, sprintf("Results: %d passed, %d failed\n", count($checks) - count($failed), count($failed)));
    exit(1);
}

echo sprintf("Staff password change contract: %d/%d passed\n", count($checks), count($checks));
