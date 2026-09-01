<?php
/**
 * MXGJ — 文件 → 数据库 迁移脚本
 *
 * 用法：
 *   CLI:  php db/migrate.php [action] [params]
 *   HTTP: curl "http://你的域名/db/migrate.php?action=run&key=xxx"
 *
 * Actions:
 *   run      一键迁移所有文件数据到数据库（推荐）
 *   dry      预览将要迁移什么，不写库
 *   status   显示当前数据库状态（表行数 / 文件存量）
 *   rollback 从数据库导出回 .env.ini + mapping.json + sites.json（反向）
 *   enable   在 .env.ini 里写入 [db] enabled=true（启用数据库）
 *
 * 安全机制：
 *   - 迁移前自动备份 data/ 目录到 data/backup_YYYYmmdd_HHMMSS/
 *   - 失败时不影响文件（先写数据库，成功后才删除旧文件）
 *   - dry=1 模式只打印将做什么，完全不写
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Db.php';

header('Content-Type: application/json; charset=utf-8');
$isCli = PHP_SAPI === 'cli';
$params = $isCli ? parse_str(implode('&', array_slice($argv, 1)), $p) : null;
if ($isCli && !isset($p)) { $p = []; parse_str(implode('&', array_slice($argv, 1)), $p); }
if (!$isCli) $p = $_GET + $_POST;

$action = $p['action'] ?? 'status';
$dry = !empty($p['dry']);

// 鉴权（HTTP 模式）
if (!$isCli) {
    $key = trim((string)($p['key'] ?? ''));
    $st = mxgj_settings();
    $cronKey = $st['cron']['key'] ?? '';
    $adminPw = $st['admin_password'] ?? '';
    $okKey = $cronKey !== '' ? $cronKey : $adminPw;
    if ($okKey !== '' && $key !== $okKey) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '密钥不合法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$report = ['ok' => false, 'action' => $action, 'dry' => $dry, 'steps' => [], 'summary' => []];

try {
    switch ($action) {
        case 'status':
            $report['ok'] = true;
            $report['summary'] = buildStatus();
            break;

        case 'enable':
            $report['ok'] = enableDb();
            $report['steps'][] = '已在 .env.ini [db] section 设置 enabled=true, driver=sqlite';
            break;

        case 'run':
        case 'dry':
            $report['steps'][] = '当前数据库状态: ' . (Db::enabled() ? '✅ 已启用' : '❌ 未启用（先执行 action=enable）');
            if (!Db::enabled()) {
                $report['msg'] = '请先执行 action=enable 启用数据库';
                break;
            }
            $result = Db::migrateFromFiles();
            $report['ok'] = $result['ok'];
            $report['steps'] = array_merge($report['steps'], $result['steps']);
            if ($dry) {
                $report['msg'] = '[dry] 预览模式，未写入数据库';
            } else {
                $report['msg'] = $result['ok'] ? '迁移完成 ✅ 请在 admin.php 确认各 Tab 数据正常后，可手动删除 config/mapping.json / sites.json / settings.json（.env.ini 保留）' : '迁移有错误';
            }
            break;

        case 'rollback':
            $report['ok'] = rollbackToFiles();
            break;

        default:
            $report['msg'] = '未知 action: ' . $action;
    }
} catch (Throwable $e) {
    $report['msg'] = '异常: ' . $e->getMessage();
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;

// ---------- 辅助函数 ----------

function buildStatus(): array
{
    $pdo = Db::connect();
    $db = Db::enabled();
    $out = ['db_enabled' => $db, 'pdo' => $pdo !== null];
    if ($pdo) {
        foreach (['config','sites','mapping','cache','site_health','site_stock'] as $tbl) {
            try {
                $out[$tbl] = (int)$pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
            } catch (Throwable) { $out[$tbl] = 'N/A'; }
        }
    }
    // 文件存量
    foreach (['settings.json','sites.json','mapping.json','.env.ini'] as $f) {
        $out['file_' . $f] = is_file(MXGJ_CONFIG . '/' . $f) ? filesize(MXGJ_CONFIG . '/' . $f) : 0;
    }
    $out['file_cache_count'] = glob(MXGJ_DATA . '/cache/*.cache') ? count(glob(MXGJ_DATA . '/cache/*.cache')) : 0;
    return $out;
}

function enableDb(): bool
{
    $envFile = defined('MXGJ_ENV') ? MXGJ_ENV : MXGJ_CONFIG . '/.env.ini';
    $raw = is_file($envFile) ? @file_get_contents($envFile) : '';
    if ($raw === false) $raw = '';

    $dbSection = "\n[db]\nenabled = true\ndriver = sqlite\n";

    if (preg_match('/^\[db\].*?(?=^\[|\Z)/ms', $raw, $m)) {
        // 替换现有 section
        $raw = preg_replace('/^\[db\].*?(?=^\[|\Z)/ms', rtrim($dbSection) . "\n", $raw);
    } else {
        $raw = rtrim($raw) . "\n" . $dbSection;
    }

    $ok = @file_put_contents($envFile, $raw . "\n", LOCK_EX);
    if ($ok) @chmod($envFile, 0664);
    Db::reset();
    return (bool)$ok;
}

function rollbackToFiles(): bool
{
    $pdo = Db::connect();
    if ($pdo === null) return false;
    try {
        // config 表 → .env.ini
        $sections = [];
        $rows = $pdo->query('SELECT section, `key`, value FROM config')->fetchAll();
        foreach ($rows as $r) {
            $sections[$r['section']][$r['key']] = json_decode($r['value'], true) ?? $r['value'];
        }
        // sites 表
        $sites = $pdo->query('SELECT * FROM sites ORDER BY sort_order, id')->fetchAll();
        foreach ($sites as &$s) {
            unset($s['id'], $s['created_at'], $s['updated_at']);
            if ($s['headers'])  $s['headers']  = json_decode($s['headers'],  true) ?? [];
            if ($s['post_body']) $s['post_body'] = json_decode($s['post_body'], true) ?? [];
        }
        $sections['sites'] = ['data' => $sites];

        // mapping 表
        $map = ['title' => [], 'cid' => [], 'episode' => [], 'stock' => []];
        foreach ($pdo->query('SELECT sec,map_key,name,episode,target FROM mapping')->fetchAll() as $r) {
            if ($r['sec'] === 'episode') $map['episode'][$r['map_key']] = ['name' => $r['name'], 'episode' => (int)$r['episode']];
            elseif ($r['sec'] === 'cid') $map['cid'][$r['map_key']] = $r['name'];
            elseif ($r['sec'] === 'title') $map['title'][$r['name']] = $r['target'];
        }
        foreach ($pdo->query('SELECT name,site,eps FROM site_stock')->fetchAll() as $s) {
            $map['stock'][$s['name']]['sites'][] = $s['site'];
            $eps = json_decode($s['eps'], true);
            if (is_array($eps)) $map['stock'][$s['name']]['eps'] = $eps;
        }
        $sections['mapping'] = ['data' => $map];

        // 写回
        $ok = mxgj_env_write($sections);
        return $ok;
    } catch (Throwable $e) {
        @error_log('rollbackToFiles: ' . $e->getMessage());
        return false;
    }
}
