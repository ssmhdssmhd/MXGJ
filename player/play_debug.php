<?php
require_once __DIR__ . '/../lib/bootstrap.php';
$playUrl = trim((string)($_GET['url'] ?? ''));
if ($playUrl === '') { http_response_code(400); exit('缺少 url'); }
if (strpos($playUrl, 'http') !== 0) $playUrl = 'http://' . $playUrl;
$code = trim((string)($_GET['code'] ?? ''));
if ($code !== '') {
    $player = mxgj_get_player($code);
    if (!$player || empty($player['enabled'])) { http_response_code(404); exit('播放器不存在'); }
} else {
    $player = mxgj_default_player();
    if (!$player) { http_response_code(500); exit('无默认播放器'); }
}
$playerCode = mxgj_render_player_code($player['player_code_content'] ?? '');
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>DEBUG</title>
<style>body{background:#111;color:#fff;font-family:monospace;padding:15px;font-size:12px}pre{background:#000;border:1px solid #333;padding:8px;border-radius:6px;max-height:300px;overflow:auto}
.ok{color:#4ade80}.err{color:#f87171}.info{color:#60a5fa}.warn{color:#fbbf24}</style></head><body>
<h3>🔍 DEBUG</h3>
<pre id="log"></pre>
<div id="player" style="border:2px dashed #666;height:200px;margin-top:10px"></div>
<script>
var _log = document.getElementById('log');
var _uid = 'dbg_' + Date.now() + '_' + Math.random().toString(36).slice(2,8);
function L(msg, cls){
    var line = '[' + (cls||'info').toUpperCase() + '] ' + (msg||'');
    var span = document.createElement('span');
    span.className = cls||'info'; span.textContent = line + '\n';
    _log.appendChild(span);
    // 也 postMessage 给父窗口
    try { if (window.parent && window.parent !== window) window.parent.postMessage({type:'dbg',uid:_uid,msg:line}, '*'); } catch(e){}
    try { window.top.postMessage({type:'dbg',uid:_uid,msg:line}, '*'); } catch(e){}
}

// 暴露到全局，方便外部直接调用
window.__dbg = { log: L, uid: _uid };

L('===== DEBUG START uid='+_uid+' =====');
L('location.href = ' + location.href);
L('window.top === window: ' + (window.top === window));
L('window.parent === window: ' + (window.parent === window));
</script>
<script>
L('--- Step 1: MacPlayer runtime ---');
(function () {
    window.MacPlayer = {
        PlayUrl: <?= json_encode(rawurlencode($playUrl)) ?>,
        Html: '',
        Show: function () {
            L('MacPlayer.Show() called', 'info');
            var c = document.getElementById('player');
            L('#player found: ' + !!c, c ? 'ok' : 'err');
            if (c) {
                L('MacPlayer.Html length: ' + (this.Html||'').length, 'info');
                c.innerHTML = this.Html || '';
                L('#player.innerHTML set. iframe count: ' + c.querySelectorAll('iframe').length, 'ok');
            }
        }
    };
})();
L('MacPlayer.PlayUrl = ' + MacPlayer.PlayUrl, 'info');
L('MacPlayer.Html (initial) = "' + MacPlayer.Html + '"', 'info');
L('--- Step 2: 执行播放器代码 ---');
</script>
<?php if ($playerCode): ?>
<script>
try {
    L('>>> About to execute player code (<?= strlen($playerCode) ?> bytes)', 'info');
    <?= $playerCode ?>
    L('>>> Player code done', 'ok');
    L('MacPlayer.Html length after code: ' + (MacPlayer.Html||'').length, 'info');
    L('MacPlayer.Html snippet: ' + (MacPlayer.Html||'').substring(0,180), 'info');
} catch(e) {
    L('!!! Player code EXCEPTION: ' + (e.message||e) + ' at line ' + (e.lineNumber||'?'), 'err');
    L('!!! stack: ' + (e.stack||'').split('\n').slice(0,5).join(' | '), 'err');
}
</script>
<?php else: ?>
<script>
L('⚠️ NO player code! Fallback vv00', 'warn');
MacPlayer.Html = '<iframe src="https://vv00.xyz?url='+MacPlayer.PlayUrl+'" allowfullscreen></iframe>';
try { MacPlayer.Show(); } catch(e){ L('Fallback Show error: '+e.message, 'err'); }
</script>
<?php endif; ?>
<script>
// 强制调用一次 Show() 防止播放器代码内部失败
try {
    L('--- Step 3: Force MacPlayer.Show() ---', 'info');
    if (MacPlayer && MacPlayer.Html) {
        MacPlayer.Show();
    } else if (MacPlayer) {
        L('MacPlayer.Html is EMPTY! Player code did not set it.', 'err');
    } else {
        L('MacPlayer is NULL/undefined! Runtime failed.', 'err');
    }
} catch(e) { L('Force Show error: '+e.message, 'err'); }
L('===== DEBUG END =====', 'info');
</script>
</body></html>
