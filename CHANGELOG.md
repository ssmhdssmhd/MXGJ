# 更新日志

本项目由 Python 版「视频解析工具」整体转换为 Node.js 实现，作为 MXGJ 视频解析框架发布。

## [0.0.1] - 2026-08-24

### 框架发布（首个版本）

- 正式命名为 **mxgj**，版本号定为 `0.0.1`，作为 MXGJ 视频解析框架（Node.js 版）发布
- 在 `package.json` 补充 `repository` 字段，关联仓库 `ssmhdssmhd/MXGJ`
- 通过 GitHub Releases 发布 `v0.0.1` 供下载

## [1.0.0] - 2026-08-24

### 新增（Node.js 版首发）

- 视频解析能力由 Python 版完整迁移至 Node.js
  - `VideoParser` 核心类（`src/parser.js`）：第三方接口解析、直接解析、播放源提取、标题抓取清理、视频类型识别
  - Express API 服务（`src/server.js`）：`/api/parse`、`/api/health`、`/` 三个接口
- 桌面 GUI 由 tkinter 重写为 Electron
  - 复用原生 HTML 播放页，安全 IPC 桥（contextIsolation + preload）
  - 保留三种主题：科技蓝 / 优雅紫 / 清新绿
- 原项目的 `player.html`、`video_player.html`、`接口页面.html` 作为静态资源由 API 托管

### 变更（相对 Python 版）

- 依赖栈替换：Flask → Express，requests → axios，BeautifulSoup → cheerio，tkinter → Electron
- 新增系统代理（HTTP_PROXY / HTTPS_PROXY）自动支持，内网 / 代理环境可直接运行
- GUI 解析接口升级为多播放源返回（`parseForGui`），可一次展示并复制全部来源

### 依赖（runtime）

- express ^4.19.0，cors ^2.8.5，axios ^1.7.0，cheerio ^1.0.0，https-proxy-agent ^7.0.0

### 备注

- Electron（可选依赖，体积较大）需独立安装后才能使用 GUI 形态
- 解析结果依赖第三方接口的可用性