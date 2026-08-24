'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');

const logger = require('./lib/logger');
const { sendJson, extractEpisode } = require('./lib/utils');
const { fetchWithTimeout } = require('./lib/concurrency');
const { ConfigStore } = require('./lib/configStore');
const { parseOfficialUrl } = require('./lib/parser');
const { resolveNameAndEpisode } = require('./lib/nameResolver');
const { searchAll } = require('./lib/resourceSearcher');

const PORT = Number(process.env.PORT) || 3000;
const HOST = process.env.HOST || '0.0.0.0';
const PUBLIC_DIR = path.resolve(__dirname, '../public');

const config = new ConfigStore();

/** 读取请求体（JSON） */
function readBody(req) {
  return new Promise((resolve) => {
    let body = '';
    req.on('data', (chunk) => {
      body += chunk;
      if (body.length > 1e6) req.destroy();
    });
    req.on('end', () => {
      try {
        resolve(body ? JSON.parse(body) : {});
      } catch (e) {
        resolve({});
      }
    });
    req.on('error', () => resolve({}));
  });
}

/** 解析 /api/vod 请求参数（兼容 GET query 与 POST body） */
function collectVodParams(req, query, body) {
  const params = {
    url: query.get('url') || body.url || '',
    name: query.get('name') || body.name || '',
    ep: query.get('ep') ?? body.ep ?? '',
  };
  return params;
}

/**
 * 核心逻辑：官方链接 -> 剧名/集数 -> 资源站多线程搜索 -> 返回替换结果
 */
async function handleVod(params) {
  const start = Date.now();
  const urlStr = String(params.url || '').trim();
  if (!urlStr) {
    return { code: 400, msg: '缺少参数 url（官方视频链接）' };
  }

  const parsed = parseOfficialUrl(urlStr);
  if (!parsed.ok) {
    return { code: 400, msg: parsed.error || '链接解析失败' };
  }

  // 解析剧名与集数
  const overrideEp = params.ep !== '' && params.ep !== undefined && params.ep !== null
    ? extractEpisode(String(params.ep)) || Number(params.ep) || null
    : null;

  const { name, episode, source } = await resolveNameAndEpisode({
    parsed,
    nameMap: config.nameMap,
    overrideName: params.name,
    overrideEp,
  });

  if (!name) {
    return {
      code: 404,
      msg: '无法识别该链接对应的剧名，请在 nameMap.json 中配置，或在请求参数中显式指定 name/ep',
      data: {
        platform: parsed.platform,
        platformLabel: parsed.platformLabel,
        ids: parsed.ids,
        rawUrl: parsed.rawUrl,
      },
    };
  }

  // 多线程搜索所有资源站
  const targetEp = episode || null;
  const { found, results } = await searchAll(name, targetEp, config.sites(), {
    concurrency: config.concurrency(),
    timeout: config.defaultTimeout(),
  });

  const costMs = Date.now() - start;

  if (found.length === 0) {
    return {
      code: 404,
      msg: `未在资源站中找到「${name}」${targetEp ? `第${targetEp}集` : ''}的替换资源`,
      data: {
        name,
        episode: targetEp,
        platform: parsed.platform,
        platformLabel: parsed.platformLabel,
        searched: results.length,
        details: results.map((r) => ({
          site: r.site && r.site.name,
          ok: !!r.ok,
          reason: r.reason || '',
          candidates: r.candidates || undefined,
        })),
      },
    };
  }

  // 命中，按耗时取最快的站点结果作为默认返回
  found.sort((a, b) => (a.costMs || 0) - (b.costMs || 0));
  const top = found[0];

  return {
    code: 200,
    url: top.url,
    msg: 'success',
    data: {
      name,
      episode: top.episode || targetEp,
      platform: parsed.platform,
      platformLabel: parsed.platformLabel,
      matchedSite: top.site.name,
      playFrom: top.playFrom,
      totalEpisodes: top.totalEpisodes,
      candidates: found.map((f) => ({
        site: f.site.name,
        url: f.url,
        episode: f.episode,
        costMs: f.costMs,
      })),
      costMs,
    },
  };
}

/** 资源站连通性测试 */
async function testSites() {
  const sites = config.enabledSites();
  const tasks = sites.map(async (site) => {
    const url = String(site.api).replace(/\/+$/, '') + '?ac=detail&wd=' + encodeURIComponent('测试');
    const t0 = Date.now();
    try {
      const resp = await fetchWithTimeout(url, { timeout: site.timeout || 8000 });
      const text = await resp.text();
      let code = null;
      try {
        code = JSON.parse(text).code;
      } catch (e) { /* ignore */ }
      return { id: site.id, name: site.name, api: site.api, ok: resp.ok, http: resp.status, apiCode: code, costMs: Date.now() - t0 };
    } catch (e) {
      return { id: site.id, name: site.name, api: site.api, ok: false, reason: e.message, costMs: Date.now() - t0 };
    }
  });
  const results = await Promise.all(tasks);
  return { code: 200, msg: 'ok', data: { total: results.length, results } };
}

