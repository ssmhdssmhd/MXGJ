# 更新日志 (CHANGELOG)

本项目遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/) 规范。

## [1.0.0] - 2026-08-24

### 新增

- 首版发布：沫兮官替系统
- 官方视频链接解析：支持腾讯视频、爱奇艺、优酷、芒果TV、哔哩哔哩、搜狐、PPTV、乐视等平台，抽取 `cid`/`vid`/`BV号`/专辑ID 标识
- 剧名与集数三级识别：请求参数覆盖 → `nameMap.json` 映射表 → 官方页面 `<title>` 兜底
- 资源站**多线程（并发池）**搜索：并发检索全部后台资源站，支持并发数与超时配置
- 播放列表解析：兼容 `第02集$url#第03集$url` 与无序列表，集数精准匹配
- JSON 输出：命中 `code=200` + `url(m3u8/mp4)`；未命中 `code=404` 附各站检索明细；参数错误 `code=400`
- 资源站管理 API：新增 / 修改 / 删除 / 连通性测试，变更自动落盘
- Web 前端页面：浏览器输入官方链接一键官替并复制 JSON
- 本地 Mock 资源站 + 端到端自动化测试（`npm test`），全流程可离线验证

### 技术说明

- 零第三方依赖，基于 Node.js 原生 `http` / `fetch`（要求 Node >= 18）
- 配置持久化：`config/resources.json`（资源站）、`config/nameMap.json`（剧名映射）
