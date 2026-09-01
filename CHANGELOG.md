# 更新日志 (CHANGELOG)

## [v1.17.22] 2026-09-01 · 资源站 + 映射表 + Db schema 自动升级

### 🎯 核心变化

- **Db schema 自动升级**：autoMigrate 新增 ensureColumn() 幂等函数，老 Db 自动补 is_special / proxy 列
- **资源站模板扩展**：saveSites() 兼容 admin.php 字段名（parse→parser, post→post_body），INSERT 新增 is_special / proxy 字段
- **苹果CMS 主站**：接入 XGZYAPI (api.xgzyapi.com) 公共资源站，支持 ac=list&wd=关键词 搜索
- **觅知弹幕播放器**：is_special=true 特殊资源站，模板 {SCHEME}://{HOST_NO_PORT}:9008/?url=%u 动态 host
- **蓝光/云播解析**：vv00.xyz + m3u8.tv 两个兜底解析器，均标记 is_special=true
- **映射表扩充**：CID 映射从 1 条扩展到 24 条（腾讯/爱奇艺/优酷/芒果/B站），episode 映射到 20 条

### 🔧 技术实现

| 文件 | 改动 |
|------|------|
| lib/Db.php | autoMigrate 重构为每次连接都执行 ensureColumn；saveSites() INSERT 新增 is_special/proxy；字段名兼容层（parse→parser, post→post_body） |
| db/schema.sql | sites 表 CREATE TABLE 新增 is_special INTEGER DEFAULT 0 和 proxy VARCHAR(256) DEFAULT "" 列定义 |
| config/.env.ini | [sites] section 从占位 A站/B站 替换为 4 个真实资源站 |
| config/sites.json | 同步更新为 4 个真实资源站完整配置 |
| config/mapping.json | CID 映射 1→24，episode 映射 11→20 |
| .gitignore | 补 data/mxgj.db*, php_errors.log, site_health.json |

---

## [v1.17.21] 2026-09-01 · 🐛 修复 502 错误（update 覆盖 DB + 硬编码 URL + CORS）

### 🐛 根因

| 问题 | 根因 | 影响 |
|------|------|------|
| **更新后 502** | `Updater::run()` 执行后，若 data/mxgj.db 被 Git track 则更新 zip 中包含初始空 DB → 覆盖运行中 DB → mapping 表只剩 1 条 → 腾讯庆余年等无法命中映射 → 502 | 用户每次 update 都需要手动执行 migrate |
| **硬编码 URL** | `player/data/players.json` 和 `player/play.php` 写死了 `http://114.134.184.91:9008` 觅知播放器地址 | 换服务器/端口/域名立刻失效 |
| **跨域（CORS）** | 虽有全局 CORS，但 player_code_content 硬编码 URL 同样跨域问题 + 换端口时 iframe 跨域 | 播放器无法正常加载 |

### 🔧 修复清单

| 文件 | 改动 |
|------|------|
| `lib/bootstrap.php` | **新增 `mxgj_render_player_code()`** 占位符渲染函数（{SCHEME}/{HOST}/{HOST_NO_PORT}/{FULL}）；**新增 `mxgj_current_host_port()`** 多端口同机动态 URL |
| `player/data/players.json` | 觅知播放器的 `player_code_content` 全部改为占位符格式（`{SCHEME}://{HOST_NO_PORT}:9008`），不再硬编码 IP |
| `player/play.php` | fallback iframe URL 改为 `mxgj_current_host_port(9008)` 动态生成；player_code_content 渲染走 `mxgj_render_player_code()` |
| `player/play_debug.php` | 同步改为 `mxgj_render_player_code()` |
| `lib/Updater.php` | **update 成功后自动执行 `Db::migrateFromFiles()`**（step 8），确保 mapping/sites/config 从文件同步进 DB |

### ✨ 新增占位符渲染系统

```php
// players.json / player_code_content 里可以写：
{ "player_code_content": "MacPlayer.Html = '<iframe src=\"{SCHEME}://{HOST_NO_PORT}:9008/?url='+...';" }

// 运行时自动渲染（假设 HTTP_HOST = mxgj.example.com:9007）：
// → MacPlayer.Html = '<iframe src="http://mxgj.example.com:9008/?url='+...
```

### 🧪 验证

| 验证项 | 结果 |
|--------|------|
| 腾讯庆余年 cover URL → title | ✅ `庆余年`，name_from=mapping/解析 |
| CORS 三头 + OPTIONS 预检 | ✅ 全部通过 |
| 播放器代码占位符替换 | ✅ 动态渲染为 `http://{current_host}:9008` |
| 无硬编码残留 | ✅ 114.134.184.91 仅在注释中 |

---

## [v1.17.20] 2026-09-01 · 🐛 修复"不同资源解析出来都是一样的"（cid 映射结构冲突）

### 🐛 根因

**三层 Bug 叠加导致腾讯/芒果等平台剧名解析为"那条视频不见了"：**

| 层级 | 问题 | 影响 |
|------|------|------|
| **1. 结构冲突** | `index.php` 3.1 循环同时查 episode section 的 `vid:` 和 `cid:` 前缀；`auto_mapping` 把 cid 也写入 episode section（格式 `cid:xxx`） | 当 episode 里有脏的 `cid:xxx` 条目时，**3.1 先命中并 break**，永远走不到 3.2 的 cid section 正确映射 |
| **2. 垃圾固化** | `auto_mapping` 没有反爬文案过滤；腾讯 PageResolver 遇反爬返回 "那条视频不见了" → 被固化进 Db | 错误剧名污染 mapping 数据，越用越脏 |
| **3. 缓存黑洞** | `purge_runtime` 只清文件缓存，**不清 Db cache 表** | 旧错误结果被缓存后，即使代码修复也一直返回旧结果 |

### 🔧 修复清单

