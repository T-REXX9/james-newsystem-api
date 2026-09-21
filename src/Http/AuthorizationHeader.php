<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Resolve the Authorization header across CGI/FastCGI/proxy setups where
 * PHP may expose it under alternate $_SERVER keys or only via getallheaders().
 */
final class AuthorizationHeader
{
    public static function value(): string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['Authorization'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value) && trim($value) !== '') {
                        return trim($value);
                    }
                }
            }
        }

        return '';
    }

    public static function bearerToken(): ?string
    {
        $header = self::value();
        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return (string) $matches[1];
    }
}
