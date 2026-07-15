<?php

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Only serve actual static files (not directories)
if ($uri !== '/' && is_file($publicPath . $uri)) {
    return false;
}

// Fix: PHP's built-in server resolves /admin/dashboard to
// SCRIPT_NAME=/admin/index.html when public/admin/index.html exists,
// breaking Laravel's Request::path() for SPA catch-all routes.
// Override only for non-API requests (API routes don't have this problem).
if (! str_starts_with($uri, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $publicPath . '/index.php';
}

$formattedDateTime = date('D M j H:i:s Y');
$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'] . ':' . $_SERVER['REMOTE_PORT'];
file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath . '/index.php';
