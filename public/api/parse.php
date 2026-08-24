<?php
/**
 * 视频解析 API 接口
 *
 * 外部可调用接口，支持跨域（CORS）与 JSONP。
 *
 * 用法：
 *   GET/POST /api/parse.php?url=<视频链接>
 *   GET/POST /api/parse.php?url=<视频链接>&format=json   （默认）
 *   GET/POST /api/parse.php?url=<视频链接>&callback=myFunc （JSONP）
 *
 * 返回 JSON：
 *   {
 *     "success": true,
 *     "title": "视频标题",
 *     "description": "视频描述",
 *     "urls": ["播放源1", "播放源2", ...],
 *     "video_info": {...},
 *     "video_id": "iqiyi_xxx",
 *     "parse_time": "2026-08-24 19:00:00"
 *   }
 */

declare(strict_types=1);

// ---------- 跨域支持（外部调用必需） ----------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// 预检请求直接返回
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../src/VideoParser.php';

/**
 * 统一 JSON 输出（支持 JSONP）
 */
function respond(array $data, int $status = 200): void
{
    http_response_code($status);

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = json_encode(['success' => false, 'error' => 'JSON 编码失败'], JSON_UNESCAPED_UNICODE);
    }

    $callback = isset($_GET['callback']) ? trim((string) $_GET['callback']) : '';
    if ($callback !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $callback)) {
        // JSONP 模式
        header('Content-Type: application/javascript; charset=utf-8');
        echo $callback . '(' . $json . ');';
    } else {
        echo $json;
    }
    exit;
}

// ---------- 参数获取 ----------
$url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($url === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json) && !empty($json['url'])) {
        $url = trim((string) $json['url']);
    } elseif (!empty($_POST['url'])) {
        $url = trim((string) $_POST['url']);
    }
}

// ---------- 参数校验 ----------
if ($url === '') {
    respond([
        'success' => false,
        'error' => '缺少参数 url，请传入视频链接，例如：/api/parse.php?url=https://www.iqiyi.com/v_xxx.html',
        'usage' => [
            'method' => 'GET / POST',
            'params' => [
                'url' => '必填，视频页面链接',
                'callback' => '可选，JSONP 回调函数名',
            ],
            'example' => '/api/parse.php?url=https://www.iqiyi.com/v_1re8v439zmw.html',
        ],
    ], 400);
}

if (!preg_match('#^https?://#i', $url)) {
    respond([
        'success' => false,
        'error' => 'url 格式不正确，必须以 http:// 或 https:// 开头',
    ], 400);
}

// 防止 SSRF：仅允许常见视频域名（可选，注释掉则放行所有 http/https）
$allowedDomains = [
    'iqiyi.com', 'v.qq.com', 'qq.com', 'youku.com', 'mgtv.com',
    'bilibili.com', 'b23.tv', 'sohu.com', 'letv.com', '163.com',
    'miguvideo.com', 'wasu.cn', 'cctv.com', 'cntv.cn', 'douyin.com',
];
$host = parse_url($url, PHP_URL_HOST);
$hostOk = false;
if ($host) {
    $host = strtolower($host);
    foreach ($allowedDomains as $domain) {
        if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
            $hostOk = true;
            break;
        }
    }
}
if (!$hostOk) {
    respond([
        'success' => false,
        'error' => '暂不支持该域名，支持平台：爱奇艺、腾讯视频、优酷、芒果TV、哔哩哔哩等',
        'supported_domains' => $allowedDomains,
    ], 400);
}

// ---------- 执行解析 ----------
try {
    $parser = new VideoParser();
    $result = $parser->parseVideo($url);
    respond($result, $result['success'] ? 200 : 200);
} catch (\Throwable $e) {
    respond([
        'success' => false,
        'error' => '服务异常: ' . $e->getMessage(),
    ], 500);
}
