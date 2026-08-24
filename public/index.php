<?php
/**
 * 视频解析工具 - Web 版首页
 * 前端调用 /api/parse.php 接口完成解析与播放
 */
$siteName = '视频解析工具';
$apiUrl = 'api/parse.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> - 多平台视频解析</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Microsoft YaHei', 'PingFang SC', Arial, sans-serif;
            background: linear-gradient(135deg, #0f1419 0%, #1a2332 100%);
            min-height: 100vh;
            color: #e2e8f0;
        }
        .container { max-width: 1100px; margin: 0 auto; padding: 24px 16px 60px; }
        .header {
            background: linear-gradient(45deg, #ff6b6b, #ee5a24);
            color: #fff; padding: 24px; text-align: center;
            border-radius: 14px 14px 0 0;
        }
        .header h1 { font-size: 26px; margin-bottom: 6px; }
        .header p { font-size: 14px; opacity: .9; }
        .card {
            background: #fff; color: #2d3748;
            border-radius: 0 0 14px 14px; box-shadow: 0 20px 40px rgba(0,0,0,.25);
            padding: 24px; margin-bottom: 20px;
        }
        .input-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .input-row input {
            flex: 1; min-width: 260px; padding: 13px 16px; font-size: 15px;
            border: 2px solid #e2e8f0; border-radius: 8px; outline: none;
            transition: border-color .2s;
        }
        .input-row input:focus { border-color: #3182ce; }
        .btn {
            padding: 13px 26px; font-size: 15px; font-weight: 600;
            border: none; border-radius: 8px; cursor: pointer; color: #fff;
            transition: opacity .2s, transform .1s;
        }
        .btn:hover { opacity: .9; }
        .btn:active { transform: scale(.97); }
        .btn-primary { background: #38a169; }
        .btn-secondary { background: #718096; }
        .btn-copy { background: #3182ce; }
        .btn-sm { padding: 8px 14px; font-size: 13px; }
        .toolbar { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .status {
            margin-top: 14px; padding: 10px 14px; border-radius: 8px;
            font-size: 14px; display: none;
        }
        .status.loading { display: block; background: #ebf8ff; color: #2b6cb0; }
        .status.error { display: block; background: #fff5f5; color: #c53030; }
        .status.success { display: block; background: #f0fff4; color: #276749; }
        .section-title {
            font-size: 17px; font-weight: 700; margin: 22px 0 12px;
            color: #2d3748; display: flex; align-items: center; gap: 8px;
        }
        .video-info { background: #f7fafc; border-left: 4px solid #3182ce; padding: 14px 16px; border-radius: 8px; }
        .video-info p { margin: 4px 0; font-size: 14px; color: #4a5568; word-break: break-all; }
        .video-info .t { font-weight: 700; color: #2d3748; font-size: 16px; }
        .player-wrap {
            position: relative; width: 100%; background: #000;
            border-radius: 10px; overflow: hidden; margin-top: 14px;
        }
        .player-wrap video, .player-wrap iframe { width: 100%; display: block; }
        .player-wrap video { aspect-ratio: 16/9; background: #000; }
        .player-wrap iframe { height: 520px; border: none; }
        .url-list { list-style: none; }
        .url-item {
            background: #f7fafc; border-left: 4px solid #38a169;
            padding: 12px 14px; margin: 8px 0; border-radius: 8px;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .url-item .u { flex: 1; font-size: 13px; color: #4a5568; word-break: break-all; min-width: 200px; }
        .url-item .tag {
            background: #38a169; color: #fff; font-size: 12px;
            padding: 2px 8px; border-radius: 4px; white-space: nowrap;
        }
        .empty { text-align: center; color: #a0aec0; padding: 40px 0; }
        .footer { text-align: center; color: #718096; font-size: 13px; margin-top: 30px; }
        .footer a { color: #3182ce; text-decoration: none; }
        .loading-bar {
            height: 4px; background: #e2e8f0; border-radius: 2px;
            overflow: hidden; margin-top: 14px; display: none;
        }
        .loading-bar.active { display: block; }
        .loading-bar span {
            display: block; height: 100%; width: 30%;
            background: linear-gradient(90deg, #3182ce, #38a169);
            animation: slide 1.2s infinite ease-in-out;
        }
        @keyframes slide { 0% { margin-left: -30%; } 100% { margin-left: 100%; } }
        @media (max-width: 640px) {
            .player-wrap iframe { height: 240px; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎬 <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>支持爱奇艺、腾讯视频、优酷、芒果TV、哔哩哔哩等主流平台</p>
    </div>

    <div class="card">
        <div class="input-row">
            <input type="text" id="urlInput" placeholder="请粘贴视频页面链接，如 https://www.iqiyi.com/v_xxx.html"
                   autocomplete="off">
            <button class="btn btn-primary" id="parseBtn">🚀 开始解析</button>
        </div>
        <div class="toolbar">
            <button class="btn btn-secondary btn-sm" id="exampleBtn">📋 示例</button>
            <button class="btn btn-secondary btn-sm" id="clearBtn">🗑️ 清空</button>
        </div>
        <div class="loading-bar" id="loadingBar"><span></span></div>
        <div class="status" id="status"></div>
    </div>

    <div class="card" id="resultCard" style="display:none;">
        <div class="section-title">📺 视频信息</div>
        <div class="video-info" id="videoInfo"></div>

        <div class="section-title">▶️ 在线播放</div>
        <div class="player-wrap" id="playerWrap" style="display:none;"></div>

        <div class="section-title">🔗 播放源列表</div>
        <ul class="url-list" id="urlList"></ul>
    </div>

    <div class="footer">
        接口文档见 <a href="api/parse.php?url=https://www.iqiyi.com/v_1re8v439zmw.html" target="_blank">/api/parse.php</a> ·
        本工具仅供学习交流使用
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.13/dist/hls.min.js"></script>
<script>
(function () {
    'use strict';

    var apiUrl = '<?php echo $apiUrl; ?>';
    var urlInput = document.getElementById('urlInput');
    var parseBtn = document.getElementById('parseBtn');
    var exampleBtn = document.getElementById('exampleBtn');
    var clearBtn = document.getElementById('clearBtn');
    var loadingBar = document.getElementById('loadingBar');
    var statusBox = document.getElementById('status');
    var resultCard = document.getElementById('resultCard');
    var videoInfo = document.getElementById('videoInfo');
    var playerWrap = document.getElementById('playerWrap');
    var urlList = document.getElementById('urlList');

    var currentUrls = [];

    function setStatus(text, type) {
        statusBox.className = 'status ' + (type || '');
        statusBox.textContent = text;
    }

    function setLoading(on) {
        loadingBar.classList.toggle('active', on);
        parseBtn.disabled = on;
        parseBtn.textContent = on ? '⏳ 解析中...' : '🚀 开始解析';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function parse() {
        var url = urlInput.value.trim();
        if (!url) {
            setStatus('⚠️ 请先输入视频链接！', 'error');
            return;
        }
        if (!/^https?:\/\//i.test(url)) {
            setStatus('⚠️ 链接必须以 http:// 或 https:// 开头', 'error');
            return;
        }

        setLoading(true);
        setStatus('🔄 正在解析，请稍候...', 'loading');
        resultCard.style.display = 'none';
        playerWrap.style.display = 'none';

        fetch(apiUrl + '?url=' + encodeURIComponent(url))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                setLoading(false);
                if (data.success) {
                    renderResult(data);
                    setStatus('✅ 解析完成，共找到 ' + data.urls.length + ' 个播放源', 'success');
                } else {
                    setStatus('❌ ' + (data.error || '解析失败'), 'error');
                }
            })
            .catch(function (err) {
                setLoading(false);
                setStatus('❌ 请求失败：' + err.message, 'error');
            });
    }

    function renderResult(data) {
        currentUrls = data.urls || [];
        resultCard.style.display = 'block';

        // 视频信息
        var info = data.video_info || {};
        var html = '<p class="t">' + escapeHtml(data.title || '未知视频') + '</p>';
        if (data.description) {
            html += '<p>📝 ' + escapeHtml(data.description) + '</p>';
        }
        if (info.duration) html += '<p>⏱️ 时长：' + escapeHtml(info.duration) + '</p>';
        if (info.cover) html += '<p>🖼️ 封面：' + escapeHtml(info.cover) + '</p>';
        if (data.video_id) html += '<p>🆔 视频ID：' + escapeHtml(data.video_id) + '</p>';
        if (data.parse_time) html += '<p>🕐 解析时间：' + escapeHtml(data.parse_time) + '</p>';
        videoInfo.innerHTML = html;

        // 播放源列表
        urlList.innerHTML = '';
        currentUrls.forEach(function (u, i) {
            var li = document.createElement('li');
            li.className = 'url-item';
            var tag = document.createElement('span');
            tag.className = 'tag';
            tag.textContent = '播放源 ' + (i + 1);
            var span = document.createElement('span');
            span.className = 'u';
            span.textContent = u;
            var btn = document.createElement('button');
            btn.className = 'btn btn-copy btn-sm';
            btn.textContent = '播放';
            btn.onclick = (function (url) { return function () { play(url); }; })(u);
            var copy = document.createElement('button');
            copy.className = 'btn btn-secondary btn-sm';
            copy.textContent = '复制';
            copy.onclick = (function (url) { return function () { copyUrl(url); }; })(u);
            li.appendChild(tag);
            li.appendChild(span);
            li.appendChild(btn);
            li.appendChild(copy);
            urlList.appendChild(li);
        });

        // 自动播放第一个
        if (currentUrls.length) {
            play(currentUrls[0]);
        }
    }

    function play(url) {
        playerWrap.style.display = 'block';
        playerWrap.innerHTML = '';

        var lower = url.toLowerCase();
        if (/\.m3u8(\?|$)/.test(lower)) {
            // HLS 播放
            var video = document.createElement('video');
            video.controls = true;
            video.autoplay = true;
            video.src = url;
            playerWrap.appendChild(video);
            if (window.Hls && Hls.isSupported()) {
                var hls = new Hls();
                hls.loadSource(url);
                hls.attachMedia(video);
            }
        } else if (/\.mp4(\?|$)|\.flv(\?|$)|\.webm(\?|$)|\.mov(\?|$)/.test(lower)) {
            // 原生视频播放
            var v2 = document.createElement('video');
            v2.controls = true;
            v2.autoplay = true;
            v2.src = url;
            playerWrap.appendChild(v2);
        } else {
            // 其他（iframe 播放页）
            var iframe = document.createElement('iframe');
            iframe.src = url;
            iframe.allowFullscreen = true;
            playerWrap.appendChild(iframe);
        }
    }

    function copyUrl(url) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                setStatus('📋 链接已复制到剪贴板', 'success');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = url;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            setStatus('📋 链接已复制到剪贴板', 'success');
        }
    }

    parseBtn.addEventListener('click', parse);
    urlInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') parse();
    });
    exampleBtn.addEventListener('click', function () {
        urlInput.value = 'https://www.iqiyi.com/v_1re8v439zmw.html';
        setStatus('💡 已填入示例链接，点击解析按钮开始', 'success');
    });
    clearBtn.addEventListener('click', function () {
        urlInput.value = '';
        resultCard.style.display = 'none';
        playerWrap.style.display = 'none';
        setStatus('✨ 已清空', 'success');
    });
})();
</script>
</body>
</html>
