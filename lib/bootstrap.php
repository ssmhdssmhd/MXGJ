<?php
// 防重复加载（允许 api.php → index.php 链路内 bootstrap 不重复声明）
if (defined("MXGJ_BOOTSTRAP_LOADED")) return;
define("MXGJ_BOOTSTRAP_LOADED", true);
/**
 * 沫兮官替系统 - 公共引导文件
 *
 * 职责：定义常量、注册自动加载、提供无数据库(JSON 文件)的读写工具与公共函数。
 */

// === PHP 兼容性：抑制 Deprecated/Notice 污染 JSON 输出 ===
// 生产环境 error_reporting 关闭 E_DEPRECATED & E_NOTICE，避免 trim(null) 等 8.1+ 警告 echo 到 JSON 前面
// 调试时可在 admin 设置里打开 debug 模式
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
define('MXGJ_NAME', '沫兮官替系统');
define('MXGJ_VERSION', '1.17.10');

if (!defined('MXGJ_ROOT')) {
    define('MXGJ_ROOT', dirname(__DIR__));
}
define('MXGJ_CONFIG', MXGJ_ROOT . '/config');
define('MXGJ_DATA', MXGJ_ROOT . '/data');
define('MXGJ_CACHE', MXGJ_DATA . '/cache');

// 播放器目录（苹果CMS10 MacPlayer 兼容后台）
define('MXGJ_PLAYER', MXGJ_ROOT . '/player');
define('MXGJ_PLAYER_DATA', MXGJ_PLAYER . '/data');
define('MXGJ_PLAYER_FILE', MXGJ_PLAYER_DATA . '/players.json');

// 错误日志路径（此时 MXGJ_DATA 已可用）
@ini_set('error_log', MXGJ_DATA . '/php_errors.log');

// ====== 全局 CORS 头 + OPTIONS 预检（所有入口自动生效，避免各文件重复写）======
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range, X-Requested-With, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    header('Content-Length: 0');
    exit;
}

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
    if ($content === false || trim($content ?? '') === '') {
        return $default;
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

/**
 * 写入 JSON 文件（自动创建目录）
 *
 * @return bool  true=写入成功；false=写入失败（含目录不存在、权限不足、磁盘满等）
 */
function mxgj_write_json(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        Logger::log('error', 'mxgj_write_json 失败：目录不可创建', 'error', ['dir' => $dir]);
        return false;
    }
    if (!is_writable($dir)) {
        Logger::log('error', 'mxgj_write_json 失败：目录不可写（请 chmod / chown）', 'error', ['dir' => $dir]);
        return false;
    }
    $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($content === false) {
        Logger::log('error', 'mxgj_write_json 失败：JSON 编码错误', 'error', ['file' => $file]);
        return false;
    }
    $ok = file_put_contents($file, $content) !== false;
    if (!$ok) {
        Logger::log('error', 'mxgj_write_json 失败：file_put_contents 返回 false（权限不足或磁盘满）', 'error', ['file' => $file]);
    }
    return $ok;
}

/**
 * 检测配置/数据目录的可写性（admin.php 启动时用于 UI 警告）
 *
 * @return array ['ok'=>bool, 'config'=>bool, 'data'=>bool, 'warnings'=>string[]]
 */