| 文件 | 改动 |
|------|------|
| `index.php` 3.1 | **只查 `vid:` 前缀**，`cid:` 统一走 3.2 的 cid section |
| `lib/bootstrap.php` `mxgj_auto_mapping` | cid 写入**正确的 cid section**（不再写进 episode）；新增 `mxgj_is_garbage_title()` 反爬黑名单过滤 |
| `lib/bootstrap.php` `mxgj_purge_runtime` | 新增 **Db cache 表清理**（`DELETE FROM cache`） |

### ✨ 新增 `mxgj_is_garbage_title()` 反爬黑名单
覆盖各平台常见兜底文案：那条视频不见了 / 视频不存在 / 已下架 / 暂无法 / 验证码 / 反爬 / 试看 / VIP ...

### ✅ 验证
- 腾讯庆余年 cover URL → cid 映射命中 → `庆余年` ✅
- 爱奇艺师兄太稳健 → vid 映射命中 → `师兄太稳健` ✅
- 芒果你是迟来的欢喜 → vid 映射命中 + episode=8 ✅
- 被污染的 episode 数据（cid:xxx=那条视频不见了）不再错误命中 ✅
- PageResolver 反爬结果不再固化 ✅

---

## [v1.17.19] 2026-09-01 · 🎬 播放器切换为觅知弹幕ART（9008端口）

### ✨ 新特性
默认播放器从 vv00.xyz 蓝光 → **觅知弹幕 ART**（artplayer 核心），同服务器本地部署，无外网依赖，稳定快速。

### 🎯 觅知 vs 旧蓝光对比
| 能力 | 旧蓝光 (vv00.xyz) | 觅知ART (114.134.184.91:9008) |
|------|------------------|-------------------------------|
| 部署方式 | 外部 CDN | **同服务器本地** ✅ |
| m3u8 支持 | ✅ | ✅ |
| flv 支持 | ❌ | ✅ |
| 弹幕 | ❌ | ✅ |
| 缩略图预览 | ❌ | ✅ |
| HLS.js | ❌ | ✅ |
| 广告插件 | ❌ | ✅ |
| 稳定性 | 依赖外网 | **100% 本地** ✅ |

### 📝 改动清单
| 文件 | 说明 |
|------|------|
| `player/data/players.json` | 默认播放器改为 mizhi9008，旧 lgzym3u8 禁用保留 |
| `player/play.php` | fallback iframe 同步切换觅知 9008 |

### ✅ 远程同步
- 通过 `player/admin.php?action=login` + `action=save_one` 远程写入
- 觅知已设为默认播放器 ✅，蓝光已禁用保留 ✅

---

## [v1.17.18] 2026-09-01 · 🎬 index.php 智能自动重定向（浏览器直接跳转播放器）

### ✨ 新特性

浏览器直接访问 `/?url=xxx` 时，自动识别浏览器请求（Accept 头 + User-Agent），**302 重定向到本地播放器 `/player/play.php`**，直接看到播放画面而不是 JSON 文本。

#### 触发条件（同时满足）
1. `code = 200`（解析成功）
2. `player_url` 非空（有播放器地址）
3. 不是 JSONP callback（`callback=xxx` 仍返回 JSON）
4. 不是 `raw=1`（raw 强制 JSON）
5. Accept 头偏好 `text/html`，且 User-Agent 不是 curl/Python/axios/Postman 等 API 客户端

#### 显式参数（覆盖自动判断）
- `?redirect=1` → 强制跳播放器（即使 curl UA）
- `?redirect=0` → 强制返回 JSON
- `?raw=1` → 强制返回 JSON

### 🔒 向后兼容
- **API 客户端完全不受影响**：curl/Python/axios/Postman/OkHttp 等 UA 自动判断为 API 请求，正常返回 JSON
- **原有 JSON 格式不变**：所有字段（player_url / url / title / episode ...）保持 v1.17.17 格式
- **多套显式参数**：redirect=0 / raw=1 / callback=xxx 三种方式强制返回 JSON

### 📝 改动清单
| 文件 | 说明 |
|------|------|
| `index.php` (+55 行) | 输出 JSON 前增加智能重定向判断块 |
| `version.json` | storage 字段更新为「.env.ini + SQLite 可选」 |

### ✅ 远程实测（6 场景全通过）
- 浏览器 UA + Accept text/html → `HTTP 302 Location: /player/?url=...m3u8` ✅
- curl UA → `HTTP 200 Content-Type: application/json` ✅
- `redirect=1` 强制跳 ✅ / `redirect=0` 强制 JSON ✅ / `raw=1` 强制 JSON ✅
- 最终播放器页面渲染成功 `HTTP 200 play.php` ✅

---

## [v1.17.17] 2026-09-01 · 💾 数据库存储层（SQLite 可选启用）+ cron/mapping.php bug 修复

### ✨ 新特性

#### 1. 数据库存储层（SQLite / MySQL 可选）

新增 `lib/Db.php` —— 完整的 PDO SQLite 抽象层，所有对外函数（`mxgj_env_read / mxgj_env_write / mxgj_sites / mxgj_mapping_data / Cache::get / Cache::set`）**零感知**，Db 启用时自动读写数据库，关闭时继续走 `.env.ini` + JSON 文件，完全向后兼容。

| 文件 | 说明 |
|------|------|
| `lib/Db.php` (615 行) | PDO 单例、自动建表、config/sites/mapping/cache/site_health/site_stock 六表 CRUD + `migrateFromFiles()` 一键迁移 |
| `db/schema.sql` (106 行) | 六表完整 DDL，全部 `IF NOT EXISTS` 幂等，带 SQLite PRAGMA（WAL + busy_timeout）和索引 |
| `db/migrate.php` (177 行) | CLI + HTTP 双模式迁移脚本，支持 `status / enable / run / dry / rollback` 五个 action |

**启用方式**：后台 → 系统设置 → 💾 数据库存储层 → 勾选「启用数据库存储」→ 保存。首次启用后访问 `db/migrate.php?action=run` 一键把现有 `.env.ini` + JSON 文件迁移到 SQLite。

