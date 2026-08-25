<?php
/**
 * 沫兮官替系统 - 表面播放入口（App 表面播放链接）
 *
 * 前台返回的播放链接统一伪装为：/play.php?u=<base64url 加密的真实播放地址>
 * 该入口保证链接「表面是一个链接」且可正常打开播放（浏览器/APP 均可识别）：
 *
 *   1) 302 跳转模式：直接跳转到真实播放地址（省流量，但真实地址在跳转后可见）
 *   2) 代理转发模式（默认，推荐）：
 *      - m3u8：本站抓取并重写切片/密钥地址（同样走本站入口），完全隐藏真实源
 *      - ts/mp4 等二进制：本站流式转发，APP 原生播放器可直接播放
 *      - HTML 播放页：自动降级为 302 跳转
 *
 * 调用：/play.php?u=<base64url>  （兼容 ?url=<原始地址>）
 */

require __DIR__ . '/lib/bootstrap.php';

$app = mxgj_settings()['app'] ?? [];
$mode = (($app['surface_mode'] ?? 'proxy') === 'proxy') ? 'proxy' : 'redirect';
$path = trim((string)($app['surface_path'] ?? 'play.php'), '/');

// 读取目标地址（优先加密参数 u，兼容明文 url）
$u = trim((string)($_GET['u'] ?? ''));
if ($u === '') {
    $u = trim((string)($_GET['url'] ?? ''));
}
if ($u === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('bad request: missing u/url');
}
$real = mxgj_b64url_decode($u);
if ($real === '' || strpos($real, 'http') !== 0) {
    $real = (strpos($u, 'http') === 0) ? $u : ''; // 兼容明文地址（?url= / ?u=http://...）
}
if ($real === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('bad request: invalid url');
}
if (strpos($real, 'http') !== 0) {
    $real = 'http://' . $real;
}
$parts = parse_url($real);
$host  = $parts['host'] ?? '';
if (empty($parts['scheme']) || $host === '' || preg_match('~[\x00-\x20]~', $host)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('bad request: invalid url');
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

/**
 * 解析相对地址为绝对地址
 */
function mxgj_play_resolve(string $rel, string $base): string
{
    if ($rel === '') {
        return $base;
    }
    if (strpos($rel, 'http') === 0 || strpos($rel, '//') === 0) {
        return strpos($rel, '//') === 0 ? 'http:' . $rel : $rel;
    }
    $b = parse_url($base);
    if (empty($b['scheme']) || empty($b['host'])) {
        return $rel;
    }
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if ($rel[0] === '/') {
        return $origin . $rel;
    }
    $dir = isset($b['path']) ? substr($b['path'], 0, strrpos($b['path'], '/') + 1) : '/';
    return $origin . $dir . $rel;
}

/**
 * HEAD 探测目标类型
 */
function mxgj_play_probe(string $url, string $ua): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY           => true,
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => 6,
        CURLOPT_CONNECTTIMEOUT   => 8,
        CURLOPT_TIMEOUT          => 15,
        CURLOPT_SSL_VERIFYPEER   => false,
        CURLOPT_SSL_VERIFYHOST   => false,
        CURLOPT_USERAGENT        => $ua,
        CURLOPT_HTTPHEADER       => ['Accept: */*', 'Referer: ' . (parse_url($url, PHP_URL_SCHEME) ? $url : '')],
    ]);
    $ok = curl_exec($ch) !== false;
    $info = curl_getinfo($ch);
    curl_close($ch);
    return [
        'ok'    => $ok,
        'type'  => (string)($info['content_type'] ?? ''),
        'code'  => (int)($info['http_code'] ?? 0),
        'final' => (string)($info['url'] ?? $url),
    ];
}

/**
 * 抓取正文（用于 m3u8）
 */
function mxgj_play_fetch(string $url, string $ua): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 6,
        CURLOPT_CONNECTTIMEOUT  => 8,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => false,
        CURLOPT_USERAGENT       => $ua,
        CURLOPT_HTTPHEADER      => ['Accept: */*', 'Referer: ' . (parse_url($url, PHP_URL_SCHEME) ? $url : '')],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body === false ? '' : $body;
}

