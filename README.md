<div align="center">

# 🎬 沫兮官替系统 <span style="color:#8b5cf6">MXGJ</span>

### 官方视频链接 → 多线程资源站搜索 → 真实 m3u8 直出

> 🚀 一款开箱即用的「官代/官替」媒体解析系统 · 无数据库 · 零依赖

<br>

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF?logo=php&logoColor=white&style=for-the-badge)](https://www.php.net)
[![Version](https://img.shields.io/badge/Version-v1.17.2-4f7cff?style=for-the-badge&labelColor=2d1b69)](https://github.com/ssmhdssmhd/MXGJ/releases)
[![License: MIT](https://img.shields.io/badge/License-MIT-22a06b?style=for-the-badge)](LICENSE)
[![Stars](https://img.shields.io/github/stars/ssmhdssmhd/MXGJ?style=for-the-badge&logo=github&color=8b5cf6)](https://github.com/ssmhdssmhd/MXGJ/stargazers)
[![Forks](https://img.shields.io/github/forks/ssmhdssmhd/MXGJ?style=for-the-badge&logo=github&color=6366f1)](https://github.com/ssmhdssmhd/MXGJ/forks)
[![Issues](https://img.shields.io/github/issues/ssmhdssmhd/MXGJ?style=for-the-badge&color=f59e0b)](https://github.com/ssmhdssmhd/MXGJ/issues)
[![Pulls](https://img.shields.io/github/issues-pr/ssmhdssmhd/MXGJ?style=for-the-badge&color=10b981)](https://github.com/ssmhdssmhd/MXGJ/pulls)
[![CI](https://img.shields.io/github/actions/workflow/status/ssmhdssmhd/MXGJ/ci.yml?branch=main&style=for-the-badge&logo=github-actions)](https://github.com/ssmhdssmhd/MXGJ/actions)
[![Storage](https://img.shields.io/badge/Storage-No--DB-2ecc71?style=for-the-badge)](https://github.com/ssmhdssmhd/MXGJ)
[![Docker](https://img.shields.io/badge/Docker-Ready-0ea5e9?style=for-the-badge&logo=docker)](https://github.com/ssmhdssmhd/MXGJ)

<br>

[**📖 快速开始**](#-快速开始) · [**🧭 工作原理**](#-工作原理) · [**🔧 配置指南**](#-配置说明) · [**🐛 提 Issue**](https://github.com/ssmhdssmhd/MXGJ/issues) · [**📦 一键部署**](#-docker-一键部署)

<br>

| 支持平台 | 🎯 | 📺 | 🎬 | 🥭 | 💜 | 📺 |
|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| 腾讯视频 | 爱奇艺 | 优酷 | 芒果TV | 哔哩哔哩 | PPTV |

输入任意官方播放链接（腾讯 / 爱奇艺 / 优酷 / 芒果TV / 哔哩哔哩 / PPTV），
系统自动解析出 **剧名 + 集数**，然后**并发**向后台配置的全部资源站搜索对应资源，
命中后以 JSON 返回 `code=200` 与**可直接播放的 m3u8 地址**。

> 🎯 无数据库 · 纯 JSON 文件存储 · 零依赖部署，放上 PHP 环境即可用

</div>

---

## 📌 更新说明（置顶 · 最新在最上）

<details open>

<summary>查看历史更新（点击折叠）</summary>

### [v1.17.2] 2026-08-28 · App 接口 key 参数前置 + 完整 key 鉴权
- **key 参数放到 URL 最前面**：所有接口统一 `?key=xxx&url=...` 格式（第一个 query 参数位置）
- **`player/api.php` 补齐完整 key 鉴权**（之前注释写了 key 但代码完全没实现）：
  - App 接口 enable 开关（关闭返回 403）
  - require_key 强制鉴权开关
  - api_key 自定义（fallback 到 config/key.php）
- **admin.php URL 预览全部 key 前置**：11 处 `?key=xxx`，0 处 `&key=xxx`
- 🐛 修 bug：`mxgj_settings('app_api')` 参数被 PHP 静默忽略 → 改为 `mxgj_settings()['app_api'] ?? []`

### [v1.17.1] 2026-08-28 · 新增 🎬 App 接口配置 tab（侧边栏 + 完整 UI）
- **资源配置组新增「🎬 App接口」** nav-item（sidebar nav 硬编码，非循环）
- **完整配置面板**：启用开关 / 接口鉴权 key 开关 / 代理转发开关 / App Key 自定义 / 播放器类型（lgzym3u8/虾米FLV/云播）/ CORS / 最大代理大小 / 单 IP 限流
- **📡 实时接口路径预览**：5 种 APP/TVBox 调用格式（播放器页面带真实 m3u8 / 加密 u 参数 / TVBox API 代理 / 官方链接解析 / 苹果CMS 透传），一键复制
- **🧪 一键测试**：输入任意 URL 直接调 `/player/api.php` 显示 JSON 结果
- **bootstrap settings 默认值** 新增完整 `app_api` 配置块
- **save_settings 后端** 加 app_api 读写（8 字段 + 安全校验）

### [v1.17.0] 2026-08-27 · 搜索接口模板库（v1 首版）+ 修复复制/封面图/播放按钮 + player/api.php
- **搜索接口模板库**（`config/search_templates.json`）：v1 预置 8 个最常用苹果CMS10 模板，选模板 → 填 host → 自动生成 URL
- **admin.php 前端模板选择器**：弹窗式 UI，📦 预置 / ✨ 自定义标记，点「生成并打开检测」一键流程
- **renderSiteListView** 完整功能：选站即显最新资源（`svSearch` 去掉 wd 非空拦截）+ 模板下拉 + 表格缩略图列 + 详情弹窗封面图 + 每集 ▶播放 → `/player/?u=<base64url>` + 📋复制
- **`svCopy` 修复**：用 `data-url` 存储 URL 避免 JSON.stringify 转义破坏 onclick；加 `svCopyFallback` 用 `document.execCommand('copy')` 兼容 HTTP 页面（navigator.clipboard.writeText 仅 HTTPS）
- **新建 `player/api.php`**：4 种模式（m3u8 代理重写 / 苹果CMS 透传 / 完整解析流程 / JSONP），APP/TVBox 统一调用入口
- **bootstrap.php 防重复加载**：加 `MXGJ_BOOTSTRAP_LOADED` 守卫 + 所有 PHP 文件 `require_once bootstrap.php`
- **renderSiteListView**：表格加缩略图列 + 详情弹窗封面图 + 每集加 ▶播放 按钮

### [v1.16.9] 2026-08-27 · 后台 page_size 设置 + 模板下拉 + 紫色渐变 UI
- 后台设置新增 `page_size`（每页返回条数，默认 50）
- 资源站配置里的模板下拉改成「tt-preset select + tt-tpl-list datalist」
- select 加 `onchange="svSearch()"` 实现选站即显最新资源
- **紫色渐变 UI 全套 CSS 变量**（`--sidebar-w:220px` / `--sidebar-bg:#1a1440` / `--accent:#6366f1`）+ sidebar 渐变 + topbar 毛玻璃 + logo 紫粉渐变
- 消除漂移常量：侧边栏宽度统一用 CSS 变量，不再散落硬编码 208px

### [v1.16.8] 2026-08-27 · buildTemplate 智能保留用户 ac 值 + 选站即显资源 + 复制修复
- SiteDetector::buildTemplate 智能保留用户原始 ac 值（list/videolist），wd=%u 占位符
- **svSearch 去掉 wd 非空拦截**：选资源站下拉即触发搜索最新资源（wd 为空时返回列表前 20 条）
- **svCopy 修复**：用 `data-url` 属性存储避免 JSON.stringify 破坏 HTML 属性

### [v1.16.7] 2026-08-27 · 搜索验证验证码解锁（Phase 3）
- 每个资源站 host 独立 cookie jar（`data/cookies/{md5}.txt`），SiteSearcher curl 自动带上
- SiteDetector 探测到验证码 → 弹窗显示「🔓 尝试手动解锁搜索验证」
- 用户输入验证码 → 后端 submit 到苹果CMS verify 接口（5 种路径轮询）+ cookie jar 持久化 → 后续请求自动过验证
- admin.php 新增 `captcha_fetch` / `captcha_submit` / `captcha_clear` 三个 action

### [v1.16.6] 2026-08-27 · SiteDetector v3 — 超鲁棒多策略关键词搜索探测
- **三阶段架构**：Phase 1 空列表探测 → Phase 1.5 会话预热（cookie jar + 浏览器 headers）→ Phase 2 多策略关键词搜索（5 策略 × curl_multi 并发）
- SiteDetector 新增 captcha_fetch 完整链路：验证码图片提取 + cookie jar 持久化 + 手动解锁
- 全局代理支持：bootstrap.php `mxgj_apply_proxy()` 自动读取 HTTP_PROXY/HTTPS_PROXY/NO_PROXY
- sidebar nav PHP 循环遍历 `$tabLabels` 数组生成（不是硬编码 HTML），新增 tab 自动渲染

### [v1.13.0] 2026-08-26 · 移除 App/安全设置 + 后台改为「点击空白处自动保存并自动清理」
- **彻底移除「表面播放链接（App设置）」与「安全设置（欺诈/伪装）」**：
  - 删除 `app` / `security` 配置段、后台对应区块、`mxgj_surface_url` / `mxgj_protect_url` / `mxgj_obfuscate_url`
  - 前台 `index.php` **直接返回真实 m3u8 地址**，不再伪装为 `/play.php?u=...`
  - `play.php` 固定为「代理转发」模式，可继续作独立播放入口
- **取消所有「保存」按钮，改为点击页面空白处自动保存**（设置 / 资源站 / 映射表）
- **保存时自动清理运行时数据**（搜索缓存、站点健康、日志），Web 环境 PHP 每次请求自动重载，无需真正重启进程
- 自测 9/9 通过

### [v1.12.0] 2026-08-26 · 资源站「特殊调用方法」（GET/POST · 自定义Header · POST体 · 返回解析模式）
- 为每个资源站新增**特殊调用方法**配置（后台「资源站」表新增「特殊调用方法」列）：
  - **调用方法**：`GET`（默认）或 `POST`（特殊，走 POST 提交搜索）
  - **自定义Header**：每行 `Key: Value`，发送时附带（Referer / Cookie / Auth 等）
  - **POST体**：含占位符 `%u/%s/%p` 的请求体；`method=POST` 且留空时默认 `wd=%u&ep=%p`
  - **返回解析**：自动 / JSON / 纯文本 / 苹果CMS，强制指定防误判
- 前台搜索自动路由到对应调用方法（`lib/SiteSearcher.php` 新增 `buildRequest`/`normalizeHeaders`，`makeHandle` 支持 POST，`parseBody` 支持强制模式）
- 旧配置无新字段时全部走默认 GET+自动解析，**完全兼容**；苹果CMS 检测弹窗修改已有站时保留原特殊配置
- 自测新增「特殊资源站(POST调用方法)收到 `wd=%u&ep=%p` 请求体」用例（10/10 通过）

### [v1.11.1] 2026-08-25 · 修复 play.php 对 HTML 播放页「特殊资源站不能播放」
- `play.php?u=<加密地址>` 遇 HTML 播放页（如 `https://lgvideo.xyz/player/xxx`）不再 302 跳转，改为**直接渲染内联播放器**（内嵌 `https://vv00.xyz?url=<真实地址>` iframe，与 player.php / lgzym3u8 一致）
  - 此前：redirect 模式 302 拿到 HTML 页、APP 原生播放器无法播放；proxy 模式探测失败时还会把 HTML 当二进制流式输出
  - 现在：浏览器 / APP webview 均可正常打开播放；m3u8/ts 行为不变；顺带修复 m3u8 以 `text/plain` 返回时被误判跳转

### [v1.11.0] 2026-08-25 · 播放器页面 player.php（lgzym3u8 / 蓝光资源 MacPlayer）
- 新增 `player.php`：将「Igzym3u8」播放器配置（苹果CMS MacPlayer / 蓝光资源）**原样加载**渲染真实播放
  - 调用：`/player.php?url=<播放地址>` 或 `/player.php?u=<base64url 加密地址>`（与表面播放链接同款加密，可作播放入口）
  - 内置 MacPlayer 运行时：设置 `PlayUrl` → 执行导入 `code`（iframe 指向 `https://vv00.xyz?url=播放地址`）→ `MacPlayer.Show()` 渲染
  - 可在后台「App 设置」把播放入口路径改为 `player.php`，让表面播放链接直接打开该播放器页面

### [v1.10.0] 2026-08-25 · App 设置「表面播放链接」（自托管播放入口）
- 后台「设置」新增 **App 设置（表面播放链接，默认开启）**：返回的播放链接统一伪装为当前域名下的播放入口
  - `https://当前域名/play.php?u=<base64url 加密的真实播放地址>` —— **表面是一个链接**，浏览器打开即可播放，也能被 APP 识别并直接播放，同时隐藏真实播放地址
- 新增**播放入口 `play.php`**：
  - **代理转发（默认，推荐）**：本站抓取并重写 m3u8 切片/密钥地址、ts/mp4 二进制流式转发，完全隐藏真实源，APP 原生播放器可直连播放；HTML 播放页自动降级为 302 跳转
  - **302 跳转**：直接跳转真实地址（省流量）
- 修复：此前「欺诈/伪装」仅替换中转域名，替换后 `https://当前域名?url=...` 无播放入口、无法播放；现由 `play.php` 保证链接可正常打开播放

### [v1.9.1] 2026-08-25 · 调整「欺诈/伪装」默认关闭
- 「安全设置-欺诈/伪装」默认**关闭**（`security.obfuscate_enable` 默认 `false`），按需开启

### [v1.9.0] 2026-08-25 · 资源站「跟随播放链接」+ 安全设置（欺诈/伪装）
- **资源站单独配置「跟随播放链接」中转前缀**：每行可填如 `https://vv00.xyz?url=`，仅该站命中时自动拼接到真实播放地址前
  - 返回 `https://vv00.xyz?url=真实m3u8地址`，满足「特定资源站启用」的需求
- **后台「安全设置（欺诈/伪装）」默认开启**：把返回链接中的中转前缀域名伪装为当前系统域名
  - `https://vv00.xyz?url=真实地址` → `https://当前域名?url=真实地址`，不暴露真实中转站，且不影响正常播放
  - 对返回 JSON 中所有含播放地址的字段（url/msg）统一生效；可在设置页随时开关

### [v1.8.0] 2026-08-25 · 通用快捷开关（映射表 / 输出字段 / 设置项）
- 「快捷开关」升级为**通用机制**：点击即时生效、无需保存，后续新增配置项可直接复用
  - **映射表**：官方ID / 剧名 / 腾讯cid 每条映射新增「启用」开关，关闭后该条映射不再参与匹配
  - **输出返回设置**：每条返回字段可单独启用/禁用，关闭后不再出现在返回 JSON
  - **设置页**：启用心跳检测 / 启用资源站轮训 改为点击即时生效
- 禁用状态存于 `mapping.json` 的 `disabled` 段 / 字段 `enabled` 标记，完全兼容旧数据

### [v1.7.0] 2026-08-25 · 后台日志系统
- 后台新增「**日志**」tab，自动记录**六类日志**（存 `data/logs/*.json`，每类保留 500 条）：
  - **登录日志**：后台登录成功/失败（含 IP）· **操作日志**：后台全部增删改操作
  - **更新日志**：在线升级 / `update.php` 升级结果（含步骤与测速）
  - **搜索调用日志**：每次搜索的剧名/集数/状态码/站点/耗时/来源/IP
  - **配置日志**：资源站/映射表/设置等配置保存成功或失败 · **错误日志**：拦截与异常
- 支持级别标记（成功/警告/错误/提示）、详情展开、按类清空 / 一键清空全部

### [v1.6.1] 2026-08-25 · 资源站快捷「启用/禁用」开关
- 后台「资源站」列表每行新增**启用开关**（滑块），点击**即时生效、无需保存**
- 关闭后该站**不再参与前台搜索与心跳探测**，禁用行置灰标识
- 新站默认启用，编辑/保存保留启用状态；`sites.json` 每站新增 `enabled` 字段（兼容旧配置）

### [v1.6.0] 2026-08-25 · 后台「输出返回设置」（自定义返回字段映射）
- 后台「设置」新增**输出返回设置**：前台返回的 JSON 字段完全由你定义
  - 每条配置 **键名 k**（输出字段名）+ **值来源 v**（系统字段或固定常量文本）
  - 可映射系统字段：`code` 状态码 / `url` 播放链接 / `title` 影视剧名 / `episode` 集数 / `time` 耗时 / `site` 命中站点 / `msg` 提示 / `source` 请求链接
  - 或填**常量文本**作固定值，例如返回 `KFZ=沫兮官替系统`
  - 例：返回 `JM=庆余年`、`JJ=第2集`——键名 `JM` 值来源 `title`，键名 `JJ` 值来源 `episode`
- **原始请求链接默认隐藏**（`show_source=false`），避免返回杂乱；可随时勾选开启
- **默认字段**：`code` · `msg`(=url) · `url` · `time` · `KFZ`，开箱即用、兼容既有调用

### [v1.5.0] 2026-08-25 · 资源站检测 + 自动添加/修改（苹果CMS10）
- 后台「资源站」页新增 **「⚡ 检测并自动添加」**：只需粘贴苹果 CMS10 采集接口地址到弹窗
  - 自动检测接口是否可用、是否返回苹果CMS列表（`list[]` 含可播放地址），**证明能取到真实资源**
  - 自动生成搜索模板 `?ac=videolist&wd=%u` 与站点名称，并预览样本剧名 + 首条真实地址
  - 支持直接**新增**或按名称**修改**，保存到 `config/sites.json` 后前台立即并发调用
- 新增后台操作 `detect_site` / `save_site_one`，实现见 `lib/SiteDetector.php`

### [v1.4.x] 2026-08-25 · 资源站频率控制（搜索 / 心跳 / 轮训）
- 新增 `lib/SiteHealth.php`，避免被资源站频繁/密集调用而屏蔽或禁用：
  - **搜索频率**：限制同一站两次实际调用最短间隔，依赖结果缓存兜底
  - **心跳频率**：周期并发探测各站可达性，连续失败自动禁用、冷却后自动恢复；只请求存活站点
  - **轮训**：定期推进命中顺序（round-robin），可限制每请求并发站数，分散压力
- 后台「设置」新增频率控制配置 + **资源站健康状态**可视化（立即心跳 / 重置）
- **默认即用推荐值**：搜索 15s · 心跳 600s · 超时 5s · 连错 3 次禁用 · 冷却 1800s · 轮训 600s · 并发 4 站

### [v1.3.0] 2026-08-25 · 定时自动采集映射 + 后台帮助
- 新增 `cron/mapping.php` 定时访问功能，周期性自动补全官方链接映射 + 盘点资源站库存
- 前台返回 `code=200` 时自动固化映射（`vid/cid → 剧名+集数`），下次直接命中、不再联网抓取
- 后台新增「帮助」tab：资源站「映射要求」说明 +「定时访问功能」文档

### [v1.2.1] 2026-08-25 · 芒果/优酷实测映射 + 修复
- 修复芒果TV `/b/{cid}/{vid}.html` 未提取 `cid/vid` 导致自动映射不落库
- 新增实测映射：芒果「你是迟来的欢喜」、优酷「师兄太稳健」「以法之名」、哔哩「开心锤锤」

</details>

---

## ✨ 特性总览

| 分类 | 特性 |
| ---- | ---- |
| 🧵 搜索引擎 | **多线程 (curl_multi)** 并发请求全部资源站，耗时≈最慢站点，而非 站点数 × 单站耗时 |
| 🎯 链接解析 | 腾讯 / 爱奇艺 / 优酷 / 芒果TV / 哔哩哔哩 / PPTV，提取 `vid / cid / 集数` |
| 🕷️ 智能抓取 | 无法直接解析时自动访问官方页识别「剧名+集数」（含无效标题校验、移动端回退） |
| 🗺️ 映射表 | `vid/cid → 剧名+集数` 精确映射，命中后**自动固化**、下次免联网 |
| 🧩 模板系统 | `%s / %u / %t / %p` 占位符，兼容 **JSON / JSONP / 纯文本 / 苹果CMS 列表** |
| 🧪 资源站检测 | 粘贴苹果CMS10采集接口即自动探测、生成模板、一键保存 |
| 🧬 特殊调用方法 | 资源站级可配 `GET/POST`、自定义请求头、POST 请求体、返回解析模式（自动/JSON/纯文本/苹果CMS） |
| 🖱️ 自动保存 | 后台取消「保存」按钮，修改后**点击页面空白处自动保存**，并自动清理缓存/健康/日志 |
| 📦 缓存 | 搜索结果文件缓存，降低对资源站的重复请求 |
| 🕰️ 定时采集 | `cron/mapping.php` 自动补全映射 + 盘点资源站库存，省去手动 |
| 🔍 频率控制 | 搜索节流 + 心跳探测 + 轮训分批，防被资源站屏蔽 |
| 🔄 在线更新 | GitHub 多加速镜像自动测速选最快节点拉取，更新后权限 777 |
| 🛠️ 可视化后台 | 资源站 / 映射 / 设置 / 帮助 / 一键测试，全部免改代码 |
| 🔌 开放接口 | `key` 鉴权 + `callback` JSONP + CORS，方便前端跨域调用 |
| 🎬 App 接口 | 🆕 TVBox / 影视 APP / 小程序 统一调用入口（player/api.php，4 种模式） |
| 🔑 App Key | 🆕 key 放 URL 最前面 `?key=xxx&url=...`，完整鉴权链路 |
| 🧬 搜索模板库 | 🆕 预置 8 种苹果CMS10 搜索模板，选框架填 host 自动生成 |
| 🎨 紫色 UI | 🆕 紫色渐变侧边栏 + CSS 变量统一（消除漂移常量） |
| 🎥 播放器 | 🆕 lgzym3u8 / vv00.xyz iframe 内嵌播放 |

---

## 📸 界面预览

<details>
<summary><strong>点击展开 · Demo 预览</strong></summary>

### 🛠️ 后台管理

紫色渐变 UI · 侧边栏 · 资源配置 · App 接口配置

```
┌─────────────────────────────────────────────────────────┐
│  🎬 MXGJ              v1.17.2                    [MX] │
├────────────┬────────────────────────────────────────────┤
│ 📊 概览    │ 🏠 / 资源配置 / 🎬 App接口                 │
│ 🌐 资源站  │ ┌────────────────────────────────────────┐ │
│ 🔍 查看    │ │ 🎬 App 接口配置              ✅ 已启用 │ │
│ 🎬 App接口 │ │ ─────────────────────────────────────  │ │
│ 🗂️ 映射表 │ │ 基础开关                                │ │
│            │ │ ✅ 启用  ✅ 鉴权  ✅ 代理                │ │
│ ⬆️ 更新    │ │ 接口配置                                │ │
│ 📋 日志    │ │ key: moxi123 · 播放器: lgzym3u8        │ │
│ ⚙️ 设置    │ │ 📡 接口路径 · 🧪 一键测试              │ │
│ ❓ 帮助    │ └────────────────────────────────────────┘ │
└────────────┴────────────────────────────────────────────┘
```

### 🔧 App 接口 5 种调用路径

```
🎬 播放器页面（m3u8直链）     /player/?key=xxx&url=<m3u8>
🎬 播放器页面（加密 u 参数）   /player/?key=xxx&u=<base64url>
🔧 TVBox API（媒体代理）       /player/api.php?key=xxx&url=<m3u8>
🔧 TVBox API（官方解析）       /player/api.php?key=xxx&url=<腾讯链接>
🔧 TVBox API（苹果CMS透传）    /player/api.php?key=xxx&url=<苹果CMSAPI>
```

</details>

---

## 🚀 快速开始

```bash
cd mxgj
php -S 0.0.0.0:8080 -t .
```

- 🔧 前台接口：`http://你的域名/index.php`
- 🛠️ 后台管理：`http://你的域名/admin.php`（默认密码 `moxi123`，**登录后请立即修改**）

> 生产环境建议使用 Nginx / Apache + PHP-FPM 部署，同样兼容。

---

## 🧭 工作原理

```text
用户输入官方链接 (腾讯/爱奇艺/优酷/芒果/哔哩/PPTV)
      │
      ▼
 ① LinkParser 解析 → platform / vid / cid / 集数
      │
      ▼
 ② 命中本地映射表 (mapping.json) → 剧名 + 集数？
      │ 未命中
      ▼  否
 ③ PageResolver 自动抓取官方页 → 识别「剧名 + 集数」并自动固化映射
      │
      ▼
 ④ SiteHealth 过滤（心跳存活 + 节流 + 轮训）→ 选出该次要请求的资源站
      │
      ▼
 ⑤ SiteSearcher 多线程并发请求全部存活资源站（同时段）
      │
      ▼
 ⑥ 按集数从 苹果CMS vod_play_url 提取 m3u8，择优（分辨率高者优先）
      │
      ▼
 ⑦ JSON 输出  { "code":200, "url":"...m3u8", ... }
```

---

## 📖 使用教程

### ① 配置资源站

后台 → **资源站**：

- **推荐 · 一键检测自动添加（苹果CMS10）**
  点「⚡ 检测并自动添加」→ 弹窗内只粘贴**采集接口地址**
  （如 `https://caiji.xxx.com/api.php/provide/vod/`）→ 点「检测」，系统自动：
  1. 校验接口可达、识别是否为苹果CMS列表（`list[]` 含可播放 http 地址），证明能取到**真实资源**；
  2. 自动生成搜索模板 `?ac=videolist&wd=%u` 与站点名称，并预览样本剧名 + 首条真实地址；
  3. 确认后自动**新增**或按名称**修改**，保存到 `config/sites.json`，前台立即并发调用。
- 每行「编辑」打开同一弹窗预填，便于修正后重新检测/保存。

**模板占位符**：

| 占位符 | 含义             | 示例                         |
| ------ | ---------------- | ---------------------------- |
| `%s`   | 剧名（不转码）   | `庆余年`                     |
| `%u`   | 剧名（URL 编码） | `%E5%BA%86%E4%BD%99%E5%B9%B4` |
| `%t`   | 剧名（同 `%s`）  | `庆余年`                     |
| `%p`   | 集数             | `2`                          |

示例（苹果 CMS10 类）：`https://your-cms.com/api.php/provide/vod/?ac=videolist&wd=%u`

**特殊调用方法**（针对不能用普通 GET 搜索的「特殊资源站」）：

后台「资源站」表每行「特殊调用方法」列可个性化配置该站如何被调用：

| 配置 | 说明 |
| ---- | ---- |
| 调用方法 | `GET`（默认）/ `POST`（特殊：以 POST 提交搜索请求） |
| 自定义Header | 每行 `Key: Value`，发送时附带（如 `Cookie`、`Authorization`、`X-Token`） |
| POST体 | 含占位符 `%u/%s/%p` 的请求体模板；`method=POST` 且留空时默认 `wd=%u&ep=%p` |
| 返回解析 | `自动`（默认）/ `JSON` / `纯文本` / `苹果CMS`，强制指定解析方式防误判 |

例如某站要求 `POST https://special-cms.com/api/search`、请求体 `key=%u&page=%p`、需带 Token：

```json
{
  "name": "特殊资源站",
  "template": "https://special-cms.com/api/search",
  "method": "post",
  "headers": "X-Api-Token: abc123",
  "post": "key=%u&page=%p"
}
```

> 旧配置不带这些字段时，全部自动走默认 `GET` + `自动解析`，行为与之前完全一致。

### ② 配置官方链接映射

后台 → **映射表**，官方ID格式：`vid:视频ID` 或 `cid:剧集ID`。

> 💡 多数情况**无需手动添加**：链接无法解析时系统会自动抓取官方页识别并**自动写入映射表**。

支持平台与链接示例：

| 平台 | 链接格式 |
| ---- | -------- |
| 腾讯视频 | `.../play?cid=mzc00200zx8psx0&vid=k4102szvyce`（cid/vid 双 ID） |
| 爱奇艺 | `https://www.iqiyi.com/v_19hly1wd1gg.html`（`v_` ID） |
| 优酷 | `https://m.youku.com/alipay_video/id_fcad042e84ef43ce8309.html`（`id_` ID） |
| 芒果TV | `https://m.mgtv.com/b/731684/24269945.html`（cid=731684，vid=24269945） |
| 哔哩哔哩 | `https://www.bilibili.com/bangumi/play/ep431046`（`av`/`BV`/`ep`） |

例如腾讯 `.../cid=mzc00200zx8psx0&vid=k4102szvyce` → **庆余年第2集**

```json
{
  "title": {},
  "cid": { "mzc00200zx8psx0": "庆余年" },
  "episode": { "vid:k4102szvyce": { "name": "庆余年", "episode": 2 } }
}
```

### ③ 调用前台 API

```text
GET /index.php?key=<密钥>&url=<官方视频链接>[&page=<集数>][&debug=1][&callback=<jsonp>]
```

> `key` 固定放在 `url` 之前（第一个 query 参数位置），未开启 key 鉴权时可省略。

**示例**（腾讯 → 庆余年第2集）：

```
/index.php?key=YOUR_KEY&url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce
```

**返回**（直接返回资源站的真实 m3u8 地址）：

```json
{
  "code": 200,
  "url": "https://cdn.example.com/play/2/index.m3u8",
  "msg": "https://cdn.example.com/play/2/index.m3u8",
  "time": 1206.6,
  "KFZ": "沫兮官替系统"
}
```

> 打开 `url` 即为真实播放地址，浏览器/APP 均可直接播放。

**状态码约定**：

| code | 说明 |
| ---- | ---- |
| `200` | 成功，`url` 为资源站 m3u8 地址 |
| `400` | 缺少 `url` 参数或链接非法 |
| `403` | 访问密钥错误（开启 key 鉴权时） |
| `404` | 资源站未匹配到该剧对应集数 |
| `501` | 后台未配置任何资源站 |
| `502` | 无法识别链接对应的剧名（需配置映射） |
| `503` | 资源站 URL 模板无法生成 |

---

## 🧰 高级能力

### 🤖 一键测试 · AI 智能分析

后台「概览」页一键测试：解析 →（必要时）抓取官方页识别 → **自动写映射** → 并发去资源站搜索 → 返回播放地址。
后台反馈 `ai_mode`（解析/页面抓取）与 `auto_mapped`（是否自动建映射）。

### 🕰️ 定时自动采集映射（cron）

`cron/mapping.php` 按周期自动做两件事，省去手动：

1. **官方种子链接补全**：遍历 `settings.json → cron.seed_links`，对未映射的官方链接自动抓取并写映射（已存在跳过、不覆盖人工配置）
2. **资源站库存盘点**：并发拉资源站最新列表，把「在库剧名+集数」写入 `mapping.json → stock`

**触发方式（任选其一）**：

```bash
# 手动/浏览器预览（不写库）
php cron/mapping.php key=你的定时密钥 dry=1
# 或
curl -s "http://你的域名/cron/mapping.php?key=你的定时密钥&dry=1"

# Linux crontab（每小时一次，quiet 抑制明细）
0 * * * *  php /你的绝对路径/cron/mapping.php key=你的定时密钥 quiet
```

运行记录追加到 `data/cron_mapping.log`（最多保留 200 条）。

### 🔍 资源站调用频率控制（搜索 / 心跳 / 轮训）

后台 **设置** 页可配置三项策略，降低调用频率、防被屏蔽：

| 策略 | 关键配置项 | 作用 |
| ---- | ---------- | ---- |
| 搜索频率 | `search_interval` | 同一站两次实际调用最短间隔，限制单站 QPS |
| 心跳频率 | `heartbeat_interval` / `heartbeat_max_fail` / `cooldown_seconds` | 周期探测可达性，连续失败自动禁用、冷却后自动恢复 |
| 轮训 | `rotation_interval` / `max_sites_per_request` | 定期推进命中顺序，分散请求压力 |

**推荐配置**：

```
search_interval       = 15    (秒，同一站约每 15 秒最多 1 次)
heartbeat_interval    = 600   (秒，每 10 分钟探测一次)
heartbeat_timeout     = 5     (秒)
heartbeat_max_fail    = 3     (连续失败 3 次自动禁用)
cooldown_seconds      = 1800  (秒，30 分钟后自动恢复重试)
rotation_interval     = 600   (秒，每 10 分钟切换命中顺序)
max_sites_per_request = 4     (每次最多并发请求 4 个站)
```

运行状态存于 `data/site_health.json`，后台 **设置 → 资源站健康状态** 可实时查看并「立即心跳探测 / 重置」。

### 🖱️ 后台「自动保存 + 自动清理」（取代手动保存）

后台 **设置 / 资源站 / 映射表** 已取消「保存」按钮：

- **点击页面空白处自动保存**：修改任一表单后，点击页面空白即自动提交并保存对应配置，无需手动点按钮
- **保存时自动清理运行时数据**：保存成功后自动清空搜索缓存、站点健康状态、日志等，让新配置立即可见生效
- Web 环境下 PHP 每次请求都会重载代码，**无需真正重启进程**

> 快捷开关（如启用心跳/输出字段）仍为点击即生效，不会与自动保存重复提交。

### 🎬 App 接口（player/api.php · TVBox / 影视 APP 统一入口）

后台 **资源配置 → 🎬 App接口** tab 完整配置面板，支持：

| 配置 | 说明 |
| ---- | ---- |
| enable | App 接口总开关（关闭返回 403） |
| require_key | 强制 key 鉴权（key 放 URL 最前面 `?key=xxx&url=...`） |
| api_key | 自定义 key（留空 fallback 到 config/key.php） |
| player_type | 播放器类型（lgzym3u8/虾米FLV/云播） |
| proxy_enable | 代理转发（m3u8 切片走本站 play.php）或 302 跳转 |
| cors | CORS 跨域来源（`*`=全部） |
| max_size_mb | 最大代理大小（MB） |
| rate_limit | 单 IP 限流（次/分钟，0=不限） |

**4 种调用模式**（`/player/api.php` 自动路由）：

| 模式 | 输入 | 返回 |
| ---- | ---- | ---- |
| m3u8 代理重写 | `?key=xxx&url=<m3u8/mp4>` | 本站代理，切片/密钥重写走 play.php |
| 苹果CMS 透传 | `?key=xxx&url=<苹果CMSAPI>` | 原始 JSON 直接返回 |
| 完整解析流程 | `?key=xxx&url=<腾讯/爱奇艺链接>` | LinkParser→映射→多资源站搜索 |
| JSONP | `&callback=xxx` | 任意模式 + JSONP 包装 |

**5 种 APP 调用路径预览**（后台实时显示 + 一键复制）：

```
🎬 /player/?key=xxx&url=<m3u8>        播放器页面
🎬 /player/?key=xxx&u=<base64url>      加密 u 参数
🔧 /player/api.php?key=xxx&url=<m3u8>  TVBox 媒体代理
🔧 /player/api.php?key=xxx&url=<官方链接>  完整解析
🔧 /player/api.php?key=xxx&url=<苹果CMSAPI>  苹果CMS 透传
```

### 🎥 播放器（lgzym3u8 / vv00.xyz iframe）

`/player/?url=<地址>` 或 `/player/?u=<base64url加密地址>`：
- lgzym3u8：内置 MacPlayer 运行时（蓝光资源）
- HTML 播放页自动降级为 `https://vv00.xyz?url=<真实地址>` iframe

### 🧬 搜索接口模板库

后台 **资源站** 页「📋 从模板生成」按钮，弹窗式 UI：
- 8 个预置苹果CMS10 模板（前端搜索页/API JSON/ajax/data/伪静态/ac=list/老版本/帝国CMS/织梦/自定义）
- 选框架 → 填 host → 实时预览生成 URL → 点「生成并打开检测」→ SiteDetector 自动探测 → 一键保存

### 🔄 在线自动更新

后台「更新」页一键更新到 GitHub 最新代码：

- 自动在多个 **GitHub 加速镜像**间测速、选最快节点下载
- 更新前删除当前代码文件，**保留 `config/` 与 `data/`**；更新后权限统一 **0777**

**独立升级入口**（自动更新异常时兜底）：

```
http://你的域名/update.php?key=升级密钥
http://你的域名/update.php?key=升级密钥&dry=1   # 仅测速排查
```

升级密钥默认等于后台管理密码，可在「设置」页单独配置。

---

## 🐳 Docker 一键部署

不想装 PHP？用 Docker：

```bash
# 方式 A: docker-compose（推荐）
git clone https://github.com/ssmhdssmhd/MXGJ.git
cd MXGJ
docker-compose up -d
# 打开 http://localhost:8080/admin.php （默认密码 moxi123）

# 方式 B: 直接 docker run
docker run -d --name mxgj -p 8080:8080 -v $(pwd)/data:/var/www/html/data -v $(pwd)/config:/var/www/html/config php:8.2-fpm-alpine php -S 0.0.0.0:8080 -t /var/www/html
```

## 📈 GitHub Stats

<p align="center">
  <a href="https://github.com/ssmhdssmhd/MXGJ"><img height="280" src="https://github-readme-stats.vercel.app/api?username=ssmhdssmhd&repo=MXGJ&show_icons=true&theme=tokyonight&hide_border=true" alt="Stats"></a>
  <a href="https://github.com/ssmhdssmhd/MXGJ"><img height="280" src="https://github-readme-stats.vercel.app/api/top-langs/?username=ssmhdssmhd&repo=MXGJ&layout=compact&theme=tokyonight&hide_border=true&hide=HTML,CSS,JavaScript" alt="Languages"></a>
</p>

<p align="center">
  <img src="https://star-history.com/#ssmhdssmhd/MXGJ&Date" alt="Star History">
</p>

<p align="center">
  <a href="https://github.com/ssmhdssmhd/MXGJ/graphs/contributors">
    <img src="https://contrib.rocks/image?repo=ssmhdssmhd/MXGJ&max=20" alt="Contributors">
  </a>
</p>

## ⚙️ 配置说明

### config/settings.json

| 字段 | 默认 | 说明 |
| ---- | ---- | ---- |
| `admin_password` | `moxi123` | 后台登录密码 |
| `timeout` | `15` | 单个资源站请求超时（秒） |
| `cache_ttl` | `600` | 搜索缓存时长（秒，`0`=关闭） |
| `replace_domain` | `""` | 域名替换/中转前缀，留空则直接返回资源站地址 |
| `cron` | `{...}` | 定时采集配置（`seed_links`、盘点开关等） |
| `site_control` | `{...}` | 频率控制配置（搜索/心跳/轮训） |
| `app_api` | `{...}` | 🆕 App 接口配置（enable/require_key/api_key/player_type/proxy_enable/cors/max_size_mb/rate_limit） |
| `output` | `{...}` | 输出返回设置（字段映射，默认 `code`/`msg`(=url)/`url`/`time`/`KFZ`） |

`replace_domain` 示例：配置 `https://your-proxy.com/m3u8/` 后，
返回的 `url` 自动变为 `https://your-proxy.com/m3u8/play/2/index.m3u8`（去掉资源站原域名）。

### config/key.php

可选的前台访问鉴权：返回空字符串关闭；改为密钥字符串后开启：

```php
return 'your_secret_key';
```

开启后调用需携带 `&key=your_secret_key`（放在 `url` 之前），否则返回 `code=403`。

---

## 📁 目录结构

```text
mxgj/
├── index.php               # 前台解析 API（入口）
├── admin.php               # 后台管理（登录后可配置）
├── play.php                # 独立播放入口（代理转发，m3u8 切片走本站）
├── player/
│   ├── index.php           # 播放器页面（lgzym3u8 / 蓝光资源 MacPlayer / vv00.xyz iframe）
│   └── api.php             # 🆕 APP/TVBox 统一调用入口（4 种模式：m3u8代理重写/苹果CMS透传/完整解析/JSONP）
├── update.php              # 独立升级入口（update.php?key=升级密钥）
├── cron/
│   └── mapping.php         # 定时自动采集映射（可配 crontab）
├── lib/
│   ├── bootstrap.php       # 公共引导 / JSON 读写 / 设置加载 / 自动写映射
│   ├── LinkParser.php      # 官方链接解析（平台 / vid / cid / 集数）
│   ├── PageResolver.php    # 官方页面抓取（AI 识别剧名+集数）
│   ├── SiteSearcher.php    # 多线程资源站搜索（curl_multi + 苹果CMS）
│   ├── SiteHealth.php      # 资源站频率控制（节流/心跳/轮训）
│   ├── SiteDetector.php    # 苹果CMS10 采集接口检测 + 模板构建
│   ├── Updater.php         # GitHub 多加速镜像在线更新
│   └── Cache.php           # 文件缓存
├── config/
│   ├── settings.json       # 全局设置
│   ├── sites.json          # 资源站列表
│   ├── mapping.json        # 剧名 / cid / vid 映射表
│   └── key.php             # 前台访问密钥（可选）
├── data/
│   └── cache/              # 搜索缓存（自动生成）
└── tests/
    ├── run_test.php        # 端到端自测脚本
    └── mock_site.php       # 模拟资源站（自测用）
```

---

## 🧪 自测

内置端到端自测脚本（自动启动模拟资源站并验证多线程并发）：

```bash
php tests/run_test.php
```

预期输出（全部 PASS）：

```text
【主流程】腾讯链接 → 庆余年第2集（直接返回真实地址）
  请求耗时: 1.21s (阈值 2.9s)      ← 多站各延迟1.2s，串行约4.8s，证明多线程并发
  [PASS] 返回 code=200
  [PASS] url 直接返回真实 1080p 播放地址
  [PASS] 特殊资源站(POST调用方法)收到 wd=%u&ep=%p 请求体
  ...
结果: 9 通过 / 0 失败
```

---

## 📈 版本与更新

- 更新日志：[CHANGELOG.md](CHANGELOG.md)
- 版本信息：[version.json](version.json)

---

## ❤️ 致谢 & 赞助

本项目由社区驱动开发。如果你觉得有用，欢迎 **Star** 或 **Fork**，也可以：

[![Star](https://img.shields.io/github/stars/ssmhdssmhd/MXGJ?style=social)](https://github.com/ssmhdssmhd/MXGJ)
[![Fork](https://img.shields.io/github/forks/ssmhdssmhd/MXGJ?style=social)](https://github.com/ssmhdssmhd/MXGJ)
[![Watch](https://img.shields.io/github/watchers/ssmhdssmhd/MXGJ?style=social)](https://github.com/ssmhdssmhd/MXGJ)

## 📜 免责声明

本项目仅用于**学习与接口调用演示**。请遵守国家法律法规及各大视频平台/资源站的服务条款，
**切勿**用于盗版、侵权内容的传播。因使用本项目产生的任何法律风险由使用者自行承担。
---

<div align="center">

**MXGJ · 沫兮官替系统**

[![PHP](https://img.shields.io/badge/PHP-8892BF?logo=php&logoColor=white)](https://www.php.net)
[![License](https://img.shields.io/badge/License-MIT-22a06b)](LICENSE)
[![Version](https://img.shields.io/badge/Version-v1.17.2-4f7cff)](https://github.com/ssmhdssmhd/MXGJ/releases)

🚀 [快速开始](#-快速开始) · 📖 [README](README.md) · 📋 [CHANGELOG](CHANGELOG.md) · 🤝 [贡献指南](CONTRIBUTING.md) · 🐛 [安全报告](SECURITY.md)

Made with ❤️ by [MXGJ Team](https://github.com/ssmhdssmhd/MXGJ) · [Issue](https://github.com/ssmhdssmhd/MXGJ/issues) · [Discussions](https://github.com/ssmhdssmhd/MXGJ/discussions)

</div>
