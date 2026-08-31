# 更新日志 (CHANGELOG)

## [v1.17.9] 2026-08-31 · 📦 标准化 JSON 响应（code/msg/data/meta 四段式）

### 背景与思路
原来的 JSON 返回是扁平结构，字段可配置但缺乏统一的元信息（版本号、请求 ID、耗时等），
网页 / APP / 小程序等外部调用方难以做统一的错误分发和兼容性判断。

本次改造引入 **标准四段式响应**，同时保留 **legacy 旧版兼容模式** 一键切换，
所有错误分支（鉴权失败、参数错误、链接无法识别等）也统一走标准格式，不再出现部分接口返回扁平、部分返回嵌套的混乱情况。

### 改动文件
| 文件 | 改动 |
|------|------|
| `lib/bootstrap.php` | 版本号 1.17.8→1.17.9；新增 `mxgj_build_standard_response()` 四段式构建；新增 `mxgj_code_message()` 错误码→描述映射；新增 `mxgj_request_id()` 请求唯一 ID；新增 `mxgj_early_response()` 错误快速返回；`mxgj_build_output()` 按 `output.mode` 分派 standard/legacy；`mxgj_settings()` output 默认值新增 `mode`/`show_meta`；fMap 字段扩展到 platform/vid/cid/cached |
| `index.php` | 错误路径全部改用 `mxgj_early_response()`；`$vars` 增加 platform/vid/cid/cached/params；修复 `$cachedIsHit` 未定义 BUG（统一为 `$cachedHit`） |
| `admin.php` | 保存设置新增 `mode`/`show_meta`；输出设置 UI 新增格式下拉、meta 开关、standard 响应示例、扩展值来源 datalist |
| `config/settings.json` | 新增 `output.mode: standard` + `output.show_meta: true` |
| `version.json` | 版本号 1.17.8→1.17.9；features 新增标准四段式、API 自描述、错误码规范化 |

### 标准响应格式
```json
// 成功
{
  "code": 200,
  "msg": "success",
  "data": {
    "url": "https://.../xxx.m3u8",
    "player_url": "http://host/player/?url=...",
    "raw_url": "https://...raw.m3u8",
    "title": "庆余年",
    "episode": 2,
    "platform": "腾讯视频",
    "site": "A站",
    "is_special": false,
    "site_special": false,
    "from_fallback": false,
    "from_pool": "primary"
  },
  "meta": {
    "api_version": "1.17.9",
    "service": "沫兮官替系统",
    "mode": "standard",
    "request_id": "ed5b98779c979a54",
    "elapsed_ms": 85.2,
    "timestamp": 1756000000,
    "cached": false,
    "platform": "腾讯视频",
    "vid": "k4102szvyce",
    "cid": "mzc00200zx8psx0",
    "params": { "page": 1, "debug": 0 }
  }
}

// 失败（data 为 null）
{ "code": 400, "msg": "缺少 url 参数", "data": null, "meta": { ... } }
```

### 错误码规范
| code | 含义 |
|------|------|
| 200  | 成功 |
| 400  | 请求参数错误（缺少 url / url 格式错） |
| 403  | 访问被拒绝（key 无效） |
| 404  | 资源未找到 |
| 501  | 功能未实现（未配置任何资源站） |
| 502  | 无法识别链接对应的剧名 |
| 503  | 所有资源站暂不可用 |

### 兼容性 ✅
- **legacy 模式**：后台「输出格式」下拉切到 legacy，即可恢复 v1.17.8 及以前的扁平结构
- **旧 settings.json**：没有 mode/show_meta 字段时，bootstrap 默认值兜底
- **所有错误分支统一格式**：包括 key 校验失败、url 校验失败、映射失败等提前返回场景

### 使用示例
```bash
# 标准格式（默认）
curl "http://114.134.184.91:9007/?url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce"

# legacy 格式（后台切换后生效）
# 返回 { code, msg, url, title, time, ... } 扁平结构

# JSONP 跨域调用不受影响
curl "http://114.134.184.91:9007/?url=...&callback=handleResponse"
```

---

## [v1.17.5] 2026-08-29 · 🔄 资源平替 / 资源站互换（主池失败自动降级）

### 背景与思路
当用户的主资源站无法播放（接口返回空 / HTTP 错误 / 被风控限流）时，
系统自动去「平替资源站」里再搜一次作为兜底返回。

关键设计：
- **平替是独立开关**：平时完全不影响原系统的性能与逻辑
- **主用池 ≠ 平替池**：资源站配置里每个站多了一个 `role` 字段
  - `primary`（默认）= 主用，正常参与搜索
  - `fallback` = 平替，仅在主用池全部未命中时才参与
- **两级缓存**：主池命中 / 平替命中 共享同一条缓存
- **自动回主池重试**（`step2_retry_main`）：平替命中后，
  下次请求会再次尝试主池，避免主池只是临时故障却一直用平替

### 改动文件
| 文件 | 改动 |
|------|------|
| `lib/bootstrap.php` | 版本号 1.17.4→1.17.5；settings 默认值新增 `fallback` 节；新增 `mxgj_sites_by_role()` 辅助函数；`mxgj_sites()` 自动补全 role 字段；`mxgj_build_output` fMap 注册 `from_fallback`/`from_pool` |
| `lib/SiteSearcher.php` | 新增 `searchWithFallback()` 统一调度入口：主池 → 平替池 两级搜索，返回值带 `from_fallback`/`from_pool` 标记 |
| `index.php` | 改用 `searchWithFallback()`；缓存支持平替命中后的自动回主池重试 |
| `admin.php` | 侧边栏新增「资源平替」tab；`save_fallback` 保存接口；`renderFallbackForm` 全新 UI；资源站配置表格 + 新增动态行均增加「角色」下拉；`save_sites`/`save_site_one` 后端接口支持 role 字段 |
| `config/settings.json` | 新增 `fallback: {enable:false, step2_retry_main:true}` 节（默认关闭） |
| `config/sites.json` | 向后兼容（role 字段自动补 primary） |
| `version.json` | 版本号 1.17.4→1.17.5 |

### API 返回变化
新增两个感知字段：
```json
{
  "from_fallback": true,    // true=本次由平替池命中
  "from_pool": "fallback"   // primary=主用池 / fallback=平替池
}
```

