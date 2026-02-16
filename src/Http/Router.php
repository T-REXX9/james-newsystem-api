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
                Response::json(['ok' => true, 'data' => $result], 200);
                return;
            }

            throw new HttpException(404, 'Route not found');
        } catch (HttpException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], $e->statusCode());
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return $_POST ?? [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }
}