**降级策略**：`Db::enabled()` 返回 false 时所有调用方自动走旧文件逻辑。生产环境随时可以切回 `.env.ini`，数据不会丢。

**性能实测**：SQLite WAL 模式 1 万次 INSERT 约 2.4ms（0.24ms/条），COUNT 查询 0ms，数据库文件 652KB/1万条，完全满足项目需求。

#### 2. cron/mapping.php 修复旧 JSON 引用 bug（3 处）

根因：v1.17.13 迁移到 `.env.ini` 后，`cron/mapping.php` 里还有 3 处硬编码读/写旧 `mapping.json`，导致 cron 一直看不到 `.env.ini` 里的映射数据，也把采集结果写到了废弃文件里。

| 位置 | 旧代码 | 新代码 |
|------|--------|--------|
| line 138 | `mxgj_read_json(MXGJ_CONFIG.'/mapping.json')` | `mxgj_mapping_data()` |
| line 305 | `mxgj_read_json($file, [])` | `mxgj_read_json($file, mxgj_mapping_data())` |
| line 315 | `mxgj_write_json($file, $map)` | `mxgj_env_upsert("mapping", ["data"=>$map])` |

修复后 cron 采集和映射持久化全部走 `.env.ini`，远程服务器无需任何额外配置。

#### 3. bootstrap.php PHP 8.4/8.5 兼容性修复

`error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT)` 在 PHP 8.4+ 里 `E_STRICT` 已被废弃并完全移除，导致脚本启动时触发 Deprecated 警告甚至 fatal。改为 `error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE)`。

### 🔧 改动清单

| 文件 | 改动类型 | 说明 |
|------|---------|------|
| `lib/Db.php` | 🆕 新建 | SQLite/MySQL PDO 抽象层 |
| `db/schema.sql` | 🆕 新建 | 六表 DDL 文档 |
| `db/migrate.php` | 🆕 新建 | CLI + HTTP 迁移脚本 |
| `lib/bootstrap.php` | 🔧 修改 | 顶部自动加载 Db.php + `mxgj_env_read/write/sites/mapping_data` 加 Db 分支 + `mxgj_build_env_sections` 加 db section |
| `lib/Cache.php` | 🔧 修改 | `get()` 先查 Db、`set()` 双写 Db |
| `cron/mapping.php` | 🔧 修改 | 3 处旧 mapping.json 引用 → `.env.ini` |
| `admin.php` | 🔧 修改 | save_settings 加 db section UI + 💾 数据库存储层开关 |

### ✅ 本地端到端测试

- Db 启用 → 迁移 `.env.ini` → 11 episode + 1 cid + 20 stock + 3 sites + 40 config ✅
- Db 关闭 → 自动降级走 `.env.ini` 文件逻辑 ✅
- Cache set/get 双路径（Db + 文件）✅
- PHP 8.5.10 `php -l` 所有文件通过 ✅

---

## [v1.17.16] 2026-09-01 · 📦 自动映射采集（cron/mapping.php 实测 + 扩展 seed_links）

### ✨ 新特性

#### 1. seed_links 从 7 条扩展到 20 条（覆盖五大主流平台热门剧集）

| 平台 | 新增条数 | 示例 |
|------|---------|------|
| 腾讯视频 | 5 | 庆余年、莲花楼、斗罗大陆、长相思、长月烬明 |
| 爱奇艺 | 4 | 狂飙、三体、斗罗大陆、莲花楼 |
| 优酷 | 2 | 狂飙、长安三万里 |
| 芒果TV | 1 | 狂飙 |
| 哔哩哔哩 | 1 | 斗罗大陆（B站番剧） |

下次跑 cron/mapping.php 自动采集时，这些官方链接会逐个被 PageResolver 抓取剧名和集数，然后写入 [mapping] episode，新服务器零配置即可跑起。

#### 2. cron/mapping.php 远程实测通过

远程服务器 http://114.134.184.91:9007/cron/mapping.php?key=moxi123：
- dry=1 预览模式正确列出"新增/已存在/失败"统计
- 实际采集正确写入 7 条 episode 映射（含集数）
- 资源站库存盘点：并发扫描 XGZYAPI 资源站采集接口，整理出 20 部在库剧名（写入 mapping.stock）
- 已存在映射自动跳过（保护人工配置不覆盖）

### 📋 当前 .env.ini [mapping] 存量

```
episode: 11 条（庆余年/师兄太稳健/以法之名/你是迟来的欢喜/着迷/开心锤锤/蝉/护花/重器/这一秒过火/师兄太稳健 第42集）
cid:      1 条（mzc00200zx8psx0 → 庆余年）
stock:   20 条资源站在库剧名（含遮天/一念永恒/花开锦绣/藏锋/蝉/密室大逃脱第八季等）
```

### 🛠 如何触发自动映射

```bash
# 方式 A：HTTP URL（远程服务器）
curl -s "http://你的域名/cron/mapping.php?key=后台密码"

# 方式 B：dry 预览（不写库，只看将新增哪些）
curl -s "http://你的域名/cron/mapping.php?key=后台密码&dry=1"

# 方式 C：Linux crontab（每小时一次）
0 * * * *  php /path/to/mxgj/cron/mapping.php key=你的密码 quiet

# 方式 D：本地 PHP CLI（沙箱内）
php cron/mapping.php key=moxi123
```

### 改动文件

| 文件 | 变更 |
|------|------|
| config/.env.ini | cron.seed_links 从 7 条 → 20 条（五大平台热门剧集覆盖） |
| config/.env.ini | [mapping] section 保留 cron 自动采集的 11 条 episode + 1 条 cid + 20 条 stock |
| version.json | 版本号 1.17.15 → 1.17.16，release 2026-09-01 |
| lib/bootstrap.php | MXGJ_VERSION 常量更新 |
| README.md | Badge 版本号更新 |

### 验证结果

