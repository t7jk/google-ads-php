<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * A simple router: exact match (method + path). The path is computed relative to the index.php directory,
 * so it works the same at a domain root and in a subdirectory (dev). Controller: a new instance, a method without arguments.
 */
final class Router
{
    public static function dispatch(array $routes): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = self::path();
        $allowed = [];

        foreach ($routes as $route) {
            [$routeMethod, $routePath, $handler] = $route;
            if ($routePath !== $path) {
                continue;
            }
            $allowed[] = $routeMethod;
            if ($routeMethod !== $method && !($method === 'HEAD' && $routeMethod === 'GET')) {
                continue;
            }
            if ($method === 'POST' && ($route[3]['csrf'] ?? true) && !csrf_valid()) {
                view('error', ['title' => 'Error', 'message' => 'Invalid form token (CSRF). Refresh the page and try again.'], 403);
            }
            [$class, $action] = $handler;
            (new $class())->$action();
            return;
        }

        if ($allowed) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            view('error', ['title' => 'Error', 'message' => 'Method not allowed.'], 405);
        }
        view('error', ['title' => 'Not found', 'message' => 'No such page.'], 404);
    }

    private static function path(): string
    {
        $uriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = base_path();
        if ($base !== '' && str_starts_with($uriPath, $base)) {
            $uriPath = substr($uriPath, strlen($base));
        }
        $uriPath = '/' . trim($uriPath, '/');
        return $uriPath === '/index.php' ? '/' : $uriPath;
    }
}
