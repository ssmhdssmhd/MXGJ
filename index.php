<?php
/**
 * 沫兮官替系统 - 前台解析 API
 *
 * 使用：/index.php?url=<官方视频链接>[&page=<集数>][&key=<访问密钥>][&debug=1][&callback=<jsonp>]
 * 例：   /index.php?url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce
 *
 * 流程：
 *  1. 解析官方链接 → 平台 / vid / cid
 *  2. 命中本地映射表（mapping.json）得到「剧名 + 集数」
 *  3. 多线程并发向后台所有资源站搜索对应资源
 *  4. 命中返回 code=200 + m3u8 地址；否则返回明确错误码
 */

require __DIR__ . '/lib/bootstrap.php';

$raw   = isset($_GET['url']) ? trim($_GET['url']) : '';
$debug = !empty($_GET['debug']);
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

/* 可选访问密钥（config/key.php 返回空串则关闭） */
$visitKey = @include MXGJ_CONFIG . '/key.php';
if (is_string($visitKey) && $visitKey !== '' && (($_GET['key'] ?? '') !== $visitKey)) {
    mxgj_json_out(['code' => 403, 'msg' => '访问被拒绝：缺少有效的 key 参数', 'url' => ''], 403);
}

// 1. 校验链接
if ($raw === '') {
    mxgj_json_out(['code' => 400, 'msg' => '缺少 url 参数', 'url' => ''], 400);
}
if (strpos($raw, 'http') !== 0) {
    $raw = 'http://' . $raw;
}
if (!parse_url($raw, PHP_URL_HOST)) {
    mxgj_json_out(['code' => 400, 'msg' => '链接格式非法', 'url' => ''], 400);
}

// 2. 解析官方链接
$parsed = LinkParser::parse($raw);

// 3. 本地映射表（无数据库）
$mapping = mxgj_read_json(MXGJ_CONFIG . '/mapping.json', []);
$name    = $parsed['title'];
$episode = $parsed['episode'] > 0 ? $parsed['episode'] : $page;

// 3.1 官方ID精确映射：vid / cid → 剧名+集数
$epMap = isset($mapping['episode']) && is_array($mapping['episode']) ? $mapping['episode'] : [];
foreach (['vid:' . $parsed['vid'], 'cid:' . $parsed['cid']] as $key) {
    if ($key === 'vid:' || $key === 'cid:') {
        continue;
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
    if (isset($cidMap[$parsed['cid']])) {
        $name = $cidMap[$parsed['cid']];
    }
}

// 3.3 剧名映射（解析出的剧名 → 资源站使用的剧名）
if ($name !== '') {
    $titleMap = isset($mapping['title']) && is_array($mapping['title']) ? $mapping['title'] : [];
    if (isset($titleMap[$name])) {
        $name = $titleMap[$name];
    }
}

if ($name === '') {
    mxgj_json_out([
        'code' => 502,
        'msg'  => '无法识别该链接对应的剧名，请到后台「映射表」添加 vid/cid 映射',
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
$out = [
    'code'    => $result['code'],
    'url'     => $result['url'] ?? '',
    'msg'     => $result['msg'] ?? '',
    'episode' => $result['episode'] ?? $episode,
    'source'  => $raw,
];
if ($debug) {
    $out['debug'] = [
        'parsed' => $parsed,
        'title'  => $name,
        'episode'=> $episode,
        'sites'  => count($sites),
        'cached' => $cachedIsHit,
    ];
}
mxgj_json_out($out, $result['code'] === 200 ? 200 : 200);
