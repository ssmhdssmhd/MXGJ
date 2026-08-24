'use strict';
/**
 * 视频解析 API 服务 (Node.js / Express 版)
 * 由 Python 版 video_parser_api.py 转换而来
 * 路由：/api/parse 、/api/health 、/
 */

const express = require('express');
const cors = require('cors');
const path = require('path');
const fs = require('fs');
const VideoParser = require('./parser');

const app = express();
const PORT = process.env.PORT || 5000;

app.use(cors()); // 允许跨域
app.use(express.json()); // 解析 JSON body
app.use(express.urlencoded({ extended: true }));

const parser = new VideoParser();
const PUBLIC_DIR = path.join(__dirname, '..', 'public');

// 静态资源：托管前端页面（player.html 等）
app.use(express.static(PUBLIC_DIR));

/**
 * 视频解析接口
 * GET /api/parse?url=...&show_url=1
 * POST /api/parse  { url, show_url }
 */
app.all(['/api/parse', '/api/parse/'], async (req, res) => {
  let videoUrl;
  let showUrl;

  if (req.method === 'POST') {
    videoUrl = (req.body && req.body.url) || '';
    showUrl = req.body && req.body.show_url != null ? String(req.body.show_url) : '1';
  } else {
    videoUrl = req.query.url || '';
    showUrl = req.query.show_url != null ? String(req.query.show_url) : '1';
  }

  // 参数验证
  if (!videoUrl) {
    return res.json({
      code: 400,
      msg: '缺少必填参数: url',
      title: '',
      type: '',
      url: '',
      from: '',
      time: 0,
    });
  }

  const showUrlOnError = showUrl === '1';
  const result = await parser.parseVideo(videoUrl, showUrlOnError);
  return res.json(result);
});

/** 健康检查 */
app.get('/api/health', (req, res) => {
  res.json({
    status: 'ok',
    service: '视频解析 API',
    version: '1.0.0',
  });
});

/** API 文档页 */
app.get('/', (req, res) => {
  const docFile = path.join(PUBLIC_DIR, 'api_docs.html');
  if (fs.existsSync(docFile)) {
    return res.sendFile(docFile);
  }
  res.type('html').send(DOC_HTML);
});

// 若文档文件不存在，则使用内置简易文档
const DOC_HTML = `<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <title>视频解析 API</title>
  <style>
    body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
    h1 { color: #333; }
    .endpoint { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; }
    code { background: #e0e0e0; padding: 2px 5px; border-radius: 3px; }
  </style>
</head>
<body>
  <h1>🎬 视频解析 API</h1>
  <div class="endpoint">
    <h2>GET/POST /api/parse</h2>
    <p><strong>参数：</strong></p>
    <ul>
      <li><code>url</code> (必填) - 视频链接</li>
      <li><code>show_url</code> (可选) - 失败时是否返回URL，1=是，0=否</li>
    </ul>
    <p><strong>示例：</strong></p>
    <code>GET /api/parse?url=https://www.iqiyi.com/v_xxx.html&show_url=1</code>
  </div>
  <p>💡 静态页面请访问 /player.html 、/video_player.html 、/接口页面.html</p>
</body>
</html>`;

// 启动服务
if (require.main === module) {
  app.listen(PORT, '0.0.0.0', () => {
    console.log('='.repeat(60));
    console.log('🎬 视频解析 API 服务启动 (Node.js / Express)');
    console.log('='.repeat(60));
    console.log(`📡 API 地址: http://localhost:${PORT}/api/parse`);
    console.log(`📖 文档地址: http://localhost:${PORT}/`);
    console.log(`💚 健康检查: http://localhost:${PORT}/api/health`);
    console.log('='.repeat(60));
  });
}

module.exports = app;