### 使用步骤
1. 进入「资源站配置」→ 给每个站设置角色（主用 / 平替）
2. 进入「资源平替」→ 开启「启用资源平替」开关 → 保存
3. 平时请求只跑主用池，速度与之前完全一样
4. 主用池全部未命中 → 自动去平替池再搜一次

### 向后兼容性 ✅
- 旧 sites.json 没有 role 字段时，`mxgj_sites()` 自动补 primary
- 旧 settings.json 没有 fallback 节时，bootstrap 默认值兜底（enable=false，相当于不启用平替）
- 不开平替开关时，searchWithFallback 与 search 行为完全一致，无额外网络开销



## [v1.17.4] 2026-08-29 · 🐛 修复后台「资源站查看」选择站点后不加载的问题
## [v1.17.4] 2026-08-29 · 🐛 修复后台「资源站查看」选择站点后不加载的问题

### 问题描述
后台 → 资源站查看 → 选择资源站后页面无任何反应，Console 报 `SyntaxError: Invalid or unexpected token`，导致 `svSearch`、`svRenderResult`、`svOpenDetail` 等所有前端函数未定义。

### 根因
`admin.php` 第 1980 行附近，PHP heredoc/echo 模板输出的 JavaScript 单引号字符串内直接包含了**多行真实换行符**（`svOpenDetail` 函数构建播放源集数 `<div class="sv-ep">` 的 body 拼接语句跨了 5 行）。PHP 输出时换行符原样进入 JS 字符串，浏览器 JS 解析器看到单引号内有未转义的字面换行 → 立即抛出 SyntaxError → 整个 `<script>` 块中断 → 后续所有函数定义失效。

### 修复方案
将 `admin.php:1979-1984` 的多行字符串拼接（单引号内直接换行）改为**单行 `+` 连接的独立字符串常量**：

```javascript
// 修复前：单引号内包含真实换行 → SyntaxError
body += '<div class="sv-ep" data-url="...">'
                    '<span class="ep-name"...'
                    '<span class="ep-name"...'
                    '<button class="ep-copy"...'
                    '<button class="ep-play"...';

// 修复后：每行一个完整的 '...' 常量 + 连接符
body += '<div class="sv-ep" data-url="'+svEscape(ep.url.replace(/"/g,'&quot;'))+'">'
    + '<span class="ep-name" onclick="svPlayFromEl(this.parentElement)">▶</span>'
    + '<span class="ep-name" style="flex:1;padding-left:4px">'+svEscape(ep.name)+'</span>'
    + '<button class="ep-copy" onclick="svCopy(this.parentElement.dataset.url)" title="复制链接">📋</button>'
    + '<button class="ep-play" onclick="svPlayFromEl(this.parentElement)" title="播放">▶</button></div>';
```

### 端到端回归测试 ✅
- JS 语法验证：`node --check` 通过，0 错误
- 浏览器 Console：0 error / 0 warning（仅调试 log）
- 选择西瓜资源站 → 自动加载 20 条最新资源 ✅
- 输入"斗罗大陆"搜索 → 返回 13 条匹配结果 ✅
- 点击详情弹窗 → 正常显示 2 个播放源 + 168 集列表 + 播放/复制按钮 ✅
- 切换蓝光资源站 → 同样流程正常 ✅

## [v1.17.2] 2026-08-28 · App 接口 key 参数前置 + 完整 key 鉴权

### 核心改动

- **key 参数放到 URL 最前面**（第一个 query 参数位置）：`?key=xxx&url=...` 而不是 `?url=xxx&key=xxx`
- **`player/api.php` 补齐完整 key 鉴权**（之前注释写了 key 但代码**完全没实现**）：
  - App 接口 enable 开关（关闭返回 403）
  - require_key 强制鉴权开关
  - api_key 自定义（留空 fallback 到 config/key.php）
  - 错误 key / 缺少 key → 返回 `{"code":403,"msg":"访问被拒绝：缺少或无效的 key 参数"}`

### Bug 修复

- 🐛 `mxgj_settings('app_api')` 参数被 PHP 静默忽略（函数签名 `: array` 不接受参数）→ 改为 `mxgj_settings()['app_api'] ?? []`
- admin.php URL 预览：之前用 PHP 三元表达式 `$item[1] . ($item[1]=='?'?'':'&key=xxx')` 拼到末尾 → 现在用 `preg_replace('/\?/', '?key=xxx&', $item[1], 1)` 放到最前面

### 端到端验证

- require_key=false：不带 key 正常访问 ✅
- require_key=true + 正确 key 前置：正常访问 ✅
- require_key=true + 不带 key：HTTP 403 ✅
- require_key=true + 错误 key：HTTP 403 ✅
- player/api.php 苹果CMS透传模式 code=1, list=20 ✅
- admin.php URL 预览：11 处 `?key=` 前置，0 处 `&key=` 末尾 ✅

## [v1.17.1] 2026-08-28 · 新增 🎬 App 接口配置 tab（侧边栏 + 完整 UI）

### 新增

- **资源配置组新增「🎬 App接口」** nav-item（sidebar 硬编码 `<a>`，在 sites_view 和 mapping 之间）
- **完整配置面板**（后台 → 资源配置 → 🎬 App接口）：
  - 基础开关：enable / require_key / proxy_enable
  - 接口配置：api_key / player_type（lgzym3u8/虾米FLV/云播）/ cors / max_size_mb / rate_limit
- **📡 实时接口路径预览**：5 种 APP/TVBox 调用格式（播放器页面 / 加密 u / TVBox API 代理 / 官方解析 / 苹果CMS 透传）+ 一键复制
- **🧪 一键测试**：输入任意 URL 直接调 `/player/api.php` 显示 JSON 结果（HTTP code + 耗时 + 响应）
- **响应格式示例**：苹果CMS 标准 JSON

### 后端改动

- bootstrap.php settings 默认值加完整 `app_api` 配置块
- save_settings 加 `$st['app_api']` 读写（8 字段 + 安全校验：max/min 边界 + trim）
- tabLabels 加 `'app_api' => ['label'=>'App接口','icon'=>'🎬','crumb'=>'资源配置']`
- tab switch case 加 `case 'app_api': renderAppApiForm($settings); break;`

### 新增文件

- player/api.php（v1.17.0 已创建，v1.17.1 补齐 key 鉴权见 v1.17.2）

### 侧边栏 nav 结构（确认）

之前误以为 nav-item 是 PHP 循环遍历 `$tabLabels` 生成的，实际上是**硬编码 HTML `<a>` 标签**：