function mxgj_env_check(): array
{
    $warnings = [];
    $configOk = is_dir(MXGJ_CONFIG) && is_writable(MXGJ_CONFIG);
    $dataOk   = is_dir(MXGJ_DATA)   && is_writable(MXGJ_DATA);
    if (!$configOk) {
        $warnings[] = '⚠️ config/ 目录不可写 — 后台保存会静默失败。请执行：chmod 777 ' . MXGJ_CONFIG . ' 或 chown www-data:www-data ' . MXGJ_CONFIG;
    }
    if (!$dataOk) {
        $warnings[] = '⚠️ data/ 目录不可写 — 搜索缓存、日志、心跳探测都无法工作。请执行：chmod 777 ' . MXGJ_DATA . ' 或 chown www-data:www-data ' . MXGJ_DATA;
    }
    return [
        'ok'       => $configOk && $dataOk,
        'config'   => $configOk,
        'data'     => $dataOk,
        'warnings' => $warnings,
    ];
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
            'page_size'      => 50,        // 资源站查看每页返回条数（默认50）
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
                'mode'        => 'standard', // standard=标准四段式(code/msg/data/meta) | legacy=旧版扁平格式
                'show_source' => false,      // 是否在返回中附带原始请求链接（默认隐藏）
                'show_meta'   => true,       // standard 模式是否返回 meta 段（关闭则只输出 data 段）
                'fields'      => [           // legacy 模式的返回字段模板：k=输出键名，v=值来源(系统字段名)或常量字符串
                    ['k' => 'code', 'v' => 'code'],         // 状态码
                    ['k' => 'msg',  'v' => 'url'],          // msg 默认等于播放链接(便于兼容)
                    ['k' => 'url',  'v' => 'url'],          // 播放链接
                    ['k' => 'time', 'v' => 'time'],         // 耗时(毫秒)
                    ['k' => 'KFZ',  'v' => '沫兮官替系统'], // 开发者(常量)
                ],
            ],
            'app_api'        => [
                'enable'       => true,
                'require_key'  => false,
                'api_key'      => '',
                'player_type'  => 'lgzym3u8',
                'proxy_enable' => true,
                'cors'         => '*',
                'max_size_mb'  => 10,
                'rate_limit'   => 0,
            ],
            // 🔄 资源平替（资源互换 / 主池失败自动降级到备用池）
            'fallback'       => [
                'enable'           => false,  // 总开关（默认关闭，需手动开启）
                'step2_retry_main' => true,   // 平替命中后，下次请求是否再尝试主池
            ],
        ], mxgj_read_json(MXGJ_CONFIG . '/settings.json'));
    }
    return $settings;
}

/**
 * 读取后台配置的资源站列表（自动补全 role 字段）
 */
function mxgj_sites(): array
{
    static $sites = null;
    if ($sites === null) {
        $data = mxgj_read_json(MXGJ_CONFIG . '/sites.json');
        $raw  = isset($data['sites']) && is_array($data['sites']) ? $data['sites'] : [];
        // 自动补全 role 字段：缺省为 primary
        foreach ($raw as $i => $s) {
            if (!isset($s['role']) || !in_array($s['role'], ['primary', 'fallback'], true)) {
                $raw[$i]['role'] = 'primary';
            }
        }
        $sites = $raw;
    }
    return $sites;
}

/**
 * 按 role 筛选资源站
 *
 * @param array  $sites 资源站列表（mxgj_sites()）
 * @param string $role  'primary'（主池） | 'fallback'（平替池） | 'all'（全部）
 * @return array
 */
function mxgj_sites_by_role(array $sites, string $role = 'primary'): array
{
    if ($role === 'all') return $sites;
    $out = [];
    foreach ($sites as $s) {
        if (($s['role'] ?? 'primary') === $role && !empty($s['enabled'])) {
            $out[] = $s;
        }
    }
    return $out;
}

/**
 * 搜索接口模板库
 * 返回预置模板 + 用户自定义模板
 */
