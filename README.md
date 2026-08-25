# 沫兮官替系统 (MXGJ)

一款基于 **PHP + cURL 多线程** 的「官方链接 → 资源站 m3u8」替换解析系统。
用户输入官方播放链接（如腾讯视频），系统自动解析出「剧名 + 集数」，然后**并发**向后台配置的
多个资源站搜索对应资源，命中后以 JSON 返回 `code=200` 与可直接播放的 `m3u8` 地址。

**全程无数据库**，所有配置与映射均以 JSON 文件存储，开箱即用。

---

## ✨ 功能特性

- 🧵 **多线程资源站搜索**：基于 `curl_multi` 同时并发请求后台全部资源站，耗时≈最慢站点，而非站点数量×单站耗时
- 🎯 **官方链接解析**：支持 腾讯视频 / 爱奇艺 / 优酷 / 芒果TV / 哔哩哔哩 / PPTV 等主流平台
- 🕷️ **页面自动抓取**：链接无法直接解析出剧名/集数时，自动访问官方播放页抓取《剧情 第N集》并清洗出剧名（含无效标题校验）
- 🗺️ **映射表**：`vid/cid → 剧名+集数` 精确映射，链接自带集数时可自动识别（如庆余年第2集）
- 🧩 **资源站模板**：`%s / %u / %t / %p` 占位符，兼容 JSON / JSONP / 纯文本 等任意返回格式
- 📦 **文件缓存**：搜索结果缓存，减少对资源站的重复请求
- 🛠️ **可视化后台**：资源站管理、映射管理、系统设置、一键测试，无需改代码
- 🤖 **一键测试 · AI 智能分析**：自动抓取官方页面识别剧名+集数，并**自动写入映射表**
- 🔄 **在线自动更新**：从 GitHub 多加速镜像自动测速选最快节点拉取最新代码，更新后权限 777
- 🔌 **JSONP 支持**：`callback` 参数，方便前端跨域调用

---

## 📁 目录结构

```
mxgj/
├── index.php               # 前台解析 API（入口）
├── admin.php               # 后台管理（登录后可配置）
├── update.php              # 独立升级入口（update.php?key=升级密钥）
├── lib/
│   ├── bootstrap.php       # 公共引导 / JSON 文件读写 / 设置加载 / 自动写映射
│   ├── LinkParser.php      # 官方链接解析（平台 / vid / cid / 集数）
│   ├── PageResolver.php    # 官方页面自动抓取（AI 智能识别剧名+集数）
│   ├── SiteSearcher.php    # 多线程资源站搜索（curl_multi + 苹果CMS）
│   ├── Updater.php         # GitHub 多加速镜像在线更新
│   └── Cache.php           # 文件缓存
├── config/
│   ├── settings.json       # 全局设置（密码/密钥/超时/缓存/域名替换/仓库）
│   ├── sites.json          # 资源站列表
│   ├── mapping.json        # 剧名 / cid / vid 映射表
│   └── key.php             # 前台访问密钥（可选）
├── data/
│   └── cache/              # 搜索缓存目录（自动生成）
└── tests/
    ├── run_test.php        # 端到端自测脚本
    └── mock_site.php       # 模拟资源站（自测用）
```

---

## ⚙️ 环境要求

- PHP **7.4+**（推荐 8.x）
- 扩展：`curl`、`json`（`mbstring` 可选，缺失时自动降级）

---

## 🚀 快速开始

```bash
cd mxgj
php -S 0.0.0.0:8080 -t .
```

- 前台接口：`http://你的域名/index.php`
- 后台管理：`http://你的域名/admin.php`（默认密码 `moxi123`，登录后请立即修改）

> 生产环境建议使用 Nginx/Apache 部署，PHP-FPM 同样兼容。

---

## 📖 使用说明

### 1. 后台配置资源站

登录后台 → **资源站** 页，填写资源站名称与「搜索地址模板」。

模板支持占位符：

| 占位符 | 含义               | 示例输出                    |
| ------ | ------------------ | --------------------------- |
| `%s`   | 剧名（不转码）     | `庆余年`                    |
| `%u`   | 剧名（URL 编码）   | `%E5%BA%86%E4%BD%99%E5%B9%B4` |
| `%t`   | 剧名（同 %s）      | `庆余年`                    |
| `%p`   | 集数               | `2`                         |

示例（苹果 CMS 类）：

```
http://your-cms.com/api.php/provide/vod/?ac=videolist&wd=%u
```

资源站返回支持三种格式，任选其一：

```json
// JSON
{"code": 1, "url": "https://cdn.example.com/play/2/index.m3u8", "w": 1080}
```

```json
// JSONP
mycallback({"url": "https://cdn.example.com/play/2/index.m3u8"})
```

```text
// 纯文本
https://cdn.example.com/play/2/index.m3u8
```

> `w` 字段为分辨率（宽），多个资源站命中时**优先返回分辨率更高**的地址。

### 2. 配置官方链接映射

官方链接的 `vid / cid` 与「剧名 + 集数」的对应关系存放在映射表：

后台 → **映射表** 页，官方ID映射格式：`vid:视频ID` 或 `cid:剧集ID`。

