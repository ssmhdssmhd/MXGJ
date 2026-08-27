<?php
/**
 * 沫兮官替系统 - 动态播放器入口
 *
 * 从后台 player/data/players.json 读取播放器配置，
 * 根据 code 参数（播放器编码）加载对应播放器渲染页面。
 *
 * 调用：
 *   /player/play.php?url=<播放地址>                         （使用默认播放器）
 *   /player/play.php?code=<播放器编码>&url=<播放地址>        （指定播放器）
 *   /player/play.php?code=<播放器编码>&u=<base64url地址>    （加密地址）
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 1. 读取播放地址
$playUrl = trim((string)($_GET['url'] ?? ''));
if ($playUrl === '') {
    $dec = mxgj_b64url_decode(trim((string)($_GET['u'] ?? '')));
    if ($dec !== '' && strpos($dec, 'http') === 0) {
        $playUrl = $dec;
    }
}
if ($playUrl === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('缺少 url 参数');
}
if (strpos($playUrl, 'http') !== 0) {
    $playUrl = 'http://' . $playUrl;
}
$parts = parse_url($playUrl);
if (empty($parts['scheme']) || empty($parts['host'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('url 参数非法');
}

// 2. 选择播放器
$code = trim((string)($_GET['code'] ?? ''));
$player = null;
if ($code !== '') {
    $player = mxgj_get_player($code);
}
if (!$player) {
    $player = mxgj_default_player();
}
if (!$player) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('未配置任何播放器，请先到后台添加');
}
if (empty($player['enabled'])) {
    // 播放器已禁用，降级使用默认播放器
    $player = mxgj_default_player();
    if (!$player) {
        http_response_code(500);
        exit('默认播放器不可用');
    }
}

$playerShow = $player['player_name'] ?? '播放器';
$playerFrom = $player['player_from'] ?? '';
$playerCode = $player['player_code_content'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="referrer" content="no-referrer">
<title><?= htmlspecialchars($playerShow) ?> - 在线播放</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { width: 100%; height: 100%; overflow: hidden; background: #0b0e14; color: #e6e8ee;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; }
    #player { position: fixed; inset: 0; background: #000; }
    #player iframe { width: 100%; height: 100%; border: 0; display: block; }
    .bar { position: fixed; top: 0; left: 0; right: 0; z-index: 10; display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; background: linear-gradient(180deg, rgba(0,0,0,.78), rgba(0,0,0,0));
        pointer-events: none; }
    .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px;
        background: rgba(79, 124, 255, .18); border: 1px solid rgba(79, 124, 255, .5); color: #9db8ff;
        font-size: 12px; font-weight: 600; letter-spacing: .5px; }
    .src { font-size: 12px; color: rgba(230,232,238,.55); }
    .loading { position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; }
    .spinner { width: 34px; height: 34px; border: 3px solid rgba(79,124,255,.2); border-top-color: #4f7cff;
        border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .hint { font-size: 13px; color: rgba(230,232,238,.6); }
    /* 移动端返回按钮 */
    .mobile-back { position: fixed; top: 12px; left: 12px; z-index: 20; width: 36px; height: 36px;
        background: rgba(0,0,0,.5); border-radius: 50%; display: none; align-items: center; justify-content: center;
        color: #fff; text-decoration: none; font-size: 18px; pointer-events: auto; }
    @media (max-width: 768px) { .mobile-back { display: flex; } }
</style>
</head>
<body>
    <div class="bar">
        <span class="badge"><?= htmlspecialchars($playerShow) ?></span>
        <?php if ($playerFrom !== ''): ?>
            <span class="src"><?= htmlspecialchars($playerFrom) ?></span>
        <?php endif; ?>
    </div>
    <a class="mobile-back" href="javascript:history.back()" title="返回">←</a>
    <div id="player"></div>
    <noscript>
        <div style="padding:40px;text-align:center;color:#8a93a6">请开启 JavaScript 后播放</div>
    </noscript>

    <script>
    // MacPlayer 最小运行时（苹果CMS10 兼容）
    (function () {
        window.MacPlayer = {
            PlayUrl: <?= json_encode($playUrl) ?>,
            PlayUrlEncoded: <?= json_encode(rawurlencode($playUrl)) ?>,
            Html: '',
            // 可选：播放器初始化参数（部分 MacPlayer 会读取）
            'Player.Title': <?= json_encode($playerShow) ?>,
            'Player.From': <?= json_encode($playerFrom) ?>,
            Show: function () {
                var c = document.getElementById('player');
                if (c) c.innerHTML = this.Html || '';
            }
        };
    })();
    </script>

    <?php if ($playerCode !== ''): ?>
    <script>
    // ===== 后台配置的播放器代码（原样注入执行） =====
    <?= $playerCode ?>
    </script>
    <?php else: ?>
    <script>
    // ===== 兜底：默认 iframe 播放器 =====
    MacPlayer.Html = '<iframe width="100%" height="100%" src="' + MacPlayer.PlayUrl + '" frameborder="0" allowfullscreen></iframe>';
    try { MacPlayer.Show(); } catch(e) {}
    </script>
    <?php endif; ?>

    <script>
    // 加载完成前展示过渡动画，避免白屏
    (function () {
        var d = document.getElementById('player');
        if (d && !d.innerHTML.trim()) {
            d.innerHTML = '<div class="loading"><div class="spinner"></div><div class="hint">正在加载播放器…</div></div>';
        }
        // 超时（8秒）后提示加载失败
        setTimeout(function () {
            if (d && d.querySelector('.loading')) {
                d.innerHTML = '<div class="loading"><div class="spinner" style="border-top-color:#e74c3c"></div><div class="hint">加载超时，播放地址可能失效</div></div>';
            }
        }, 8000);
    })();
    </script>
</body>
</html>
