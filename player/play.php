<?php
/**
 * 动态播放器入口 — 按后台配置渲染 MacPlayer
 *
 * /player/play.php?code=<播放器编码>&url=<播放地址>
 * /player/play.php?url=<播放地址>           (使用默认播放器)
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 读取播放地址
$playUrl = trim((string)($_GET['url'] ?? ''));
if ($playUrl === '') {
    $dec = mxgj_b64url_decode(trim((string)($_GET['u'] ?? '')));
    if ($dec !== '' && strpos($dec, 'http') === 0) $playUrl = $dec;
}
if ($playUrl === '') { http_response_code(400); exit('缺少 url 参数'); }
if (strpos($playUrl, 'http') !== 0) $playUrl = 'http://' . $playUrl;

// 确定用哪个播放器
$code = trim((string)($_GET['code'] ?? ''));
if ($code !== '') {
    $player = mxgj_get_player($code);
    if (!$player || empty($player['enabled'])) {
        http_response_code(404); exit('播放器 ' . htmlspecialchars($code) . ' 不存在或已禁用');
    }
} else {
    $player = mxgj_default_player();
    if (!$player) { http_response_code(500); exit('后台未配置任何播放器'); }
}

$playerShow   = $player['player_name'] ?? '播放器';
$playerFrom   = $player['player_from'] ?? '';
$playerCode   = $player['player_code_content'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= htmlspecialchars($playerShow) ?>播放器</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { width: 100%; height: 100%; overflow: hidden; background: #0b0e14; color: #e6e8ee;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; }
    #player { position: fixed; inset: 0; background: #000; }
    #player iframe { width: 100%; height: 100%; border: 0; display: block; }
    .bar { position: fixed; top: 0; left: 0; right: 0; z-index: 10; display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; background: linear-gradient(180deg, rgba(0,0,0,.78), rgba(0,0,0,0)); pointer-events: none; }
    .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px;
        background: rgba(79, 124, 255, .18); border: 1px solid rgba(79, 124, 255, .5); color: #9db8ff;
        font-size: 12px; font-weight: 600; letter-spacing: .5px; }
    .src { font-size: 12px; color: rgba(230,232,238,.55); }
    .loading { position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; }
    .spinner { width: 34px; height: 34px; border: 3px solid rgba(79,124,255,.2); border-top-color: #4f7cff;
        border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .hint { font-size: 13px; color: rgba(230,232,238,.6); }
</style>
</head>
<body>
    <div class="bar">
        <span class="badge"><?= htmlspecialchars($playerShow) ?></span>
        <?php if ($playerFrom): ?><span class="src"><?= htmlspecialchars($playerFrom) ?></span><?php endif; ?>
    </div>
    <div id="player"></div>
    <noscript><div style="padding:40px;text-align:center">请开启 JavaScript 后播放</div></noscript>

    <script>
    // MacPlayer 最小运行时
    (function () {
        window.MacPlayer = {
            PlayUrl: <?= json_encode(rawurlencode($playUrl)) ?>,
            Html: '',
            Show: function () { var c = document.getElementById('player'); if (c) c.innerHTML = this.Html || ''; }
        };
    })();
    </script>
    <?php if ($playerCode): ?>
    <script><?= $playerCode ?></script>
    <?php else: ?>
    <script>MacPlayer.Html = '<iframe width="100%" height="100%" src="https://vv00.xyz?url='+MacPlayer.PlayUrl+'" frameborder="0" scrolling="no" allowfullscreen></iframe>';try{MacPlayer.Show()}catch(e){}</script>
    <?php endif; ?>
    <script>
    (function () {
        var d = document.getElementById('player');
        if (d && !d.innerHTML.trim()) {
            d.innerHTML = '<div class="loading"><div class="spinner"></div><div class="hint">正在加载播放器…</div></div>';
        }
    })();
    </script>
</body>
</html>
