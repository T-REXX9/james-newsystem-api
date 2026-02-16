<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public function __construct(
        public readonly string $appEnv,
        public readonly bool $appDebug,
        public readonly string $allowedOrigin,
        public readonly string $authSecret,
        public readonly int $authTokenTtlSeconds,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPass
    ) {
    }
}
