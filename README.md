# 视频解析工具（PHP 版）

多平台视频解析工具，由 Python 桌面版（`iqiyi_gui_modern.py` / `iqiyi_gui_simple.py`）转换而来。提供**外部可调用的 REST API 接口**和 **Web 播放界面**，支持爱奇艺、腾讯视频、优酷、芒果TV、哔哩哔哩等主流平台。

## 功能特性

- 多平台支持：爱奇艺、腾讯视频、优酷、芒果TV、哔哩哔哩等
- 解析策略：依次尝试第三方解析接口 → 直接解析 → 移动端解析 → 通用播放器链接兜底
- 画质升级：自动解析 m3u8 主清单，优先选择 4K / HDR / 最高分辨率 / 最高码率变体
- 外部可调用 API：支持 GET / POST / JSONP / 跨域（CORS）
- Web 播放界面：m3u8 走 HLS.js，mp4 走原生播放器，其他走 iframe
- 内置 SSRF 防护：仅允许常见视频域名

## 目录结构

```
video-parser-php/
├── public/
│   ├── index.php          # Web 播放界面入口
│   └── api/
│       └── parse.php      # 外部可调用解析 API
├── src/
│   └── VideoParser.php    # 核心解析类（由 Python 版转换）
├── tests/
│   └── unit_test.php      # 核心逻辑自测脚本
├── README.md
└── CHANGELOG.md
```

## 环境要求

- PHP >= 8.0
- 扩展：`curl`、`dom`、`mbstring`、`json`、`openssl`

Ubuntu 安装示例：

```bash
sudo apt-get install -y php-cli php-curl php-xml php-mbstring
```

## 快速开始

### 本地运行（PHP 内置服务器）

```bash
cd video-parser-php
php -S 0.0.0.0:8080 -t public
```

访问 `http://localhost:8080/` 打开 Web 播放界面。

### 生产部署（Nginx + PHP-FPM）

将 `public/` 作为站点根目录，`public/api/parse.php` 即 API 入口。示例 Nginx 配置：

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/video-parser-php/public;
    index index.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }
}
```

## API 接口文档

### 接口地址

```
GET /api/parse.php?url=<视频链接>
POST /api/parse.php   （JSON: {"url":"<视频链接>"} 或表单 url 字段）
```

### 请求参数

| 参数 | 必填 | 说明 |
|---|---|---|
| `url` | 是 | 视频页面链接，必须以 `http://` 或 `https://` 开头 |
| `callback` | 否 | JSONP 回调函数名（启用 JSONP 模式） |

### 返回格式（JSON）

```json
{
  "success": true,
  "title": "视频标题",
  "description": "视频描述",
  "urls": ["播放源1", "播放源2", "..."],
  "video_info": {
    "title": "视频标题",
    "description": "视频描述",
    "duration": "时长",
    "cover": "封面地址",
    "url": "原始链接"
  },
  "video_id": "iqiyi_xxx",
  "parse_time": "2026-08-24 19:00:00"
}
```

解析失败时返回：

```json
{
  "success": false,
  "error": "错误信息"
}
```

### 调用示例

```bash
# GET
curl "http://localhost:8080/api/parse.php?url=https://www.iqiyi.com/v_1re8v439zmw.html"

# POST JSON
curl -X POST -H "Content-Type: application/json" \
  -d '{"url":"https://www.iqiyi.com/v_1re8v439zmw.html"}' \
  "http://localhost:8080/api/parse.php"

# JSONP
curl "http://localhost:8080/api/parse.php?url=https://www.iqiyi.com/v_1re8v439zmw.html&callback=myCallback"

# 前端跨域调用（CORS 已开启）
fetch("http://your-domain.com/api/parse.php?url=" + encodeURIComponent(videoUrl))
  .then(r => r.json())
  .then(data => console.log(data.urls));
```

### 错误码

| HTTP 状态码 | 说明 |
|---|---|
| `200` | 请求成功（`success` 字段区分解析结果） |
| `400` | 参数缺失 / 格式错误 / 域名不支持 |
| `500` | 服务端异常 |

## 运行测试

```bash
php tests/unit_test.php
```

测试覆盖：视频 ID 提取、播放源验证、m3u8 主清单解析、最佳变体选择、相对 URL 解析、播放源正则提取。

## 从 Python 版转换说明

| Python 组件 | PHP 对应实现 |
|---|---|
| `requests`（HTTP） | cURL 封装（`httpGet`，含重试策略） |
| `BeautifulSoup`（HTML 解析） | `DOMDocument` + `DOMXPath` |
| `re`（正则） | `preg_match` / `preg_match_all` |
| `urllib.parse`（URL 处理） | `parse_url` / `parse_str` / `urldecode` |
| `json` | `json_encode` / `json_decode` |
| `threading`（多线程） | PHP-FPM 天然多进程，无需转换 |
| `tkinter`（桌面界面） | 改为 Web 前端（HTML + JS + HLS.js） |
| `cv2` / VLC（本地播放） | 改为 HLS.js / 原生 video 播放 |

## 免责声明

本工具仅供学习交流使用，请遵守相关法律法规及视频平台的服务条款，勿用于商业用途或侵权用途。
