'use strict';

const { fetchWithTimeout } = require('./concurrency');
const { extractEpisode } = require('./utils');
const logger = require('./logger');

/**
 * 剧名/集数解析器。
 * 优先级：
 *   1. 请求参数显式指定（name / ep）——最高优先级
 *   2. config/nameMap.json 映射表（cid/vid/BV 等标识 → 剧名+集数）
 *   3. 抓取官方页面 <title> 兜底解析（可能被反爬拦截，仅作兜底）
 */

/** 去掉页面标题中的平台后缀，如 "庆余年-第2集-腾讯视频" -> "庆余年" */
function cleanTitle(title) {
  let t = String(title || '').trim();
  t = t.replace(/-{1,3}\s*(腾讯视频|爱奇艺|优酷|芒果TV|哔哩哔哩|搜狐视频|PPTV|乐视|高清完整版|免费在线观看|在线观看).*$/i, '');
  t = t.replace(/^\s*<title>\s*|\s*<\/title>\s*$/g, '');
  return t.trim();
}

/** 从标题解析集数：优先 "第N集"，其次 "N-全集" 等 */
function titleEpisode(title) {
  return extractEpisode(title);
}

/**
 * 解析剧名与集数
 * @param {Object} params { parsed, nameMap, overrideName, overrideEp }
 * @returns {Promise<{name:string|null, episode:number|null, source:string}>}
 */
async function resolveNameAndEpisode({ parsed, nameMap = {}, overrideName, overrideEp }) {
  const ids = (parsed && parsed.ids) || {};
  const platform = (parsed && parsed.platform) || 'unknown';
  const results = { name: null, episode: null, source: 'none' };

  // 1. 参数覆盖
  if (overrideName && String(overrideName).trim()) {
    results.name = String(overrideName).trim();
    results.episode = overrideEp !== undefined && overrideEp !== null && overrideEp !== ''
      ? Number(overrideEp) || null
      : null;
    results.source = 'param';
    return results;
  }

  // 2. 映射表
  const platformMap = nameMap[platform] || {};
  let foundId = null;
  let foundKey = null;
  const primary = ids._primary || Object.keys(ids)[0];
  for (const key of Object.keys(ids)) {
    if (key === '_primary') continue;
    if (ids[key] && platformMap[ids[key]]) {
      foundId = ids[key];
      foundKey = key;
      break;
    }
  }
  // 如果按平台映射没命中，再尝试 misc 映射
  if (!foundId && nameMap.misc) {
    for (const key of Object.keys(ids)) {
      if (key === '_primary') continue;
      if (ids[key] && nameMap.misc[ids[key]]) {
        foundId = ids[key];
        foundKey = key;
        break;
      }
    }
  }
  if (foundId) {
    const hit = (platformMap[foundId] || (nameMap.misc && nameMap.misc[foundId]) || {});
    results.name = hit.name || null;
    results.episode = hit.episode ? Number(hit.episode) : null;
    results.source = `nameMap(${foundKey}=${foundId})`;
    if (results.name) return results;
  }

  // 3. 页面标题兜底
  if (parsed && parsed.rawUrl) {
    try {
      const resp = await fetchWithTimeout(parsed.rawUrl, { timeout: 8000 });
      const html = await resp.text();
      const m = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
      if (m) {
        const title = cleanTitle(m[1]);
        if (title) {
          results.name = title;
          results.episode = titleEpisode(title) || results.episode;
          results.source = 'pageTitle';
          return results;
        }
      }
    } catch (e) {
      logger.warn(`官方页面抓取失败(${parsed.rawUrl}): ${e.message}`);
    }
  }

  if (results.name) return results;

  logger.warn(`无法解析剧名: platform=${platform} primary=${primary} ids=${JSON.stringify(ids)}`);
  return results;
}

module.exports = { resolveNameAndEpisode, cleanTitle, titleEpisode };