```html
<div class="nav-group">
  <div class="nav-group-title">资源配置</div>
  <a class="nav-item" href="?tab=sites">...</a>          <!-- 🌐 资源站配置 -->
  <a class="nav-item" href="?tab=sites_view">...</a>      <!-- 🔍 资源站查看 -->
  <a class="nav-item" href="?tab=app_api">...</a>        <!-- 🎬 App接口 (新增) -->
  <a class="nav-item" href="?tab=mapping">...</a>         <!-- 🗂️ 映射表 -->
</div>
```

## [v1.17.0] 2026-08-27 · 搜索接口模板库（v1 首版）+ 修复复制/封面图/播放按钮 + player/api.php

## [v1.17.0] 2026-08-27 · 搜索接口模板库（v1 首版）

### 背景

不同资源站的搜索接口路径/参数都不一样：
- 苹果CMS10 前端 HTML: `/index.php/vod/search.html?wd=`
- 苹果CMS10 API JSON: `/api.php/provide/vod/?ac=videolist&wd=`
- 苹果CMS10 ajax/data: `/index.php/ajax/data?mid=1&wd=`
- 伪静态重写: `/search/{keyword}.html`
- 老版本苹果CMS8/9: `/index.php?m=vod-search-wd-xxx.html`
- 甚至帝国CMS、织梦 ...

之前用户必须自己手拼 URL，现在：**选模板 → 填 host → 自动生成**。

### 新增

- **搜索接口模板库**（`config/search_templates.json`）—— v1 预置 8 个最常用模板：
  ① 苹果CMS10 · 前端搜索页 (推荐) → `/index.php/vod/search.html?wd={kw}`
  ② 苹果CMS10 · API JSON 搜索 → `/api.php/provide/vod/?ac=videolist&wd={kw}`
  ③ 苹果CMS10 · ajax/data 接口 → `/index.php/ajax/data?mid=1&wd={kw}`
  ④ 苹果CMS10 · 伪静态 → `/search/{kw}.html`
  ⑤ 苹果CMS10 · ac=list → `/api.php/provide/vod/?ac=list&wd={kw}`
  ⑥ 苹果CMS8/9 · 老版本 → `/index.php?m=vod-search-wd-{kw}.html`
  ⑦ 帝国CMS / 织梦 → `/search.php?keyword={kw}`
  ⑧ 自定义（手动填）

- **bootstrap.php 新增 3 个函数**：
  - `mxgj_search_templates()` — 返回预置模板 + 用户自定义（自动从 `search_templates_user.json` 合并）
  - `mxgj_render_search_template(template_id, host)` — 模板 → 搜索 URL（含 %u 占位符）
  - `mxgj_guess_search_template(raw_url)` — 从裸 URL 反推匹配哪个模板 + 提取 host（模糊匹配路径关键片段）

- **admin.php 后端 4 个新 action**：
  - `get_templates` — 返回所有模板列表
  - `save_templates` — 保存用户自定义模板
  - `render_from_template` — 模板 + host → 生成搜索 URL（JSON）
  - `guess_template` — 裸 URL → 反推模板 + host（用于粘贴 URL 时自动识别）

- **admin.php 前端模板选择器**（资源站页面「📋 从模板生成」按钮）：
  - 弹窗式 UI：框架类型下拉 + 域名输入 + 实时预览生成的模板 URL
  - 8 个模板带 emoji 标记：📦 预置 / ✨ 自定义 + HTML/JSON 类型标签
  - 点「生成并打开检测」→ 自动填到检测弹窗 → 用户一键测试 → 保存
  - 已有的「⚡ 检测并自动添加」按钮保留（支持粘贴任意 URL 自动识别模板）

### 用户体验

**之前**：用户看到一个资源站，得自己想"这是什么框架？搜索路径是啥？wd 还是 keyword？" → 手动拼 URL
**现在**：
  1. 看到资源站 → 打开后台 → 点「📋 从模板生成」
  2. 选「苹果CMS10 · 前端搜索页 (推荐)」→ 填 host（api.wsyzy.net）→ 预览框自动出完整 URL
  3. 点「生成并打开检测」→ SiteDetector 自动探测 → 一键保存 ✅
  
**或者**：直接点「⚡ 检测并自动添加」→ 粘贴任意采集接口地址 → 系统反推模板 + host → 自动填

## [v1.16.9] 2026-08-27 · 完整搜索验证码解锁（端到端验证）

### 核心发现

- WSYZY（api.wsyzy.net）前端搜索走 `/index.php/vod/search.html`，开启了**苹果CMS10 双重拦截**：
  ① 第一次访问 → 系统安全验证（验证码）
  ② 两次请求间隔 < 3 秒 → 频率限制（请不要频繁操作）
- 之前 SiteDetector 一直测 `/api.php/provide/vod/` 接口，WSYZY 在这个接口层面直接返回「暂不支持搜索」，
  其实他们开放了前端 HTML 搜索接口（带验证码），用户浏览器里就能正常搜索。

### 本轮修复

- **SiteDetector::buildTemplate**：智能区分 `api.php`（加 ac=videolist&wd=%u）和 `index.php/vod/search.html`（只加 wd=%u），不再强制改用户 ac 值
- **SiteDetector::needsSearchCaptcha 完全重写**：
  - 第一步就测 basePath（HTML headers，无 XMLHttpRequest），命中验证码信号立刻返回
  - 加频率限制信号「请不要频繁操作」识别（不是验证码）
  - 第二步等 3.5 秒再测关键词（规避苹果CMS默认 3 秒间隔限制）
  - 移除 XMLHttpRequest header：苹果CMS 看到这个 header 会跳过验证码拦截
- **全局代理支持**：
  - bootstrap.php 新增 `mxgj_apply_proxy($ch, $url)` —— 自动读取 HTTP_PROXY/HTTPS_PROXY/NO_PROXY 环境变量
  - SiteDetector::fetchWithHeaders + SiteSearcher 全部 curl 调用自动走代理
  - 本地 sandbox 必须走代理才能访问某些境外服务器（如 WSYZY）
- **captcha_fetch 完整链路验证通过**：admin.php captcha_fetch → needsSearchCaptcha → 检测到「系统安全验证」→ 返回 captcha_img + cookie jar 持久化路径

### 验证码解锁完整链路（端到端）

