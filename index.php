<?php
/**
 * 沫兮官替系统 - 前台解析 API
 *
 * 使用：/index.php?key=<访问密钥>&url=<官方视频链接>[&page=<集数>][&debug=1][&callback=<jsonp>]
 * 例：   /index.php?key=YOUR_KEY&url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce
 *
 * 说明：key 参数固定在 url 之前（key 校验最先执行，key 为空字符串则跳过鉴权）。
 *
 * 流程：
 *  0. 校验访问密钥 key（可选）
 *  1. 解析官方链接 → 平台 / vid / cid
 *  2. 命中本地映射表（mapping.json）得到「剧名 + 集数」
 *  3. 多线程并发向后台所有资源站搜索对应资源
 *  4. 命中返回 code=200 + m3u8 地址；否则返回明确错误码
 */

require __DIR__ . '/lib/bootstrap.php';

$debug = !empty($_GET['debug']);
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$t0    = microtime(true); // 计时（毫秒）
$ip    = $_SERVER['REMOTE_ADDR'] ?? '';

/* 0. 访问密钥鉴权（key 参数在最前，config/key.php 返回空串则跳过） */
$visitKey = @include MXGJ_CONFIG . '/key.php';
if (is_string($visitKey) && $visitKey !== '' && (($_GET['key'] ?? '') !== $visitKey)) {
    Logger::log('error', '访问被拒绝：key 缺失或无效', 'warn', ['ip' => $ip]);
    mxgj_json_out(['code' => 403, 'msg' => '访问被拒绝：缺少或无效的 key 参数', 'url' => ''], 403);
}

/* 1. 读取 url 参数（位于 key 之后） */
$raw = isset($_GET['url']) ? trim($_GET['url']) : '';

// 1. 校验链接
if ($raw === '') {
    Logger::log('error', '缺少 url 参数', 'warn', ['ip' => $ip]);
    mxgj_json_out(['code' => 400, 'msg' => '缺少 url 参数', 'url' => ''], 400);
}
if (strpos($raw, 'http') !== 0) {
    $raw = 'http://' . $raw;
}
if (!parse_url($raw, PHP_URL_HOST)) {
    Logger::log('error', '链接格式非法：' . $raw, 'warn', ['ip' => $ip]);
    mxgj_json_out(['code' => 400, 'msg' => '链接格式非法', 'url' => ''], 400);
}

// 2. 解析官方链接
$parsed = LinkParser::parse($raw);

// 3. 本地映射表（无数据库）
$mapping = mxgj_read_json(MXGJ_CONFIG . '/mapping.json', []);
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
    mxgj_json_out([
        'code' => 502,
        'msg'  => '无法识别该链接对应的剧名，请到后台「映射表」添加映射'
                . ($mapHint !== '' ? '（' . $mapHint . '）' : ''),
        'url'  => '',
    ], 200);
}

// 4. 读配置
$settings = mxgj_settings();
$sites    = mxgj_sites();
$now      = time();

// 5. 缓存命中（相同 剧名+集数）
$cacheKey    = 'search:' . mxgj_lower($name) . ':' . $episode;
$cached      = Cache::get($cacheKey);
$cachedIsHit = false;
if ($cached && isset($cached['code'], $cached['url']) && ($cached['time'] ?? 0) + (int)$settings['cache_ttl'] > $now) {
    $cachedIsHit = true;
}

if ($cachedIsHit) {
    $result = $cached;
} else {
    // 6. 多线程搜索全部资源站
    $result = SiteSearcher::search($sites, $name, $episode, (int)$settings['timeout']);
    if ($result['code'] === 200) {
        $result['time'] = $now;
        Cache::set($cacheKey, $result, (int)$settings['cache_ttl']);
    }
}

// 7. 组装返回
//    - 特殊资源站（is_special=true）自动套 本地//player/ 播放器，无需手动拼接
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
    'url'          => $finalUrl,                      // 最终可播放地址（特殊站已自动套播放器）
    'title'        => $name,
    'episode'      => $episode,
    'site'         => $result['site'] ?? '',
    'source'       => $raw,
    'time'         => round((microtime(true) - $t0) * 1000, 1),
    // 特殊资源站专用字段
    'is_special'   => $isSpecial,                     // 是否特殊资源站命中
    'site_special' => $isSpecial,                     // 同 is_special，兼容两种命名
    'player_url'   => $playerUrl,                     // 本地播放器入口 URL
    'raw_url'      => $rawPlayUrl,                    // 资源站原始返回地址（未套 proxy / 播放器）
];
if ($debug) {
    $vars['debug'] = [
        'parsed' => $parsed,
        'title'  => $name,
        'episode'=> $episode,
        'name_from' => $nameFrom !== '' ? 'page抓取' : 'mapping/解析',
        'page_title' => $nameFrom,
        'sites'  => count($sites),
        'cached' => $cachedIsHit,
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
        'code'     => $result['code'],
        'site'     => $result['site'] ?? '',
        'url'      => $result['url'] ?? '',
        'cached'   => $cachedIsHit,
        'time_ms'  => round((microtime(true) - $t0) * 1000, 1),
        'from'     => $nameFrom !== '' ? 'page抓取' : 'mapping/解析',
        'ip'       => $ip,
    ]
);

mxgj_json_out($out);
