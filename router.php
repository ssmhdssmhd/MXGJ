<?php
/**
 * PHP 内置服务器开发路由。
 * 用法：php -S 127.0.0.1:3000 router.php
 * - /admin 相关请求 → 后台管理
 * - 其余 → 前台入口 index.php
 * - 已存在的静态文件 → 直接由内置服务器返回
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 静态文件直接返回
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

// 后台
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require __DIR__ . '/admin/index.php';
    return true;
}

// 前台（官替 API / 前端页面 / webhook）
require __DIR__ . '/index.php';
return true;