用户在 admin.php 后台：
1. 输入 WSYZY search.html URL → 点击「⚡ 检测」
2. SiteDetector Phase 1 检测到验证码 → 弹窗显示「🚨 关键词搜索不可用」+「🔓 手动解锁」按钮
3. 点击「🔓 解锁」→ 弹出验证码图片 + 输入框
4. 输入验证码 → 后端 submit 到 `/index.php/ajax/verify_check?type=search&verify={code}`（5 种路径轮询）
5. 验证成功 → cookie jar 持久化到 `data/cookies/{md5(host)}.txt`（Netscape 格式）
6. 后续所有 SiteSearcher 请求自动带 CURLOPT_COOKIEJAR / CURLOPT_COOKIEFILE → 苹果CMS 认为已验证 → 正常返回搜索结果 ✅

## [v1.16.8] 2026-08-27 · buildTemplate 智能保留用户 ac 值

### Bug 修复

- **SiteDetector::buildTemplate**：之前强制把用户给的 ac=list 换成 ac=videolist，现在智能保留用户原始 ac 值（list/videolist），只在用户完全没给 ac 时才默认用 videolist。搜索关键词占位符改用 wd=%u。
- 修复后 WSYZY ac=list 接口在 Phase 1 就被正确识别为「无可播放真实地址」，比之前 Phase 1 通过再 Phase 2 拒更直观。

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。
## [v1.16.7] 2026-08-27 · 搜索验证验证码解锁（Phase 3）

### 🔐 苹果CMS10 搜索验证码解决方案

**核心思路**：苹果CMS 搜索验证 = 先过验证码（一次 15-30 分钟有效），后正常搜索。
我们帮用户手动过一次验证码，Cookie 保存到本地，后续请求自动带上。

### 后端新增
- `SiteSearcher::hostCookieFile()` — 每个资源站 host 独立 cookie jar 文件（`data/cookies/{md5}.txt`）
- `SiteSearcher::readCookieJar()` / `clearCookieJar()` — Cookie 管理
- `SiteSearcher` curl opts 自动注入 CURLOPT_COOKIEJAR / CURLOPT_COOKIEFILE
- `SiteDetector::extractCaptchaUrl()` — 从被拦截响应中提取验证码图片 URL
- `SiteDetector::needsSearchCaptcha()` — Phase 2.5：空列表通但关键词搜被拦截 → 可能是验证码
- admin.php 三个新 action：
  - `captcha_fetch`：获取验证码图片 + 预建 cookie jar
  - `captcha_submit`：提交验证码到苹果CMS verify 接口（5 种路径尝试）+ 自动 cookie jar 持久化
  - `captcha_clear`：手动清除 cookie jar（验证码过期后）

### 前端新增
- SiteDetector 弹窗 Phase 2 不可用时，warn 区增加「🔓 尝试手动解锁搜索验证」按钮
- 点击后弹出验证码图片 + 输入框，输入正确 cookie 自动保存到持久位置
- 解锁成功后自动重新 detectSite()，Phase 2 变绿 ✅

## [v1.16.6] 2026-08-27 · SiteDetector v3 — 超鲁棒多策略关键词搜索探测

**核心升级：解决「真接口被误判为假」和「假接口被误判为真」**

### SiteDetector 三阶段架构
- **Phase 1**：空列表探测（可达 + 苹果CMS格式 + 真实播放地址）
- **Phase 1.5**：会话预热（cookie jar + 浏览器级 headers：UA/Referer/Accept/X-Requested-With）
- **Phase 2**：🔑 智能关键词搜索探测（curl_multi 并发 5 策略 × 快速失败）

### Phase 2 新能力（v3 之前都没有）
- 5 种搜索参数并发尝试：wd → keyword → q → name（兼容不同苹果CMS变体）
- 2 种 ac 端点尝试：ac=videolist → ac=search
- **Diff 比对**：关键词返回的 vod_id 集合 vs 空列表 vod_id 集合（重合度 ≥ 70% → 判定为参数被忽略）
- **风控检测**：识别 Cloudflare challenge / 403 / 429 / HTML 拦截页
- **快速失败**：同一关键词所有策略同时返回「暂不支持搜索」→ 直接 break 换下一个关键词都没意义
- **指数退避重试**：429/5xx 自动重试，300ms→600ms

### 性能
- curl_multi 并发 5 策略（之前串行）
- 单策略超时 5-10s（之前 15s 串行 × 10 次 = 最长 150s）
- WSYZY 假接口：12s → 4.5s
- 西瓜/蓝光真接口：2-4s 即可返回

## [v1.16.5] 2026-08-27 · 资源站检测升级 — Phase 2 关键词搜索探测

**升级 SiteDetector：两阶段检测**
- Phase 1：基础列表探测（ac=videolist 不带 wd）—— 确认接口可达、苹果CMS格式、有真实播放地址
- 🔑 Phase 2（新增）：关键词搜索探测（ac=videolist&wd=斗罗大陆/庆余年）—— 真实验证接口搜索能力
- 关键词搜索探测能识别「暂不支持搜索」/ 非 code=1 / list 为空等假接口特征
- 双关键词交叉验证，避免偶发 false-positive

**前端弹窗改造**
- dd-hint 区拆为 Phase 1（基础）+ Phase 2（关键词搜索）两段展示
- 🚨 关键词搜索不可用时，弹窗显示橙色警告：「此接口不能用于本系统」+ 具体原因
- ✅ 正常接口显示绿色「关键词搜索：正常 ✔」+ 样本结果

**生产配置修复**
- WSYZY 资源站不支持关键词搜索 → 已禁用（仅返回随机列表）
- 西瓜 / 蓝光 资源站均通过 Phase 1+2 → 保持启用

## [v1.16.4] 2026-08-27 · 一键测试双模式 + 资源站直链自动映射

**新增**：
- 一键测试改为 Tab 切换（🎯 官方链接 / 📡 资源站直链）
- 资源站直链 Tab：输入 m3u8 URL → 自动检测剧名/集数 → 手动修正 → 写入 episode 映射表
- 后端新增 `test_direct` action：自动解析 m3u8 URL（中文集数/英文 episode-XX/路径数字）→ 去资源站搜验证 → 写入映射
- 自动检测函数 `admin_parse_direct_url()`：多正则匹配集数 + 智能路径段提取剧名

**优化**：
- m3u8 测试带 debounce（400ms 自动触发检测）
- 检测完成后自动用剧名+集数去所有资源站并发搜索验证

