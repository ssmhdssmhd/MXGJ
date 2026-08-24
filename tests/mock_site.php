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
