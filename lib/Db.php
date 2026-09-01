<?php
/**
 * MXGJ v1.17.17 — 数据库抽象层（SQLite / MySQL 可选）
 *
 * 设计原则：
 *   1. PDO SQLite 为默认（零依赖，PHP 内置 pdo_sqlite + sqlite3）
 *   2. 同时支持 MySQL（config/.env.ini [db] driver=mysql + DSN 参数）
 *   3. 所有 Db::get() / set() 内部用 switch 分支统一，调用方无感知
 *   4. 首次连接自动建表（执行 db/schema.sql），幂等安全
 *   5. Db 不可用时自动降级到文件存储（env.ini / JSON）
 *
 * 使用：
 *   Db::enabled()              → bool   数据库是否可用
 *   Db::connect()              → PDO|null 拿到 PDO 单例（失败返回 null）
 *   Db::configGet/Set()        → config 表 KV 读写
 *   Db::sites() / Db::saveSites()       → sites 表 CRUD
 *   Db::mapping() / Db::saveMapping()   → mapping 表 CRUD
 *   Db::cacheGet/Set/Delete()  → cache 表
 *   Db::healthGet/Set()        → site_health 表
 *   Db::migrate()              → 一键从文件存储迁移到数据库
 */

// Db 类必须在 bootstrap.php 之后加载（MXGJ_ROOT / MXGJ_DATA 常量由 bootstrap 定义）
// 为了让 bootstrap.php 里也能用 Db，我们手动 require 一次 bootstrap.php 里的常量定义
if (!defined('MXGJ_ROOT')) {
    // fallback — 允许单独测试
    define('MXGJ_ROOT', dirname(__DIR__));
    define('MXGJ_DATA', MXGJ_ROOT . '/data');
    define('MXGJ_CONFIG', MXGJ_ROOT . '/config');
}

class Db
{
    private static ?PDO $_pdo = null;
    private static ?bool $_enabled = null;

    // -----------------------------------------------------------------
    // 启用检测
    // -----------------------------------------------------------------

    /**
     * 数据库是否启用（用户在 .env.ini [db] enabled=true 且 PHP 有对应扩展）
     */
    public static function enabled(): bool
    {
        if (self::$_enabled !== null) return self::$_enabled;

        // 1. 扩展检查
        if (!extension_loaded('PDO')) { self::$_enabled = false; return false; }

        // 2. 读取配置（注意：bootstrap.php 可能还没加载，我们手动读 .env.ini）
        $envFile = defined('MXGJ_ENV') ? MXGJ_ENV : MXGJ_CONFIG . '/.env.ini';
        $driver = 'sqlite'; // 默认 SQLite
        $enabled = false;

        if (is_file($envFile)) {
            $raw = @file_get_contents($envFile);
            if ($raw !== false && preg_match('/^\[db\](.*?)(?=^\[|\Z)/ms', $raw, $m)) {
                $section = $m[1];
                if (preg_match('/^\s*enabled\s*=\s*(.*)$/m', $section, $em)) {
                    $enabled = (bool)trim($em[1]);
                }
                if (preg_match('/^\s*driver\s*=\s*(\w+)/m', $section, $dm)) {
                    $driver = strtolower(trim($dm[1]));
                }
            }
        }

        // 3. SQLite 是默认启用（零依赖），MySQL 需用户显式
        if ($enabled === false && $driver === 'sqlite') {
            // 没配置过 db.enabled，默认不启用（保持向后兼容）
            self::$_enabled = false;
            return false;
        }

        // 4. 检查驱动扩展
        $needle = match($driver) {
            'sqlite' => 'pdo_sqlite',
            'mysql'  => 'pdo_mysql',
            'pgsql'  => 'pdo_pgsql',
            default  => "pdo_$driver",
        };
        if (!extension_loaded($needle)) {
            self::$_enabled = false;
            return false;
        }

        // 5. 试连接
        $pdo = self::tryConnect($driver, $envFile);
        if ($pdo === null) {
            self::$_enabled = false;
            return false;
        }

        self::$_pdo = $pdo;
        self::$_enabled = true;
        return true;
    }