## [v1.16.3] 2026-08-27 · 移动端响应式修复 · 底部 Tab 栏

**重构**：
- 修复手机端布局错乱：body 不再 overflow:hidden，layout 改 min-height 替代 height:100vh
- 桌面端侧边栏改为 position:fixed + main margin-left 布局（滚动时侧边栏不跟随）
- 手机端（≤768px）侧边栏隐藏，底部 Tab 栏导航（概览/资源站/映射/日志/设置）
- 手机端表格横向可滚动（overflow-x:auto + -webkit-overflow-scrolling）
- 手机端 form-grid 自动单列，统计卡片 1fr 1fr 两列
- 顶栏简化：隐藏版本标签和退出按钮，面包屑 ellipsis 截断
- 支持 iOS 安全区（env(safe-area-inset-bottom)）

## [v1.16.2] 2026-08-27 · 后台侧边栏分组 + 自动保存优化 + 映射表添加按钮

**改进**：
- 侧边栏重构为三组分类（概览 / 资源配置 / 系统），间距更整齐，视觉更清晰
- 侧边栏品牌区改为居中 logo 布局，footer 简化为单行版权信息
- 自动保存升级：输入过程中实时标记 dirty + 250ms debounce 提交 + 点击空白立即触发
- 新增映射表行内添加按钮（集数 / 剧名 / cid 三个表格各带「＋ 添加」按钮）

## [v1.16.1] 2026-08-27 · 后台界面升级为现代侧边栏卡片布局

**重构**：
- 后台 admin.php 从 tab 切换式布局升级为「左侧固定侧边栏 + 顶部顶栏 + 主区域卡片」现代风格
- 浅色主题：白色卡片、圆角、柔和阴影、浅灰背景（#f0f2f5）
- 深色侧边栏：渐变蓝灰背景，导航项带图标（emoji），选中态高亮蓝紫渐变
- 顶栏面包屑：🏠 / 父级 / 当前页 + 版本标签 + 用户头像 + 退出按钮
- 统计卡片升级为图标+数值+描述的卡片式（绿/橙/紫/蓝/红彩色图标）
- 表单控件样式统一：聚焦蓝色光环、按钮悬停微抬升、开关更轻量
- 响应式：侧边栏窄屏自动收缩为图标模式


## [v1.16.0] 2026-08-27 · 特殊资源站 · 自动套本地播放器

**新增**：
- 后台资源站列表新增「特殊站」开关（is_special 字段）
- 特殊资源站返回的 URL 自动套「当前域名/player/?url=原始地址」，无需手动拼接
- JSON 返回新增专用字段：is_special / site_special / player_url / raw_url
- 命中特殊站时，主字段 url 也自动替换为 /player/ 播放器入口
- 非特殊站不受影响，保持原有行为

**技术细节**：
- SiteSearcher::search 返回结果新增 site_special / raw_url 字段
- bootstrap.php 新增 mxgj_current_host() / mxgj_player_url() helper
- mxgj_build_output 扩展 fMap，命中特殊站时自动追加专用字段（不受 output.fields 配置限制）

## [1.13.0] - 2026-08-26

### ✨ 移除「App 设置 / 安全设置」，前台直接返回真实地址

- **彻底移除「表面播放链接（App 设置）」**：
  - 删除 `settings.json` 的 `app` 段、后台「App 设置」区块与相关快捷开关
  - 删除 `lib/bootstrap.php` 的 `mxgj_surface_url()` / `mxgj_protect_url()` / `mxgj_obfuscate_url()`
  - `index.php` 不再把返回地址伪装为 `/play.php?u=...`，改为**直接返回真实 m3u8 地址**
- **删除「安全设置（欺诈/伪装）」**：移除 `security` 段与后台该区块
- `play.php` 固定为「代理转发」模式，可继续作为独立的手动播放入口使用

### ✨ 取消「保存」按钮，改为点击空白处自动保存

- 移除后台「设置 / 资源站 / 映射表」的**保存按钮**（表单标记为 `auto-save`）
- 新增通用前端脚本（`admin.php` 底部）：修改任意表单后，**点击页面空白处自动提交并保存**
  - 增/删行视为一次改动；快捷开关仍即时生效，不重复提交
  - 点击链接/按钮/输入框等交互元素不会误触发

### ✨ 保存时自动清理运行时数据

- 新增公共函数 `mxgj_purge_runtime()`：保存成功后**自动清理搜索缓存、站点健康状态与心跳锁、日志、定时采集日志**
- 三个保存入口（设置 / 资源站 / 映射）统一调用
- 说明：Web 环境 PHP 每次请求都会重载代码，无需真正重启进程

### ✨ 新增：资源站「特殊调用方法」（GET/POST / 自定义Header / POST体 / 返回解析模式）

部分资源站不可按普通 GET+模板的方式搜索，本版本为每个资源站新增一组**特殊调用方法**配置（存于 `sites.json` 每条站点级字段）：

| 字段 | 取值 | 含义 |
| ---- | ---- | ---- |
| `method` | `get`（默认）/ `post` | HTTP 调用方法，`post` 时走特殊 POST 提交 |
| `headers` | 每行 `Key: Value` 或关联数组 | 自定义请求头（Referer/Cookie/Auth 等），发送时附带 |
| `post` | 含 `%u/%s/%p` 的模板 | POST 请求体；`method=post` 且留空时默认 `wd=%u&ep=%p` |
| `parse` | 空（自动）/ `json` / `text` / `apple` | 强制返回解析模式，防止自动识别误判 |

- **后台「资源站」表新增「特殊调用方法」列**：每行可直接选 GET/POST、返回解析模式，并填写自定义 Header 与 POST 请求体；手动添加/编辑行同步支持
- **前台搜索自动路由**（`lib/SiteSearcher.php`）：新增 `buildRequest()`/`normalizeHeaders()`，`makeHandle()` 支持 POST + 自定义头 + 请求体，`parseBody()` 支持强制解析模式
- **兼容性**：旧配置无新字段时全部走默认 GET+自动解析，行为不变；苹果CMS 检测/自动添加弹窗（`save_site_one`）在修改已有站时**保留**原特殊调用配置，不会覆盖
- 自测新增「特殊资源站(POST调用方法)收到 `wd=%u&ep=%p` 请求体」用例（10/10 通过）

### 🔧 修复：play.php 对 HTML 播放页「特殊资源站不能播放」

