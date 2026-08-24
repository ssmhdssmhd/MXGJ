'use strict';

const { mapWithConcurrency, fetchWithTimeout } = require('./concurrency');
const { nameScore, extractEpisode } = require('./utils');
const logger = require('./logger');

/**
 * 资源站多线程搜索器。
 * 对后台配置的每一个资源站（苹果CMS/海洋CMS provide/vod 接口）并发发起搜索，
 * 匹配剧名与集数，返回可直链播放的地址（m3u8/mp4）。
 */

/** 构建搜索地址：苹果CMS/海洋CMS 通用 detail 接口 */
function buildSearchUrl(site, keyword) {
  const api = String(site.api || '').replace(/\/+$/, '');
  return `${api}?ac=detail&wd=${encodeURIComponent(keyword)}`;
}

/**
 * 解析资源站的播放列表字符串
 * 常见格式："第01集$https://xxx.m3u8#第02集$https://xxx.m3u8"
 * 也兼容："https://a.m3u8#https://b.m3u8"（无集数标签，按顺序）
 * @returns {Array<{label:string, ep:number|null, url:string}>}
 */
function parsePlayList(raw) {
  if (!raw) return [];
  return String(raw)
    .split('#')
    .map((s) => s.trim())
    .filter(Boolean)
    .map((item, idx) => {
      const dollar = item.indexOf('$');
      if (dollar === -1) {
        return { label: '', ep: idx + 1, url: item };
      }
      const label = item.slice(0, dollar).trim();
      const url = item.slice(dollar + 1).trim();
      return { label, ep: extractEpisode(label) || idx + 1, url };
    });
}

/** 在播放列表中挑选目标集数的地址 */
function pickPlayUrl(playList, targetEp) {
  if (!playList || playList.length === 0) return null;
  if (targetEp) {
    const hit = playList.find((p) => p.ep === targetEp);
    if (hit) return hit;
    // 标签包含目标集数字符串的兜底（如 "2" / "02"）
    const pad = String(targetEp).padStart(2, '0');
    const fuzzy = playList.find((p) => p.label.includes(pad) || p.label.includes(String(targetEp)));
    if (fuzzy) return fuzzy;
  }
  return playList[0];
}

/** 解析资源站返回 JSON，挑出与关键词最匹配的剧集 */
function bestVideo(list, keyword) {
  if (!Array.isArray(list) || list.length === 0) return null;
  let best = null;
  let bestScore = 0;
  for (const v of list) {
    const vn = v.vod_name || v.name || '';
    const score = nameScore(vn, keyword);
    if (score > bestScore) {
      bestScore = score;
      best = v;
    }
  }
  if (!best || bestScore < 0.5) return null;
  return best;
}

/**
 * 单资源站搜索
 */
async function searchOneSite(site, name, targetEp, timeout) {
  const url = buildSearchUrl(site, name);
  const t0 = Date.now();
  try {
    const resp = await fetchWithTimeout(url, { timeout: timeout || site.timeout || 8000 });
    if (!resp.ok) {
      return { ok: false, site, reason: `HTTP ${resp.status}` };
    }
    const text = await resp.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      return { ok: false, site, reason: '返回非JSON' };
    }
    const list = (data && (data.list || data.data || [])) || [];
    if (list.length === 0) {
      return { ok: false, site, reason: '无搜索结果' };
    }
    const video = bestVideo(list, name);
    if (!video) {
      return { ok: false, site, reason: '未匹配到剧名', candidates: list.slice(0, 3).map((v) => v.vod_name || v.name) };
    }
    const playFrom = video.vod_play_from || video.play_from || '';
    const playUrl = video.vod_play_url || video.play_url || '';
    const playList = parsePlayList(playUrl);
    const picked = pickPlayUrl(playList, targetEp);
    if (!picked) {
      return { ok: false, site, reason: '无可播放地址' };
    }
    return {
      ok: true,
      site: { id: site.id, name: site.name, api: site.api },
      vodName: video.vod_name || video.name,
      playFrom,
      episode: picked.ep,
      label: picked.label,
      url: picked.url,
      totalEpisodes: playList.length,
      costMs: Date.now() - t0,
    };
  } catch (e) {
    const reason = e && e.name === 'AbortError' ? '请求超时' : (e && e.message) || String(e);
    return { ok: false, site, reason, costMs: Date.now() - t0 };
  }
}

/**
 * 多线程（并发池）搜索所有资源站
 * @param {string} name 剧名
 * @param {number|null} targetEp 目标集数（null 则取第一集）
 * @param {Array} sites 资源站配置列表
 * @param {Object} opts { concurrency, timeout }
 * @returns {Promise<{found:Array, results:Array}>}
 */
async function searchAll(name, targetEp, sites, opts = {}) {
  const enabled = (sites || []).filter((s) => s.enabled !== false);
  if (enabled.length === 0) {
    return { found: [], results: [], skipped: 'no enabled sites' };
  }
  const concurrency = opts.concurrency || 8;
  const results = await mapWithConcurrency(enabled, concurrency, (site) =>
    searchOneSite(site, name, targetEp, opts.timeout)
  );
  const found = results.filter((r) => r && r.ok === true);
  logger.debug(`搜索完成: name=${name} ep=${targetEp} sites=${enabled.length} found=${found.length}`);
  return { found, results };
}

module.exports = { searchAll, searchOneSite, parsePlayList, pickPlayUrl, bestVideo };
