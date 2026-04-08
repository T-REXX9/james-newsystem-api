<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function json(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            http_response_code(500);
            $json = '{"ok":false,"error":"Failed to encode JSON response"}';
        }

        echo $json;
    }

    public static function eventStream(): void
    {
        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

        echo ':' . str_repeat(' ', 2048) . "\n\n";
        self::flush();
    }

    public static function sseEvent(string $event, array $payload, ?string $id = null): void
    {
        if ($id !== null && $id !== '') {
            echo 'id: ' . $id . "\n";
        }

        echo 'event: ' . $event . "\n";

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = '{"ok":false,"error":"Failed to encode SSE payload"}';
        }

        foreach (preg_split("/\r\n|\r|\n/", $json) ?: [] as $line) {
            echo 'data: ' . $line . "\n";
        }

        echo "\n";
        echo ':' . str_repeat(' ', 2048) . "\n\n";
        self::flush();
    }

    public static function sseComment(string $comment): void
    {
        echo ': ' . $comment . "\n\n";
        self::flush();
    }

    public static function flush(): void
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        }

        flush();
    }
}
