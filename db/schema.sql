-- =====================================================================
-- MXGJ v1.17.17 数据库 Schema（SQLite 3.45+，兼容 PDO SQLite）
--
-- 本文件定义 SQLite 文件数据库的表结构。MySQL/PostgreSQL 迁移只需
-- 在 Db.php 里把 DSN 换成 mysql: / pgsql: 即可，表结构/字段名不变。
--
-- SQLite 特性开关（由 Db.php 在连接时自动 PRAGMA）：
--   journal_mode=WAL        读写并发不阻塞
--   synchronous=NORMAL      性能与安全平衡
--   busy_timeout=5000       锁等待超时
-- =====================================================================

PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA busy_timeout=5000;

-- ---------------------------------------------------------------------
-- 1. 统一配置表（替代 .env.ini 的所有 section → 扁平 KV）
--    section + key 组成复合主键，value 字段统一存 JSON 字符串
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS config (
    section   VARCHAR(64)  NOT NULL,      -- system / app_api / site_control / cron / fallback ...
    key       VARCHAR(128) NOT NULL,
    value     TEXT,
    updated_at INTEGER,
    PRIMARY KEY (section, key)
);
CREATE INDEX IF NOT EXISTS idx_config_sec ON config(section);

-- ---------------------------------------------------------------------
-- 2. 资源站表（比 INI 更规范，可单独启停/排序）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sites (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        VARCHAR(128) NOT NULL,
    template    TEXT         NOT NULL,      -- 搜索模板，含 %u/%s/%t/%p
    enabled     INTEGER      DEFAULT 1,
    role        VARCHAR(32)  DEFAULT 'primary',  -- primary / fallback
    method      VARCHAR(8)   DEFAULT 'GET',      -- GET / POST
    headers     TEXT,                          -- JSON: {"X-Requested-With":"XMLHttpRequest"}
    post_body   TEXT,                          -- JSON: POST 模板（支持 %u）
    parser      VARCHAR(32)  DEFAULT 'cMaccms', -- cMaccms / json / regex / xml
    sort_order  INTEGER      DEFAULT 0,
    is_special  INTEGER      DEFAULT 0,     -- 1=特殊资源站（直接套 url，跳过苹果CMS 解析）
    proxy       VARCHAR(256) DEFAULT '',    -- 中转代理前缀（可选）
    created_at  INTEGER,
    updated_at  INTEGER
);
CREATE INDEX IF NOT EXISTS idx_sites_role ON sites(role, enabled);

-- ---------------------------------------------------------------------
-- 3. 映射表（三合一：episode / cid / title 映射）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mapping (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sec         VARCHAR(16)  NOT NULL,      -- 'episode' | 'cid' | 'title'
    map_key     VARCHAR(256) NOT NULL,      -- vid:xxx / cid:xxx / 剧名
    name        VARCHAR(256),                   -- 剧名（episode）/ 目标剧名（title）
    episode     INTEGER      DEFAULT 1,
    target      VARCHAR(256),                   -- title 映射的目标剧名（可选）
    enabled     INTEGER      DEFAULT 1,
    created_at  INTEGER,
    updated_at  INTEGER,
    UNIQUE (sec, map_key)
);
CREATE INDEX IF NOT EXISTS idx_mapping_ep ON mapping(sec, enabled);
CREATE INDEX IF NOT EXISTS idx_mapping_key ON mapping(map_key);

-- ---------------------------------------------------------------------
-- 4. 搜索缓存表（替代 data/cache/*.cache 散文件）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cache (
    key        VARCHAR(128) PRIMARY KEY,     -- md5 搜索 key
    value      TEXT          NOT NULL,       -- JSON 完整结果
    ttl        INTEGER       DEFAULT 600,
    expire     INTEGER       NOT NULL,       -- Unix 时间戳
    time       INTEGER,                      -- 原始写入时间
    site       VARCHAR(128),                 -- 命中的资源站名
    from_fb    INTEGER       DEFAULT 0,
    created_at INTEGER
);
CREATE INDEX IF NOT EXISTS idx_cache_expire ON cache(expire);

-- ---------------------------------------------------------------------
-- 5. 资源站健康状态（替代 data/site_health.json）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_health (
    name             VARCHAR(128) PRIMARY KEY,
    status           VARCHAR(16) DEFAULT 'ok',   -- ok / fail / cooldown
    last_check       INTEGER,
    fail_count       INTEGER     DEFAULT 0,
    cooldown_until   INTEGER,
    latency_ms       INTEGER,
    error_msg        TEXT
);

-- ---------------------------------------------------------------------
-- 6. 资源站库存快照（替代 mapping.stock）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_stock (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        VARCHAR(256) NOT NULL,          -- 剧名
    site        VARCHAR(128) NOT NULL,          -- 来自哪个资源站
    eps         VARCHAR(64),                    -- JSON: [21] 集数范围
    updated_at  INTEGER,
    UNIQUE (name, site)
);
CREATE INDEX IF NOT EXISTS idx_stock_name ON site_stock(name);