function mxgj_search_templates(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $defaults = [
        ['id'=>'maccms10_html', 'name'=>'苹果CMS10 · 前端搜索页 (推荐)', 'desc'=>'走 HTML 页面，有验证码的也能搜（配合解锁功能）', 'pattern'=>'https://{host}/index.php/vod/search.html?wd={kw}', 'params'=>['host','kw'], 'is_html'=>true, 'built_in'=>true],
        ['id'=>'maccms10_api',  'name'=>'苹果CMS10 · API JSON 搜索', 'desc'=>'走 api.php 返回 JSON，速度快但很多站关了搜索', 'pattern'=>'https://{host}/api.php/provide/vod/?ac=videolist&wd={kw}', 'params'=>['host','kw'], 'is_html'=>false, 'built_in'=>true],
        ['id'=>'maccms10_ajax', 'name'=>'苹果CMS10 · ajax/data 接口', 'desc'=>'前端无限加载用的 JSON 接口', 'pattern'=>'https://{host}/index.php/ajax/data?mid=1&wd={kw}', 'params'=>['host','kw'], 'is_html'=>false, 'built_in'=>true],
        ['id'=>'maccms10_rewrite','name'=>'苹果CMS10 · 伪静态', 'desc'=>'有些站开了伪静态重写', 'pattern'=>'https://{host}/search/{kw}.html', 'params'=>['host','kw'], 'is_html'=>true, 'built_in'=>true],
        ['id'=>'maccms10_list', 'name'=>'苹果CMS10 · ac=list', 'desc'=>'有些站只开放 ac=list', 'pattern'=>'https://{host}/api.php/provide/vod/?ac=list&wd={kw}', 'params'=>['host','kw'], 'is_html'=>false, 'built_in'=>true],
        ['id'=>'maccms8',       'name'=>'苹果CMS8/9 · 老版本', 'desc'=>'m=vod-search 老版路径', 'pattern'=>'https://{host}/index.php?m=vod-search-wd-{kw}.html', 'params'=>['host','kw'], 'is_html'=>true, 'built_in'=>true],
        ['id'=>'dede',          'name'=>'帝国CMS / 织梦', 'desc'=>'?keyword= 参数风格', 'pattern'=>'https://{host}/search.php?keyword={kw}', 'params'=>['host','kw'], 'is_html'=>true, 'built_in'=>true],
        ['id'=>'so',            'name'=>'通用：自定义（手动填）', 'desc'=>'完全自己写模板 URL，{host}=域名 {kw}=关键词', 'pattern'=>'', 'params'=>['host','kw'], 'is_html'=>false, 'built_in'=>false],
    ];

    $userFile = MXGJ_CONFIG . '/search_templates_user.json';
    $userList = mxgj_read_json($userFile, []);

    $all = $defaults;
    foreach ($userList as $u) {
        if (is_array($u) && !empty($u['pattern'])) {
            $u['built_in'] = false;
            $all[] = $u;
        }
    }
    $cached = $all;
    return $cached;
}

/**
 * 用模板生成搜索 URL
 * @param string $templateId 模板 ID（如 maccms10_html）
 * @param string $host       域名（如 api.wsyzy.net）
 * @return string            搜索 URL 模板（含 %u 占位符供 SiteSearcher 使用）
 */
function mxgj_render_search_template(string $templateId, string $host): string
{
    if ($templateId === 'custom') {
        return ''; // 需要用户手动填
    }
    foreach (mxgj_search_templates() as $t) {
        if ($t['id'] === $templateId && !empty($t['pattern'])) {
            // 先替换 {host}
            $url = str_replace('{host}', $host, $t['pattern']);
            // 再把 {kw} 换成 SiteSearcher 认识的 %u（URL编码）
            $url = str_replace('{kw}', '%u', $url);
            return $url;
        }
    }
    return '';
}

/**
 * 从一个裸 URL（如 https://api.wsyzy.net/api.php/provide/vod/?ac=videolist）
 * 自动反推匹配哪个搜索模板 + 提取 host
 * 返回 [templateId, host]
 */
