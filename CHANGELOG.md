# 更新日志 (CHANGELOG)

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

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
