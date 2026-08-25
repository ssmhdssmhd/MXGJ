<div align="center">

# 🎬 沫兮官替系统 (MXGJ)

**官方视频链接 → 多线程资源站搜索 → 真实 m3u8 直出** · 一款开箱即用的「官代/官替」媒体解析系统

![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF?logo=php&logoColor=white)
![Version](https://img.shields.io/badge/Version-v1.7.0-4f7cff)
![License](https://img.shields.io/badge/License-MIT-22a06b)
![Storage](https://img.shields.io/badge/Storage-No--DB-2ecc71)
![Platform](https://img.shields.io/badge/腾讯·爱奇艺·优酷·芒果·哔哩·PPTV-888)

输入任意官方播放链接（腾讯 / 爱奇艺 / 优酷 / 芒果TV / 哔哩哔哩 / PPTV），
系统自动解析出 **剧名 + 集数**，然后**并发**向后台配置的全部资源站搜索对应资源，
命中后以 JSON 返回 `code=200` 与**可直接播放的 m3u8 地址**。

> 🎯 无数据库 · 纯 JSON 文件存储 · 零依赖部署，放上 PHP 环境即可用

</div>

---

## 📌 更新说明（置顶 · 最新在最上）

<details open>

<summary>查看历史更新（点击折叠）</summary>

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
| 📦 缓存 | 搜索结果文件缓存，降低对资源站的重复请求 |
| 🕰️ 定时采集 | `cron/mapping.php` 自动补全映射 + 盘点资源站库存，省去手动 |
| 🔍 频率控制 | 搜索节流 + 心跳探测 + 轮训分批，防被资源站屏蔽 |
| 🔄 在线更新 | GitHub 多加速镜像自动测速选最快节点拉取，更新后权限 777 |
| 🛠️ 可视化后台 | 资源站 / 映射 / 设置 / 帮助 / 一键测试，全部免改代码 |
| 🔌 开放接口 | `key` 鉴权 + `callback` JSONP + CORS，方便前端跨域调用 |

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
【主流程】腾讯链接 → 庆余年第2集
  请求耗时: 1.21s (阈值 2.2s)      ← 两站各延迟1.2s，串行约2.4s，证明多线程并发
  [PASS] 返回 code=200
  [PASS] url 命中 1080p 资源(第2集)
  ...
结果: 8 通过 / 0 失败
```

---

## 📈 版本与更新

- 更新日志：[CHANGELOG.md](CHANGELOG.md)
- 版本信息：[version.json](version.json)

---

## 📜 免责声明

本项目仅用于**学习与接口调用演示**。请遵守国家法律法规及各大视频平台/资源站的服务条款，
**切勿**用于盗版、侵权内容的传播。因使用本项目产生的任何法律风险由使用者自行承担。