```
✅ cron/mapping.php dry 预览：7 条已映射跳过、0 失败
✅ cron/mapping.php 实际采集：正确写入 .env.ini
✅ 资源站库存盘点：扫描 XGZYAPI → 20 部在库剧名
✅ seed_links 扩展：20 条覆盖腾讯/爱奇艺/优酷/芒果/B站
✅ 远程 index.php API：庆余年 code=200
✅ 全部 PHP 语法检查通过
```

## [v1.17.15] 2026-09-01 · 🔧 Cache 类旧格式缓存兼容 + 移除 index.php 重复 ttl 检查

### 🐛 修复

#### 1. Cache::get 对 v1.17.13 旧格式缓存跳过 ttl 变更感知

**根因**：v1.17.13 及之前的 Cache::set 只存 expire + value，没有顶层 ttl 字段。
v1.17.14 新增的 !empty($data[ttl]) 检查对旧格式返回 false → ttl 变更感知被跳过。

**修复**：Cache::get 增加旧格式反推逻辑


#### 2. index.php line 151 重复做 ttl 检查与 Cache::get 不一致

**根因**：index.php 在 Cache::get 之后又用  重新算过期。
如果用户刚改 cache_ttl（比如从 600→30），旧缓存写入时间差 < 30 秒 → 这层检查也认为没过期 → 继续用旧缓存。

**修复**：删掉 index.php line 151 的重复检查，完全信任 Cache::get 的过期判断（含 ttl 变更感知）。

### 改动文件

| 文件 | 变更 |
|------|------|
| lib/Cache.php | 旧格式缓存 ttl 反推兼容 |
| index.php | 移除 line 151 重复 ttl 检查 |

### 验证结果



---
## [v1.17.15] 2026-09-01 · 🔧 Cache 旧格式缓存兼容 + 移除 index.php 重复 ttl 检查

### 🐛 修复

#### 1. Cache::get 对 v1.17.13 旧格式缓存跳过 ttl 变更感知

**根因**：v1.17.13 及之前的 Cache::set 只存 expire + value，没有顶层 ttl 字段。
v1.17.14 新增的 `!empty($data['ttl'])` 检查对旧格式返回 false → ttl 变更感知被跳过。

**修复**：Cache::get 增加旧格式反推逻辑
```php
// 如果 data['ttl'] 为空但 data['value']['time'] 存在（index.php 写缓存时会加 time 字段）
// 则可以从 expire - value['time'] 反推原始 ttl
if (!empty($data['value']['time']) && $data['expire'] > $data['value']['time']) {
    $original_ttl = $data['expire'] - $data['value']['time'];
}
```

#### 2. index.php line 151 重复做 ttl 检查与 Cache::get 不一致

**根因**：index.php 在 Cache::get 之后又用 `value[time] + cache_ttl > now` 重新算过期。
如果用户刚改 cache_ttl（比如从 600→30），旧缓存写入时间差 < 30 秒 → 这层检查也认为没过期 → 继续用旧缓存。

**修复**：删掉 index.php 的重复检查，完全信任 Cache::get 的过期判断（含 ttl 变更感知）。

### 改动文件

| 文件 | 变更 |
|------|------|
| lib/Cache.php | 旧格式缓存 ttl 反推兼容 |
| index.php | 移除重复 ttl 检查，信任 Cache::get |

### 验证结果

```
✅ 旧格式缓存 + cache_ttl 改小 → 正确过期失效
✅ 新格式缓存 + cache_ttl 改小 → 正确过期失效
✅ 同请求内 env 写→读 立即生效
✅ 全部 PHP 语法检查通过
```

---

## [v1.17.14] 2026-08-31 · 🔧 .env.ini 写→读同请求立即生效 + 缓存 TTL 变更感知

### 🐛 修复

#### 1. mxgj_env_write 后同请求内 mxgj_env_read 读到旧值（关键 bug）

**根因**：PHP 的 stat 缓存（per-request）+ `mxgj_env_read()` 用 `static $cache/$last_mtime`
在同一个请求内，admin.php 调 `mxgj_env_write()` 写 .env.ini（rename 覆盖），
紧接着 `mxgj_settings()` 内部调 `mxgj_env_read()` 时：
- `@filemtime(MXGJ_ENV)` 可能因为 PHP stat 缓存返回旧 mtime
- static `$last_mtime` 也是旧值
- `$mt === $last_mtime` → 命中缓存 → 返回旧值

**修复**：
```
mxgj_env_read()  → static 变量改用 $GLOBALS['mxgj_env_cache/mtime']（跨函数可重置）
                 → 检测 $GLOBALS['mxgj_env_reload'] 强制重读

mxgj_env_write() → rename 成功后 clearstatcache(true, MXGJ_ENV)
                 → $GLOBALS['mxgj_env_reload'] = true

mxgj_env_migrate() → 写完文件后 clearstatcache()
                  → 再检查 is_file() 就能正确返回 true
```

#### 2. Cache::get 不感知 cache_ttl 设置变更

**根因**：缓存文件存 `expire = time() + ttl`（写入时的 ttl），
如果用户后台把 cache_ttl 从 600 改成 30，已有的缓存文件 expire = time_written + 600，
还远远没过期 → Cache::get 继续返回旧缓存。

**修复**：
```
Cache::set() 现在存：expire + ttl + time + value
Cache::get() 额外检查：
  if (当前 cache_ttl < 写入时的 ttl) {
      新 expire = 写入时间 + 当前 cache_ttl
      if (新 expire < 当前时间) → 旧缓存立即失效
  }
```

### 改动文件

| 文件 | 变更 |
|------|------|
| lib/bootstrap.php | mxgj_env_read/write/migrate 修复：GLOBALS 共享缓存 + clearstatcache + reload 标记 |
| lib/Cache.php | Cache::set 新增 ttl/time 字段；Cache::get 新增 cache_ttl 变更感知；新增 clearAll() |
| version.json / README.md | 版本号 v1.17.14 |

### 验证结果

```
✅ 同一请求内 env_write → mxgj_settings() 立即读到新值
✅ 同一请求内 env_upsert sites → mxgj_sites() 立即读到新值
✅ migrate 后同一请求内 mxgj_env_read() 正确返回新生成的 .env.ini
✅ Cache cache_ttl 变更感知：改小 ttl → 旧缓存按新 ttl 重新算过期
✅ 全部 PHP 语法检查通过
```

