<?php

/**
 * public/index.php – Front controller
 *
 * All HTTP requests are rewritten here by the web server (see .htaccess).
 * This file bootstraps the autoloader, sets global headers, and dispatches.
 */

declare(strict_types=1);

// ── Autoloader ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Middleware\Router;

// ── Global response headers ───────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle pre-flight CORS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Error handling ────────────────────────────────────────────────────────────
// In production, suppress PHP's HTML error output and return JSON instead.
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unhandled exception: ' . $e->getMessage()]);
    exit;
});

// ── Routes ────────────────────────────────────────────────────────────────────
$router = new Router();

// Products
$router->get('/products',          [ProductController::class, 'index']);
$router->get('/products/{id}',     [ProductController::class, 'show']);
$router->post('/products',         [ProductController::class, 'store']);
$router->put('/products/{id}',     [ProductController::class, 'update']);
$router->patch('/products/{id}',   [ProductController::class, 'update']);
$router->delete('/products/{id}',  [ProductController::class, 'destroy']);

// Orders
$router->get('/orders',            [OrderController::class, 'index']);
$router->get('/orders/{id}',       [OrderController::class, 'show']);
$router->post('/orders',           [OrderController::class, 'store']);

// Health check
$router->get('/health', function () {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'timestamp' => date('c')]);
    exit;
});

$router->dispatch();