> 多数情况下你**无需手动添加**：链接无法直接解析时，系统会自动抓取官方页面
> 识别「剧名+集数」并**自动写入映射表**，下次直接命中、不再联网抓取。
>
> 支持平台及链接示例：
> - 腾讯视频：`.../play?cid=mzc00200zx8psx0&vid=k4102szvyce`（cid/vid 双ID）
> - 爱奇艺：`https://www.iqiyi.com/v_19hly1wd1gg.html`（`v_` ID）
> - 优酷：`https://m.youku.com/alipay_video/id_fcad042e84ef43ce8309.html`（`id_` ID）
> - 芒果TV：`https://m.mgtv.com/b/731684/24269945.html`（cid=731684，vid=24269945）
> - 哔哩哔哩：`https://www.bilibili.com/bangumi/play/ep431046`（`av`/`BV`/`ep`）

例如腾讯链接 `.../play?cid=mzc00200zx8psx0&vid=k4102szvyce` 是**庆余年第2集**：

| ID                 | 剧名   | 集数 |
| ------------------ | ------ | ---- |
| `vid:k4102szvyce`  | 庆余年 | 2    |

映射文件 [mapping.json](config/mapping.json)：

```json
{
  "title": {},
  "cid": { "mzc00200zx8psx0": "庆余年" },
  "episode": {
    "vid:k4102szvyce": { "name": "庆余年", "episode": 2 }
  }
}
```

### 3. 调用前台 API

```
GET /index.php?key=<密钥>&url=<官方视频链接>[&page=<集数>][&debug=1][&callback=<jsonp>]
```

> `key` 固定放在 `url` 之前（未开启 key 鉴权时可省略）。

**示例**（腾讯 → 庆余年第2集）：

```
/index.php?key=YOUR_KEY&url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce
```

**返回**：

```json
{
  "code": 200,
  "url": "https://cdn.example.com/play/2/index.m3u8",
  "msg": "success",
  "episode": 2,
  "source": "https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce"
}
```

**状态码约定**：

| code | 说明                                   |
| ---- | -------------------------------------- |
| 200  | 成功，`url` 为资源站 m3u8 地址         |
| 400  | 缺少 `url` 参数或链接非法              |
| 403  | 访问密钥错误（开启了 key 鉴权时）      |
| 404  | 资源站未匹配到该剧对应集数             |
| 501  | 后台未配置任何资源站                   |
| 502  | 无法识别链接对应的剧名（需配置映射）   |
| 503  | 资源站 URL 模板无法生成                |

---

## 🤖 一键测试 · AI 智能分析

后台「概览」页的一键测试，会自动完成：

1. 解析官方链接 → 若无法直接识别，自动抓取官方页面（PageResolver）识别「剧名 + 集数」
2. 识别成功后**自动写入映射表**（`vid/cid → 剧名+集数`），下次直接命中、不再联网抓取
3. 再去后台全部资源站并发搜索，返回对应集数的播放地址

> 已在后台反馈 `ai_mode`（解析 / 页面抓取）与 `auto_mapped`（是否自动建映射）。

## 🔄 在线自动更新

后台「更新」页（设置前）可一键更新到 GitHub 最新代码：

- 自动在多个 **GitHub 加速镜像**间测速，**选择最快节点**下载
- 更新前删除当前代码文件，**保留 `config/` 与 `data/`**
- 更新后文件与目录权限统一设为 **0777**

**独立升级入口**（后台自动更新异常时兜底）：

```
http://你的域名/update.php?key=升级密钥
# 仅测速排查：
http://你的域名/update.php?key=升级密钥&dry=1
```

升级密钥默认等于后台管理密码，可在「设置」页单独配置 `升级密钥`。

---

## ⚙️ 配置说明

### config/settings.json

| 字段             | 默认值  | 说明                                           |
| ---------------- | ------- | ---------------------------------------------- |
| `admin_password` | moxi123 | 后台登录密码                                   |
| `timeout`        | 15      | 单个资源站请求超时（秒）                       |
| `cache_ttl`      | 600     | 搜索缓存时长（秒，0=关闭）                     |
| `replace_domain` | ""      | 域名替换/中转前缀；留空则直接返回资源站地址    |

`replace_domain` 示例：配置 `https://your-proxy.com/m3u8/` 后，
返回的 `url` 会变为 `https://your-proxy.com/m3u8/play/2/index.m3u8`（自动去掉资源站原域名）。

### config/key.php

可选的前台访问鉴权。返回空字符串关闭；改为你的密钥字符串后开启：

```php
return 'your_secret_key';
```

开启后调用需携带 `&key=your_secret_key`（放在 url 之前），否则返回 `code=403`。例如：

```
/index.php?key=your_secret_key&url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce
```

---

## 🧪 自测

内置端到端自测脚本（自动启动模拟资源站并验证多线程并发）：

```bash
php tests/run_test.php
```

预期输出（全部 PASS）：

```text
【主流程】腾讯链接 → 庆余年第2集
  请求耗时: 1.21s (阈值 2.2s)      ← 两个资源站各延迟1.2s，串行约2.4s，证明多线程并发
  [PASS] 返回 code=200
  [PASS] url 命中 1080p 资源(第2集)
  ...
结果: 8 通过 / 0 失败
```

---

## ⚠️ 免责声明

本项目仅用于学习与接口调用演示。请遵守国家法律法规与各视频平台/资源站的服务条款，
**切勿**用于盗版、侵权内容的传播。因使用本项目产生的任何法律风险由使用者自行承担。

---

## 📜 更新日志

见 [CHANGELOG.md](CHANGELOG.md)。

## 🏷️ 版本信息

见 [version.json](version.json)。
