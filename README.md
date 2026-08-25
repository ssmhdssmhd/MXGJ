# 沫兮官替系统 (MXGJ)

一款基于 **PHP + cURL 多线程** 的「官方链接 → 资源站 m3u8」替换解析系统。
用户输入官方播放链接（如腾讯视频），系统自动解析出「剧名 + 集数」，然后**并发**向后台配置的
多个资源站搜索对应资源，命中后以 JSON 返回 `code=200` 与可直接播放的 `m3u8` 地址。

**全程无数据库**，所有配置与映射均以 JSON 文件存储，开箱即用。

---

## 📌 更新说明（置顶，最新在最上）

### v1.4.1 · 2026-08-25 — 频率控制推荐值落地为默认
- 将「资源站频率控制」推荐值写为系统默认：搜索间隔 15s / 心跳 600s / 超时 5s / 连错3次禁用 / 冷却 1800s / 轮训 600s / 每请求并发4站
- 后台帮助、设置页、README 推荐值同步为具体数值

### v1.4.0 · 2026-08-25 — 资源站频率控制（搜索/心跳/轮训）
- 新增「**资源站调用频率控制**」（`lib/SiteHealth.php`），避免被资源站频繁/密集调用而屏蔽或禁用：
  - **搜索频率**（`search_interval`）：限制同一资源站两次实际调用最短间隔，依赖结果缓存兜底
  - **心跳频率**（`heartbeat_interval`）：周期并发探测各站可达性，连续失败自动禁用、冷却后自动恢复；前端只请求存活站点
  - **轮训**（`rotation_interval` + `max_sites_per_request`）：定期推进命中顺序（round-robin），可限制每请求并发站数，分散压力
- 后台「设置」新增频率控制配置项 + **资源站健康状态**可视化（`立即心跳探测 / 重置健康状态`）
- 后台「帮助」新增「资源站调用频率控制」说明与推荐值
- 运行状态存于 `data/site_health.json`（已加入 .gitignore）
- **默认即用推荐稳定值**：搜索间隔 15s / 心跳 600s / 超时 5s / 连错3次禁用 / 冷却 1800s / 轮训 600s / 每请求并发4站

### v1.3.0 · 2026-08-25 — 定时自动采集映射 + 后台帮助
- 新增 **`cron/mapping.php` 定时访问功能**，周期性自动完成映射采集，省去手动：
  - **官方种子链接补全**：遍历 `settings.json → cron → seed_links`，对未映射的官方链接自动抓取剧名+集数并写入映射表（已存在则跳过、不覆盖人工配置）
  - **资源站库存盘点**：并发拉取资源站最新采集列表，把「在库剧名 + 集数」写入 `mapping.json → stock`
- 前台返回 `code=200` 时自动固化映射（`vid/cid → 剧名+集数`），下次直接命中、不再联网抓取
- 后台新增 **「帮助」tab**：含资源站「映射要求」说明与「定时访问功能」使用文档（crontab 用法 / dry 预览 / 运行记录）
- 支持平台：腾讯视频 / 爱奇艺 / 优酷 / 芒果TV / 哔哩哔哩 / PPTV（芒果已修复双数字 `cid/vid` 提取）
- 使用方法见下方「🕰️ 定时自动采集映射」章节

### v1.2.1 · 2026-08-25 — 芒果/优酷实测映射 + 修复
- 修复芒果TV `/b/{cid}/{vid}.html` 未能提取 `cid/vid` 导致自动映射不落库的问题
- 新增实测映射：芒果「你是迟来的欢喜」、优酷「师兄太稳健」「以法之名」、哔哩「开心锤锤」
- 清理 `tests/run_test.php` 中 PHP 8.5 `curl_close` 弃用告警，回归 8/8 通过

---

## ✨ 功能特性

- 🧵 **多线程资源站搜索**：基于 `curl_multi` 同时并发请求后台全部资源站，耗时≈最慢站点，而非站点数量×单站耗时
- 🎯 **官方链接解析**：支持 腾讯视频 / 爱奇艺 / 优酷 / 芒果TV / 哔哩哔哩 / PPTV 等主流平台
- 🕷️ **页面自动抓取**：链接无法直接解析出剧名/集数时，自动访问官方播放页抓取《剧情 第N集》并清洗出剧名（含无效标题校验）
- 🗺️ **映射表**：`vid/cid → 剧名+集数` 精确映射，链接自带集数时可自动识别（如庆余年第2集）
- 🧩 **资源站模板**：`%s / %u / %t / %p` 占位符，兼容 JSON / JSONP / 纯文本 等任意返回格式
- 📦 **文件缓存**：搜索结果缓存，减少对资源站的重复请求
- 🛠️ **可视化后台**：资源站管理、映射管理、系统设置、一键测试，无需改代码
- 🕰️ **定时自动采集映射**：`cron/mapping.php` 按周期自动补全官方链接映射 + 盘点资源站库存，省去手动（详见对应章节）
- ⛑️ **后台帮助**：内置资源站「映射要求」与「定时访问功能」使用文档
- 🤖 **一键测试 · AI 智能分析**：自动抓取官方页面识别剧名+集数，并**自动写入映射表**
- 🔄 **在线自动更新**：从 GitHub 多加速镜像自动测速选最快节点拉取最新代码，更新后权限 777
- 🔍 **资源站调用频率控制**：搜索节流 + 心跳探测 + 轮训分批，降低单站调用频率、防被屏蔽（可在「设置」页配置并查看健康状态）
- 🔌 **JSONP 支持**：`callback` 参数，方便前端跨域调用

