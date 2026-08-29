<?php
/**
 * /player/ 目录默认入口 — 动态按后台配置渲染
 *
 * /player/?url=播放地址              → 使用默认播放器
 * /player/?code=xxx&url=播放地址    → 指定播放器
 * /player/?u=<base64url加密>         → 加密地址
 * /player/ (无参数)                  → 跳转后台管理
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$playUrl = trim((string)($_GET['url'] ?? ''));
if ($playUrl === '') {
    $dec = mxgj_b64url_decode(trim((string)($_GET['u'] ?? '')));
    if ($dec !== '' && strpos($dec, 'http') === 0) $playUrl = $dec;
}

// 无参数 → 跳后台
if ($playUrl === '') {
    header('Location: admin.php');
    exit;
}

if (strpos($playUrl, 'http') !== 0) $playUrl = 'http://' . $playUrl;
$parts = parse_url($playUrl);
if (empty($parts['scheme']) || empty($parts['host'])) {
    http_response_code(400); exit('url 参数非法');
}

// 转到 play.php 统一处理
header('Location: play.php'
    . '?url=' . urlencode($playUrl)
    . (isset($_GET['code']) ? '&code=' . urlencode($_GET['code']) : ''));
exit;