function mxgj_guess_search_template(string $rawUrl): array
{
    $host = strtolower((string)parse_url($rawUrl, PHP_URL_HOST));
    if (!$host) return ['custom', ''];

    $path = (string)parse_url($rawUrl, PHP_URL_PATH);
    $path .= (string)parse_url($rawUrl, PHP_URL_QUERY);
    $path = strtolower($path);

    foreach (mxgj_search_templates() as $t) {
        if (empty($t['pattern']) || ($t['built_in'] ?? false) === false && ($t['id'] ?? '') === 'custom') continue;
        $pat = str_replace('{host}', preg_quote($host, '~'), $t['pattern']);
        $pat = preg_quote($pat, '~');
        $pat = str_replace('\{kw\}', '[^&\s]+', $pat);
        if (preg_match("~$pat~i", $rawUrl)) {
            return [$t['id'], $host];
        }
    }

    // 模糊匹配：路径包含关键片段
    if (strpos($path, 'index.php/vod/search') !== false) return ['maccms10_html', $host];
    if (strpos($path, 'index.php/ajax/data') !== false) return ['maccms10_ajax', $host];
    if (strpos($path, 'api.php') !== false && strpos($path, 'videolist') !== false) return ['maccms10_api', $host];
    if (strpos($path, 'api.php') !== false && strpos($path, 'ac=list') !== false) return ['maccms10_list', $host];
    if (strpos($path, 'search.php') !== false || strpos($path, 'keyword=') !== false) return ['dede', $host];
    if (strpos($path, '/search/') !== false && strpos($path, '.html') !== false) return ['maccms10_rewrite', $host];

    return ['custom', $host];
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
    $s = strtr(trim($s ?? ''), '-_', '+/');
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
    // 注意：CORS 头已在 bootstrap 顶部全局设置，这里不需要重复

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
 * 给 curl handle 自动应用系统代理
 * 读取环境变量 HTTP_PROXY / HTTPS_PROXY / NO_PROXY
 * 调用时机：curl_init() 之后，curl_exec() 之前
 */
function mxgj_apply_proxy($ch, string $url): void
{
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $host   = strtolower((string)parse_url($url, PHP_URL_HOST));

    // NO_PROXY 检查
    $noProxy = strtolower(getenv('NO_PROXY') ?: getenv('no_proxy') ?: '');
    if ($noProxy !== '') {
        foreach (preg_split('/[,\s]+/', $noProxy) as $pat) {
            $pat = trim($pat ?? '');
            if ($pat === '') continue;
            if ($pat === '*') return;
            if (strpos($host, $pat) !== false) return;
            if (str_ends_with($host, '.' . $pat)) return;
        }
    }

    // 按 scheme 选代理
    if ($scheme === 'https') {
        $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: getenv('HTTP_PROXY') ?: getenv('http_proxy');
    } else {
        $proxy = getenv('HTTP_PROXY') ?: getenv('http_proxy') ?: getenv('HTTPS_PROXY') ?: getenv('https_proxy');
    }
    $proxy = trim($proxy ?: '');

    if ($proxy !== '') {
        // 去掉 scheme 前缀（curl 的 CURLOPT_PROXY 可以带也可以不带）
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_PROXYPORT, 0);
        // 某些代理需要 CONNECT tunnel
        if ($scheme === 'https') {
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
        }
    }
}

/**
 * 获取当前系统的域名根（带 scheme），如 https://example.com
 * CLI 环境或无 Host 时返回配置中的 base_url，再无则 fallback http://localhost
 */
function mxgj_current_host(): string
{
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = (string)$_SERVER['HTTP_X_FORWARDED_PROTO'];
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $cached = $scheme . '://' . $host;
        return $cached;
    }

    // 回退：配置中的 base_url
    $st = mxgj_settings();
    $base = trim((string)($st['base_url'] ?? ''));
    if ($base !== '') {
        $cached = rtrim($base, '/');
        return $cached;
    }
    $cached = 'http://localhost';
    return $cached;
}

/**
 * 用本地 /player/ 播放器包装播放地址，方便直接调用本地播放器
 * $rawUrl 是资源站返回的原始播放地址
 */
function mxgj_player_url(string $rawUrl): string
{
    $host = mxgj_current_host();
    return $host . '/player/?url=' . rawurlencode($rawUrl);
}

/**
 * 生成标准四段式响应：{ code, msg, data, meta }
 *
 * 面向对外 API 调用（网页、APP、小程序等），字段稳定、结构清晰，
 * 方便前端做统一的 code 分发和错误提示。
 *
 * @param array $vars    内部值，可包含：code,msg,url,title,episode,site,source,time,
 *                       platform,vid,cid,is_special,player_url,raw_url,
 *                       from_fallback,from_pool,cached,params,parsed,debug
 * @param bool  $debug   是否附加 debug 详情
 * @return array
 */
