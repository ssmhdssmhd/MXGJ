<?php
/**
 * 沫兮官替系统 - 根目录极简 JSON API（v1.17.28 新增）
 *
 * 专门解决：浏览器访问 ?url=xxx 时被自动重定向到播放器 HTML 的问题。
 * 本入口 **强制返回 JSON**，不走 index.php 的智能自动重定向逻辑。
 *
 * 调用方式：
 *   /api.php?url=<官方视频链接>[&key=<密钥>][&page=<集数>][&debug=1][&callback=<jsonp>]
 *   /api.php?url=https://v.qq.com/x/cover/mzc00200zx8psx0.html&key=moxi123
 *
 * 跨域：bootstrap.php 全局已设置 Access-Control-Allow-Origin: *
 * URL：完全动态，从 $_GET['url'] 读取，无任何硬编码
 *
 * 返回格式（四段式 standard）：
 *   {
 *     "code": 200,
 *     "msg": "success",
 *     "data": { "url": "...", "title": "...", "episode": 1, "site": "...", ... },
 *     "meta": { "api_version": "1.17.28", "service": "沫兮官替系统", "mode": "standard", ... }
 *   }
 */

// 🔥 关键：在 require index.php 之前强制关闭自动重定向
// index.php 第 275 行: } elseif (isset($_GET['raw'])) { $doRedirect = false; }
// 所以设置 $_GET['raw'] 就能强制返回 JSON
$_GET['raw'] = '1';
$_GET['redirect'] = '0';

// 复用 index.php 的完整解析流程（LinkParser → 映射 → 多资源站搜索）
// index.php 内部会 require bootstrap.php（CORS 全局生效）
require_once __DIR__ . '/index.php';

// index.php 已经 mxgj_json_out() → exit，不会走到这里
exit;