- 此前 `play.php?u=<加密地址>` 遇 HTML 播放页（如 `https://lgvideo.xyz/player/xxx`）走 **302 跳转**：
  - `redirect` 模式顶部直接 302 → APP 跟随跳转拿到 HTML 页，原生播放器无法播放
  - `proxy` 模式探测到 `text/html` 也 302；探测失败时还会把 HTML 当二进制流式输出
- 现改为：**HTML 播放页 / 播放页地址（路径含 `/player/ /play/ /live/`）→ 直接渲染内联播放器页面**
  - 内嵌 `https://vv00.xyz?url=<真实地址>` iframe（与 `player.php` / lgzym3u8 播放器一致），浏览器/APP webview 均可正常打开播放
  - 同时修复 m3u8 以 `text/plain` 返回时被误判跳转的问题（m3u8 判断提到 HTML 之前）
- 测试：HTML 播放页内联渲染、m3u8 重写、ts 流式、redirect 模式下播放页仍内联渲染（12/12 通过）

## [1.11.0] - 2026-08-25

### ✨ 新增：播放器页面 player.php（lgzym3u8 / 蓝光资源 MacPlayer）

- 新增 `player.php`：将导入的「Igzym3u8」播放器配置（苹果CMS MacPlayer / 蓝光资源）**原样加载**渲染真实播放
  - 调用：`/player.php?url=<播放地址>`（明文）或 `/player.php?u=<base64url 加密地址>`（与表面播放链接同款加密，可作播放入口）
  - 页面内置 MacPlayer 最小运行时：设置 `PlayUrl` → 执行导入的 `code`（iframe 指向 `https://vv00.xyz?url=播放地址`）→ `MacPlayer.Show()` 渲染
  - 深色播放器 UI、顶部来源徽标、加载过渡动画、非法参数 400
- 浏览器实测：`#player` 成功注入 vv00.xyz iframe（`src` 携带播放地址），页面无 JS 报错
- 可在后台「App 设置」把播放入口路径改为 `player.php`，让表面播放链接直接打开该播放器页面

## [1.10.0] - 2026-08-25

### ✨ 新增：App 设置 - 表面播放链接（自托管播放入口，解决「欺诈伪装后不能播放」）

- **后台「设置」新增 App 设置（表面播放链接，默认开启）**（`settings.json` 新增 `app` 段）
  - 开启后，前台返回的播放链接统一伪装为当前域名下的播放入口：
    `https://当前域名/play.php?u=<base64url 加密的真实播放地址>`
  - 该链接「表面是一个链接」，浏览器打开即可播放，也能被 APP 识别并直接播放，同时隐藏真实播放地址
- **新增播放入口 `play.php`**（App 表面播放链接落地）：
  - `proxy` 代理转发（默认，推荐）：本站抓取并**重写 m3u8 切片/密钥地址**（同样走本站入口）、ts/mp4 二进制**流式转发**，完全隐藏真实源，APP 原生播放器可直接播放；HTML 播放页自动降级为 302 跳转
  - `redirect` 302 跳转：直接跳转真实地址（省流量）
- **统一链接保护** `mxgj_protect_url()`：优先「App 表面播放链接」，其次「安全-欺诈/伪装」
  - 若真实地址带「跟随播放链接」中转前缀（如 `https://vv00.xyz?url=真实地址`），自动提取真实地址后再包装，避免中转域名外泄
- 支持快捷开关（`app_surface_enable`）+ 设置页保存（路径/模式可配）
- 修复：此前「欺诈/伪装」仅替换中转域名，替换后 `https://当前域名?url=...` 无播放入口、无法播放；现由 `play.php` 播放入口保证链接可正常打开播放

## [1.9.1] - 2026-08-25

### 🔧 调整

- 「安全设置-欺诈/伪装」默认**关闭**（`security.obfuscate_enable` 默认 `false`），按需开启
- 说明文案同步为「默认关闭」

## [1.9.0] - 2026-08-25

### ✨ 新增：资源站「跟随播放链接」中转前缀 + 后台「安全设置-欺诈/伪装」

- **资源站单独配置「跟随播放链接」（中转前缀）**（`sites.json` 每站新增 `proxy` 字段，仅该站启用）
  - 命中该资源站时，在真实播放地址前自动拼接中转前缀，如配置 `https://vv00.xyz?url=`
    → 返回 `https://vv00.xyz?url=真实m3u8地址`
  - 兼容两种写法：以 `=` 结尾（自带参数名）直接拼接；否则统一以 `?url=` 拼接
  - 后台「资源站」列表每行新增「跟随播放链接（中转前缀）」输入框；`save_sites` / `save_site_one` 均保存/保留
- **后台「安全设置（欺诈/伪装）」，默认开启**（`settings.json` 新增 `security.obfuscate_enable`）
  - 开启后，返回链接中「跟随播放链接」的<b>中转前缀域名被伪装为当前系统域名</b>
    → `https://vv00.xyz?url=真实地址` 输出为 `https://当前域名?url=真实地址`
  - 仅替换链接开头的中转域名，**不触碰真实播放地址参数，不影响正常播放**；对返回 JSON 中所有含播放地址的字段（url/msg）统一生效
  - 支持设置页开关与快捷开关即时切换（`toggle_setting` 新增 `obfuscate_enable`）
- 实现：`SiteSearcher::finalizeUrl()` 拼接前缀、`lib/bootstrap.php` 新增 `mxgj_obfuscate_url()`、前台 `index.php` 输出时实时伪装（不写缓存）

## [1.8.0] - 2026-08-25

### ✨ 新增：通用快捷开关（映射表 / 输出字段 / 设置项，即时生效）

- 将「快捷开关」扩展为**通用机制**，点击即生效、无需保存，后续新增配置项可复用：
  - **映射表**：官方ID映射 / 剧名映射 / 腾讯cid映射 每条新增「启用」开关
    - 关闭后该条映射不再参与匹配（排查/临时关闭），前端 `index.php` 查找时跳过
    - 禁用列表存于 `mapping.json` 新增 `disabled` 段（按区块 title/cid/episode 存键名），完全兼容旧数据
    - `save_mapping` 保存时保留 `stock`（库存盘点）与 `disabled`，不再丢失
  - **输出返回设置**：每条返回字段新增「启用」开关，关闭后该字段不再出现在返回 JSON（`mxgj_build_output` 过滤）
    - `save_settings` 保存时按索引保留开关状态
  - **设置页**：启用心跳检测 / 启用资源站轮训 两个开关改为**点击即时生效**
