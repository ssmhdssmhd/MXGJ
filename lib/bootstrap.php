<?php
/**
 * 沫兮官替系统 - 公共引导文件
 *
 * 职责：定义常量、注册自动加载、提供无数据库(JSON 文件)的读写工具与公共函数。
 */

define('MXGJ_NAME', '沫兮官替系统');
define('MXGJ_VERSION', '1.13.0');

if (!defined('MXGJ_ROOT')) {
    define('MXGJ_ROOT', dirname(__DIR__));
}
define('MXGJ_CONFIG', MXGJ_ROOT . '/config');
define('MXGJ_DATA', MXGJ_ROOT . '/data');
define('MXGJ_CACHE', MXGJ_DATA . '/cache');
define('MXGJ_PLAYER', MXGJ_ROOT . '/player');
define('MXGJ_PLAYER_DATA', MXGJ_PLAYER . '/data');
define('MXGJ_PLAYER_FILE', MXGJ_PLAYER_DATA . '/players.json');

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
            // 输出返回设置（自定义返回字段映射）
            'output'         => [
                'show_source' => false, // 是否在返回中附带原始请求链接（默认隐藏，避免太乱）
                'fields'      => [      // 返回字段模板：k=输出键名，v=值来源(系统字段名)或常量字符串
                    ['k' => 'code', 'v' => 'code'],         // 状态码
                    ['k' => 'msg',  'v' => 'url'],          // msg 默认等于播放链接(便于兼容)
                    ['k' => 'url',  'v' => 'url'],          // 播放链接
                    ['k' => 'time', 'v' => 'time'],         // 耗时(毫秒)
                    ['k' => 'KFZ',  'v' => '沫兮官替系统'], // 开发者(常量)
                ],
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
 * 判断某映射条目是否启用（未在 disabled 禁用列表中即启用）
 *
 * @param array  $mapping 映射表数据（含可选 disabled 段）
 * @param string $sec     区块：title / cid / episode
 * @param string $key     条目键
 */
function mxgj_mapping_enabled(array $mapping, string $sec, string $key): bool
{
    $disabled = $mapping['disabled'][$sec] ?? [];
    return is_array($disabled) ? !in_array($key, $disabled, true) : true;
}

/**
 * URL 安全的 base64 编码（去掉 +/ 与末尾 =，适合放查询参数）
 */
function mxgj_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * URL 安全的 base64 解码，失败返回空字符串
 */
function mxgj_b64url_decode(string $s): string
{
    $s = strtr(trim($s), '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $dec = base64_decode($s, true);
    return $dec === false ? '' : $dec;
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
 * 依据「输出返回设置」把内部变量映射为对外返回的 JSON 字段
 *
 * @param array $vars 内部值，可包含：code,msg,url,title,episode,site,source,time,debug
 * @param bool  $debug 是否附加 debug 详情
 * @return array 对外返回字段（顺序与配置一致）
 */
function mxgj_build_output(array $vars, bool $debug): array
{
    $cfg    = mxgj_settings()['output'] ?? [];
    $fields = is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];
    $fMap   = ['code', 'msg', 'url', 'title', 'episode', 'site', 'source', 'time']; // 系统值来源

    $out = [];
    if ($fields === []) {
        // 兜底默认字段
        $fields = [
            ['k' => 'code', 'v' => 'code'],
            ['k' => 'msg',  'v' => 'url'],
            ['k' => 'url',  'v' => 'url'],
            ['k' => 'time', 'v' => 'time'],
            ['k' => 'KFZ',  'v' => '沫兮官替系统'],
        ];
    }
    foreach ($fields as $f) {
        $k = (string)($f['k'] ?? '');
        $v = (string)($f['v'] ?? '');
        if ($k === '' || $k === 'debug') continue;
        // 该字段被快捷关闭则跳过（默认启用）
        if (array_key_exists('enabled', $f) && empty($f['enabled'])) {
            continue;
        }
        // 是系统字段：取对应内部值；否则当作常量字符串输出
        $out[$k] = in_array($v, $fMap, true) ? ($vars[$v] ?? '') : $v;
    }

    // 是否附带原始请求链接（默认不显示，避免返回太乱）
    if (!empty($cfg['show_source']) && isset($vars['source']) && !array_key_exists('source', $out)) {
        $out['source'] = $vars['source'];
    }
    if ($debug && array_key_exists('debug', $vars)) {
        $out['debug'] = $vars['debug'];
    }
    return $out;
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

/**
 * 保存配置后自动清理运行时数据（缓存 / 站点健康 / 日志 / 心跳锁）
 *
 * 无数据库，所有运行期产生的临时数据均可安全清空，让新配置立即生效。
 * Web 环境下 PHP 每次请求都会重载代码，无需真正重启进程。
 *
 * @return array 已清理的项目清单
 */
function mxgj_purge_runtime(): array
{
    $cleaned = [];

    // 1) 搜索缓存
    foreach (glob(MXGJ_CACHE . '/*.cache') ?: [] as $f) {
        @unlink($f);
    }
    $cleaned['cache'] = MXGJ_CACHE;

    // 2) 站点健康状态 + 心跳锁
    @unlink(MXGJ_DATA . '/site_health.json');
    @unlink(MXGJ_DATA . '/heartbeat.lock');
    $cleaned['health'] = 'site_health';

    // 3) 日志（含所属系统目录）
    foreach (glob(MXGJ_DATA . '/logs/*.json') ?: [] as $f) {
        @unlink($f);
    }
    $cleaned['logs'] = MXGJ_DATA . '/logs';

    // 4) 定时采集运行日志
    @unlink(MXGJ_DATA . '/cron_mapping.log');
    $cleaned['cron'] = 'cron_mapping.log';

    return $cleaned;
}

/* ---------------------------------------------------------------------------
 * 播放器管理函数（苹果CMS10 MacPlayer 兼容格式）
 * ------------------------------------------------------------------------- */

/**
 * 读取所有播放器列表
 */
function mxgj_players(): array
{
    static $players = null;
    if ($players === null) {
        $data = mxgj_read_json(MXGJ_PLAYER_FILE);
        $players = isset($data['players']) && is_array($data['players']) ? $data['players'] : [];
    }
    return $players;
}

/**
 * 获取默认播放器（第一个启用的）
 */
function mxgj_default_player(): ?array
{
    $players = mxgj_players();
    foreach ($players as $p) {
        if (!empty($p['enabled']) && !empty($p['is_default'])) {
            return $p;
        }
    }
    foreach ($players as $p) {
        if (!empty($p['enabled'])) {
            return $p;
        }
    }
    return $players[0] ?? null;
}

/**
 * 按 ID 或 player_code 获取播放器
 */
function mxgj_get_player($key): ?array
{
    $players = mxgj_players();
    foreach ($players as $p) {
        if ((is_int($key) && (int)($p['id'] ?? 0) === $key)
            || (is_string($key) && $p['player_code'] === $key)) {
            return $p;
        }
    }
    return null;
}

/**
 * 保存播放器列表
 */
function mxgj_save_players(array $players): bool
{
    $dir = dirname(MXGJ_PLAYER_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return mxgj_write_json(MXGJ_PLAYER_FILE, ['players' => array_values($players)]);
}
