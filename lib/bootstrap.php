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
            'updater_key'    => '',        // 升级密钥（update.php 用，留空则回退 admin_password）
            'timeout'        => 15,        // 单次资源站请求超时(秒)
            'cache_ttl'      => 600,       // 搜索缓存时长(秒)
            'replace_domain' => '',        // 域名替换/中转前缀，留空则直接返回资源站地址
            'repo_owner'     => 'ssmhdssmhd',
            'repo_name'      => 'MXGJ',
            'repo_branch'    => 'main',
            // 资源站调用频率控制（搜索节流 + 心跳探测 + 轮训）
            'site_control'   => [
                'search_interval'   => 15,    // 资源站搜索频率：同一站两次实际调用最短间隔(秒)，防刷屏被屏蔽
                'heartbeat_enable'  => true,  // 是否启用心跳检测
                'heartbeat_interval'=> 600,   // 资源站心跳频率：多久探测一次资源站可达性(秒)
                'heartbeat_timeout' => 5,     // 心跳探测单站超时(秒)
                'heartbeat_max_fail'=> 3,     // 连续失败达 N 次自动禁用该站
                'cooldown_seconds'  => 1800,  // 被禁用的站在多少秒后自动恢复重试
                'rotation_enable'   => true,  // 是否启用资源站轮训（定期切换优先顺序/分批调用）
                'rotation_interval'=> 600,    // 资源站轮训：每隔多少秒切换一次命中顺序(秒)
                'max_sites_per_request' => 4, // 每次搜索最多并发请求几个资源站(0=不限制)
            ],
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

/**
 * 依据官方链接解析结果，自动把 vid/cid → 剧名+集数 追加进映射表
 *
 * 用于「一键测试 / AI 分析」识别出剧名与集数后自动建映射，
 * 下次直接命中，无需再次联网抓取。已有该 ID 的映射则不覆盖（保护人工配置）。
 *
 * @return bool 是否写入了新映射
 */
function mxgj_auto_mapping(array $parsed, string $name, int $episode): bool
{
    $key = $parsed['vid'] !== '' ? 'vid:' . $parsed['vid']
         : ($parsed['cid'] !== '' ? 'cid:' . $parsed['cid'] : '');
    if ($key === '' || $name === '' || $episode <= 0) {
        return false;
    }
    $file   = MXGJ_CONFIG . '/mapping.json';
    $mapping = mxgj_read_json($file, []);
    if (!isset($mapping['episode']) || !is_array($mapping['episode'])) {
        $mapping['episode'] = [];
    }
    if (isset($mapping['episode'][$key])) {
        return false; // 已有映射，不覆盖
    }
    $mapping['episode'][$key] = ['name' => $name, 'episode' => (int)$episode];
    return mxgj_write_json($file, $mapping);
}