- 新增后台操作：`toggle_mapping` / `toggle_output` / `toggle_setting`
- 前端统一为通用 `quick-toggle` 开关组件（`data-action` 分发，便于扩展）；禁用行置灰标识
- 新增辅助函数 `mxgj_mapping_enabled()`（bootstrap.php）

## [1.7.0] - 2026-08-25

### ✨ 新增：后台日志系统

- 新增 `lib/Logger.php`，无数据库按类型各存一个 JSON 文件（`data/logs/{type}.json`），每类最多保留 500 条自动裁剪
- 六类日志自动记录：
  - **登录日志** `login`：后台登录成功 / 失败（含 IP）
  - **操作日志** `operation`：后台增删改、测试、心跳、清缓存等全部操作
  - **更新日志** `update`：在线升级 / 独立 `update.php` 升级结果（含步骤与测速）
  - **搜索调用日志** `search`：前台每次搜索请求，记录 剧名/集数/状态码/命中站点/链接/耗时/来源/IP
  - **配置日志** `config`：资源站 / 映射表 / 系统设置等配置保存成功与失败
  - **错误日志** `error`：key 拦截、参数缺失、链接非法、无法识别剧名等异常
- 后台新增「**日志**」tab：六类统计卡片 + 级别（成功/警告/错误/提示）标记 + 详情展开 + 按类/全部清空
- 新增后台操作 `log_clear`（type=all 清空全部）；`data/logs/` 已加入 .gitignore

## [1.6.1] - 2026-08-25

### ✨ 新增：资源站快捷「启用/禁用」开关

- 后台「资源站」列表每行新增**启用开关**（滑块），点击**即时生效、无需保存**
- 关闭后该资源站**不再参与前台搜索与心跳探测**（`SiteHealth::usable` / `probeAllNow` 直接跳过）
- 禁用行整行置灰并划线标识，开关悬停提示当前状态
- 新增后台操作 `toggle_site`（按模板快捷切换）；`save_sites` / `save_site_one` 保存时保留启用状态，新站默认启用
- `sites.json` 每站新增 `enabled` 字段（默认 `true`，兼容旧配置）

## [1.6.0] - 2026-08-25

### ✨ 新增：后台「输出返回设置」（自定义返回字段映射）

- 后台「设置」新增**输出返回设置**区块，用户可自定义前台返回的 JSON 字段
- 每条字段配置：**键名 k**（对外输出字段名）+ **值来源 v**（系统字段名或固定常量文本）
- 支持映射系统字段：`code` 状态码 / `url` 播放链接 / `title` 影视剧名 / `episode` 集数 / `time` 耗时(ms) / `site` 命中站点 / `msg` 提示 / `source` 请求链接
- 支持直接填**常量文本**作为固定值（如 `KFZ=沫兮官替系统`）
- 例：想返回 `JM=庆余年` `JJ=第2集` —— 键名 `JM` 值来源 `title`，键名 `JJ` 值来源 `episode` 即可
- **原始请求链接默认隐藏**（`show_source=false`），避免返回过于杂乱；可勾选「附带原始链接」开启
- 默认字段：`code`（状态码）、`msg`（= 播放链接，便于兼容）、`url`（播放链接）、`time`（耗时ms）、`KFZ`（开发者=沫兮官替系统）
- 实现：`lib/bootstrap.php` 的 `mxgj_build_output()`，前台 `index.php` 统一经此函数映射输出
- 对应后台操作 `save_settings` 新增输出字段的解析与持久化

## [1.5.0] - 2026-08-25

### ✨ 新增

- **资源站检测 + 自动添加/修改（苹果CMS10 采集接口）**（`lib/SiteDetector.php`）
  - 后台「资源站」页一键「⚡ 检测并自动添加」：粘贴 CMS10 采集接口地址，弹窗自动探测
  - 自动校验接口可达性、识别是否为苹果CMS列表（`list[]` + 可播放 http 地址），证明能取到真实资源
  - 自动生成搜索模板（`?ac=videolist&wd=%u`）与可读站点名称，并给出样本剧名+首条真实地址
  - 弹窗可直接新增/修改后保存到 `config/sites.json`，前台即刻可并发调用
  - 每行「编辑」打开同一弹窗预填进行修改
- 新增后台操作 `detect_site`（检测）与 `save_site_one`（单条保存，同名覆盖/新增）

### 🧪 实测

- 检测西瓜 CMS10 接口成功：`XGZYAPI 资源站`，模板自动生成，样本为《放肆！谁说本宫是恶毒后妈》真实地址
- 新增与同名修改均正确写入 sites.json，测试临时站点已清理

## [1.4.1] - 2026-08-25

### 🎯 优化

- 将「资源站频率控制」的推荐值写为系统默认（开箱即用、更防屏蔽）：
  - 搜索间隔 15s / 心跳间隔 600s / 心跳超时 5s / 连续失败 3 次禁用 / 冷却 1800s / 轮训周期 600s / 每次最多并发请求 4 个站
- README、后台帮助、设置页的推荐值同步更新为具体数值

## [1.4.0] - 2026-08-25

### ✨ 新增

- **资源站调用频率控制**（`lib/SiteHealth.php`）——降低对资源站的调用频率，避免频繁/密集调用被屏蔽或禁用：
  - **搜索频率**（`search_interval`）：同一资源站两次实际调用最短间隔，间隔内跳过（依赖结果缓存），限制单站 QPS
  - **心跳频率**（`heartbeat_interval` / `heartbeat_max_fail` / `cooldown_seconds`）：周期并发探测各站可达性，连续失败自动禁用、冷却后自动恢复重试；前端只请求存活站点
  - **轮训**（`rotation_interval` / `max_sites_per_request`）：轮训周期推进命中顺序（round-robin），可限制每请求并发站数，分散压力
- 后台「设置」页新增频率控制配置项 + **资源站健康状态**可视化（立即心跳探测 / 重置健康状态）
- 后台「帮助」新增「资源站调用频率控制」说明与推荐值
- 运行状态存于 `data/site_health.json`（已加入 .gitignore）
- 将推荐频率写为默认值：搜索间隔 15s / 心跳 600s / 超时 5s / 连错 3 次禁用 / 冷却 1800s / 轮训 600s / 每请求并发 4 站

### 🧪 实测

