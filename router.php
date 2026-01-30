<?php
// Simple router for PHP built-in server
$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Remove query string for path matching
$path = strtok($requestPath, '?');

// Route API requests
if (strpos($path, '/api/') === 0 || $path === '/api') {
    // Extract action from path or query string
    $pathParts = explode('/', trim($path, '/'));
    if (count($pathParts) >= 2 && $pathParts[1] !== 'api') {
        $_GET['action'] = $_GET['action'] ?? $pathParts[1];
    } else {
        $_GET['action'] = $_GET['action'] ?? '';
    }
    require __DIR__ . '/api/index.php';
    exit;
}

// Default: return 404
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found']);
