'use strict';
// 渲染进程脚本 —— 界面交互与解析调用

const urlInput = document.getElementById('videoUrl');
const parseBtn = document.getElementById('parseBtn');
const resultCard = document.getElementById('resultCard');
const videoTitle = document.getElementById('videoTitle');
const statusBadge = document.getElementById('statusBadge');
const videoInfo = document.getElementById('videoInfo');
const playerFrame = document.getElementById('playerFrame');
const playerPlaceholder = document.getElementById('playerPlaceholder');
const urlItems = document.getElementById('urlItems');
const urlCount = document.getElementById('urlCount');
const themeSelect = document.getElementById('themeSelect');

// 主题切换
themeSelect.addEventListener('change', () => {
  document.body.dataset.theme = themeSelect.value;
});

function copyText(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => alert('链接已复制到剪贴板！')).catch(() => fallbackCopy(text));
  } else {
    fallbackCopy(text);
  }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  document.body.appendChild(ta);
  ta.select();
  document.execCommand('copy');
  document.body.removeChild(ta);
  alert('链接已复制到剪贴板！');
}

function pickPlayerUrl(url) {
  // 对 iframe 播放器：优先直接用解析链接；否则用 /api/parse 生成的接口
  playerFrame.src = url + (url.includes('?') ? '&' : '?') + '_t=' + Date.now();
  playerPlaceholder.style.display = 'none';
}

function renderSources(result) {
  resultCard.classList.remove('hidden');

  const urls = result.urls || (result.url ? [result.url] : []);
  const ok = result.success !== false && result.code === 200;
  const hasUrls = Array.isArray(urls) && urls.length > 0;

  videoTitle.textContent = result.title || (ok ? '解析成功' : '解析失败');
  statusBadge.textContent = ok ? '解析成功' : '失败';
  statusBadge.className = 'badge ' + (ok ? 'ok' : 'err');

  const from = result.from || result.video_info?.url || '';
  videoInfo.innerHTML = `<span class="from">来源：${from}</span>`;

  urlCount.textContent = hasUrls ? `(${urls.length} 个)` : '(0 个)';
  urlItems.innerHTML = '';

  if (hasUrls) {
    pickPlayerUrl(urls[0]);
    urls.forEach((u) => {
      const item = document.createElement('div');
      item.className = 'url-item';
      const link = document.createElement('a');
      link.href = u;
      link.textContent = u;
      link.addEventListener('click', (e) => {
        e.preventDefault();
        if (window.api && window.api.openExternal) window.api.openExternal(u);
        else window.open(u, '_blank');
      });
      const btn = document.createElement('button');
      btn.className = 'copy-btn';
      btn.textContent = '复制';
      btn.addEventListener('click', () => copyText(u));
      item.appendChild(link);
      item.appendChild(btn);
      urlItems.appendChild(item);
    });
  } else {
    playerFrame.src = '';
    playerPlaceholder.style.display = 'flex';
    playerPlaceholder.querySelector('.placeholder-inner').textContent = result.error || '未找到可用的播放源';
  }
}

async function doParse() {
  const url = urlInput.value.trim();
  if (!url) {
    alert('请输入视频链接');
    urlInput.focus();
    return;
  }
  parseBtn.disabled = true;
  parseBtn.textContent = '解析中...';
  resultCard.classList.remove('hidden');
  videoTitle.textContent = '解析中...';
  playerFrame.src = '';
  playerPlaceholder.style.display = 'flex';
  playerPlaceholder.querySelector('.placeholder-inner').textContent = '正在解析，请稍候...';
  urlItems.innerHTML = '';
  urlCount.textContent = '';

  try {
    // 优先使用 GUI 全量接口（多播放源）
    if (window.api && window.api.parseGui) {
      const result = await window.api.parseGui(url);
      renderSources(result);
    } else {
      const result = await window.api.parseVideo(url);
      renderSources(result);
    }
  } catch (e) {
    videoTitle.textContent = '解析出错';
    statusBadge.textContent = '异常';
    statusBadge.className = 'badge err';
    videoInfo.textContent = String(e && e.message ? e.message : e);
    playerFrame.src = '';
    playerPlaceholder.style.display = 'flex';
    playerPlaceholder.querySelector('.placeholder-inner').textContent = '解析异常，请重试';
  } finally {
    parseBtn.disabled = false;
    parseBtn.textContent = '🔍 解析';
  }
}

parseBtn.addEventListener('click', doParse);
urlInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') doParse();
});

// 初始化 placeholder
playerPlaceholder.querySelector('.placeholder-inner').textContent = '请输入链接并解析';
document.body.dataset.theme = themeSelect.value = 'tech';