- 实时搜索（腾讯/爱奇艺等）在心跳+节流生效下仍返回 m3u8；后台「立即心跳探测」显示站点可达
- 内置自测 8/8 通过（含并发时序 1.21s < 2.2s）

## [1.3.0] - 2026-08-25

### ✨ 新增

- **定时自动采集映射**（`cron/mapping.php`）
  - 按周期自动做两件事，省去手动添加映射：
    1. **官方种子链接补全**：遍历 `settings.json → cron → seed_links`，对未映射的官方链接自动抓取剧名+集数并写入映射表（已存在则跳过、不覆盖人工配置）
    2. **资源站库存盘点**：并发拉取资源站最新采集列表，把「在库剧名 + 集数」写入 `mapping.json → stock`
  - 支持 `dry=1` 预览不写库、`quiet` 抑制明细、`key` 鉴权（回退 updater_key/admin_password）
  - 每次运行追加记录到 `data/cron_mapping.log`（最多 200 条）
- **后台新增「帮助」tab**
  - 内置资源站「映射要求」说明（苹果CMS：按剧名搜索 + `vod_play_url` 集数提取）
  - 「定时访问功能」使用文档：种子链接配置 / crontab 用法 / dry 预览 / 最近运行记录

### 🧪 实测

- `cron/mapping.php` 实测：7 个默认种子全部命中跳过、资源站盘点正常
- 临时新增爱奇艺 `v_cix2gal5d8` 种子，自动采集成映射 `vid:cix2gal5d8 → 蝉 第1集`

## [1.2.1] - 2026-08-25

### 🐛 修复

- **芒果TV 链接无法提取 `cid/vid`（导致自动映射不落库）**
  - 旧正则可匹配 `/b/{cid}/{vid}.html` 双数字格式，解析结果 `vid/cid` 恒为空
  - 现按 `/b/{cid}` 与 `/b/{cid}/{vid}` 两种形式分别提取 `cid`、`vid`
  - 修复后芒果TV链接实测可自动抓取剧名+集数并**自动写入映射表**

### 🧪 实测

- 新增平台映射（自动抓取+自动固化）：
  - 芒果TV：`vid:24269945` → 你是迟来的欢喜 · 第8集
  - 优酷：`vid:fcad042e84ef43ce8309` → 师兄太稳健 · 第42集
  - 优酷：`vid:cbff0b0703e54d659628` → 以法之名 · 第1集
  - 哔哩哔哩：`vid:ep431046` → 开心锤锤 · 第1集

## [1.2.0] - 2026-08-25

### ✨ 新增

- **在线自动更新**（`lib/Updater.php`）
  - 从 GitHub 自动拉取最新代码，更新前删除当前代码文件（保留 `config/`、`data/`）
  - 内置多个 GitHub 加速镜像，**自动测速选取最快节点**下载（适配国内网络）
  - 更新后文件与子目录权限统一设为 0777
- **独立升级入口**（`update.php`）
  - `域名/update.php?key=升级密钥` 手动触发更新；`&dry=1` 仅测速排查
  - 供后台自动更新异常时兜底使用
- **后台「更新」页**（设置前的新 tab）
  - 「立即更新 / 仅测速」按钮，实时展示测速与更新报告
- **一键测试 · AI 智能分析自动加映射**
  - 后台一键测试：自动抓取官方页面识别「剧名+集数」
  - 识别成功后**自动写入映射表**（`vid/cid → 剧名+集数`），下次直接命中、不再联网
  - 增加站点词防误判：清洗结果为纯站点词时判定无效、回退 502
- 自动写映射（`mxgj_auto_mapping`）：前台抓取识别成功即自动固化映射，保护已有配置

### 🐛 修复

- 偶发把站点通用页标题（如「爱奇艺」）误当剧名 → 增加站点词过滤

## [1.1.0] - 2026-08-24

### ✨ 新增

- 官方页面自动抓取（`lib/PageResolver.php`）：
  - 当链接字符串无法解析出剧名/集数时，自动访问官方播放页（移动端 UA）抓取信息
  - 从 `<title>` 提取剧名并清洗（去平台后缀/修饰词），正则提取「第N集」集数
  - 可信度校验：拒绝 404 页、通用首页、站点提示等无效标题
  - 实测：`m.iqiyi.com/v_cix2gal5d8.html` →「蝉」第1集
- 前台解析流程增强（`index.php`）：
  - 解析/映射未命中时自动降级到页面抓取
  - `debug` 新增 `name_from`（page抓取 / mapping）与 `page_title`
  - `502` 提示附带解析出的 `vid/cid`，方便定位需映射的 ID

### ✨ 优化

- `SiteSearcher` 支持苹果CMS采集列表格式（`list[]` + `vod_play_url`），
  按集数提取地址（`第N集$地址#第N集$地址`）
- 测试脚本启动前自动清空运行时缓存，保证测试确定性

## [1.0.0] - 2026-08-24

### ✨ 新增

- 前台解析 API（`index.php`）：输入官方链接 → JSON 输出 `code=200` + m3u8 地址
- 官方链接解析器（`lib/LinkParser.php`）：
  - 腾讯视频（`vid` / `cid`）
  - 爱奇艺（`v_` ID）
  - 优酷（`id_` ID）
  - 芒果TV / PPTV / 哔哩哔哩（`BV` / `av` / `ep`）
- 多线程资源站搜索（`lib/SiteSearcher.php`）：
  - 基于 `curl_multi` 并发请求全部后台资源站
  - 多结果按分辨率（`w`）择优返回
  - 兼容 JSON / JSONP / 纯文本 返回格式
- 无数据库配置体系：
  - `config/settings.json`：全局设置（密码 / 超时 / 缓存 / 域名替换）
  - `config/sites.json`：资源站模板列表
  - `config/mapping.json`：`vid/cid → 剧名+集数` 映射表
  - `config/key.php`：可选的前台访问密钥
- 文件缓存（`lib/Cache.php`）：缓存搜索结果，`cache_ttl` 可配
- 可视化后台（`admin.php`）：
  - 会话登录鉴权
  - 资源站增删改 + 单站测试
  - 官方ID映射 / 剧名映射 / cid 映射
  - 设置修改与缓存清理
  - 一键解析测试
- JSONP 支持（`callback` 参数）、CORS 跨域头
- 端到端自测脚本（`tests/run_test.php`）+ 模拟资源站（`tests/mock_site.php`）

### 🔧 变更

- 无（初始版本）

### 🐛 修复

- 无（初始版本）