/** 静态文件服务 */
function serveStatic(req, res, pathname) {
  const filePath = pathname === '/' ? path.join(PUBLIC_DIR, 'index.html') : path.join(PUBLIC_DIR, pathname);
  if (!filePath.startsWith(PUBLIC_DIR)) {
    sendJson(res, 403, { code: 403, msg: 'forbidden' });
    return;
  }
  fs.readFile(filePath, (err, data) => {
    if (err) {
      sendJson(res, 404, { code: 404, msg: 'not found' });
      return;
    }
    const ext = path.extname(filePath).toLowerCase();
    const types = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8' };
    res.writeHead(200, { 'Content-Type': types[ext] || 'application/octet-stream' });
    res.end(data);
  });
}

async function route(req, res) {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const pathname = url.pathname;
  const query = url.searchParams;
  const method = req.method;

  if (method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET,POST,PUT,DELETE,OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    });
    res.end();
    return;
  }

  // 静态页面
  if (method === 'GET' && (pathname === '/' || pathname.startsWith('/static/'))) {
    serveStatic(req, res, pathname.replace(/^\/static/, ''));
    return;
  }

  // 健康检查
  if (method === 'GET' && pathname === '/api/health') {
    sendJson(res, 200, {
      code: 200,
      msg: 'ok',
      data: { name: '沫兮官替系统', version: require('../package.json').version, upTime: process.uptime(), hostname: os.hostname() },
    });
    return;
  }

  // 核心接口：官替
  if (method === 'GET' && pathname === '/api/vod') {
    const params = collectVodParams(req, query, {});
    const result = await handleVod(params);
    sendJson(res, result.code === 200 ? 200 : 200, result);
    return;
  }
  if (method === 'POST' && pathname === '/api/vod') {
    const body = await readBody(req);
    const params = collectVodParams(req, query, body);
    const result = await handleVod(params);
    sendJson(res, 200, result);
    return;
  }

  // 资源站配置管理
  if (method === 'GET' && pathname === '/api/config/resources') {
    sendJson(res, 200, { code: 200, msg: 'ok', data: config.resources });
    return;
  }
  if (method === 'POST' && pathname === '/api/config/resources') {
    const body = await readBody(req);
    try {
      const site = config.addSite(body);
      sendJson(res, 200, { code: 200, msg: '已添加资源站', data: site });
    } catch (e) {
      sendJson(res, 400, { code: 400, msg: e.message });
    }
    return;
  }
  if (method === 'PUT' && pathname.startsWith('/api/config/resources/')) {
    const id = decodeURIComponent(pathname.split('/').pop());
    const body = await readBody(req);
    const site = config.updateSite(id, body);
    if (!site) sendJson(res, 404, { code: 404, msg: '资源站不存在' });
    else sendJson(res, 200, { code: 200, msg: '已更新', data: site });
    return;
  }
  if (method === 'DELETE' && pathname.startsWith('/api/config/resources/')) {
    const id = decodeURIComponent(pathname.split('/').pop());
    const ok = config.deleteSite(id);
    if (!ok) sendJson(res, 404, { code: 404, msg: '资源站不存在' });
    else sendJson(res, 200, { code: 200, msg: '已删除' });
    return;
  }

  // 资源站连通性测试
  if (method === 'GET' && pathname === '/api/sites/test') {
    const result = await testSites();
    sendJson(res, 200, result);
    return;
  }

  sendJson(res, 404, { code: 404, msg: '接口不存在: ' + pathname });
}

const server = http.createServer((req, res) => {
  route(req, res).catch((err) => {
    logger.error('路由处理异常:', err);
    sendJson(res, 500, { code: 500, msg: '服务器内部错误: ' + err.message });
  });
});

server.listen(PORT, HOST, () => {
  logger.info('==============================================');
  logger.info('  沫兮官替系统 v1.0.0 已启动');
  logger.info(`  服务地址: http://${HOST}:${PORT}`);
  logger.info(`  官替接口: http://127.0.0.1:${PORT}/api/vod?url=<官方链接>`);
  logger.info(`  启用资源站: ${config.enabledSites().length} 个, 并发: ${config.concurrency()}`);
  logger.info('==============================================');
});

module.exports = server;
