<?php
/**
 * 沫兮官替系统 - 模拟资源站（自测用）
 *
 * 作为 PHP 内置服务器的路由器使用，模拟一个「按剧名+集数返回 m3u8 地址」的资源站 API：
 *   GET /vod?wd=<剧名>&ep=<集数>[&fmt=text]
 *
 * 8001 端口：返回 JSON(720p)，带 fmt=text 时返回纯文本地址
 * 8002 端口：返回 JSON(1080p)
 * 两个端口都延迟 1.2 秒，用于验证多线程并发的耗时优势。
 */

$port = (int)($_SERVER['SERVER_PORT'] ?? 8001);

// 特殊调用（POST）：仅当资源站以 POST 方式调用时进入此分支
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $wd = '';
    $ep = 1;
    $raw = file_get_contents('php://input');
    parse_str($raw, $q);
    $wd = trim($q['wd'] ?? '');
    $ep = max(1, (int)($q['ep'] ?? 1));
    usleep(1200000); // 模拟处理耗时
    if ($wd === '' || mb_strpos($wd, '庆余年') === false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 0, 'msg' => '未找到相关资源'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 记录收到的 POST 请求体，供自测断言特殊调用方法生效
    $proof = dirname(__DIR__) . '/data/proof_post.txt';
    @mkdir(dirname($proof), 0755, true);
    @file_put_contents($proof, $raw);
    header('Content-Type: application/json; charset=utf-8');
    $url = "http://127.0.0.1:{$port}/vod/post/{$ep}/index.m3u8";
    echo json_encode(['code' => 1, 'url' => $url, 'w' => 480], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$wd   = isset($_GET['wd']) ? trim($_GET['wd']) : '';
$ep   = max(1, (int)($_GET['ep'] ?? 1));

usleep(1200000); // 模拟资源站处理耗时

// 剧名校验（模拟：只有包含“庆余年”才有资源）
if ($wd === '' || mb_strpos($wd, '庆余年') === false) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'msg' => '未找到相关资源'], JSON_UNESCAPED_UNICODE);
    exit;
}

$w = $port === 8002 ? 1080 : 720;
$url = "http://127.0.0.1:{$port}/vod/qingyounian/{$ep}/index.m3u8";

// 纯文本模式（测试 parseBody 的纯文本分支）
if (isset($_GET['fmt']) && $_GET['fmt'] === 'text') {
    header('Content-Type: text/plain; charset=utf-8');
    echo $url;
    exit;
}

// JSON 模式
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['code' => 1, 'url' => $url, 'w' => $w], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
