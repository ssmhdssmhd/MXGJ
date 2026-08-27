<?php
/**
 * 沫兮官替系统 - /player/ 目录默认入口
 *
 * 访问形式：
 *   /player/?url=播放地址           使用默认播放器直接播放
 *   /player/?code=播放器编码&url=xxx 指定播放器
 *   /player/?u=base64url加密地址
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 如果没有参数，跳到管理后台
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['url']) && !isset($_GET['u']) && !isset($_GET['code'])) {
    header('Location: admin.php');
    exit;
}

// 有播放参数时，交给 play.php 处理
$_SERVER['SCRIPT_NAME'] = str_replace('/index.php', '/play.php', $_SERVER['SCRIPT_NAME'] ?? '');
require __DIR__ . '/play.php';
