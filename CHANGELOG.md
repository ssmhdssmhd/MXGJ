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

### 变更

- API 入口由 `public/api/parse.php` 调整为 `public/api.php`（框架统一入口）
- 解析接口、超时、重试、域名白名单等参数改为 `config/config.php` 集中配置
