<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Exceptions\HttpException;
use Throwable;

final class Router
{
    /** @var array<string, array<int, array{pattern: string, handler: callable}>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $regex = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[strtoupper($method)][] = [
            'pattern' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $method = strtoupper($method);
        $body = $this->parseBody();

        try {
            foreach ($this->routes[$method] ?? [] as $route) {
                if (!preg_match($route['pattern'], (string) $path, $matches)) {
                    continue;
                }

                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                $query = $_GET ?? [];
                $result = ($route['handler'])($params, $query, $body);
                if ($result === null && headers_sent()) {
                    return;
                }
                Response::json(['ok' => true, 'data' => $result], 200);
                return;
            }

            throw new HttpException(404, 'Route not found');
        } catch (HttpException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], $e->statusCode());
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    private function parseBody(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'multipart/form-data')) {
            return is_array($_POST ?? null) ? $_POST : [];
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            $raw = '';
        }

        if (str_contains($contentType, 'application/octet-stream')) {
            return ['__raw_body' => $raw];
        }

        if (trim($raw) === '') {
            return is_array($_POST ?? null) ? $_POST : [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }
}
