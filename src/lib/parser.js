'use strict';

/**
 * 官方视频链接解析器
 * 识别常见平台并抽取可用于"反查剧名/集数"的标识（cid / vid / BV号 / 专辑ID 等）。
 */

const PLATFORMS = {
  tencent: { hosts: ['v.qq.com', 'm.v.qq.com', 'film.qq.com'], label: '腾讯视频' },
  iqiyi: { hosts: ['v.iqiyi.com', 'www.iqiyi.com', 'm.iqiyi.com'], label: '爱奇艺' },
  youku: { hosts: ['v.youku.com', 'www.youku.com'], label: '优酷' },
  mgtv: { hosts: ['www.mgtv.com', 'm.mgtv.com'], label: '芒果TV' },
  bilibili: { hosts: ['www.bilibili.com', 'm.bilibili.com', 'bilibili.com'], label: '哔哩哔哩' },
  sohu: { hosts: ['tv.sohu.com', 'v.sohu.com'], label: '搜狐视频' },
  pptv: { hosts: ['v.pptv.com'], label: 'PPTV' },
  leshi: { hosts: ['www.le.com'], label: '乐视' },
};

function detectPlatform(host) {
  const h = String(host || '').toLowerCase();
  for (const key of Object.keys(PLATFORMS)) {
    if (PLATFORMS[key].hosts.some((x) => h === x || h.endsWith('.' + x))) {
      return key;
    }
  }
  return 'unknown';
}

/**
 * 解析官方链接
 * @param {string} urlStr
 * @returns {{ ok:boolean, platform:string, platformLabel:string, host:string, ids:Object, rawUrl:string, error?:string }}
 */
function parseOfficialUrl(urlStr) {
  let url;
  try {
    url = new URL(urlStr.trim());
  } catch (e) {
    return { ok: false, error: '无效的URL: ' + urlStr };
  }
  const host = url.hostname.toLowerCase();
  const platform = detectPlatform(host);
  const ids = {};

  if (platform === 'tencent') {
    ids.cid = url.searchParams.get('cid') || '';
    ids.vid = url.searchParams.get('vid') || '';
    const cover = url.searchParams.get('vid') ? 'vid' : (url.searchParams.get('cid') ? 'cid' : '');
    ids._primary = cover;
  } else if (platform === 'iqiyi') {
    ids.vid = url.searchParams.get('vid') || '';
    ids.aid = url.searchParams.get('aid') || '';
    const m = url.pathname.match(/([a-zA-Z0-9]{10,})\.html/);
    if (m) ids.albumId = m[1];
    if (ids.vid) ids._primary = 'vid';
    else if (ids.albumId) ids._primary = 'albumId';
    else if (ids.aid) ids._primary = 'aid';
  } else if (platform === 'youku') {
    const m = url.pathname.match(/id_([A-Za-z0-9_=]+)/);
    if (m) ids.vid = m[1];
    ids.showid = url.searchParams.get('showid') || '';
    if (ids.vid) ids._primary = 'vid';
    else if (ids.showid) ids._primary = 'showid';
  } else if (platform === 'mgtv') {
    const segs = url.pathname.split('/').filter(Boolean);
    ids.vid = url.searchParams.get('vid') || '';
    ids.cid = url.searchParams.get('cid') || '';
    // 形如 /v/1/12345.html
    const m = url.pathname.match(/(\d{6,})\.html/);
    if (m) ids.vid = ids.vid || m[1];
    if (segs.length >= 3 && /^\d+$/.test(segs[2] || '')) ids.cid = ids.cid || segs[2];
    if (ids.vid) ids._primary = 'vid';
    else if (ids.cid) ids._primary = 'cid';
  } else if (platform === 'bilibili') {
    const bv = url.pathname.match(/BV[0-9A-Za-z]+/);
    if (bv) ids.bvid = bv[0];
    ids.av = url.searchParams.get('av') || '';
    const avm = url.pathname.match(/\/av(\d+)/i);
    if (avm) ids.av = avm[1];
    if (ids.bvid) ids._primary = 'bvid';
    else if (ids.av) ids._primary = 'av';
  } else if (platform === 'sohu') {
    const m = url.pathname.match(/\/(\d{6,})\.shtml/);
    if (m) ids.vid = m[1];
    ids._primary = ids.vid ? 'vid' : '';
  } else if (platform === 'pptv') {
    const m = url.pathname.match(/\/(\d+)\//);
    if (m) ids.vid = m[1];
    ids._primary = ids.vid ? 'vid' : '';
  } else if (platform === 'leshi') {
    const m = url.pathname.match(/\/play\/?([A-Za-z0-9]+)/);
    if (m) ids.vid = m[1];
    ids._primary = ids.vid ? 'vid' : '';
  }

  return {
    ok: true,
    platform,
    platformLabel: PLATFORMS[platform] ? PLATFORMS[platform].label : '未知平台',
    host,
    ids,
    rawUrl: urlStr.trim(),
  };
}

module.exports = { parseOfficialUrl, detectPlatform, PLATFORMS };
