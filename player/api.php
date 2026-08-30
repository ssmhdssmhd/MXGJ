<?php
/**
 * 沫兮官替系统 - 对外 TVBox / 影视 APP 调用接口
 *
 * 调用方式：
 *   ① 直接给 m3u8/mp4 → 代理转发（m3u8 重写切片走本站）
 *      /player/api.php?url=https://xxx.com/xxx.m3u8
 *
 *   ② 给官方视频链接 → 完整解析流程（LinkParser→映射→多资源站搜索）
 *      /player/api.php?key=<密钥>&url=<腾讯/爱奇艺/优酷 官方链接>[&page=集数]
 *      → 返回 {"code":200,"url":"m3u8","player_url":"播放页",...}
 *      兼容 jsonp: &callback=xxx
 *
 *   ③ 给苹果CMS资源站 URL → 直接代理原始 JSON 透传
 *      /player/api.php?url=https://xxx.com/api.php/provide/vod/?ac=videolist&wd=斗罗大陆
 */

require_once __DIR__ . '/../lib/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range');

// 浏览器预检：直接返回 204
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    header('Content-Length: 0');
    exit;
}

// ====== App 接口开关 & key 鉴权 ======
$_api = mxgj_settings()['app_api'] ?? [];
$_api = array_merge(['enable'=>true,'require_key'=>false,'api_key'=>''], $_api);
if (empty($_api['enable'])) {
    http_response_code(403);
    mxgj_json_out(['code'=>403,'msg'=>'App 接口已禁用','url'=>''], 403);
    exit;
}
if (!empty($_api['require_key'])) {
    $_visitKey = trim((string)($_api['api_key'] ?? ''));
    if ($_visitKey === '') $_visitKey = trim((string)(@include MXGJ_CONFIG.'/key.php'));
    if ($_visitKey !== '' && (($_GET['key'] ?? '') !== $_visitKey)) {
        http_response_code(403);
        mxgj_json_out(['code'=>403,'msg'=>'访问被拒绝：缺少或无效的 key 参数','url'=>''], 403);
        exit;
    }
}

$url = trim((string)($_GET['url'] ?? $_GET['u'] ?? ''));
$cb  = trim((string)($_GET['callback'] ?? ''));

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    mxgj_json_out(['code' => 400, 'msg' => '非法/缺失 url 参数'], 400);
}
if (strpos($url, 'http') !== 0) $url = 'http://' . $url;

// ====== 快速路由 ======
$path = parse_url($url, PHP_URL_PATH) ?: '';
$host = parse_url($url, PHP_URL_HOST) ?: '';
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$q    = parse_url($url, PHP_URL_QUERY) ?: '';

// ① 媒体文件 → 代理转发
if (in_array($ext, ['m3u8','m3u','mp4','mpd','flv','ts','m4v','mov','webm','aac','mp3'])) {
    mxgj_api_proxy_media($url);
    exit;
}

// ② 苹果CMS资源站API → 代理透传原始JSON（wd=斗罗大陆 这种搜索）
if (stripos($url, 'api.php/provide/vod') !== false || (stripos($q, 'ac=videolist') !== false)) {
    mxgj_api_proxy_json($url);
    exit;
}

// ③ 官方视频链接 → 走完整解析流程（复用 index.php）
// index.php 内部会自己 require bootstrap（现在已防重复），然后 mxgj_json_out → exit
$_GET['url'] = $url; // index.php 读的是 $_GET['url']
require __DIR__ . '/../index.php';
exit;

// ==================== 代理函数 ====================

function mxgj_api_proxy_media(string $url): void
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
    $is_m3u8 = stripos($url, '.m3u8') !== false || stripos($url, '.m3u') !== false;

    if ($is_m3u8) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => $ua,
        ]);
        $body = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        curl_close($ch);

        if ($body === false) { header('Location: ' . $finalUrl); exit; }

        $entry = 'play.php?u=';
        $lines = preg_split('/\r\n|\r|\n/', $body);
        $out = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') { $out[] = ''; continue; }
            if ($line[0] === '#') {
                if (preg_match('/#EXT-X-(?:KEY|MAP):.*?URI="([^"]+)"/i', $line, $m)) {
                    $abs = mxgj_api_resolve($m[1], $finalUrl);
                    $line = str_replace('URI="' . $m[1] . '"', 'URI="' . $entry . mxgj_b64url($abs) . '"', $line);
                } elseif (preg_match('/#EXT-X-MEDIA:.*?URI="([^"]+)"/i', $line, $m)) {
                    $abs = mxgj_api_resolve($m[1], $finalUrl);
                    $line = str_replace('URI="' . $m[1] . '"', 'URI="' . $entry . mxgj_b64url($abs) . '"', $line);
                }
                $out[] = $line;
            } else {
                $abs = mxgj_api_resolve($line, $finalUrl);
                $out[] = $entry . mxgj_b64url($abs);
            }
        }
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-cache');
        echo implode("\n", $out);
        exit;
    }
    // 非 m3u8 媒体 → 302 跳转
    header('Location: ' . $url);
    exit;
}

function mxgj_api_proxy_json(string $url): void
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => $ua,
    ]);
    $body = curl_exec($ch);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) {
        mxgj_json_out(['code' => 500, 'msg' => '上游请求失败'], 500);
    }
    if (empty($ctype)) $ctype = 'application/json';
    header('Content-Type: ' . $ctype . '; charset=utf-8');
    echo $body;
    exit;
}

function mxgj_api_resolve(string $rel, string $base): string
{
    if ($rel === '' || strpos($rel, 'http') === 0) return $rel;
    if (strpos($rel, '//') === 0) return 'http:' . $rel;
    $b = parse_url($base);
    $origin = ($b['scheme'] ?? 'http') . '://' . ($b['host'] ?? '');
    if (!empty($b['port'])) $origin .= ':' . $b['port'];
    if ($rel[0] === '/') return $origin . $rel;
    $dir = isset($b['path']) ? substr($b['path'], 0, strrpos($b['path'], '/') + 1) : '/';
    return $origin . $dir . $rel;
}