    public static function reset(): void { self::$_pdo = null; self::$_enabled = null; }

    // -----------------------------------------------------------------
    // 连接
    // -----------------------------------------------------------------

    public static function connect(): ?PDO
    {
        if (self::$_pdo !== null) return self::$_pdo;
        if (!self::enabled()) return null;
        return self::$_pdo;
    }

    private static function tryConnect(string $driver, string $envFile): ?PDO
    {
        try {
            $dsn = ''; $user = ''; $pass = '';

            // 从 .env.ini [db] section 解析连接参数
            if (is_file($envFile)) {
                $raw = @file_get_contents($envFile);
                if ($raw !== false && preg_match('/^\[db\](.*?)(?=^\[|\Z)/ms', $raw, $m)) {
                    $section = $m[1];
                    $kv = [];
                    foreach (explode("\n", $section) as $line) {
                        if (str_contains($line, '=')) {
                            [$k, $v] = explode('=', $line, 2);
                            $kv[trim($k)] = trim($v);
                        }
                    }
                    $user = $kv['user'] ?? '';
                    $pass = $kv['pass'] ?? '';
                }
            }

            // SQLite DSN（默认：data/mxgj.db）
            if ($driver === 'sqlite') {
                $dbFile = MXGJ_DATA . '/mxgj.db';
                @mkdir(MXGJ_DATA, 0755, true);
                $dsn = "sqlite:$dbFile";
            } elseif ($driver === 'mysql') {
                $host  = $kv['host'] ?? '127.0.0.1';
                $port  = $kv['port'] ?? '3306';
                $name  = $kv['name'] ?? 'mxgj';
                $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            } else {
                return null;
            }

            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT       => true,
            ]);

            // SQLite 性能优化
            if ($driver === 'sqlite') {
                $pdo->exec('PRAGMA journal_mode=WAL');
                $pdo->exec('PRAGMA synchronous=NORMAL');
                $pdo->exec('PRAGMA busy_timeout=5000');
                $pdo->exec('PRAGMA foreign_keys=ON');
            }

            // 自动建表（执行 schema.sql）
            self::autoMigrate($pdo);

