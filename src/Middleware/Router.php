<?php

namespace App\Middleware;

/**
 * Router
 *
 * Lightweight regex-based router.
 * Supports GET, POST, PUT, PATCH, and DELETE.
 * Segments in curly braces (e.g. {id}) are captured and passed to the handler.
 *
 * Usage:
 *   $router->get('/products/{id}', [ProductController::class, 'show']);
 *   $router->dispatch();
 */
class Router
{
    /** @var array<string, list<array{pattern:string, handler:callable|array}>> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable|array $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    /** Register a route for the given method. */
    private function addRoute(string $method, string $path, callable|array $handler): void
    {
        // Convert {param} placeholders to named capture groups
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[$method][] = [
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Match the current request and invoke the appropriate handler.
     * Returns a 404 or 405 JSON response if no route matches.
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

        // Try to match across all methods first (to distinguish 404 vs 405)
        $anyMethodMatch = false;

        foreach ($this->routes as $routeMethod => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $uri, $matches)) {
                    $anyMethodMatch = true;

                    if ($routeMethod === $method) {
                        // Extract named captures (drop numeric keys)
                        $params = array_filter(
                            $matches,
                            fn($k) => !is_int($k),
                            ARRAY_FILTER_USE_KEY
                        );

                        $this->invoke($route['handler'], $params);
                        return;
                    }
                }
            }
        }

        http_response_code($anyMethodMatch ? 405 : 404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => $anyMethodMatch
                ? "Method $method not allowed on $uri"
                : "Route not found: $uri",
        ]);
    }

    /** Instantiate the controller (if needed) and call the action method. */
    private function invoke(callable|array $handler, array $params): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            // Cast any {id}-style segments to int before passing
            $args = array_map(fn($v) => ctype_digit($v) ? (int) $v : $v, array_values($params));
            $controller->$method(...$args);
        } else {
            $handler(...array_values($params));
        }
    }
}
