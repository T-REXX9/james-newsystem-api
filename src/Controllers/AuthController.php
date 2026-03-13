<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AuthRepository;
use App\Security\TokenService;
use App\Support\Exceptions\HttpException;

final class AuthController
{
    public function __construct(
        private readonly AuthRepository $repo,
        private readonly TokenService $tokens
    ) {
    }

    public function login(array $params = [], array $query = [], array $body = []): array
    {
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new HttpException(422, 'email and password are required');
        }

        $user = $this->repo->findActiveUserByEmail($email);
        if ($user === null) {
            throw new HttpException(401, 'Account not found.');
        }

        $hashed = $this->repo->hashLegacyPassword($password);
        $stored = (string) ($user['lpassword'] ?? '');
        if (!hash_equals($stored, $hashed)) {
            throw new HttpException(401, 'Account not found.');
        }

        if ((int) ($user['lactivation'] ?? 0) === 0) {
            throw new HttpException(403, 'Account need to activate. please visit your email.');
        }

        return $this->buildLoginPayload($user);
    }

    public function me(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId <= 0) {
            throw new HttpException(401, 'Invalid token subject');
        }

        $user = $this->repo->findUserById($userId);
        if ($user === null) {
            throw new HttpException(401, 'User not found or inactive');
        }

        $result = $this->buildContext($user);
        $result['token'] = null;
        return $result;
    }

    public function logout(array $params = [], array $query = [], array $body = []): array
    {
        $this->requireAuthClaims();
        return [
            'logout' => true,
            'note' => 'Stateless token logout. Remove token on client side.',
        ];
    }

    private function buildLoginPayload(array $user): array
    {
        $context = $this->buildContext($user);

        $token = $this->tokens->issue([
            'sub' => (int) ($user['lid'] ?? 0),
            'main_userid' => $context['main_userid'],
            'user_type' => $context['user_type'],
            'session_branch' => $context['session_branch'],
            'industry' => $context['industry'],
            'logintype' => $context['logintype'],
        ]);

        $context['token'] = $token;
        return $context;
    }

    private function buildContext(array $user): array
    {
        $userId = (int) ($user['lid'] ?? 0);
        $userType = (string) ($user['ltype'] ?? '1');
        $mainUserId = $this->repo->resolveMainUserId($user);
        $branch = trim((string) ($user['lbranch'] ?? ''));
        if ($branch === '') {
            $branch = 'mainbranch';
        }
        if ($userType === '1') {
            $branch = 'mainbranch';
        }

        $industry = trim((string) ($user['lindustries'] ?? ''));
        if ($industry === '') {
            $industry = 'Shop';
        }

        $servicePackage = (string) ($userType === '1' ? ($user['lservice'] ?? '') : $this->getMainService($mainUserId));

        $rawAccessRights = $user['laccess_rights'] ?? null;
        $accessRights = null;
        if (is_string($rawAccessRights) && trim($rawAccessRights) !== '') {
            $decoded = json_decode($rawAccessRights, true);
            if (is_array($decoded)) {
                $accessRights = array_values(array_filter($decoded, static fn ($v): bool => is_string($v)));
            }
        }

        $groupId = ($user['group_id'] ?? null);
        if ($groupId !== null && $groupId !== '' && $groupId !== '0') {
            $groupId = (string) $groupId;
        } else {
            $groupId = null;
        }

        return [
            'user' => [
                'id' => $userId,
                'main_userid' => $mainUserId,
                'email' => (string) ($user['lemail'] ?? ''),
                'first_name' => (string) ($user['lfname'] ?? ''),
                'last_name' => (string) ($user['llname'] ?? ''),
                'type' => $userType,
                'status' => (int) ($user['lstatus'] ?? 0),
                'activation' => (int) ($user['lactivation'] ?? 0),
                'branch' => $branch,
                'industry' => $industry,
                'service_package' => $servicePackage,
                'sales_quota' => (float) ($user['lsales_quota'] ?? 0),
                'access_rights' => $accessRights,
                'group_id' => $groupId,
            ],
            'main_userid' => $mainUserId,
            'user_type' => $userType,
            'session_branch' => $branch,
            'logintype' => $userType,
            'industry' => $industry,
            'permissions' => [
                'web' => $this->repo->getWebPermissions($mainUserId, $userType),
                'package' => $servicePackage === '' ? [] : $this->repo->getPackagePermissions($mainUserId, $servicePackage),
            ],
        ];
    }

    private function getMainService(int $mainUserId): string
    {
        $mainUser = $this->repo->findUserById($mainUserId);
        if ($mainUser === null) {
            return '';
        }
        return (string) ($mainUser['lservice'] ?? '');
    }

    private function requireAuthClaims(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
        if (!is_string($header) || trim($header) === '') {
            throw new HttpException(401, 'Authorization header is required');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            throw new HttpException(401, 'Bearer token is required');
        }

        return $this->tokens->verify((string) $matches[1]);
    }
}