function mxgj_build_standard_response(array $vars, bool $debug): array
{
    // === code 规范化：200=成功, 4xx=客户端错误, 5xx=服务端错误 ===
    $code = (int)($vars['code'] ?? 200);
    $msg  = (string)($vars['msg'] ?? ($code === 200 ? 'success' : mxgj_code_message($code)));

    // === data：成功时放播放信息，失败时 null ===
    $data = null;
    if ($code === 200 && !empty($vars['url'])) {
        $data = [
            'url'           => $vars['url'] ?? '',
            'player_url'    => $vars['player_url'] ?? '',
            'raw_url'       => $vars['raw_url'] ?? '',
            'title'         => $vars['title'] ?? '',
            'episode'       => (int)($vars['episode'] ?? 0),
            'platform'      => $vars['platform'] ?? '',
            'site'          => $vars['site'] ?? '',
            'is_special'    => !empty($vars['is_special']),
            'site_special'  => !empty($vars['is_special']),
            'from_fallback' => !empty($vars['from_fallback']),
            'from_pool'     => $vars['from_pool'] ?? 'primary',
        ];
    }

    // === meta：元信息（方便调用方判断版本/参数/耗时） ===
    $cfg      = mxgj_settings()['output'] ?? [];
    $showMeta = !isset($cfg['show_meta']) || $cfg['show_meta'];

    $out = ['code' => $code, 'msg' => $msg];
    if ($showMeta) {
        $out['meta'] = [
            'api_version' => MXGJ_VERSION,
            'service'     => MXGJ_NAME,
            'mode'        => 'standard',
            'request_id'  => mxgj_request_id(),
            'elapsed_ms'  => (float)($vars['time'] ?? 0),
            'timestamp'   => time(),
            'cached'      => !empty($vars['cached']),
        ];
        // 附加原始请求链接（受 show_source 开关控制）
        if (!empty($cfg['show_source']) && !empty($vars['source'])) {
            $out['meta']['source'] = $vars['source'];
        }
        // 附加解析出的平台 / vid / cid（方便排查）
        if (!empty($vars['platform'])) {
            $out['meta']['platform'] = $vars['platform'];
        }
        if (!empty($vars['vid']))   { $out['meta']['vid']   = $vars['vid']; }
        if (!empty($vars['cid']))   { $out['meta']['cid']   = $vars['cid']; }
        if (!empty($vars['params'])) { $out['meta']['params'] = $vars['params']; }
    }
    $out['data'] = $data;

    // debug 信息：追加到 meta.debug 或顶层 debug（取决于 show_meta）
    if ($debug && !empty($vars['debug'])) {
        if ($showMeta) {
            $out['meta']['debug'] = $vars['debug'];
        } else {
            $out['debug'] = $vars['debug'];
        }
    }
    return $out;
}

/**
 * 把 HTTP 风格错误码翻译为中文描述
 */
function mxgj_code_message(int $code): string
{
    static $map = [
        200 => '成功',
        400 => '请求参数错误',
        401 => '未授权',
        403 => '访问被拒绝',
        404 => '资源未找到',
        429 => '请求过于频繁',
        500 => '服务器内部错误',
        501 => '功能未实现',
        502 => '无法识别该链接对应的剧名',
        503 => '所有资源站暂不可用',
    ];
    return $map[$code] ?? '未知错误';
}

/**
 * 快速构建一个标准格式的错误响应（用于鉴权失败、参数错误等提前返回场景）
 *
 * @param int   $code     业务状态码（200/400/403/404/500...）
 * @param string $msg     错误描述
 * @param array  $extra   附加 vars（可传 platform/vid/cid 等）
 * @param int    $httpCode HTTP 响应码（默认与 $code 一致，少数特殊场景可单独指定）
 */
function mxgj_early_response(int $code, string $msg, array $extra = [], ?int $httpCode = null): void
{
    $vars = array_merge([
        'code' => $code,
        'msg'  => $msg,
        'url'  => '',
        'time' => round((microtime(true) - ($extra['t0'] ?? (isset($GLOBALS['t0']) ? $GLOBALS['t0'] : microtime(true)))) * 1000, 1),
    ], $extra);
    $out = mxgj_build_output($vars, false);
    mxgj_json_out($out, $httpCode ?? ($code >= 100 && $code < 600 ? $code : 200));
}
function mxgj_request_id(): string
{
    static $rid = null;
    if ($rid !== null) return $rid;
    $rid = substr(bin2hex(random_bytes(8)), 0, 16);
    return $rid;
}

