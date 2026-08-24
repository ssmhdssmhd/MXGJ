# 更新日志

本项目所有值得注意的变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [1.0.0] - 2026-08-24

### 新增

- 由 Python 桌面版（`iqiyi_gui_modern.py` / `iqiyi_gui_simple.py`）完整转换为核心解析类 `src/VideoParser.php`
- 新增外部可调用 REST API 接口 `public/api/parse.php`，支持 GET / POST / JSONP / 跨域（CORS）
- 新增 Web 播放界面 `public/index.php`，支持 m3u8（HLS.js）、mp4、iframe 三种播放方式
- 新增核心逻辑自测脚本 `tests/unit_test.php`（19 项用例）

### 功能

- 多平台视频 ID 提取：爱奇艺、腾讯视频、优酷、芒果TV、通用参数
- 多级解析策略：第三方接口 → 直接解析 → 移动端解析 → 通用播放器链接兜底
- m3u8 主清单解析与画质升级，优先选择 4K / HDR / 最高分辨率 / 最高码率
- 相对 URL / 根相对 URL / 协议相对 URL 解析为绝对地址
- SSRF 防护：仅允许常见视频域名
- HTTP 请求内置重试策略（429 / 5xx / 连接错误，指数退避）

### 转换说明

- `requests` → cURL 封装（`httpGet`）
- `BeautifulSoup` → `DOMDocument` + `DOMXPath`
- `re` → `preg_match` / `preg_match_all`
- `tkinter` 桌面界面 → Web 前端
- `cv2` / VLC 本地播放 → HLS.js / 原生 video 播放

### 修复

- 腾讯视频 ID 提取：修正 `/x/cover/{id}` 与 `/x/page/{id}` 的正则，避免误将 `cover` / `page` 当作视频 ID
