<?php
/**
 * 沫兮官替系统 - 前台解析 API
 *
 * 使用：/index.php?key=<访问密钥>&url=<官方视频链接>[&page=<集数>][&debug=1][&callback=<jsonp>]
 * 例：   /index.php?key=YOUR_KEY&url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce
 *
 * 说明：key 参数固定在 url 之前（key 校验最先执行，key 为空字符串则跳过鉴权）。
 *
 * 返回格式（v1.17.9+ 默认 standard 四段式，可后台切换 legacy 扁平格式）：
 *   success: { code:200, msg:"success", data:{url,title,episode,site,...}, meta:{api_version,...} }
 *   error:   { code:400, msg:"缺少 url 参数", data:null, meta:{...} }
 *
 * 流程：
 *  0. 校验访问密钥 key（可选）
 *  1. 解析官方链接 → 平台 / vid / cid
 *  2. 命中本地映射表（mapping.json）得到「剧名 + 集数」
 *  3. 多线程并发向后台所有资源站搜索对应资源
 *  4. 命中返回 code=200 + m3u8 地址；否则返回明确错误码
 */

require_once __DIR__ . '/lib/bootstrap.php';

$debug = !empty($_GET['debug']);
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$t0    = microtime(true); // 计时（毫秒）
$ip    = $_SERVER['REMOTE_ADDR'] ?? '';

/* 0. 访问密钥鉴权（key 参数在最前，config/key.php 返回空串则跳过） */
$visitKey = @include MXGJ_CONFIG . '/key.php';
if (is_string($visitKey) && $visitKey !== '' && (($_GET['key'] ?? '') !== $visitKey)) {
    Logger::log('error', '访问被拒绝：key 缺失或无效', 'warn', ['ip' => $ip]);
    mxgj_early_response(403, '访问被拒绝：缺少或无效的 key 参数', ['source' => '']);
}

/* 1. 读取 url 参数（位于 key 之后） */
$raw = isset($_GET['url']) ? trim($_GET['url']) : '';

// 1. 校验链接
if ($raw === '') {
    Logger::log('error', '缺少 url 参数', 'warn', ['ip' => $ip]);
    mxgj_early_response(400, '缺少 url 参数', ['source' => '']);
}
if (strpos($raw, 'http') !== 0) {
    $raw = 'http://' . $raw;
}
if (!parse_url($raw, PHP_URL_HOST)) {
    Logger::log('error', '链接格式非法：' . $raw, 'warn', ['ip' => $ip]);
    mxgj_early_response(400, '链接格式非法', ['source' => $raw]);
}

// 2. 解析官方链接
$parsed = LinkParser::parse($raw);

// 3. 本地映射表（无数据库）
$mapping = mxgj_mapping_data();
$name    = $parsed['title'];
$episode = $parsed['episode'] > 0 ? $parsed['episode'] : $page;

// 3.1 官方ID精确映射：vid / cid → 剧名+集数（仅启用条目生效）
$epMap = isset($mapping['episode']) && is_array($mapping['episode']) ? $mapping['episode'] : [];
foreach (['vid:' . $parsed['vid'], 'cid:' . $parsed['cid']] as $key) {
    if ($key === 'vid:' || $key === 'cid:') {
        continue;
    }
    if (!mxgj_mapping_enabled($mapping, 'episode', $key)) {
        continue; // 该映射被快捷禁用
    }
    if (isset($epMap[$key]) && !empty($epMap[$key]['name'])) {
        $name    = $epMap[$key]['name'];
        $episode = !empty($epMap[$key]['episode']) ? (int)$epMap[$key]['episode'] : $episode;
        break;
    }
}

// 3.2 腾讯 cid → 剧名
if ($name === '' && $parsed['cid'] !== '') {
    $cidMap = isset($mapping['cid']) && is_array($mapping['cid']) ? $mapping['cid'] : [];
    if (isset($cidMap[$parsed['cid']]) && mxgj_mapping_enabled($mapping, 'cid', $parsed['cid'])) {
        $name = $cidMap[$parsed['cid']];
    }
}

// 3.3 剧名映射（解析出的剧名 → 资源站使用的剧名）
if ($name !== '') {
    $titleMap = isset($mapping['title']) && is_array($mapping['title']) ? $mapping['title'] : [];
    if (isset($titleMap[$name]) && mxgj_mapping_enabled($mapping, 'title', $name)) {
        $name = $titleMap[$name];
    }
}