/**
 * 依据「输出返回设置」把内部变量映射为对外返回的 JSON 字段
 *
 * - mode=standard（默认）:  返回 { code, msg, data, meta } 四段式，方便外部调用
 * - mode=legacy:             按 fields 模板输出扁平结构，保持向后兼容
 *
 * @param array $vars 内部值，可包含：code,msg,url,title,episode,site,source,time,
 *                    platform,vid,cid,is_special,player_url,raw_url,
 *                    from_fallback,from_pool,cached,debug
 * @param bool  $debug 是否附加 debug 详情
 * @return array 对外返回字段
 */
function mxgj_build_output(array $vars, bool $debug): array
{
    $cfg  = mxgj_settings()['output'] ?? [];
    $mode = $cfg['mode'] ?? 'standard';

    // 标准模式：直接返回四段式
    if ($mode === 'standard') {
        return mxgj_build_standard_response($vars, $debug);
    }

    // === legacy 模式：以下为旧版扁平结构 ===
    $fields = is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];
    // 系统值来源映射：扩展到所有可供 fields 引用的字段
    $fMap   = ['code', 'msg', 'url', 'title', 'episode', 'site', 'source', 'time',
               'platform', 'vid', 'cid', 'cached', 'params',
               'is_special', 'site_special', 'player_url', 'raw_url',
               'from_fallback', 'from_pool'];

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

    // 特殊资源站专用字段：命中时自动追加到返回末尾（不受 fields 配置限制）
    if (!empty($vars['is_special'])) {
        foreach (['is_special', 'site_special', 'player_url', 'raw_url'] as $k) {
            if (!array_key_exists($k, $out) && array_key_exists($k, $vars)) {
                $out[$k] = $vars[$k];
            }
        }
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
    $cleaned = ['items' => 0];

    // 1) 搜索缓存
    $n = 0;
    foreach (glob(MXGJ_CACHE . '/*.cache') ?: [] as $f) {
        if (@unlink($f)) $n++;
    }
    $cleaned['cache'] = ['dir' => MXGJ_CACHE, 'count' => $n];
    $cleaned['items'] += $n;

    // 2) 站点健康状态 + 心跳锁 + 所有 *.lock / *.pid 锁
    foreach (['site_health.json', 'heartbeat.lock'] as $f) {
        if (is_file(MXGJ_DATA . '/' . $f)) {
            @unlink(MXGJ_DATA . '/' . $f);
            $cleaned['items']++;
        }
    }
    foreach (glob(MXGJ_DATA . '/*.lock') ?: [] as $f) { @unlink($f); $cleaned['items']++; }
    foreach (glob(MXGJ_DATA . '/*.pid') ?: [] as $f)  { @unlink($f); $cleaned['items']++; }
    $cleaned['health'] = 'site_health';

    // 3) 日志（含所属系统目录）
    $n = 0;
    foreach (glob(MXGJ_DATA . '/logs/*.json') ?: [] as $f) {
        if (@unlink($f)) $n++;
    }
    $cleaned['logs'] = ['dir' => MXGJ_DATA . '/logs', 'count' => $n];
    $cleaned['items'] += $n;

    // 4) 定时采集运行日志
    if (@unlink(MXGJ_DATA . '/cron_mapping.log')) $cleaned['items']++;
    $cleaned['cron'] = 'cron_mapping.log';

    // 5) cookies 目录（临时 cookie 文件不影响配置，但一并清理彻底）
    $n = 0;
    foreach (glob(MXGJ_DATA . '/cookies/*') ?: [] as $f) {
        if (is_file($f) && @unlink($f)) $n++;
    }
    $cleaned['cookies'] = ['count' => $n];
    $cleaned['items'] += $n;

    // 6) PHP opcache（PHP-FPM 场景下最常见的"改了没生效"原因）
    if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
        $cleaned['opcache_reset'] = @opcache_reset();
    }

    return $cleaned;
}

/**
 * 获取全部播放器列表（静态缓存，一次请求内只读一次文件）
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
 * 获取默认播放器（优先 is_default=true 且 enabled，否则第一个启用的）
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
 * 保存播放器列表到 players.json
 */
function mxgj_save_players(array $players): bool
{
    $dir = dirname(MXGJ_PLAYER_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return mxgj_write_json(MXGJ_PLAYER_FILE, ['players' => array_values($players)]);
}
