'use strict';

/**
 * 端到端测试：
 *   1. 启动本地 Mock 资源站
 *   2. 启动官替系统（随机端口）
 *   3. 用示例官方链接请求 /api/vod
 *   4. 断言 code=200 且返回的 url 为资源站直链（m3u8），并匹配到第 2 集
 */

const { spawn } = require('child_process');
const path = require('path');
const http = require('http');

const MOCK_PORT = 26531;
const MAIN_PORT = 3001;
const BASE = `http://127.0.0.1:${MAIN_PORT}`;
const EXAMPLE_URL = 'https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce';

function start(cmd, args, readyText, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(cmd, args, {
      cwd: path.resolve(__dirname, '..'),
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, ...options.env },
    });
    let out = '';
    const timer = setTimeout(() => reject(new Error('启动超时: ' + cmd + ' ' + args.join(' ') + '\n' + out)), 15000);
    child.stdout.on('data', (d) => {
      out += d.toString();
      if (readyText && out.includes(readyText)) {
        clearTimeout(timer);
        resolve(child);
      }
    });
    child.stderr.on('data', (d) => { out += d.toString(); });
    child.on('exit', (code) => { clearTimeout(timer); reject(new Error('进程退出 code=' + code + '\n' + out)); });
  });
}

function getJson(urlPath) {
  return new Promise((resolve, reject) => {
    http.get(BASE + urlPath, (res) => {
      let data = '';
      res.on('data', (c) => (data += c));
      res.on('end', () => {
        try { resolve(JSON.parse(data)); } catch (e) { reject(new Error('响应非JSON: ' + data)); }
      });
    }).on('error', reject);
  });
}

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

async function main() {
  let mock;
  let main;
  let failed = false;
  try {
    console.log('==> 1. 启动本地 Mock 资源站 ...');
    mock = await start('node', ['test/mock-resource.js'], '本地Mock资源站已启动');

    console.log('==> 2. 启动官替系统 ...');
    main = await start('node', ['src/server.js'], '沫兮官替系统', { env: { ...process.env, PORT: String(MAIN_PORT) } });

    await sleep(500);

    console.log('==> 3. 健康检查 /api/health');
    const health = await getJson('/api/health');
    console.log('    health:', JSON.stringify(health.msg));

    console.log(`==> 4. 官替接口测试 url=${EXAMPLE_URL}`);
    const result = await getJson('/api/vod?url=' + encodeURIComponent(EXAMPLE_URL));
    console.log('    返回:', JSON.stringify(result, null, 2));

    const asserts = [
      ['code === 200', result.code === 200],
      ['存在 url 字段', typeof result.url === 'string' && result.url.length > 0],
      ['url 为 m3u8 直链', /\.m3u8/i.test(result.url)],
      ['匹配到第2集', result.data && result.data.episode === 2],
      ['来源为 Mock 资源站', result.data && result.data.matchedSite === '本地Mock资源站(测试用)'],
    ];

    console.log('==> 5. 断言结果');
    for (const [label, pass] of asserts) {
      console.log(`    ${pass ? 'PASS' : 'FAIL'}  ${label}`);
      if (!pass) failed = true;
    }

    console.log('==> 6. 资源站列表接口 /api/config/resources');
    const cfg = await getJson('/api/config/resources');
    console.log(`    资源站数量: ${cfg.data.sites.length}, 启用: ${cfg.data.sites.filter(s=>s.enabled!==false).length}`);

    if (failed) {
      console.error('\n[FAIL] 存在未通过的断言');
      process.exitCode = 1;
    } else {
      console.log('\n[PASS] 全部通过，代码可运行、接口正常');
    }
  } catch (e) {
    console.error('[ERROR] 测试失败:', e.message);
    process.exitCode = 1;
  } finally {
    if (mock) mock.kill('SIGKILL');
    if (main) main.kill('SIGKILL');
  }
}

main();