// 3.4 映射仍无法识别时，自动抓取官方页面获取「剧名 + 集数」
$nameFrom = '';
if ($name === '') {
    $stNow = mxgj_settings();
    $pgKey = 'page:' . ($parsed['vid'] !== '' ? $parsed['vid']
        : ($parsed['cid'] !== '' ? $parsed['cid'] : md5($raw)));
    $pageRes = Cache::get($pgKey);
    if (!is_array($pageRes) || empty($pageRes['title'])) {
        $pageRes = PageResolver::resolve($raw, $episode, (int)$stNow['timeout']);
        if (!empty($pageRes['title'])) {
            Cache::set($pgKey, $pageRes, 600);
        }
    }
    if (!empty($pageRes['title'])) {
        $nameFrom = $pageRes['title'];
        $name     = $pageRes['title'];
        if (!empty($pageRes['episode'])) {
            $episode = (int)$pageRes['episode'];
        }
        // 识别成功即自动固化映射，下次直接命中、不再联网抓取
        if ($episode > 0) {
            mxgj_auto_mapping($parsed, $name, $episode);
        }
    }
}

if ($name === '') {
    $mapHint = $parsed['vid'] !== '' ? 'vid=' . $parsed['vid']
             : ($parsed['cid'] !== '' ? 'cid=' . $parsed['cid'] : '');
    Logger::log('error', '无法识别剧名：' . $raw, 'warn', ['vid' => $parsed['vid'], 'cid' => $parsed['cid'], 'ip' => $ip]);
    mxgj_early_response(502, '无法识别该链接对应的剧名，请到后台「映射表」添加映射'
        . ($mapHint !== '' ? '（' . $mapHint . '）' : ''), [
            'platform' => $parsed['platform'],
            'vid'      => $parsed['vid'],
            'cid'      => $parsed['cid'],
            'source'   => $raw,
        ]);
}

// 4. 读配置
$settings = mxgj_settings();
$sites    = mxgj_sites();
$now      = time();

// 5. 缓存命中（相同 剧名+集数）
//    缓存 key 加层区分：
//      search:xxx:yyy          = 主池命中（含降级到平替的最终结果）
//      search_fb:xxx:yyy       = 仅平替池命中（用 step2_retry_main 控制是否需要重查主池）
$fallbackCfg = is_array($settings['fallback'] ?? null) ? $settings['fallback'] : [];
$fallbackEnable  = !empty($fallbackCfg['enable']);
$retryMainOnFb   = !empty($fallbackCfg['step2_retry_main']);

$primaryKey = 'search:' . mxgj_lower($name) . ':' . $episode;
$fallbackKey= 'search_fb:' . mxgj_lower($name) . ':' . $episode;
$now        = time();

$cachedResult = Cache::get($primaryKey);
$cachedHit    = false; // 是否命中缓存（给 debug / 日志用）
// Cache::get 已经负责过期判断（含 cache_ttl 变更感知），这里只检查值是否有效
if ($cachedResult && isset($cachedResult['code'], $cachedResult['url'])) {
    // 主缓存命中：如果是之前平替命中的且 step2_retry_main=true，这次再去主池试试
    if (!empty($cachedResult['from_fallback']) && $retryMainOnFb && $fallbackEnable) {
        Logger::log('search', '缓存为平替命中，重新尝试主池：' . $name . ' 第' . $episode . '集', 'info');
        $cachedResult = null; // 让下面重新搜
    } else {
        $cachedHit = true;
    }
} else {
    $cachedResult = null;
}

if ($cachedResult) {
    $result = $cachedResult;
} else {
    // 6. 🔄 两级搜索：主池 → 平替池（平替开关开启且主池未命中时自动降级）
    $result = SiteSearcher::searchWithFallback(
        $sites,
        $name,
        $episode,
        (int)$settings['timeout'],
        $fallbackCfg
    );
    if (($result['code'] ?? 0) === 200 && !empty($result['url'])) {
        $result['time'] = $now;
        // 写缓存：主池 / 平替池 命中都写主缓存，下次直接用
        Cache::set($primaryKey, $result, (int)$settings['cache_ttl']);
    }
}

// 7. 组装返回
//    - 特殊资源站（is_special=true）自动套 本地 //player/ 播放器，无需手动拼接
//    - 专用字段：is_special / player_url / raw_url / site_special
$isSpecial  = !empty($result['site_special']);
$rawPlayUrl = $result['raw_url'] ?? $result['url'] ?? '';  // finalizeUrl 前的原始地址
$playerUrl  = $rawPlayUrl !== '' ? mxgj_player_url($rawPlayUrl) : '';

// 最终对外面向的 url：特殊站走 /player/，否则按 SiteSearcher 返回值（可能已带 proxy）
$finalUrl = $result['url'] ?? '';
if ($isSpecial && $playerUrl !== '') {
    $finalUrl = $playerUrl;
}

