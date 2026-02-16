<?php

declare(strict_types=1);

namespace App\Controllers;

final class HealthController
{
    public function index(array $params = [], array $query = [], array $body = []): array
    {
        return [
            'service' => 'raw-php-api',
            'status' => 'up',
            'timestamp' => date('c'),
        ];
    }
}
