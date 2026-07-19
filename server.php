<?php

/**
 * Laravel 开发服务器路由器（php artisan serve）
 *
 * 处理 SPA 架构下的请求分流：
 *  - 静态资源（assets/*.js, *.css）→ PHP 直接服务
 *  - /admin/index.html, /console/index.html → PHP 直接服务（SPA 入口）
 *  - 其他所有请求（包括 /, /login, /api/*）→ Laravel index.php
 *
 * 注意：public/index.html（平台 SPA 入口）不能被 PHP 直接服务，
 * 否则会拦截 / 请求，导致 Laravel 路由不执行。
 */
$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// 明确不服务 public/index.html（平台 SPA 入口）
// 否则 PHP 内置服务器会在 / 请求时优先返回 index.html，绕过 Laravel 路由
if ($uri === '/' || $uri === '/index.html') {
    // 强制走 Laravel index.php
} elseif (is_file($publicPath . $uri)) {
    // 其他实际存在的静态文件（JS/CSS/图片/SPA 子入口）直接服务
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
