<?php
/**
 * 沫兮官替系统 - 引导文件
 * 负责自动加载核心类、初始化时区、全局配置与数据库单例。
 */

declare(strict_types=1);

// 时区
date_default_timezone_set('Asia/Shanghai');

// 自动加载 app/classes/**（App\ 命名空间）
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/app/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// 全局配置
$GLOBALS['MXGJ_CONFIG'] = require __DIR__ . '/config/config.php';

// 确保运行目录存在
foreach (['runtime', 'data'] as $dir) {
    $p = __DIR__ . '/' . $dir;
    if (!is_dir($p)) {
        @mkdir($p, 0775, true);
    }
}

// 全局辅助函数
require_once __DIR__ . '/app/functions.php';

/**
 * 读取全局配置，key 支持点号嵌套：config('db.host')
 */
function config(?string $key = null, mixed $default = null): mixed
{
    $cfg = $GLOBALS['MXGJ_CONFIG'] ?? [];
    if ($key === null) {
        return $cfg;
    }
    $cur = $cfg;
    foreach (explode('.', (string)$key) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) {
            $cur = $cur[$seg];
        } else {
            return $default;
        }
    }
    return $cur;
}

/**
 * 获取数据库单例
 */
function db(): \App\Core\Database
{
    static $instance = null;
    if ($instance === null) {
        $instance = new \App\Core\Database(config('db'));
    }
    return $instance;
}

// 首次运行自动建表（幂等）
\App\Core\Migration::ensure();