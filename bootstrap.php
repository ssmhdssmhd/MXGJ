<?php
/**
 * 框架引导文件
 *
 * 负责 PSR-4 风格自动加载与全局初始化。
 * 框架版本：0.0.1
 */

// 自动加载器：Core\ -> core/，App\ -> app/
spl_autoload_register(function ($class) {
    $prefixes = [
        'Core\\' => __DIR__ . '/core/',
        'App\\' => __DIR__ . '/app/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require $file;
            }
            return;
        }
    }
});

// 时区
date_default_timezone_set('Asia/Shanghai');

// 错误处理（生产环境不输出详情）
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}
if (!APP_DEBUG) {
    error_reporting(0);
    ini_set('display_errors', '0');
}