$vars = [
    'code'         => $result['code'],
    'msg'          => $result['msg'] ?? '',
    'url'          => $finalUrl,               // 最终可播放地址（特殊站已自动套播放器）
    'title'        => $name,
    'episode'      => $episode,
    'site'         => $result['site'] ?? '',
    'platform'     => $parsed['platform'],     // 解析出的来源平台（腾讯/爱奇艺/...）
    'vid'          => $parsed['vid'],
    'cid'          => $parsed['cid'],
    'source'       => $raw,
    'time'         => round((microtime(true) - $t0) * 1000, 1),
    // 特殊资源站专用字段
    'is_special'   => $isSpecial,              // 是否特殊资源站命中
    'site_special' => $isSpecial,              // 同 is_special，兼容两种命名
    'player_url'   => $playerUrl,              // 本地播放器入口 URL
    'raw_url'      => $rawPlayUrl,             // 资源站原始返回地址（未套 proxy / 播放器）
    // 🔄 平替命中标记
    'from_fallback' => !empty($result['from_fallback']), // 是否命中了平替池
    'from_pool'     => $result['from_pool'] ?? 'primary', // 命中的池：primary / fallback
    // 缓存标记
    'cached'       => $cachedHit,
    // 本次请求的公开参数（供 meta.params 参考）
    'params'       => [
        'page'   => $page,
        'debug'  => $debug ? 1 : 0,
    ],
];
if ($debug) {
    $vars['debug'] = [
        'parsed'    => $parsed,
        'title'     => $name,
        'episode'   => $episode,
        'name_from' => $nameFrom !== '' ? 'page抓取' : 'mapping/解析',
        'page_title'=> $nameFrom,
        'sites'     => count($sites),
        'cached'    => $cachedHit,
    ];
}
$out = mxgj_build_output($vars, $debug);

// 8. 搜索调用日志（记录每次搜索请求与结果，便于排查/审计）
Logger::log(
    'search',
    ($result['code'] === 200 ? '命中' : '未命中') . '：' . $name . ' 第' . $episode . '集',
    $result['code'] === 200 ? 'success' : 'warn',
    [
        'title'    => $name,
        'episode'  => $episode,
        'platform' => $parsed['platform'],
        'code'     => $result['code'],
        'site'     => $result['site'] ?? '',
        'url'      => $result['url'] ?? '',
        'cached'   => $cachedHit,
        'time_ms'  => round((microtime(true) - $t0) * 1000, 1),
        'from'     => $nameFrom !== '' ? 'page抓取' : 'mapping/解析',
        'ip'       => $ip,
    ]
);

/* ==========================================================================
 * v1.17.17: 智能自动重定向 —— 浏览器访问 ?url=xxx 时直接跳播放器
 *
 * 触发条件（同时满足全部）：
 *   1) code = 200（解析成功）
 *   2) player_url 非空（有播放器可跳）
 *   3) 不是 JSONP callback（有 callback=xxx 就返回 JSON）
 *   4) 未显式带 ?raw=1（raw 强制返回 JSON）
 *   5) 浏览器 Accept 偏好 text/html 高于 application/json
 *
 * 显式参数：
 *   ?redirect=1  → 强制跳（覆盖自动判断）
 *   ?redirect=0  → 强制返回 JSON
 *   ?raw=1       → 强制返回 JSON
 * ========================================================================== */

$doRedirect = false;
$redirectOverride = $_GET['redirect'] ?? null;

if ($redirectOverride !== null) {
    // 显式参数优先
    $doRedirect = (string)$redirectOverride === '1';
} elseif (isset($_GET['raw'])) {
    $doRedirect = false;  // raw=1 强制 JSON
} else {
    // 自动检测：浏览器 Accept 头偏好 text/html
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $prefersHtml  = str_contains($accept, 'text/html');
    $prefersJson  = str_contains($accept, 'application/json');
    $hasCallback  = !empty($_GET['callback']);

    // 浏览器请求（非 API 客户端）→ text/html 存在且 application/json 不存在，或 html 优先
    if ($prefersHtml && !$hasCallback) {
        // Accept 里 application/json 优先级通常用 q 参数
        // 简单判断：如果明确包含 application/json 且没有 text/html 的 q 更高，就不跳
        $doRedirect = true;
        if ($prefersJson) {
            // 两者都有 → 看 q 值（浏览器默认给 text/html 更高 q）
            // 简化：如果 user-agent 里有 curl/wget/Postman/python/axios → 不跳
            $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
            $apiClients = ['curl', 'wget', 'postman', 'python', 'axios', 'fetch', 'guzzle', 'node-fetch', 'okhttp', 'java/'];
            foreach ($apiClients as $c) {
                if (str_contains($ua, $c)) { $doRedirect = false; break; }
            }
        }
    }
}

// 触发重定向：code=200 + player_url 非空
if ($doRedirect && (int)($vars['code'] ?? 0) === 200 && !empty($vars['player_url'])) {
    $target = $vars['player_url'];
    header('Location: ' . $target, true, 302);
    exit;
}

mxgj_json_out($out);
