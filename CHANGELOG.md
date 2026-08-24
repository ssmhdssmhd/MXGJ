# 更新日志

本项目所有值得注意的变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [0.0.1] - 2026-08-24

### 新增

- 框架化重构：由简单脚本结构升级为轻量级 PHP 框架（MVC 风格）
  - `core/` 框架核心：`App`（应用核心）、`Router`（路由）、`Request`（请求）、`Response`（响应）
  - `app/` 应用层：`Controllers/`、`Services/`、`Views/`
  - `config/` 配置：解析接口、请求参数、域名白名单等集中管理
  - `public/` 唯一入口：`index.php`（首页）+ `api.php`（API）
  - `storage/` 存储目录、`bootstrap.php` 自动加载引导
- 新增 `composer.json`，版本号 `0.0.1`，支持 PSR-4 自动加载
- 新增健康检查接口 `GET /api.php/health`，返回应用与框架版本信息
- 新增 iframe 播放器源支持：内置虾米播放器双域名（`jx.xmflv.cc` / `jx.xmflv.com`），经实测可正常解析播放爱奇艺等视频，作为 iframe 播放源返回
- 新增播放源检测接口 `GET /api.php/check`：在配置中添加新解析接口前，可验证其可用性与类型（`parse` 直链型 / `iframe` 播放器型 / `unknown` / `error`），支持检测单个、多个或配置中全部接口
- 核心解析逻辑迁移为 `VideoParserService`（业务服务层），支持从配置注入参数
- 单元测试升级为 20 项用例（新增框架版本校验）

### 功能

- 多平台视频 ID 提取：爱奇艺、腾讯视频、优酷、芒果TV、通用参数
- 多级解析策略：第三方接口 → 直接解析 → 移动端解析 → 通用播放器链接兜底
- m3u8 主清单解析与画质升级，优先选择 4K / HDR / 最高分辨率 / 最高码率
- 相对 URL / 根相对 URL / 协议相对 URL 解析为绝对地址
- SSRF 防护：域名白名单校验
- HTTP 请求内置重试策略（429 / 5xx / 连接错误，指数退避）
- 外部可调用 API：GET / POST / JSONP / 跨域（CORS）

### 修复

- 修复 PHP 内置服务器下路由匹配问题：剥离入口脚本名（`SCRIPT_NAME`）前缀，使 `/api.php` 与 `/api.php/health` 正确路由
- 修复播放源返回「拼接地址」问题：移除未经请求验证的 `api + url` 拼接兜底逻辑，只返回真实请求并验证过的播放源（直链优先，iframe 播放器源兜底）
- 修复第三方接口 meta refresh 跳转未跟随问题：新增 `extractMetaRefreshUrl`，自动跟随 `<meta http-equiv="refresh">` 跳转继续解析
- 修复 iframe 播放器源无法识别问题：新增 `looksLikePlayerPage` 播放器页面特征识别，将返回播放器页面的接口地址作为 iframe 播放源

### 变更

- API 入口由 `public/api/parse.php` 调整为 `public/api.php`（框架统一入口）
- 解析接口、超时、重试、域名白名单等参数改为 `config/config.php` 集中配置
- 播放源分类标注：接口新增 `sources` 字段（`type` / `label` / `note`）及 `direct_count` / `iframe_count` 统计，明确区分「直链（无广告）」与「iframe 播放器源（可能含广告）」
- 新增 `enable_iframe_players` 配置（默认 `true`）：控制是否返回 iframe 播放器源，设为 `false` 时仅返回直链
- 解析接口列表更新为实测可用的接口（`jx.playerjy.com` / `jx.xmflv.cc` / `jx.xmflv.com` / `jx.77flv.cc`）
- Web 播放界面：播放源列表区分「直链 / 播放器源」标签，优先自动播放直链，无直链时播放第一个播放器源