/**
 * 渲染内联播放器页面（HTML 播放页专用）
 *
 * 真实地址是「HTML 播放页」（如 https://lgvideo.xyz/player/xxx）时，
 * 302 跳转会拿到 HTML 页、APP 原生播放器无法播放；这里直接渲染
 * 与 player.php / lgzym3u8 播放器一致的 vv00.xyz iframe 播放器，
 * 保证表面链接可正常打开播放（浏览器 / APP webview 均可）。
 */
function mxgj_play_render_player(string $real): void
{
    $u = rawurlencode($real);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="referrer" content="no-referrer">
<title>在线播放</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{width:100%;height:100%;overflow:hidden;background:#000}
  iframe{width:100%;height:100%;border:0;display:block}
</style>
</head>
<body>
<iframe src="https://vv00.xyz?url=' . $u . '" frameborder="0" scrolling="no" allowfullscreen></iframe>
</body>
</html>';
    exit;
}

// 探测类型
$probe = mxgj_play_probe($real, $ua);
$ctype = $probe['type'];
$finalUrl = $probe['final'] !== '' ? $probe['final'] : $real;

// 类型识别
$isM3u8 = stripos($ctype, 'mpegurl') !== false || preg_match('~\.m3u8($|\?)~i', $finalUrl);
$isHtml = stripos($ctype, 'text/html') !== false;
// 播放页地址（如 /player/ /play/ /live/ …）：HEAD 探测失败时也能据此识别
$isPlayerPage = (bool)preg_match('~/(player|play|live)/~i', (string)parse_url($finalUrl, PHP_URL_PATH));

// ① HTML 播放页 / 播放页地址：渲染内联播放器（vv00.xyz iframe），保证可正常打开播放
if ($isHtml || $isPlayerPage) {
    mxgj_play_render_player($real);
}

// ② 302 跳转模式：仅对非播放页生效（省流量，真实地址在跳转后可见）
if ($mode !== 'proxy') {
    header('Location: ' . $finalUrl);
    exit;
}

// ③ m3u8：抓取并重写切片/密钥地址为本站入口
if ($isM3u8) {
    $body = mxgj_play_fetch($finalUrl, $ua);
    if ($body === '') {
        header('Location: ' . $finalUrl);
        exit;
    }
    $entry = $path . '?u=';
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $out   = [];
    foreach ($lines as $line) {
        $line = rtrim($line, "\r");
        if ($line === '') {
            $out[] = '';
            continue;
        }
        if ($line[0] === '#') {
            // 重写 EXT-X-KEY / EXT-X-MAP 中的 URI（密钥/初始化段同样走本站入口）
            if (preg_match('/#EXT-X-(?:KEY|MAP):.*?URI="([^"]+)"/i', $line, $m)) {
                $abs = mxgj_play_resolve($m[1], $finalUrl);
                $line = str_replace('URI="' . $m[1] . '"', 'URI="' . $entry . mxgj_b64url($abs) . '"', $line);
            }
            $out[] = $line;
            continue;
        }
        // 切片地址：相对转绝对后走本站入口
        $abs = mxgj_play_resolve($line, $finalUrl);
        $out[] = $entry . mxgj_b64url($abs);
    }
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Cache-Control: no-cache');
    echo implode("\n", $out);
    exit;
}

// ④ 二进制媒体：本站流式转发（m3u8 切片 ts、mp4 等），APP 原生播放器可直接播放
if ($ctype === '') {
    $ctype = 'application/octet-stream';
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $ctype);
header('Cache-Control: no-cache');
$ch = curl_init($finalUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false, // 直接输出到客户端
    CURLOPT_HEADER         => false, // 不透传上游 header，避免 Transfer-Encoding 等干扰
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 6,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => $ua,
    CURLOPT_HTTPHEADER     => ['Accept: */*', 'Referer: ' . (parse_url($real, PHP_URL_SCHEME) ? $real : '')],
]);
curl_exec($ch);
curl_close($ch);
exit;
