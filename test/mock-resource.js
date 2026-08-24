'use strict';

/**
 * 本地 Mock 资源站（模拟苹果CMS provide/vod 接口），用于无外网环境下测试官替系统。
 * 运行: node test/mock-resource.js   (默认端口 19090)
 */

const http = require('http');

const PORT = Number(process.env.MOCK_PORT) || 26531;

// 模拟库：庆余年 共 4 集，播放地址为假直链（.m3u8）
const DB = [
  {
    vod_id: '1001',
    vod_name: '庆余年',
    type_name: '剧情',
    vod_play_from: 'm3u8',
    vod_play_url: [
      '第01集$http://127.0.0.1:26531/play/qingyu/01.m3u8',
      '第02集$http://127.0.0.1:26531/play/qingyu/02.m3u8',
      '第03集$http://127.0.0.1:26531/play/qingyu/03.m3u8',
      '第04集$http://127.0.0.1:26531/play/qingyu/04.m3u8',
    ].join('#'),
  },
];

function search(wd) {
  const kw = String(wd || '').replace(/\s+/g, '');
  if (!kw) return [];
  return DB.filter((v) => v.vod_name.replace(/\s+/g, '').includes(kw) || kw.includes(v.vod_name.replace(/\s+/g, '')));
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  res.setHeader('Content-Type', 'application/json; charset=utf-8');

  if (url.pathname.includes('provide/vod')) {
    const ac = url.searchParams.get('ac') || 'list';
    const wd = url.searchParams.get('wd') || '';
    if (ac === 'detail') {
      const list = search(wd);
      res.end(JSON.stringify({
        code: 1,
        msg: list.length ? '数据列表' : '暂无数据',
        page: 1,
        pagecount: 1,
        limit: '20',
        total: list.length,
        list,
      }));
      return;
    }
  }
  res.end(JSON.stringify({ code: 0, msg: 'not found' }));
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`[mock] 本地Mock资源站已启动: http://127.0.0.1:${PORT}/api.php/provide/vod/`);
});

module.exports = server;
