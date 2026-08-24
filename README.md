# MXGJ 视频解析工具（框架版 v0.0.1）

多平台视频解析工具，基于**轻量级 PHP 框架**（自研 MVC 结构）重构，由 Python 桌面版（`iqiyi_gui_modern.py` / `iqiyi_gui_simple.py`）转换而来。提供**外部可调用的 REST API 接口**和 **Web 播放界面**，支持爱奇艺、腾讯视频、优酷、芒果TV、哔哩哔哩等主流平台。

## 功能特性

- 轻量级框架架构：`core/` 框架核心 + `app/` 应用层 + `config/` 配置 + `public/` 唯一入口
- 多平台支持：爱奇艺、腾讯视频、优酷、芒果TV、哔哩哔哩等
- 解析策略：依次尝试第三方解析接口 → 直接解析 → 移动端解析 → 通用播放器链接兜底
- 画质升级：自动解析 m3u8 主清单，优先选择 4K / HDR / 最高分辨率 / 最高码率变体
- 外部可调用 API：支持 GET / POST / JSONP / 跨域（CORS）
- Web 播放界面：m3u8 走 HLS.js，mp4 走原生播放器，其他走 iframe
- 内置 SSRF 防护：仅允许常见视频域名

## 框架结构

```
MXGJ-PHP/
├── app/
│   ├── Controllers/
│   │   ├── IndexController.php     # 首页控制器（渲染视图）
│   │   └── ParseController.php     # 解析控制器（API 逻辑）
│   ├── Services/
│   │   └── VideoParserService.php  # 解析服务（核心业务）
│   └── Views/
│       └── index.php               # Web 播放界面视图
├── core/
│   ├── App.php                     # 应用核心（版本 0.0.1）
│   ├── Router.php                  # 路由器（支持 :param 路径参数）
│   ├── Request.php                 # 请求封装（GET/POST/JSON）
│   └── Response.php                # 响应封装（JSON/JSONP/CORS）
├── config/
│   └── config.php                  # 应用配置（解析接口、域名白名单等）
├── public/
│   ├── index.php                   # 首页入口
│   └── api.php                     # API 入口（外部可调用）
├── storage/                        # 存储目录（日志、缓存等）
├── tests/
│   └── unit_test.php               # 核心逻辑自测脚本（20 项用例）
├── bootstrap.php                   # 框架引导（自动加载）
├── composer.json                   # 版本 0.0.1
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
cd MXGJ-PHP
php -S 0.0.0.0:8080 -t public
```

访问 `http://localhost:8080/` 打开 Web 播放界面。

### 生产部署（Nginx + PHP-FPM）

将 `public/` 作为站点根目录，`public/api.php` 即 API 入口。示例 Nginx 配置：

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/MXGJ-PHP/public;
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
GET  /api.php?url=<视频链接>
POST /api.php   （JSON: {"url":"<视频链接>"} 或表单 url 字段）
GET  /api.php/health   （健康检查，返回版本信息）
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

### 健康检查示例

```bash
curl "http://localhost:8080/api.php/health"
# {"success":true,"app":"MXGJ 视频解析工具","version":"0.0.1","framework_version":"0.0.1","time":"..."}
```

### 调用示例

```bash
# GET
curl "http://localhost:8080/api.php?url=https://www.iqiyi.com/v_1re8v439zmw.html"

# POST JSON
curl -X POST -H "Content-Type: application/json" \
  -d '{"url":"https://www.iqiyi.com/v_1re8v439zmw.html"}' \
  "http://localhost:8080/api.php"

# JSONP
curl "http://localhost:8080/api.php?url=https://www.iqiyi.com/v_1re8v439zmw.html&callback=myCallback"

# 前端跨域调用（CORS 已开启）
fetch("http://your-domain.com/api.php?url=" + encodeURIComponent(videoUrl))
  .then(r => r.json())
  .then(data => console.log(data.urls));
```

### 错误码

| HTTP 状态码 | 说明 |
|---|---|
| `200` | 请求成功（`success` 字段区分解析结果） |
| `400` | 参数缺失 / 格式错误 / 域名不支持 |
| `404` | 路由不存在 |
| `500` | 服务端异常 |

## 运行测试

```bash
php tests/unit_test.php
```

测试覆盖：框架版本、视频 ID 提取、播放源验证、m3u8 主清单解析、最佳变体选择、相对 URL 解析、播放源正则提取（20 项用例）。

## 版本历史

- **v0.0.1**（当前）：框架化重构版本，详见 [CHANGELOG.md](CHANGELOG.md)

## 免责声明

本工具仅供学习交流使用，请遵守相关法律法规及视频平台的服务条款，勿用于商业用途或侵权用途。