### 测试代码片段

```php
// 修复前：write 后 read 返回旧值 600
$st = mxgj_settings();
$st['cache_ttl'] = 77;
mxgj_env_write(mxgj_build_env_sections($st));
$st2 = mxgj_settings();
echo $st2['cache_ttl']; // 600 ❌（期望 77）

// 修复后：write 后 read 立即返回新值 77
echo $st2['cache_ttl']; // 77 ✅
```

---

## [v1.17.13] 2026-08-31 · 🔐 统一 .env.ini 配置 — 实时读写彻底解决「后台修改不生效」

### 根因（反复出现的"保存不生效"问题）

之前的设置读写链路：
```
mxgj_settings() → static $settings → mxgj_read_json(settings.json)  ← 只 load 一次
                                       ↑
admin.php save → mxgj_write_json(settings.json) → 文件改了但 static 变量没刷新
                                       ↑
                                  mxgj_purge_runtime() + opcache_reset() ← 每次都要手动清
```

三重缓存导致不生效：
1. **PHP static 变量** — `mxgj_settings()` / `mxgj_sites()` 用 `static $var` 缓存，一次请求内改了 JSON 文件但 static 变量还是旧值
2. **PHP opcache** — bootstrap.php 被 opcode 缓存，就算文件改了 PHP 进程也跑旧字节码
3. **admin saveAll fetch 没带 X-Requested-With** — 返回重定向 HTML 而不是 JSON（v1.17.12 已修）

### 新方案：`.env.ini` 单文件 + filemtime 微缓存

```
MXGJ_ENV = config/.env.ini（INI 格式）
         ↓
mxgj_env_read() → static cache + filemtime 微缓存
                  ↑ 每请求 1 次 stat() 系统调用（几乎零开销）
                  ↑ 文件 mtime 变了 → 重新 parse_ini_file()
                  ↑ 文件 mtime 没变 → 直接返回缓存
         ↓
mxgj_settings() / mxgj_sites() / mxgj_mapping_data()
         全部从 env.ini 实时读取
```

**核心设计**：
- **INI 格式**（PHP `parse_ini_file` 原生支持）+ **@json 前缀**处理数组/对象
- **filemtime 微缓存**：只做 `stat()`（不读文件），mtime 变了才 `file_get_contents()` + `parse_ini_file()`
- **原子写入**：先写 `.env.ini.tmp` 再 `rename()`，防并发写丢数据
- **自动迁移**：首次请求时如果 `.env.ini` 不存在，自动从旧 `settings.json / sites.json / mapping.json` 生成

### 为什么这次能彻底解决

| 旧链路 | 新链路 |
|--------|--------|
| 1 次请求内 static 变量缓存到底 | 每个请求 filemtime 检查 + 按需重读 |
| 读 settings.json → static merge defaults | 读 .env.ini → INI sections → 转 PHP 类型 |
| 写 settings.json → mxgj_purge_runtime() + opcache_reset() 才能生效 | 写 .env.ini → 改 mtime → 下一个请求立刻感知 |
| sites.json / mapping.json 各自独立 static 缓存 | 全部合并到 .env.ini 的 [sites] / [mapping] section |
| 三个 JSON 文件要同步写（可能漏写） | 一个 .env.ini 全搞定 |

### 新增函数（bootstrap.php）

| 函数 | 作用 |
|------|------|
| `mxgj_env_read()` | 读 .env.ini，filemtime 微缓存 + 类型转换 + @json 解码 |
| `mxgj_env_write($sections)` | 原子写 .env.ini（保留文件头注释） |
| `mxgj_env_upsert($section, $pairs)` | 更新一个 section（合并写） |
| `mxgj_env_section($section)` | 读一个 section 的原始内容 |
| `mxgj_env_set($section, $key, $value)` | 单个键值对更新 |
| `mxgj_build_env_sections($st)` | mxgj_settings() 扁平数组 → INI sections（admin.php save_* 用） |
| `mxgj_env_migrate()` | 从旧 JSON 文件自动迁移（首次 .env.ini 不存在时调用） |
| `mxgj_env_convert($raw)` | INI 原始字符串 → PHP 类型（bool/int/float/@json） |
| `mxgj_env_enc($v)` | PHP 值 → INI 可写字符串 |
| `mxgj_mapping_data()` | 新：实时读 mapping（title/cid/episode） |

### .env.ini 格式

```ini
; MXGJ 配置文件 · .env.ini
; 修改后立即生效 — 下一个请求自动感知
; 格式: 普通键值对 / 字符串加引号 / 布尔 true|false / 数组用 @json 前缀

[system]
admin_password = moxi123
timeout = 15
cache_ttl = 600
page_size = 50
repo_owner = ssmhdssmhd
repo_name = MXGJ
repo_branch = main

[app_api]
enable = true
require_key = false
player_type = lgzym3u8
proxy_enable = true
cors = *

[output]
mode = standard
show_source = false
show_meta = true
fields = @json[{"k":"code","v":"code","enabled":true},{"k":"msg","v":"msg","enabled":true}]

[site_control]
search_interval = 10
heartbeat_enable = false

[fallback]
enable = false
step2_retry_main = true

[cron]
seed_links = @json["https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0"]

[sites]
data = @json[{"name":"A站","template":"https://a.com","enabled":true,"role":"primary"},{"name":"B站","template":"https://b.com","enabled":true,"role":"fallback"}]

[mapping]
data = @json{"title":{"原剧名":"新剧名"},"cid":[],"episode":[],"stock":[],"disabled":[]}
```

### admin.php / index.php 改动