---

## 📁 目录结构

```
mxgj/
├── index.php               # 前台解析 API（入口）
├── admin.php               # 后台管理（登录后可配置）
├── update.php              # 独立升级入口（update.php?key=升级密钥）
├── cron/
│   └── mapping.php         # 定时自动采集映射（cron，可配 crontab）
├── lib/
│   ├── bootstrap.php       # 公共引导 / JSON 文件读写 / 设置加载 / 自动写映射
│   ├── LinkParser.php      # 官方链接解析（平台 / vid / cid / 集数）
│   ├── PageResolver.php    # 官方页面自动抓取（AI 智能识别剧名+集数）
│   ├── SiteSearcher.php    # 多线程资源站搜索（curl_multi + 苹果CMS）
│   ├── SiteHealth.php      # 资源站频率控制（搜索节流/心跳/轮训）
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

## 🔍 资源站调用频率控制（搜索 / 心跳 / 轮训）

为避免频繁/密集调用资源站而被屏蔽或禁用，可在后台 **设置** 页配置三项策略（`lib/SiteHealth.php`）：

| 策略 | 配置项 | 作用 |
| ---- | ------ | ---- |
| 搜索频率 | `search_interval` | 同一资源站两次实际调用最短间隔；间隔内再次需该站则跳过（依赖结果缓存），限制单站 QPS |
| 心跳频率 | `heartbeat_interval` / `heartbeat_max_fail` / `cooldown_seconds` | 周期并发探测各站可达性；连续失败 N 次自动禁用，冷却期后自动恢复重试；只请求存活站点 |
| 轮训 | `rotation_interval` / `max_sites_per_request` | 轮训周期推进命中顺序（round-robin）；可限制每次最多并发请求几个站，分散压力 |

**推荐配置**（具体值，兼顾可用性与防屏蔽）：

```
search_interval        = 15    (秒，同一站约每 15 秒最多 1 次)
heartbeat_interval     = 600   (秒，每 10 分钟探测一次可达性)
heartbeat_timeout      = 5     (秒)
heartbeat_max_fail     = 3     (连续失败 3 次自动禁用)
cooldown_seconds       = 1800  (秒，30 分钟后自动恢复重试)
rotation_interval      = 600   (秒，每 10 分钟切换一次命中顺序)
max_sites_per_request  = 4     (每次最多并发 4 个站)
```

运行状态存于 `data/site_health.json`，后台 **设置 → 资源站健康状态** 可实时查看并「立即心跳探测 / 重置健康状态」。

## 🕰️ 定时自动采集映射（cron）

新增 `cron/mapping.php`，按周期自动做两件事，省去手动一条条添加映射：

1. **官方种子链接补全**：遍历 `config/settings.json → cron → seed_links`，
   对尚未映射的官方链接自动抓取「剧名+集数」并写入映射表（已存在则跳过、不覆盖人工配置）。
2. **资源站库存盘点**：并发拉取各资源站最新采集列表，把「在库剧名 + 集数」写入 `mapping.json → stock`。

### ① 配置种子链接

编辑 `config/settings.json` 的 `cron.seed_links`，放入想追踪的官方剧集链接
（支持腾讯/爱奇艺/优酷/芒果/哔哩哔哩等）：

```json
{
  "cron": {
    "key": "",
    "interval_mins": 60,
    "scan_sites": true,
    "seed_links": [
      "https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce",
      "https://www.iqiyi.com/v_19hly1wd1gg.html",
      "https://m.youku.com/alipay_video/id_fcad042e84ef43ce8309.html"
    ]
  }
}
```

> `key` 为空时自动回退用 `updater_key`，再回退 `admin_password`。

### ② 使用方式（任选其一）

- 手动/浏览器**预览**（不写库）：

  ```bash
  php cron/mapping.php key=你的定时密钥 dry=1
  # 或
  curl -s "http://你的域名/cron/mapping.php?key=你的定时密钥&dry=1"
  ```

- Linux crontab 定时（每小时一次，`quiet` 抑制明细输出，适合 crontab）：

  ```
  0 * * * *  php /你的绝对路径/cron/mapping.php key=你的定时密钥 quiet
  ```

- 或通过 URL 定时：

  ```bash
  curl -s "http://你的域名/cron/mapping.php?key=你的定时密钥" >/dev/null
  ```

每次运行都会追加记录到 `data/cron_mapping.log`（最多保留 200 条），
完整使用说明也内置在 **后台 → 帮助 → 定时访问功能**。

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
