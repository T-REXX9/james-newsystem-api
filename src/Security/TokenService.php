<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Exceptions\HttpException;

final class TokenService
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 28800
    ) {
    }

    public function issue(array $claims): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + max(60, $this->ttlSeconds),
        ]);

        $payloadB64 = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sigB64 = $this->base64UrlEncode(hash_hmac('sha256', $payloadB64, $this->secret, true));
        return $payloadB64 . '.' . $sigB64;
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new HttpException(401, 'Invalid token format');
        }

        [$payloadB64, $sigB64] = $parts;
        $expectedSig = $this->base64UrlEncode(hash_hmac('sha256', $payloadB64, $this->secret, true));
        if (!hash_equals($expectedSig, $sigB64)) {
            throw new HttpException(401, 'Invalid token signature');
        }

        $payloadJson = $this->base64UrlDecode($payloadB64);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new HttpException(401, 'Invalid token payload');
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp <= 0 || $exp < time()) {
            throw new HttpException(401, 'Token expired');
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padLength = 4 - (strlen($data) % 4);
        if ($padLength < 4) {
            $data .= str_repeat('=', $padLength);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