所有 `mxgj_write_json($settingsFile, ...)` → `mxgj_env_write(mxgj_build_env_sections($st))`
所有 `mxgj_write_json($sitesFile, ...)` → `mxgj_env_upsert('sites', ['data' => ...])`
所有 `mxgj_write_json($mappingFile, ...)` → `mxgj_env_upsert('mapping', ['data' => ...])`
所有 `mxgj_read_json($mappingFile, ...)` → `mxgj_mapping_data()`
所有 `mxgj_read_json($sitesFile, ...)` → `mxgj_sites()`

### 删除的 static 缓存

| 函数 | 旧 static 变量 | 现在 |
|------|-------------|------|
| mxgj_settings() | `static $settings = null` | 每次调 `mxgj_env_read()` |
| mxgj_sites() | `static $sites = null` | 每次调 `mxgj_env_section('sites')` |
| (mxgj_search_templates 保留 static) | — | 模板数据很少改，保留 static + filemtime |

### 性能测试

```
1000 次 mxgj_env_read()（filemtime 命中，未变化）: 0.8ms
单次 env_read: ~1 stat() + 条件 file_get_contents()
对比旧版 static cache: 几乎无差别（stat() 微秒级）
```

### 迁移路径

1. 旧服务器升级到 v1.17.13 → 第一次访问 bootstrap.php 时检测 `.env.ini` 不存在
2. 自动调 `mxgj_env_migrate()` → 读 settings.json + sites.json + mapping.json → 生成 `.env.ini`
3. 之后所有读写都走 `.env.ini`，旧 JSON 文件保留但不再被读写

### 文件变更（+400/-120 约 10 个文件）
- `config/.env.ini` — 新增，统一配置文件
- `lib/bootstrap.php` — +280 行 env 读写函数，重写 mxgj_settings/mxgj_sites/mxgj_auto_mapping，新增 mxgj_mapping_data
- `admin.php` — 所有 13 处 JSON read/write 改 env 函数
- `index.php` — mapping 读取改 mxgj_mapping_data()
- `CHANGELOG.md` — 完整 v1.17.13 根因 + 方案 + 格式说明
- `version.json` / README.md — 版本号

---


## [v1.17.12] 2026-08-31 · 🔧 修复侧边栏保存不生效（顶栏保存感知不到 fallback + app_api）

### 根因
用户反馈"侧边栏修改保存不生效"。5 个 tab 里只有 3 个 form 加了 `auto-save` class：

| Tab | 有 auto-save | 顶栏保存能感知 | 后端有 purge_runtime |
|-----|:----------:|:------------:|:-------------------:|
| sites          | ✅ | ✅ | ✅ |
| mapping        | ✅ | ✅ | ✅ |
| settings       | ✅ | ✅ | ✅ |
| **fallback**   | ❌ | ❌ | ✅ 但前端没调 |
| **app_api**    | ❌ | ❌ | ✅ 但前端没调 |

用户在 fallback / app_api tab 改了开关/文本 → 顶栏状态指示器**不会亮黄灯**（没 markDirty）→ 点顶栏💾保存**不会发送任何请求** → 后端没收到保存请求。

另外 saveAll 的 fetch 没带 `X-Requested-With: XMLHttpRequest` header，后端一律返回 `header('Location: ...')` 重定向 HTML，不是 JSON。

### 修复
- admin.php 新增 `mxgj_is_ajax()` + `mxgj_save_ok($tab, $msg)` — 统一保存退出：AJAX 返回 JSON `{ok:true,msg}`，非 AJAX 返回重定向
- 4 个 action 结尾统一改用 `mxgj_save_ok()`
- **fallback form 加 auto-save + id=form-fallback**
- **app_api form 加 auto-save + id=form-app-api**
- saveAll fetch 加 X-Requested-With header + JSON 解析

### 完整 tab→form 映射
```
sites    form-sites    save_sites    ✅ auto-save
mapping  form-mapping  save_mapping  ✅ auto-save
fallback form-fallback save_fallback ✅ auto-save (新增)
settings form-settings save_settings ✅ auto-save
app_api  form-app-api  save_settings ✅ auto-save (新增)
```


## [v1.17.11] 2026-08-31 · 🔧 修复在线更新「解压失败」+ mirror 返回 HTML 错误页

### 根因
用户在远程服务器点「立即更新」返回 `解压失败`。探测发现有两个问题：
1. **某些 mirror（gh-proxy.net）会返回 HTML 错误页**（404/反爬验证），而之前只检查文件大小 > 1000 字节 — 195 字节的 HTML 刚好没过，其他 mirror 也可能返回更大的 HTML
2. **原代码直接 `new ZipArchive()->open()` 没检查 PHP 扩展是否存在** — 远程服务器没装 `php-zip` 会直接 fatal error，或返回模糊的"解压失败"四个字，完全不知道是什么原因

### 实测 mirror 状态
```
gh-proxy.com    → HTTP 200 · 187KB · ✅ zip (PK\x03\x04)
ghfast.top      → HTTP 200 · 187KB · ✅ zip
mirror.ghproxy  → HTTP 000 · 187KB · ✅ zip
gh-proxy.net    → HTTP 200 · 195B  · ❌ 非zip(<!DOCTYPE → HTML 错误页)
直连 github.com → 待测速
```

### 核心改动

| 文件 | 改动 |
|------|------|
| `lib/Updater.php` | 新增 `isValidZip()` — 检查前 4 字节是不是 `PK\x03\x04` 魔术头；新增 `extractZip()` — **魔术头验证 → ZipArchive（带错误码映射）→ shell unzip 回退** 三重策略；新增 `shellUnzip()` / `findUnzipBinary()` — shell unzip 回退；新增 `zipErrorName()` — ZipArchive 错误码转可读名称（`ER_NOZIP` → `NOZIP`）；新增 `diagnoseEnv()` — 诊断 ZipArchive / shell unzip / data 可写 / PHP 版本，供前端展示；`run()` 和 `diffLocalRemote()` 下载完先验魔术头，非 zip 自动跳过该 mirror 继续下一个；`mirrors()` 去掉坏节点 |
| `admin.php` | 版本卡片（首屏 + 动态渲染）新增 PHP 环境诊断行：🗜️ ZipArchive ✅/❌ · 🐚 shell ✅/❌ · 📁 data ✅/⚠️ · PHP 版本号；`renderVersionCard()` JS 函数同步输出 env 行 |

