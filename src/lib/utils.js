'use strict';

/**
 * 通用工具函数
 */

/** 规范化剧名：去空格/标点/全半角，统一小写，用于比对 */
function normalizeName(name) {
  if (!name) return '';
  return String(name)
    .replace(/\s+/g, '')
    .replace(/[《》【】\[\]()（）:：·,，.。!！?？'"“”\-—_]/g, '')
    .toLowerCase();
}

/**
 * 剧名相似度打分（0~1）
 */
function nameScore(a, b) {
  const na = normalizeName(a);
  const nb = normalizeName(b);
  if (!na || !nb) return 0;
  if (na === nb) return 1;
  if (na.includes(nb) || nb.includes(na)) return 0.9;
  // 去掉季/部等后缀再比一次，如 "庆余年 第一季" vs "庆余年"
  const stripSeason = (s) => s.replace(/第?[一二三四五六七八九十\d]+[季部]?$/, '');
  const sa = stripSeason(na);
  const sb = stripSeason(nb);
  if (sa === sb) return 0.85;
  if (sa.includes(sb) || sb.includes(sa)) return 0.7;
  return 0;
}

/** 从 "第02集/EP02/02/第2话" 等文本中提取集数数字，取不到返回 null */
function extractEpisode(text) {
  if (text === undefined || text === null) return null;
  const s = String(text);
  let m = s.match(/第\s*(\d+)\s*[集话话]|(\d+)\s*[集话]/);
  if (m) return parseInt(m[1] || m[2], 10);
  m = s.match(/\bep\.?\s*(\d+)\b|\bE(\d+)\b|\b第(\d+)[集话]\b/i);
  if (m) return parseInt(m[1] || m[2], 10);
  m = s.match(/^\s*(\d{1,4})\s*$/);
  if (m) return parseInt(m[1], 10);
  return null;
}

/** 响应 JSON 输出 */
function sendJson(res, statusCode, obj) {
  const payload = JSON.stringify(obj);
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(payload),
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET,POST,PUT,DELETE,OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
  });
  res.end(payload);
}

module.exports = { normalizeName, nameScore, extractEpisode, sendJson };