            return $pdo;
        } catch (Throwable $e) {
            @error_log('Db::tryConnect 失败: ' . $e->getMessage());
            return null;
        }
    }

    private static function autoMigrate(PDO $pdo): void
    {
        // 检查 config 表是否存在
        $schemaExists = false;
        try {
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='config'")->fetchColumn();
            if ($tables !== false && $tables !== null) $schemaExists = true;
        } catch (Throwable) { /* MySQL/PG 不同写法，用通用方式 */ }

        if (!$schemaExists) {
            // 直接执行 schema.sql（幂等，所有语句都带 IF NOT EXISTS）
            $schema = __DIR__ . '/../db/schema.sql';
            if (is_file($schema)) {
                $sql = @file_get_contents($schema);
                if ($sql !== false) {
                    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
                        try { $pdo->exec($stmt); } catch (Throwable) { /* 忽略 PRAGMA 等重复执行警告 */ }
                    }
                }
            }
        }

        // 🔧 后处理：给已存在的旧表补新列（幂等安全）
        self::ensureColumn($pdo, 'sites', 'is_special', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'sites', 'proxy', 'VARCHAR(256) DEFAULT ""');
    }

    /**
     * 幂等安全地给表加列（列已存在时静默跳过）
     */
    private static function ensureColumn(PDO $pdo, string $table, string $column, string $def): void
    {
        try {
            // SQLite: PRAGMA table_info 检测列是否已存在
            $colExists = false;
            try {
                $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $c) {
                    if (($c['name'] ?? '') === $column) { $colExists = true; break; }
                }
            } catch (Throwable) { /* MySQL/PG 不同写法，用通用方式 */ }
            if (!$colExists) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column $def");
            }
        } catch (Throwable) { /* 忽略（列已存在 / 不支持 ALTER TABLE 等） */ }
    }

    // -----------------------------------------------------------------
    // Config 表（替代 mxgj_env_read/write）
    // -----------------------------------------------------------------

    /**
     * 读取全部 config（按 section 分组）
     */
    public static function configAll(): array
    {
        $pdo = self::connect();
        if ($pdo === null) return [];
        try {
            $rows = $pdo->query('SELECT section, `key`, value FROM config')->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[$r['section']][$r['key']] = json_decode($r['value'], true) ?? $r['value'];
            }
            return $out;
        } catch (Throwable) { return []; }
    }

    public static function configGet(string $section, string $key, mixed $default = null): mixed
    {
        $pdo = self::connect();
        if ($pdo === null) return $default;
        try {
            $stmt = $pdo->prepare('SELECT value FROM config WHERE section=? AND `key`=?');
            $stmt->execute([$section, $key]);
            $v = $stmt->fetchColumn();
            if ($v === false || $v === null) return $default;
            $decoded = json_decode($v, true);
            return $decoded !== null || json_last_error() === JSON_ERROR_NONE ? $decoded : $v;
        } catch (Throwable) { return $default; }
    }

    public static function configSet(string $section, string $key, mixed $value): bool
    {
        $pdo = self::connect();
        if ($pdo === null) return false;
        try {
            $encoded = is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO config(section,`key`,value,updated_at) VALUES(?,?,?,?)');
            return $stmt->execute([$section, $key, $encoded, time()]);
        } catch (Throwable) { return false; }
    }

    /**
     * 批量写入整个 section（原子性：BEGIN → INSERT OR REPLACE 全部 → COMMIT）
     */
    public static function configWriteSection(string $section, array $data): bool
    {
        $pdo = self::connect();
        if ($pdo === null) return false;
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM config WHERE section=?')->execute([$section]);
            $ins = $pdo->prepare('INSERT INTO config(section,`key`,value,updated_at) VALUES(?,?,?,?)');
            foreach ($data as $k => $v) {
                $enc = is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
                $ins->execute([$section, $k, $enc, time()]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable) {
            $pdo->rollBack();
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Sites 表
    // -----------------------------------------------------------------

    public static function sites(): array
    {
        $pdo = self::connect();
        if ($pdo === null) return [];
        try {
            $rows = $pdo->query('SELECT * FROM sites ORDER BY sort_order ASC, id ASC')->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                if (isset($r['headers']))  $r['headers']  = json_decode($r['headers'],  true) ?? [];
                if (isset($r['post_body'])) $r['post_body'] = json_decode($r['post_body'], true) ?? [];
                $out[] = $r;
            }
            return $out;
        } catch (Throwable) { return []; }
    }

    public static function saveSites(array $sites): bool
    {
        $pdo = self::connect();
        if ($pdo === null) return false;
        try {
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM sites');
            // 🔧 INSERT 加 is_special / proxy 字段
            $ins = $pdo->prepare('INSERT INTO sites(id,name,template,enabled,role,method,headers,post_body,parser,sort_order,created_at,updated_at,is_special,proxy) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($sites as $i => $s) {
                // 字段兼容：.env.ini/admin.php 写的是 `post`，Db schema 是 `post_body`
                $postBody = $s['post_body'] ?? $s['post'] ?? [];
                $ins->execute([
                    $s['id'] ?? null,
                    $s['name'] ?? '',
                    $s['template'] ?? '',
                    !empty($s['enabled']) ? 1 : 0,
                    $s['role'] ?? 'primary',
                    $s['method'] ?? 'GET',
                    json_encode($s['headers'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($postBody, JSON_UNESCAPED_UNICODE),
                    $s['parser'] ?? $s['parse'] ?? 'cMaccms',  // `parse` 兼容 admin.php 字段名
                    (int)($s['sort_order'] ?? $i),
                    time(), time(),
                    !empty($s['is_special']) ? 1 : 0,
                    $s['proxy'] ?? '',
                ]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable) {
            $pdo->rollBack();
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Mapping 表
    // -----------------------------------------------------------------

    public static function mapping(): array
    {
        $pdo = self::connect();
        if ($pdo === null) return [];
        try {
            $rows = $pdo->query('SELECT sec, map_key, name, episode, target, enabled FROM mapping WHERE enabled=1')->fetchAll();
            $out = ['title' => [], 'cid' => [], 'episode' => []];
            foreach ($rows as $r) {
                $sec = $r['sec'];
                if ($sec === 'episode') {
                    $out['episode'][$r['map_key']] = ['name' => $r['name'], 'episode' => (int)$r['episode']];
                } elseif ($sec === 'cid') {
                    $out['cid'][$r['map_key']] = $r['name'];
                } elseif ($sec === 'title') {
                    $out['title'][$r['name']] = $r['target'];
                }
            }
            // 附加 stock（如果有 site_stock 表）
            try {
                $stock = $pdo->query('SELECT name, site, eps FROM site_stock')->fetchAll();
                $out['stock'] = [];
                foreach ($stock as $s) {
                    $out['stock'][$s['name']]['sites'][] = $s['site'];
                    $eps = json_decode($s['eps'], true);
                    if (is_array($eps)) $out['stock'][$s['name']]['eps'] = $eps;
                }
            } catch (Throwable) {}
            return $out;
        } catch (Throwable) { return []; }
    }

    public static function saveMapping(array $titleMap, array $cidMap, array $epMap, array $stock = []): bool
    {
        $pdo = self::connect();
        if ($pdo === null) return false;
        try {
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM mapping');

            $ins = $pdo->prepare('INSERT OR REPLACE INTO mapping(sec,map_key,name,episode,target,enabled,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
            $now = time();

            foreach ($epMap as $k => $v) {
                $name = is_array($v) ? ($v['name'] ?? '') : (string)$v;
                $ep   = is_array($v) ? (int)($v['episode'] ?? 1) : 1;
                $ins->execute(['episode', $k, $name, $ep, null, 1, $now, $now]);
            }
            foreach ($cidMap as $k => $v) {
                $ins->execute(['cid', $k, $v, 1, null, 1, $now, $now]);
            }
            foreach ($titleMap as $k => $v) {
                $ins->execute(['title', $k, $k, 1, $v, 1, $now, $now]);
            }

            // stock
            $pdo->exec('DELETE FROM site_stock');
            $insS = $pdo->prepare('INSERT INTO site_stock(name,site,eps,updated_at) VALUES(?,?,?,?)');
            foreach ($stock as $name => $info) {
                foreach ($info['sites'] ?? [] as $site) {
                    $insS->execute([$name, $site, json_encode($info['eps'] ?? [], JSON_UNESCAPED_UNICODE), $now]);
                }
            }

            $pdo->commit();
            return true;
        } catch (Throwable) {
            $pdo->rollBack();
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Cache 表
    // -----------------------------------------------------------------

    public static function cacheGet(string $key): ?array
    {
        $pdo = self::connect();
        if ($pdo === null) return null;
        try {
            $stmt = $pdo->prepare('SELECT value FROM cache WHERE key=?');
            $stmt->execute([$key]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : json_decode($v, true);
        } catch (Throwable) { return null; }
    }

    public static function cacheSet(string $key, array $value, int $ttl = 600, int $time = 0, string $site = '', bool $fromFb = false): bool
    {
        $pdo = self::connect();
        if ($pdo === null) return false;
        try {
            $expire = ($time ?: time()) + $ttl;
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO cache(key,value,ttl,expire,time,site,from_fb,created_at) VALUES(?,?,?,?,?,?,?,?)');
            return $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE), $ttl, $expire, $time ?: time(), $site, $fromFb ? 1 : 0, time()]);
        } catch (Throwable) { return false; }
    }

    public static function cacheDelete(string $key): bool
    {
        $pdo = self::connect();
        if ($pdo === null) return false;
        try {
            return $pdo->prepare('DELETE FROM cache WHERE key=?')->execute([$key]);
        } catch (Throwable) { return false; }
    }

    public static function cachePurgeExpired(): int
    {
        $pdo = self::connect();
        if ($pdo === null) return 0;
        try {
            return $pdo->prepare('DELETE FROM cache WHERE expire<?')->execute([time()]) ? 1 : 0;
        } catch (Throwable) { return 0; }
    }

    public static function cacheCount(): int
    {
        $pdo = self::connect();
        if ($pdo === null) return 0;
        try { return (int)$pdo->query('SELECT COUNT(*) FROM cache')->fetchColumn(); }
        catch (Throwable) { return 0; }
    }

    // -----------------------------------------------------------------
    // Site Health 表
    // -----------------------------------------------------------------

    public static function healthGetAll(): array
    {
        $pdo = self::connect();
        if ($pdo === null) return [];
        try {
            $rows = $pdo->query('SELECT * FROM site_health')->fetchAll();
            $out = [];
            foreach ($rows as $r) { $out[$r['name']] = $r; }
            return $out;
        } catch (Throwable) { return []; }
    }

    public static function healthUpsert(string $name, array $data): bool
    {
        $pdo = self::connect();
        if ($pdo === null) return false;
        try {
            $fields = ['status','last_check','fail_count','cooldown_until','latency_ms','error_msg'];
            $set = []; $vals = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $data)) { $set[] = "$f=?"; $vals[] = $data[$f]; }
            }
            if ($set === []) return true;
            $vals[] = $name;
            $sql = 'INSERT INTO site_health(name,' . implode(',', $fields) . ') VALUES(?,?)' // fallback
                 . ' ON CONFLICT(name) DO UPDATE SET ' . implode(',', $set);
            // SQLite >= 3.24 支持 ON CONFLICT，MySQL 用 INSERT ... ON DUPLICATE KEY UPDATE
            // 这里用 PDO::ATTR_EMULATE_PREPARES 的通用方式会更复杂，我们简单点：先删后插
            $pdo->prepare('DELETE FROM site_health WHERE name=?')->execute([$name]);
            $insFields = 'name,' . implode(',', $fields);
            $placeholders = '?' . str_repeat(',?', count($fields));
            $full = array_merge([$name], array_intersect_key($data, array_flip($fields)));
            $stmt = $pdo->prepare("INSERT INTO site_health($insFields) VALUES($placeholders)");
            return $stmt->execute($full);
        } catch (Throwable) { return false; }
    }

    // -----------------------------------------------------------------
    // 一键迁移（文件 → 数据库）
    // -----------------------------------------------------------------

    /**
     * 从文件存储迁移到数据库。返回统计结果。
     *
     * @return array{ok:bool,steps:string[]}
     */
    public static function migrateFromFiles(): array
    {
        $steps = [];
        $pdo = self::connect();
        if ($pdo === null) return ['ok' => false, 'steps' => ['数据库不可用']];

        try {
            // 1. .env.ini sections → config 表
            $sections = [];
            $envFile = defined('MXGJ_ENV') ? MXGJ_ENV : MXGJ_CONFIG . '/.env.ini';
            if (is_file($envFile)) {
                $raw = @file_get_contents($envFile);
                $parsed = @parse_ini_string($raw, true, INI_SCANNER_RAW);
                if (is_array($parsed)) {
                    foreach ($parsed as $sec => $pairs) {
                        $conv = [];
                        foreach ($pairs as $k => $v) {
                            $decoded = self::iniDecode($v);
                            $conv[$k] = $decoded;
                        }
                        $sections[$sec] = $conv;
                    }
                }
            }
            foreach ($sections as $sec => $data) {
                if ($sec === 'sites' && isset($data['data'])) {
                    $sitesData = is_array($data['data']) ? $data['data'] : (json_decode($data['data'], true) ?? []);
                    self::saveSites($sitesData);
                    $steps[] = "sites 表迁移完成（" . count($sitesData) . " 个站）";
                } elseif ($sec === 'mapping' && isset($data['data'])) {
                    $map = is_array($data['data']) ? $data['data'] : (json_decode($data['data'], true) ?? []);
                    self::saveMapping($map['title'] ?? [], $map['cid'] ?? [], $map['episode'] ?? [], $map['stock'] ?? []);
                    $steps[] = "mapping 表迁移完成";
                } elseif ($sec === 'cache' || $sec === 'site_health') {
                    // 这两张不从 env.ini 来，跳过
                } else {
                    self::configWriteSection($sec, $data);
                    $steps[] = "config.$sec 写入完成（" . count($data) . " 键）";
                }
            }

            // 2. sites.json（旧版）→ 补充
            $sitesJson = MXGJ_CONFIG . '/sites.json';
            if (is_file($sitesJson)) {
                $oldSites = json_decode(@file_get_contents($sitesJson), true) ?? [];
                if (is_array($oldSites) && $oldSites !== []) {
                    $cur = self::sites();
                    $curNames = array_column($cur, 'name');
                    $added = false;
                    foreach ($oldSites as $s) {
                        $name = trim($s['name'] ?? '');
                        $tpl  = trim($s['template'] ?? $s['url'] ?? '');
                        if ($name === '' || $tpl === '') continue;
                        if (!in_array($name, $curNames)) {
                            $cur[] = $s;
                            $added = true;
                            $steps[] = "sites.json 旧版补充: $name";
                        }
                    }
                    if ($added) self::saveSites($cur);
                }
            }

            // 3. mapping.json（旧版）→ 补充
            $mapJson = MXGJ_CONFIG . '/mapping.json';
            if (is_file($mapJson)) {
                $oldMap = json_decode(@file_get_contents($mapJson), true) ?? [];
                // 合并 episode
                $cur = self::mapping();
                $mergedEp = array_merge($cur['episode'] ?? [], $oldMap['episode'] ?? []);
                $mergedCid = array_merge($cur['cid'] ?? [], $oldMap['cid'] ?? []);
                self::saveMapping($cur['title'] ?? [], $mergedCid, $mergedEp, array_merge($cur['stock'] ?? [], $oldMap['stock'] ?? []));
                $steps[] = "mapping.json 旧版补充合并完成";
            }

            // 4. 散文件缓存 → cache 表
            $nCache = 0;
            foreach (glob(MXGJ_DATA . '/cache/*.cache') ?: [] as $f) {
                $raw = @file_get_contents($f);
                $d = $raw ? json_decode($raw, true) : null;
                if (is_array($d)) {
                    $nCache++;
                    $ttl = $d['ttl'] ?? 600;
                    $time = $d['time'] ?? 0;
                    self::cacheSet(basename($f, '.cache'), $d['value'] ?? $d, $ttl, $time);
                }
            }
            if ($nCache > 0) $steps[] = "散文件缓存迁移: $nCache 条";

            // 5. site_health.json → site_health 表
            $healthFile = MXGJ_DATA . '/site_health.json';
            if (is_file($healthFile)) {
                $health = json_decode(@file_get_contents($healthFile), true) ?? [];
                if (isset($health['sites']) && is_array($health['sites'])) {
                    foreach ($health['sites'] as $name => $info) {
                        self::healthUpsert($name, $info);
                    }
                    $steps[] = "site_health.json 迁移完成";
                }
            }

            return ['ok' => true, 'steps' => $steps];
        } catch (Throwable $e) {
            return ['ok' => false, 'steps' => [...$steps, 'ERROR: ' . $e->getMessage()]];
        }
    }

    // -----------------------------------------------------------------
    // 辅助
    // -----------------------------------------------------------------

    /**
     * 把 .env.ini 里 @json 前缀的值转回 PHP 类型
     */
    public static function iniDecode(string $value): mixed
    {
        $v = trim($value);
        if (str_starts_with($v, '@json')) {
            return json_decode(trim(substr($v, 5)), true) ?? [];
        }
        if ($v === 'true' || $v === '1') return true;
        if ($v === 'false' || $v === '0') return false;
        if (is_numeric($v)) {
            $i = (int)$v;
            if ((string)$i === $v) return $i;
            return (float)$v;
        }
        return $v;
    }
}