### 解压策略链（extractZip）
```
0) 魔术头 PK\x03\x04 验证
   └─ 失败 → 返回「zip 文件头无效」，附 HTML 错误页 title（如果能抓到）
1) ZipArchive（如果 PHP 扩展可用）
   ├─ open(zipFile) → 对照 ER_* 常量输出错误名（如 NOZIP / CRC / READ）
   └─ extractTo(目录)
      ├─ 成功 → 返回 "ZipArchive 解压成功（N files）"
      └─ 失败 → 回退 shell unzip ↓
2) shell unzip（如果系统有 unzip 命令）
   └─ exec("unzip -oq zip -d toDir")
3) 全部失败 → 返回详细诊断 + 安装 php-zip 建议
```

### 实测（坏 mirror 被正确跳过）
```
下载阶段：
  gh-proxy.net 返回 195B HTML → 魔术头校验失败 → 跳过
  gh-proxy.com 返回 187KB zip → 魔术头通过 → 用它
解压阶段：
  isZip PK\x03\x04 = YES ✅
  ZipArchive::open = OK (67 files)
  → "ZipArchive 解压成功（67 files）" ✅
```

### 返回示例（check_diff + env）
```json
{
  "local": "1.17.11",
  "latest": "1.17.8",
  "has_update": false,
  "env": {
    "ziparchive": true,
    "shell_unzip": true,
    "data_writable": true,
    "php_version": "8.2.x",
    "all_ok": true,
    "notes": "✅ ZipArchive + shell unzip 都可用（双保险）"
  },
  "msg": "🚀 当前 v1.17.11 已是最新（比 GitHub main 更新）"
}
```

### 版本号
- `lib/bootstrap.php` MXGJ_VERSION: `1.17.10` → `1.17.11`
- `version.json`: `1.17.10` → `1.17.11`

---

## [v1.17.10] 2026-08-31 · 🆕 在线更新页面升级：版本对比 + 差异文件预览

### 背景
v1.17.9 加了基础的版本检测（`Updater::check()`），但用户只能看到"本地 vX vs 远程 vY"，**不知道具体哪些文件有变化**。对于代码有本地改动的场景，升级前无法预判影响范围。

### 核心改动

| 文件 | 改动 |
|------|------|
| `lib/Updater.php` | 新增 `diffLocalRemote()` — 测速选节点→下载 GitHub zip→解压→用 sha1 对比本地 vs 远程文件清单→返回 **新增/修改/删除** 三类列表；新增 `collectFileMap()` / `walkFiles()` 递归收集相对路径 + hash + size + mtime；跳过 `config/` `data/` `.git`；限制最多 500 条差异避免返回过大 |
| `admin.php` | 后端新增 `check_diff` action（计时 + 日志）；前端 `renderUpdateForm` 版本卡片新增 **📁 差异预览** 按钮（紫色）；新增差异结果面板（按 🟢新增 / 🟡修改 / 🔴删除 分段展示，sticky header + 320px 可滚动高度 + 条纹表）；`renderVersionCard` 动态生成的按钮区也加了差异预览入口 |

### 实测
```
耗时: 13s
本地 45 文件 vs 远程 45 文件
📁 差异预览：0 新增 / 9 修改 / 0 删除

🟡 admin.php        184KB → 207KB   （版本检测 + 差异预览 + 顶栏保存）
🟡 lib/Updater.php   10KB → 24KB   （check + run 清 opcache + diffLocalRemote）
🟡 lib/bootstrap.php  24KB → 31KB  （四段式 + 清 runtime 增强）
🟡 index.php           9KB → 10KB
🟡 CHANGELOG.md       41KB → 50KB
🟡 README.md          39KB → 40KB
🟡 version.json      1.0KB → 1.2KB
```

### 返回示例（admin.php POST `action=check_diff`）
```json
{
  "ok": true,
  "added":    [],
  "modified": [
    {"path": "lib/Updater.php", "local_size": 10872, "remote_size": 23934,
     "local_date": "2026-08-30 05:59", "remote_date": "2026-08-31 22:39"},
    ...
  ],
  "removed":  [],
  "total_local": 45,
  "total_remote": 45,
  "total_diff": 9,
  "truncated": false,
  "elapsed_ms": 13224,
  "speed": {"gh-proxy.com": 128.5, "ghfast.top": 312.1, ...},
  "msg": "📁 差异预览：0 个新增 / 9 个修改 / 0 个删除（本地共 45 文件，远程共 45 文件）"
}
```

### 使用场景
- **升级前预览**：点 📁 差异预览 → 看清楚会被替换的是哪些文件，本地临时 patch 过的文件记得先备份
- **代码改动核对**：推代码后重新预览，确认远程 vs 本地没有意外漂移
- **排障**：更新不生效？看删除列表是不是把你要的文件删了

### 版本号
- `lib/bootstrap.php` MXGJ_VERSION: `1.17.9` → `1.17.10`
- `version.json`: `1.17.9` → `1.17.10`

---

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

