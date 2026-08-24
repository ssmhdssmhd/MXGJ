<?php
/**
 * 沫兮官替系统 - 公共引导文件
 *
 * 职责：定义常量、注册自动加载、提供无数据库(JSON 文件)的读写工具与公共函数。
 */

define('MXGJ_NAME', '沫兮官替系统');
define('MXGJ_VERSION', '1.0.0');

if (!defined('MXGJ_ROOT')) {
    define('MXGJ_ROOT', dirname(__DIR__));
}
define('MXGJ_CONFIG', MXGJ_ROOT . '/config');
define('MXGJ_DATA', MXGJ_ROOT . '/data');
define('MXGJ_CACHE', MXGJ_DATA . '/cache');

// 自动加载 lib 目录下的类
spl_autoload_register(function ($class) {
    $file = MXGJ_ROOT . '/lib/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/* ---------------------------------------------------------------------------
 * 文件存储（无数据库，全部使用 JSON 文件）
 * ------------------------------------------------------------------------- */

/**
 * 读取 JSON 文件，失败/不存在时返回默认值
 */
function mxgj_read_json(string $file, $default = [])
{
    if (!is_file($file)) {
        return $default;
    }
    $content = @file_get_contents($file);
    if ($content === false || trim($content) === '') {
        return $default;
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

/**
 * 写入 JSON 文件（自动创建目录）
 */
function mxgj_write_json(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return @file_put_contents($file, $content) !== false;
}

/**
 * 读取全局设置
 */
function mxgj_settings(): array
{
    static $settings = null;
    if ($settings === null) {
        $settings = array_merge([
            'admin_password' => 'moxi123', // 后台登录密码（请修改）
            'timeout'        => 15,        // 单次资源站请求超时(秒)
            'cache_ttl'      => 600,       // 搜索缓存时长(秒)
            'replace_domain' => '',        // 域名替换/中转前缀，留空则直接返回资源站地址
        ], mxgj_read_json(MXGJ_CONFIG . '/settings.json'));
    }
    return $settings;
}

/**
 * 读取后台配置的资源站列表
 */
function mxgj_sites(): array
{
    static $sites = null;
    if ($sites === null) {
        $data = mxgj_read_json(MXGJ_CONFIG . '/sites.json');
        $sites = isset($data['sites']) && is_array($data['sites']) ? $data['sites'] : [];
    }
    return $sites;
}

/**
 * 大小写转换（兼容无 mbstring 环境）
 */
function mxgj_lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}

/**
 * 统一 JSON 输出并终止脚本（支持 JSONP 回调）
 */
function mxgj_json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    $callback = isset($_GET['callback']) ? preg_replace('/[^A-Za-z0-9_.]/', '', $_GET['callback']) : '';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($callback !== '') {
        echo $callback . '(' . $json . ')';
    } else {
        echo $json;
    }
    exit;
}
