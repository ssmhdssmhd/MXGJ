# 视频解析工具 (Node.js 版)

由 Python 版「视频解析工具」项目整体转换而来，包含 **视频解析 API 服务**（Express）与 **桌面 GUI 工具**（Electron），解析逻辑与 Python 版完全一致。

## 功能特性

- 多平台视频解析，内置 4 个第三方解析接口，支持爱奇艺、腾讯视频、优酷等主流站点
- 自动抓取视频标题并清理网站后缀
- 解析失败自动降级：第三方接口 → 直接解析 → 通用播放器链接
- 支持 m3u8 / mp4 / flv 等多种视频格式识别
- HTTP API 服务 + 桌面 GUI 双形态，一套解析核心
- 图形界面自带三种主题：科技蓝 / 优雅紫 / 清新绿

## 项目结构

```
videoparse-node/
├── launcher.js            # 统一启动入口（GUI / API 二选一）
├── package.json
├── src/
│   ├── parser.js          # 视频解析核心类（由 Python VideoParser 转换）
│   └── server.js          # Express API 服务
├── gui/
│   ├── main.js            # Electron 主进程
│   ├── preload.js         # 预加载脚本（安全 IPC 桥）
│   ├── index.html         # 图形界面
│   ├── styles.css         # 三主题样式
│   └── renderer.js        # 渲染进程逻辑
└── public/                # 前端静态页面（由 API 托管）
    ├── player.html
    ├── video_player.html
    └── 接口页面.html
```

## 快速开始

### 环境要求

- Node.js ≥ 18
- npm

### 1. 安装依赖

```bash
npm install --no-optional
```

> `--no-optional` 会跳过 Electron 下载（体积大、受网络影响）。如果本机网络可正常访问 GitHub 下载 Electron 二进制，可直接 `npm install`。

### 2. 启动 API 服务

```bash
npm start
# 或
node src/server.js
```

启动后访问：

- 解析接口：`GET/POST http://localhost:5000/api/parse?url=<视频链接>&show_url=1`
- 健康检查：`GET http://localhost:5000/api/health`
- 文档页：`http://localhost:5000/`

### 3. 启动桌面 GUI（需 Electron）

```bash
# 先安装 Electron 二进制
npm install electron --save-dev
# 再启动
npm run gui
```

## API 说明

### `GET/POST /api/parse`

请求参数：

| 参数 | 必填 | 说明 |
|------|------|------|
| `url` | 是 | 视频链接 |
| `show_url` | 否 | 解析失败时是否返回 URL，`1`=是，`0`=否，默认 `1` |

响应（JSON）：

```json
{
  "code": 200,
  "msg": "获取成功",
  "title": "视频标题",
  "type": "m3u8",
  "url": "播放地址",
  "from": "原始链接",
  "time": 0.545
}
```

`code` 说明：`200` 成功、`400` 缺少参数、`404` 未找到播放源、`500` 解析异常。

### 示例

```bash
# GET
curl "http://localhost:5000/api/parse?url=https://www.iqiyi.com/v_19rr2vrlfc.html&show_url=1"

# POST
curl -X POST http://localhost:5000/api/parse \
  -H "Content-Type: application/json" \
  -d '{"url":"https://www.iqiyi.com/v_19rr2vrlfc.html","show_url":"1"}'
```

## 与 Python 版的技术对照

| Python 版 | Node.js 版 |
|-----------|-----------|
| Flask + flask-cors | Express + cors |
| requests.Session | axios |
| BeautifulSoup | cheerio |
| re 正则 | 原生正则 + decodeURIComponent |
| tkinter 桌面 GUI | Electron（复用 HTML 播放页） |
| PyInstaller 打包 exe | Electron 打包（可选） |

## 常见问题

- **Electron 安装失败 / 下载超时**：多为外网受限所致，可设置代理后重试 `npm install electron`，或仅使用 API 服务（无需 Electron）。
- **解析返回 code 404**：第三方接口可能失效或目标站点暂不可解析，可稍后重试或更换视频链接。

## License

MIT