# JSONP 跨域调用不受影响
curl "http://114.134.184.91:9007/?url=...&callback=handleResponse"
```

---

## 🖥️ 后台保存体验升级（v1.17.9 同步更新）

### 问题反馈
用户反馈：**每次改完设置感觉没生效、找不到保存按钮、不知道缓存该怎么清**。

### 根因
1. 旧版 auto-save 靠"点击表单外 / blur 窗口"触发，浏览器某些场景（快速切 tab / 直接关标签）不会触发
2. 顶栏没有**显式的"保存"按钮**，用户不知道改了后要等 blur
3. 旧版 `AdminHelper::clearCache()` 只清了 `cache/*.cache`，没清 opcache、锁文件、cookies 等
4. 保存后 `mxgj_purge_runtime()` 没清 opcache —— PHP-FPM 场景下字节码缓存是"改了不生效"的最常见原因

### 改动

| 文件 | 改动 |
|------|------|
| `lib/bootstrap.php` · `mxgj_purge_runtime()` | 增强：按类型统计清理数量；新增 cookies 目录清理；所有 `*.lock` / `*.pid` 锁文件清理；**PHP opcache_reset()**（PHP-FPM 改了不生效的头号元凶） |
| `admin.php` · 后端 `clear_cache` action | 改为 AJAX/整页两用：返回清理统计 JSON（items/opcache_reset），前端顶栏按钮 fetch 触发 |
| `admin.php` · 后端 `save_templates` case | 补上漏掉的 `mxgj_purge_runtime()` |
| `admin.php` · 顶栏 HTML | 新增状态指示器（绿/黄/蓝/红）+ 💾 保存按钮 + 🧹 清理缓存按钮 |
| `admin.php` · JS 保存机制 | 完全重写：多表单脏注册表 → 10s 定时自动保存 → Ctrl+S 快捷保存 → beforeunload 离开保护（有脏表单弹窗确认）→ toast 反馈 |

### 新的保存机制（前端）

```
用户修改 input/change → markDirty → 脏表单入 Set
                                  ↓
        10s 定时轮询 ←──────────┘
        有脏表单 → fetch 顺序 POST 所有脏表单（后端自动清 opcache）
        → 完成后整页刷新一次 → toast "已保存 N 处设置"

手动触发：顶栏 💾 按钮 或 Ctrl+S
离开保护：有脏表单时 beforeunload 弹窗确认
```

### 顶栏按钮一览

| 按钮 | 作用 | 反馈 |
|------|------|------|
| 状态指示器 · 绿点 | 已就绪，没有未保存的修改 | 实时随脏/清切换 |
| 状态指示器 · 黄点 | 有未保存的修改（N 处） | 数字随 dirty.size 更新 |
| 💾 保存 | 立即保存所有脏表单（Ctrl+S 快捷键） | 蓝点→绿点 + toast |
| 🧹 清理缓存 | 一键清缓存/锁/日志 + **重置 PHP opcache** | 确认弹窗→toast "已清理 N 个文件" |

### 后端清理动作覆盖范围

```
✅ 搜索缓存  (*.cache)
✅ 站点健康  (site_health.json)
✅ 锁文件   (*.lock / *.pid)
✅ 日志     (logs/*.json + cron_mapping.log)
✅ Cookies  (cookies/*)
✅ PHP Opcache  ⭐ 关键新增（PHP-FPM 改了不生效罪魁祸首）
```

### 保存后为什么一定生效？
所有后端保存 action（save_sites / save_mapping / save_settings / save_fallback / save_templates / quick_toggle / clear_cache）都会**先写 JSON → 再调 mxgj_purge_runtime()**，其中包含 `opcache_reset()`。
PHP-FPM 的 opcache 是"改了文件但代码不刷新"的头号元凶，这次直接在保存时强制清掉，彻底解决"改了没生效"。

---

## ⬆️ 版本检测 + 在线更新修复（v1.17.9 同步更新）

### 问题
用户访问远程 `http://114.134.184.91:9007/admin.php?tab=update`，发现：
- 远程 `version.json` = v1.17.8，但 GitHub main 也是 v1.17.8 → Updater 下载下来跟原来一样
- 之前 Updater 完全没有版本比较逻辑，点"立即更新"也不知道在更什么
- Updater 替换完代码没清 opcache，即使下载成功 PHP-FPM 也跑旧字节码 → "更新了不生效"

### 改动

| 文件 | 改动 |
|------|------|
| `lib/Updater.php` | 新增 `currentVersion()` / `latestVersion()` / `check()` / `fetchRaw()` 四个版本检测方法；`run()` 末尾新增 **清 opcache + mxgj_purge_runtime()** 关键步骤 |
| `admin.php` · 后端 | 新增 `check_update` action（返回 `{local, latest, has_update, need_update, meta, msg}`） |
| `admin.php` · 前端 `renderUpdateForm` | 版本对比卡片（🆚 本地 → GitHub + 🆕 有新版本 / ✅ 已是最新 徽章 + 节点）+ 🔄 检查更新按钮 + 页面加载自动检查 + 更新成功后 3 秒自动刷新 |

### `Updater::check()` 返回示例（本地 v1.17.9，GitHub main v1.17.8）
```json
{
  "local": "1.17.9",
  "latest": "1.17.8",
  "has_update": false,
  "need_update": "newer",
  "meta": { "release": "2026-08-29", "node": "gh-proxy.com", "ok": true },
  "msg": "🚀 当前 v1.17.9 已是最新（比 GitHub main 更新）"
}
```

### 版本比较规则
| `need_update` | 含义 | 前端表现 |
|---|---|---|
| `"older"` | 本地落后于 GitHub，`has_update=true` | 🆕 徽章亮起 + 出现"立即更新"按钮 |
| `"same"` | 版本号一致 | ✅ 已是最新 |
| `"newer"` | 本地领先于 GitHub（例如刚在本地做了改动还没推） | 🚀 已是最新（蓝色） |

### Updater.run() 新流程（8.5 步 → 9 步）
```
0. 鉴权 → 1. 锁 → 2. 测速 → 3. 下载 zip → 4. 解压 → 5. 定位源码根
→ 6. 覆盖（保留 config/ data/）→ 7. chmod 777
→ 7.5 ⭐ 清 opcache + mxgj_purge_runtime()  ← 关键新增
→ 8. 清理临时文件 → 返回
```

### 根因修复链
```
远程跑旧版（GitHub main 没推新）→ Updater 下载到的版本跟本地一样 → 用户以为更新坏了
                                        ↓
Updater.run() 替换完代码没清 opcache → 即使下载了新版 PHP-FPM 也跑旧字节码
                                        ↓
现在：先点 🔄 检查更新 → 知道该更不该更 → 更完自动清 opcache → 3s 后页面刷新 → 看到新版
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
