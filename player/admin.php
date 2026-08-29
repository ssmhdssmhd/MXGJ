<?php
/**
 * 沫兮官替系统 - 播放器后台管理（现代 UI 版）
 *
 * 功能：登录 / 播放器列表增删改查 / 启用禁用 / 一键设默认
 *      / 苹果CMS10 XML/JSON/Base64 自动识别导入 / 在线预览 / 导出备份
 */

require_once __DIR__ . '/../lib/bootstrap.php';
session_start();

$ACTION = $_POST['action'] ?? ($_GET['action'] ?? '');

function playerIsLoggedIn(): bool { return !empty($_SESSION['player_admin']); }

// ==================== 路由 ====================

if ($ACTION === '') {
    playerIsLoggedIn() ? playerRenderDashboard() : playerRenderLogin();
    exit;
}

if ($ACTION === 'login') {
    $pwd = $_POST['password'] ?? '';
    $st  = mxgj_settings();
    if ($pwd !== '' && $pwd === $st['admin_password']) {
        $_SESSION['player_admin'] = true;
        header('Location: admin.php');
    } else {
        echo '<script>alert("密码错误");location.href="admin.php";</script>';
    }
    exit;
}

if (!playerIsLoggedIn()) { header('Location: admin.php'); exit; }

switch ($ACTION) {

case 'save_one':
    $id    = (int)($_POST['id'] ?? 0);
    $code  = trim($_POST['player_code'] ?? '');
    $name  = trim($_POST['player_name'] ?? '');
    if ($code === '' || $name === '') { echo '<script>alert("编码和名称不能为空");history.back();</script>'; exit; }
    $now     = date('Y-m-d H:i:s');
    $players = mxgj_players();
    $found   = false;
    foreach ($players as &$p) {
        if ((int)($p['id'] ?? 0) === $id) {
            $p['player_code']         = $code;
            $p['player_name']         = $name;
            $p['player_from']         = trim($_POST['player_from'] ?? '');
            $p['player_remark']       = trim($_POST['player_remark'] ?? '');
            $p['player_code_content'] = $_POST['player_code_content'] ?? '';
            $p['enabled']             = !empty($_POST['enabled']);
            $p['is_default']          = !empty($_POST['is_default']);
            $p['update_time']         = $now;
            $found = true; break;
        }
    }
    unset($p);
    if (!$found) {
        $maxId = 0; foreach ($players as $p) $maxId = max($maxId, (int)($p['id'] ?? 0));
        $players[] = [
            'id' => $maxId + 1, 'player_code' => $code, 'player_name' => $name,
            'player_from' => trim($_POST['player_from'] ?? ''),
            'player_remark' => trim($_POST['player_remark'] ?? ''),
            'player_code_content' => $_POST['player_code_content'] ?? '',
            'enabled' => !empty($_POST['enabled']),
            'is_default' => !empty($_POST['is_default']),
            'create_time' => $now, 'update_time' => $now,
        ];
    }
    if (!empty($_POST['is_default'])) {
        foreach ($players as &$p) if ((int)($p['id'] ?? 0) !== ($found ? $id : $maxId + 1)) $p['is_default'] = false;
        unset($p);
    }
    mxgj_save_players($players);
    header('Location: admin.php?saved=1');
    exit;

case 'delete':
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) { echo '<script>alert("参数错误");history.back();</script>'; exit; }
    $players = mxgj_players();
    foreach ($players as $i => $p) if ((int)($p['id'] ?? 0) === $id) { unset($players[$i]); break; }
    $players = array_values($players);
    $hasDefault = false; foreach ($players as $p) if (!empty($p['is_default'])) { $hasDefault = true; break; }
    if (!$hasDefault && !empty($players)) $players[0]['is_default'] = true;
    mxgj_save_players($players);
    header('Location: admin.php?deleted=1');
    exit;

case 'toggle':
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0); $on = !empty($_POST['enabled']);
    $players = mxgj_players();
    foreach ($players as &$p) if ((int)($p['id'] ?? 0) === $id) { $p['enabled'] = $on; break; }
    unset($p);
    mxgj_save_players($players);
    echo json_encode(['ok' => true]); exit;

case 'set_default':
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0); $players = mxgj_players();
    foreach ($players as &$p) $p['is_default'] = ((int)($p['id'] ?? 0) === $id);
    unset($p);
    mxgj_save_players($players);
    echo json_encode(['ok' => true]); exit;

case 'import':
    header('Content-Type: application/json; charset=utf-8');
    $raw  = trim($_POST['content'] ?? '');
    $type = trim($_POST['import_type'] ?? 'auto');
    if ($raw === '') { echo json_encode(['ok' => false, 'msg' => '请粘贴播放器数据']); exit; }
    $now = date('Y-m-d H:i:s'); $newPlayers = [];

    $normalize = function (array $item): ?array {
        $code = trim($item['player_code'] ?? $item['id'] ?? $item['code'] ?? '');
        $name = trim($item['player_name'] ?? $item['show'] ?? $item['name'] ?? '');
        if ($code === '' || $name === '') return null;
        $content = $item['player_code_content'] ?? $item['code_content']
            ?? (isset($item['code']) && stripos((string)$item['code'], 'MacPlayer') !== false ? $item['code'] : '');
        return [
            'player_code' => $code, 'player_name' => $name,
            'player_from' => trim($item['player_from'] ?? $item['from'] ?? ''),
            'player_code_content' => $content,
            'player_remark' => trim($item['player_remark'] ?? $item['des'] ?? $item['tip'] ?? ''),
        ];
    };

    if ($type === 'auto' || $type === 'base64') {
        $candidate = strtr(trim($raw), '-_', '+/');
        $pad = strlen($candidate) % 4; if ($pad > 0) $candidate .= str_repeat('=', 4 - $pad);
        $decoded = base64_decode($candidate, true);
        if ($decoded !== false && strlen($decoded) > 10 && (
            (strpos($decoded, '{') === 0 && strpos($decoded, 'MacPlayer') !== false)
            || (strpos($decoded, 'id') !== false && strpos($decoded, 'show') !== false && strpos($decoded, 'code') !== false)
        )) { $raw = $decoded; $type = 'json'; }
        elseif ($type === 'base64') { echo json_encode(['ok' => false, 'msg' => 'base64 解码失败']); exit; }
    }
    if ($type === 'auto') {
        $type = (stripos($raw, '<macplayer') !== false || stripos($raw, '<player_code>') !== false) ? 'xml' : 'json';
    }
    if ($type === 'xml') {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string('<?xml version="1.0"?><root>' . $raw . '</root>');
        if ($xml === false) { echo json_encode(['ok' => false, 'msg' => 'XML 解析失败']); exit; }
        $nodes = $xml->xpath('//macplayer'); if (empty($nodes)) $nodes = $xml->xpath('//player');
        foreach ($nodes as $node) {
            $arr = json_decode(json_encode($node), true);
            if ($n = $normalize(['player_code'=>$arr['player_code']??$arr['id']??'', 'player_name'=>$arr['player_name']??$arr['show']??'',
                'player_from'=>$arr['player_from']??$arr['from']??'', 'player_code_content'=>$arr['player_code_content']??$arr['code']??'',
                'player_remark'=>$arr['player_remark']??$arr['des']??$arr['tip']??''])) $newPlayers[] = $n;
        }
    } elseif ($type === 'json') {
        $data = json_decode($raw, true);
        if ($data === null) { echo json_encode(['ok' => false, 'msg' => 'JSON 解析失败']); exit; }
        $list = isset($data[0]) ? $data : [$data];
        foreach ($list as $item) if (is_array($item) && ($n = $normalize($item))) $newPlayers[] = $n;
    } else {
        $c = trim($_POST['player_code'] ?? ''); $n = trim($_POST['player_name'] ?? '');
        if ($c === '' || $n === '') { echo json_encode(['ok' => false, 'msg' => '编码和名称不能为空']); exit; }
        $newPlayers[] = ['player_code'=>$c,'player_name'=>$n,'player_from'=>trim($_POST['player_from'] ?? ''),
            'player_code_content'=>$raw,'player_remark'=>trim($_POST['player_remark'] ?? '')];
    }
    if (empty($newPlayers)) { echo json_encode(['ok' => false, 'msg' => '未解析到有效数据']); exit; }

    $existing = mxgj_players(); $maxId = 0;
    foreach ($existing as $p) $maxId = max($maxId, (int)($p['id'] ?? 0));
    $count = 0;
    foreach ($newPlayers as $np) {
        $hit = false;
        foreach ($existing as &$p) if ($p['player_code'] === $np['player_code']) {
            $p['player_name']=$np['player_name']; $p['player_from']=$np['player_from'];
            $p['player_code_content']=$np['player_code_content']; $p['player_remark']=$np['player_remark'];
            $p['update_time']=$now; $hit=true; break;
        }
        unset($p);
        if (!$hit) { $maxId++; $existing[] = array_merge(['id'=>$maxId,'enabled'=>true,'is_default'=>false,'create_time'=>$now,'update_time'=>$now], $np); }
        $count++;
    }
    mxgj_save_players($existing);
    echo json_encode(['ok' => true, 'msg' => '成功导入 ' . $count . ' 个播放器', 'count' => $count]);
    exit;

case 'export':
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="macplayer_backup_' . date('Ymd_His') . '.json"');
    echo json_encode(['players' => mxgj_players()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;

case 'logout':
    session_destroy(); header('Location: admin.php'); exit;

default: header('Location: admin.php'); exit;
}

/* ==================== 渲染函数 ==================== */

function playerRenderLogin() {
?>
<!DOCTYPE html>
<html lang="zh"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>播放器管理 - 登录</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;min-height:100vh;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#667eea 0%,#764ba2 50%,#f093fb 100%);position:relative;overflow:hidden}
body::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 20% 30%,rgba(255,255,255,.15) 0%,transparent 40%),radial-gradient(circle at 80% 70%,rgba(255,255,255,.1) 0%,transparent 40%)}
.login-card{position:relative;z-index:1;width:400px;background:rgba(255,255,255,.95);backdrop-filter:blur(20px);
    border-radius:20px;padding:48px 40px;box-shadow:0 25px 60px rgba(0,0,0,.25)}
.brand{text-align:center;margin-bottom:32px}
.brand-icon{width:64px;height:64px;border-radius:16px;margin:0 auto 16px;background:linear-gradient(135deg,#667eea,#764ba2);
    display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;font-weight:700;box-shadow:0 8px 24px rgba(102,126,234,.4)}
.brand h1{font-size:20px;color:#1a1f36;margin-bottom:4px}.brand p{font-size:13px;color:#6b7280}
.field{margin-bottom:20px}.field label{display:block;font-size:13px;color:#374151;font-weight:500;margin-bottom:8px}
.field input{width:100%;padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;font-size:14px;background:#f9fafb;color:#111827;transition:.2s}
.field input:focus{outline:none;border-color:#667eea;background:#fff;box-shadow:0 0 0 4px rgba(102,126,234,.15)}
.btn-submit{width:100%;padding:14px;border:0;border-radius:12px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;
    font-size:15px;font-weight:600;cursor:pointer;box-shadow:0 4px 14px rgba(102,126,234,.4);margin-top:8px}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(102,126,234,.5)}
.back-link{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;color:#6b7280;font-size:13px;text-decoration:none}
.back-link:hover{color:#667eea}
</style></head><body>
<form class="login-card" method="post">
<input type="hidden" name="action" value="login">
<div class="brand">
    <div class="brand-icon">▶</div>
    <h1>播放器管理后台</h1>
    <p>MacPlayer 苹果CMS10 兼容系统 v<?= MXGJ_VERSION ?></p>
</div>
<div class="field"><label>后台密码</label>
    <input type="password" name="password" placeholder="请输入密码" required autofocus></div>
<button type="submit" class="btn-submit">登 录</button>
<a class="back-link" href="../admin.php">← 返回主系统</a>
</form></body></html>
<?php }


function playerRenderDashboard() {
    $players = mxgj_players();
    $saved   = isset($_GET['saved']);
    $deleted = isset($_GET['deleted']);
    $tab     = $_GET['tab'] ?? 'home';

    $total   = count($players);
    $enabled = count(array_filter($players, fn($p) => !empty($p['enabled'])));
    $default = null;
    foreach ($players as $p) if (!empty($p['is_default'])) { $default = $p; break; }
    $disabled = $total - $enabled;
?>
<!DOCTYPE html>
<html lang="zh"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>播放器管理 - <?= MXGJ_NAME ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:system-ui,-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;background:#f5f7fa;color:#1f2937}
a{text-decoration:none;color:inherit}button{cursor:pointer;border:0;font-family:inherit}input,textarea,select{font-family:inherit}

.layout{display:flex;height:100vh;overflow:hidden}

/* ===== 左侧菜单 ===== */
.sidebar{width:240px;background:linear-gradient(180deg,#1e293b 0%,#0f172a 100%);color:#cbd5e1;display:flex;flex-direction:column;flex-shrink:0;border-right:1px solid rgba(255,255,255,.05)}
.sidebar-brand{padding:24px 20px;border-bottom:1px solid rgba(255,255,255,.08)}
.sidebar-logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:700;margin-bottom:12px;box-shadow:0 4px 12px rgba(102,126,234,.4)}
.sidebar-brand h1{font-size:16px;color:#fff;font-weight:600}.sidebar-brand p{font-size:11px;color:#64748b;margin-top:3px}
.sidebar-nav{flex:1;padding:12px 12px 24px;overflow-y:auto}
.nav-label{font-size:11px;color:#64748b;padding:16px 12px 8px;text-transform:uppercase;letter-spacing:.5px}
.nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;font-size:14px;color:#94a3b8;cursor:pointer;transition:.15s;margin-bottom:2px;border:1px solid transparent}
.nav-item:hover{background:rgba(255,255,255,.05);color:#e2e8f0}
.nav-item.active{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;box-shadow:0 4px 12px rgba(102,126,234,.3)}
.nav-item .icon{width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:16px}
.nav-item .badge{margin-left:auto;background:rgba(255,255,255,.15);font-size:11px;padding:1px 7px;border-radius:10px}
.sidebar-footer{padding:16px;border-top:1px solid rgba(255,255,255,.08);font-size:11px;color:#475569;text-align:center}
.sidebar-footer b{color:#64748b}

/* ===== 主区域 ===== */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}

/* ===== 顶栏 ===== */
.topbar{height:60px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;padding:0 24px;flex-shrink:0;box-shadow:0 1px 3px rgba(0,0,0,.04);z-index:10}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280}
.breadcrumb .sep{color:#d1d5db}.breadcrumb .current{color:#111827;font-weight:600}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.topbar-btn{padding:7px 14px;border-radius:8px;font-size:13px;color:#374151;background:#f3f4f6;border:1px solid #e5e7eb;transition:.15s}
.topbar-btn:hover{background:#e5e7eb}
.user-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 5px;border-radius:999px;background:#f3f4f6;border:1px solid #e5e7eb;font-size:13px;color:#374151}
.user-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600}

/* ===== 内容区 ===== */
.content{flex:1;overflow-y:auto;padding:24px}

/* ===== 统计卡片 ===== */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:#fff;border-radius:16px;padding:20px;border:1px solid #eef0f4;display:flex;align-items:center;gap:16px;transition:.2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.06)}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.stat-icon.blue{background:rgba(59,130,246,.12);color:#3b82f6}.stat-icon.green{background:rgba(34,197,94,.12);color:#22c55e}
.stat-icon.orange{background:rgba(249,115,22,.12);color:#f97316}.stat-icon.purple{background:rgba(168,85,247,.12);color:#a855f7}
.stat-info .num{font-size:26px;font-weight:700;color:#111827;line-height:1.1}
.stat-info .label{font-size:13px;color:#6b7280;margin-top:3px}

/* ===== 通用面板 ===== */
.panel{background:#fff;border-radius:16px;border:1px solid #eef0f4;overflow:hidden;margin-bottom:20px}
.panel-header{display:flex;align-items:center;padding:18px 24px;border-bottom:1px solid #f1f3f7;gap:12px}
.panel-title{font-size:16px;font-weight:600;color:#111827}.panel-sub{font-size:12px;color:#6b7280}
.panel-body{padding:24px}

/* ===== 按钮 ===== */
.btn{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:6px;border:1px solid transparent;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
.btn-primary:hover{box-shadow:0 4px 12px rgba(102,126,234,.3)}
.btn-green{background:#10b981;color:#fff}.btn-green:hover{background:#059669}
.btn-red{background:#ef4444;color:#fff}.btn-red:hover{background:#dc2626}
.btn-gray{background:#f3f4f6;color:#374151;border-color:#e5e7eb}.btn-gray:hover{background:#e5e7eb}
.btn-sm{padding:5px 10px;font-size:12px;border-radius:6px}
.btn-ghost{background:transparent;color:#6b7280}.btn-ghost:hover{background:#f3f4f6;color:#111827}

/* ===== 表格 ===== */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
thead th{text-align:left;padding:12px 16px;background:#f9fafb;color:#6b7280;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid #eef0f4}
tbody td{padding:14px 16px;border-bottom:1px solid #f1f3f7;color:#374151;vertical-align:middle}
tbody tr:hover td{background:#fafbfd}
tbody tr:last-child td{border-bottom:none}
.row-muted td{color:#9ca3af}

/* ===== 标签 ===== */
.tag{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600}
.tag-blue{background:rgba(59,130,246,.1);color:#3b82f6}.tag-green{background:rgba(34,197,94,.1);color:#22c55e}
.tag-red{background:rgba(239,68,68,.1);color:#ef4444}.tag-gray{background:#f3f4f6;color:#6b7280}
.tag-purple{background:rgba(168,85,247,.1);color:#a855f7}

/* ===== 开关 ===== */
.switch{position:relative;width:38px;height:20px;display:inline-block;vertical-align:middle;flex-shrink:0}
.switch input{display:none}
.switch .track{position:absolute;inset:0;background:#d1d5db;border-radius:20px;cursor:pointer;transition:.2s}
.switch .track::after{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.switch input:checked + .track{background:linear-gradient(135deg,#10b981,#059669)}
.switch input:checked + .track::after{transform:translateX(18px)}

/* ===== 表单 ===== */
.form-row{margin-bottom:16px}.form-row label{display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-input{width:100%;padding:10px 14px;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;background:#f9fafb;color:#111827;transition:.2s}
.form-input:focus{outline:none;border-color:#667eea;background:#fff;box-shadow:0 0 0 4px rgba(102,126,234,.12)}
textarea.form-input{min-height:140px;resize:vertical;font-family:'SF Mono','Consolas','Monaco',monospace;font-size:12px}
.form-hint{font-size:12px;color:#6b7280;margin-top:6px}

/* ===== radio 切换 ===== */
.radio-bar{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.radio-bar label{display:flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;background:#f3f4f6;border:1px solid #e5e7eb;font-size:13px;color:#374151;cursor:pointer;transition:.15s}
.radio-bar label input{display:none}
.radio-bar label:has(input:checked){background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-color:transparent}

/* ===== 模态框 ===== */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);z-index:100;align-items:center;justify-content:center;padding:24px}
.modal-backdrop.show{display:flex}
.modal{background:#fff;border-radius:20px;width:100%;max-width:720px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.25)}
.modal-head{display:flex;align-items:center;padding:20px 24px;border-bottom:1px solid #f1f3f7}
.modal-head h3{font-size:16px;font-weight:600;color:#111827}
.modal-close{margin-left:auto;width:32px;height:32px;border-radius:8px;background:#f3f4f6;color:#6b7280;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.modal-close:hover{background:#e5e7eb;color:#111827}
.modal-body{padding:24px;overflow-y:auto;flex:1}
.modal-foot{padding:16px 24px;border-top:1px solid #f1f3f7;display:flex;gap:10px;justify-content:flex-end;background:#fafbfd}

/* ===== Toast ===== */
.toast{position:fixed;top:24px;left:50%;transform:translateX(-50%) translateY(-20px);background:#111827;color:#fff;padding:12px 24px;border-radius:10px;font-size:14px;z-index:200;opacity:0;pointer-events:none;transition:.25s;box-shadow:0 10px 30px rgba(0,0,0,.2);display:flex;align-items:center;gap:8px}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast .dot{width:8px;height:8px;border-radius:50%;background:#10b981}

/* ===== 预览 iframe ===== */
.preview-frame{width:100%;aspect-ratio:16/9;background:#000;border-radius:12px;overflow:hidden;margin-top:12px}
.preview-frame iframe{width:100%;height:100%;border:0}

/* ===== 代码预览 ===== */
.code-preview{max-height:54px;overflow:hidden;font-family:'SF Mono','Consolas','Monaco',monospace;font-size:11px;color:#6366f1;background:#f5f3ff;padding:8px;border-radius:6px;white-space:nowrap;text-overflow:ellipsis;line-height:1.5}

/* ===== 空状态 ===== */
.empty-state{text-align:center;padding:60px 20px;color:#6b7280}
.empty-state .icon{font-size:48px;margin-bottom:16px;opacity:.4}
.empty-state .title{font-size:15px;font-weight:600;color:#374151;margin-bottom:6px}
.empty-state .desc{font-size:13px}

@media(max-width:900px){.sidebar{width:64px}.sidebar-brand h1,.sidebar-brand p,.nav-item span,.nav-label,.sidebar-footer{display:none}
    .stat-grid{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:1fr}}
</style></head><body>
<div class="layout">

<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">▶</div>
        <h1>播放器管理</h1><p>MacPlayer 兼容</p>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">概览</div>
        <a class="nav-item <?= $tab==='home'?'active':'' ?>" href="?tab=home"><span class="icon">🏠</span><span>首页</span></a>
        <div class="nav-label">播放器</div>
        <a class="nav-item <?= $tab==='list'?'active':'' ?>" href="?tab=list"><span class="icon">📋</span><span>播放器列表</span><span class="badge"><?= $total ?></span></a>
        <a class="nav-item <?= $tab==='import'?'active':'' ?>" href="?tab=import"><span class="icon">📥</span><span>导入播放器</span></a>
        <a class="nav-item <?= $tab==='preview'?'active':'' ?>" href="?tab=preview"><span class="icon">▶️</span><span>在线预览</span></a>
    </nav>
    <div class="sidebar-footer">v<b><?= MXGJ_VERSION ?></b><br>MXGJ System</div>
</aside>

<main class="main">
<div class="topbar">
    <div class="breadcrumb">
        <span>播放器管理</span><span class="sep">/</span>
        <span class="current"><?= $tab==='home'?'首页':($tab==='list'?'播放器列表':($tab==='import'?'导入播放器':($tab==='preview'?'在线预览':'概览'))) ?></span>
    </div>
    <div class="topbar-right">
        <a href="../admin.php" class="topbar-btn">← 主系统</a>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="logout">
            <button class="topbar-btn" type="submit">退出</button></form>
        <div class="user-chip"><div class="user-avatar">A</div><span>管理员</span></div>
    </div>
</div>

<div class="content">
    <div class="toast" id="toast"><span class="dot"></span><span id="toast-text"></span></div>
    <?php if ($saved): ?><script>document.addEventListener('DOMContentLoaded',()=>showToast('保存成功'));</script><?php endif; ?>
    <?php if ($deleted): ?><script>document.addEventListener('DOMContentLoaded',()=>showToast('已删除'));</script><?php endif; ?>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-icon blue">📦</div><div class="stat-info"><div class="num"><?= $total ?></div><div class="label">播放器总数</div></div></div>
        <div class="stat-card"><div class="stat-icon green">✅</div><div class="stat-info"><div class="num"><?= $enabled ?></div><div class="label">已启用</div></div></div>
        <div class="stat-card"><div class="stat-icon orange">⏸️</div><div class="stat-info"><div class="num"><?= $disabled ?></div><div class="label">已禁用</div></div></div>
        <div class="stat-card"><div class="stat-icon purple">⭐</div><div class="stat-info"><div class="num"><?= $default ? htmlspecialchars($default['player_name']) : '-' ?></div><div class="label">默认播放器</div></div></div>
    </div>

    <?php if ($tab === 'home'): ?>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px">
    <div class="panel">
        <div class="panel-header"><div class="panel-title">播放器列表</div><div class="panel-sub">共 <?= $total ?> 个</div>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button class="btn btn-gray btn-sm" onclick="exportPlayers()">⬇ 导出</button>
                <button class="btn btn-primary btn-sm" onclick="openEditor()">＋ 新增</button></div></div>
        <div class="panel-body" style="padding:0">
        <?php if (empty($players)): ?>
            <div class="empty-state"><div class="icon">🎬</div><div class="title">还没有播放器</div><div class="desc">点右上角「新增」添加，或到「导入播放器」批量导入</div></div>
        <?php else: ?>
        <div class="tbl-wrap"><table><thead><tr><th>ID</th><th>播放器</th><th>来源</th><th>备注</th><th>状态</th><th>默认</th><th style="width:150px">操作</th></tr></thead><tbody>
        <?php foreach ($players as $p): ?>
        <tr class="<?= empty($p['enabled']) ? 'row-muted' : '' ?>">
            <td><?= (int)$p['id'] ?></td>
            <td><div style="font-weight:600"><?= htmlspecialchars($p['player_name']) ?></div><div style="font-size:12px;color:#6b7280;font-family:monospace"><?= htmlspecialchars($p['player_code']) ?></div></td>
            <td><span class="tag tag-blue"><?= htmlspecialchars($p['player_from'] ?: '-') ?></span></td>
            <td><?= !empty($p['player_remark']) ? '<span style="font-size:12px;color:#6b7280">'.htmlspecialchars($p['player_remark']).'</span>' : '<span style="font-size:12px;color:#9ca3af">-</span>' ?></td>
            <td><label class="switch"><input type="checkbox" <?= !empty($p['enabled']) ? 'checked' : '' ?> onchange="togglePlayer(<?= (int)$p['id'] ?>,this.checked)"><span class="track"></span></label></td>
            <td><?php if(!empty($p['is_default'])){?><span class="tag tag-purple">⭐ 默认</span><?php }else{?><button class="btn btn-ghost btn-sm" onclick="setDefault(<?= (int)$p['id'] ?>)">设默认</button><?php }?></td>
            <td><div style="display:flex;gap:4px">
                <button class="btn btn-ghost btn-sm" onclick="openEditor(<?= (int)$p['id'] ?>)">编辑</button>
                <button class="btn btn-ghost btn-sm" onclick="previewPlayer(<?= (int)$p['id'] ?>)">预览</button>
                <button class="btn btn-red btn-sm" onclick="deletePlayer(<?= (int)$p['id'] ?>)">删除</button></div></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        </div></div>
        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="panel"><div class="panel-header"><div class="panel-title">快捷操作</div></div>
            <div class="panel-body" style="display:flex;flex-direction:column;gap:10px">
                <a href="?tab=import" class="btn btn-primary" style="justify-content:center">📥 导入苹果CMS 播放器</a>
                <a href="?tab=preview" class="btn btn-green" style="justify-content:center">▶️ 在线预览测试</a>
                <button class="btn btn-gray" style="justify-content:center" onclick="exportPlayers()">⬇ 导出全部备份</button>
            </div></div>
            <div class="panel"><div class="panel-header"><div class="panel-title">使用说明</div></div>
            <div class="panel-body" style="font-size:13px;line-height:1.9;color:#374151">
                <div style="margin-bottom:10px">🔗 <b>播放入口：</b><br><code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px">/player/?url=播放地址</code></div>
                <div style="margin-bottom:10px">📥 <b>导入支持：</b><br>自动识别 base64 / JSON / XML</div>
                <div>⭐ <b>默认播放器：</b><br>前台未指定 code 时使用</div>
            </div></div>
        </div></div>

    <?php elseif ($tab === 'list'): ?>
    <div class="panel">
        <div class="panel-header"><div class="panel-title">播放器列表</div><div class="panel-sub">增删改查 / 启用禁用 / 设默认</div>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button class="btn btn-gray btn-sm" onclick="exportPlayers()">⬇ 导出全部</button>
                <button class="btn btn-primary btn-sm" onclick="openEditor()">＋ 新增播放器</button></div></div>
        <div class="panel-body" style="padding:0">
        <?php if (empty($players)): ?>
            <div class="empty-state"><div class="icon">🎬</div><div class="title">暂无播放器</div><div class="desc">点右上角「新增播放器」添加，或到「导入播放器」批量导入</div></div>
        <?php else: ?>
        <div class="tbl-wrap"><table><thead><tr>
            <th style="width:60px">ID</th><th>播放器</th><th style="width:120px">来源</th><th>备注</th><th style="width:70px">状态</th><th style="width:90px">默认</th><th style="width:200px">操作</th></tr></thead><tbody>
        <?php foreach ($players as $p): ?>
        <tr class="<?= empty($p['enabled']) ? 'row-muted' : '' ?>">
            <td><?= (int)$p['id'] ?></td>
            <td>
                <div style="font-weight:600;margin-bottom:2px"><?= htmlspecialchars($p['player_name']) ?></div>
                <div style="font-size:12px;color:#6b7280;font-family:monospace;background:#f3f4f6;display:inline-block;padding:1px 8px;border-radius:4px;margin-bottom:4px"><?= htmlspecialchars($p['player_code']) ?></div>
                <?php if (!empty($p['player_code_content'])): ?>
                <details><summary style="font-size:12px;color:#6366f1;cursor:pointer">查看代码</summary>
                <div class="code-preview"><?= htmlspecialchars(mb_substr($p['player_code_content'], 0, 400)) ?><?= mb_strlen($p['player_code_content']) > 400 ? '...' : '' ?></div></details><?php endif; ?>
            </td>
            <td><span class="tag tag-blue"><?= htmlspecialchars($p['player_from'] ?: '-') ?></span></td>
            <td><span style="font-size:13px"><?= htmlspecialchars($p['player_remark'] ?: '-') ?></span></td>
            <td><label class="switch"><input type="checkbox" <?= !empty($p['enabled']) ? 'checked' : '' ?> onchange="togglePlayer(<?= (int)$p['id'] ?>,this.checked)"><span class="track"></span></label></td>
            <td><?php if(!empty($p['is_default'])){?><span class="tag tag-purple">⭐ 默认</span><?php }else{?><button class="btn btn-ghost btn-sm" onclick="setDefault(<?= (int)$p['id'] ?>)">设默认</button><?php }?></td>
            <td><div style="display:flex;gap:4px">
                <button class="btn btn-ghost btn-sm" onclick="previewPlayer(<?= (int)$p['id'] ?>)">预览</button>
                <button class="btn btn-ghost btn-sm" onclick="openEditor(<?= (int)$p['id'] ?>)">编辑</button>
                <button class="btn btn-red btn-sm" onclick="deletePlayer(<?= (int)$p['id'] ?>)">删除</button></div></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        </div></div>

    <?php elseif ($tab === 'import'): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <div class="panel"><div class="panel-header"><div class="panel-title">导入播放器</div><div class="panel-sub">自动识别 XML / JSON / Base64，按 player_code 覆盖</div></div>
    <div class="panel-body">
        <div class="radio-bar">
            <label><input type="radio" name="import_type" value="auto" checked> 自动识别（推荐）</label>
            <label><input type="radio" name="import_type" value="xml"> XML</label>
            <label><input type="radio" name="import_type" value="json"> JSON</label>
            <label><input type="radio" name="import_type" value="single"> 单个代码</label>
        </div>
        <div class="form-row"><label>粘贴播放器数据</label>
            <textarea id="import-content" class="form-input" placeholder='① 苹果CMS10 导出的 base64 字符串
② JSON：{"id":"lgzym3u8","show":"蓝光资源","code":"MacPlayer.Html = ..."}
③ XML：<macplayer><player_code>xxx</player_code></macplayer>'></textarea></div>
        <div id="import-single" style="display:none">
            <div class="form-grid">
                <div class="form-row"><label>播放器编码</label><input id="f-s-code" class="form-input" placeholder="如 lgzym3u8"></div>
                <div class="form-row"><label>播放器名称</label><input id="f-s-name" class="form-input" placeholder="如 蓝光资源"></div>
            </div>
            <div class="form-row"><label>来源（可选）</label><input id="f-s-from" class="form-input" placeholder="如 lgzym3u8"></div>
        </div>
        <button class="btn btn-primary" onclick="doImport()" style="padding:10px 24px;font-size:14px">🚀 解析并导入</button>
    </div></div>
    <div class="panel"><div class="panel-header"><div class="panel-title">格式说明</div></div>
    <div class="panel-body" style="font-size:13px;line-height:1.9;color:#374151">
        <div style="margin-bottom:16px"><b style="color:#111827">🔐 Base64</b><br>苹果CMS10 后台 → 播放器管理 → 导出，得到一长串 base64，直接粘贴即可。</div>
        <div style="margin-bottom:16px"><b style="color:#111827">📄 JSON</b><br>支持苹果CMS 原生字段 <code>id/show/from/code</code>，也支持本系统的 <code>player_code/player_name</code>。</div>
        <div><b style="color:#111827">📋 XML</b><br><code style="background:#f3f4f6;padding:2px 6px;border-radius:4px">&lt;macplayer&gt;...&lt;/macplayer&gt;</code> 标签，支持多个。</div>
    </div></div></div>

    <?php elseif ($tab === 'preview'): ?>
    <div class="panel"><div class="panel-header"><div class="panel-title">在线预览</div><div class="panel-sub">选择播放器 + 播放地址，测试实际效果</div></div>
    <div class="panel-body">
        <div class="form-grid" style="max-width:900px">
            <div class="form-row"><label>选择播放器</label>
                <select id="preview-player" class="form-input">
                <?php foreach ($players as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['player_name']) ?> (<?= htmlspecialchars($p['player_code']) ?>)</option><?php endforeach; ?>
                </select></div>
            <div class="form-row"><label>播放地址</label>
                <input id="preview-url" class="form-input" placeholder="https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8" value="https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8"></div>
        </div>
        <div style="margin-top:12px;display:flex;gap:10px">
            <button class="btn btn-primary" onclick="doPreview()">▶️ 开始预览</button>
            <a class="btn btn-gray" id="preview-open-link" href="#" target="_blank">🔗 新窗口打开</a>
        </div>
        <div id="preview-iframe-wrap" class="preview-frame" style="display:none"></div>
        <div id="preview-empty" style="margin-top:20px"><div class="empty-state"><div class="icon">▶️</div><div class="title">等待预览</div><div class="desc">选择播放器 + 地址后点「开始预览」</div></div></div>
    </div></div>
    <?php endif; ?>
</div>
</main></div>

<!-- ===== 编辑模态框 ===== -->
<div class="modal-backdrop" id="editor-modal">
    <div class="modal">
        <div class="modal-head"><h3 id="editor-title">新增播放器</h3><button class="modal-close" onclick="closeEditor()">✕</button></div>
        <form id="editor-form" method="post" class="modal-body">
            <input type="hidden" name="action" value="save_one"><input type="hidden" name="id" id="f-id" value="0">
            <div class="form-grid">
                <div class="form-row"><label>播放器编码 <span style="color:#ef4444">*</span></label>
                    <input name="player_code" id="f-player_code" class="form-input" placeholder="英文标识，如 lgzym3u8" required></div>
                <div class="form-row"><label>播放器名称 <span style="color:#ef4444">*</span></label>
                    <input name="player_name" id="f-player_name" class="form-input" placeholder="如 蓝光资源" required></div>
            </div>
            <div class="form-grid">
                <div class="form-row"><label>来源</label><input name="player_from" id="f-player_from" class="form-input" placeholder="如 lgzym3u8"></div>
                <div class="form-row"><label>备注</label><input name="player_remark" id="f-player_remark" class="form-input" placeholder="可选说明"></div>
            </div>
            <div class="form-row"><label>播放器代码 (MacPlayer JavaScript)</label>
                <textarea name="player_code_content" id="f-player_code_content" class="form-input" placeholder="MacPlayer.Html = '<iframe ...>'; ... MacPlayer.Show()"></textarea>
                <div class="form-hint">苹果CMS10 导出的 JS 代码，原样粘贴即可</div></div>
            <div style="display:flex;gap:18px;padding:8px 2px">
                <label style="display:flex;align-items:center;gap:8px;font-size:14px;color:#374151">
                    <label class="switch"><input type="checkbox" name="enabled" id="f-enabled" checked><span class="track"></span></label>启用该播放器</label>
                <label style="display:flex;align-items:center;gap:8px;font-size:14px;color:#374151">
                    <label class="switch"><input type="checkbox" name="is_default" id="f-default"><span class="track"></span></label>设为默认</label>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px">
                <button type="submit" class="btn btn-primary" style="padding:10px 22px">💾 保存</button>
                <button type="button" class="btn btn-gray" onclick="closeEditor()">取消</button>
                <span id="edit-tip" style="margin-left:auto;font-size:12px;color:#6b7280"></span>
            </div>
        </form>
    </div></div>

<!-- ===== 小预览弹窗 ===== -->
<div class="modal-backdrop" id="preview-modal">
    <div class="modal" style="max-width:820px">
        <div class="modal-head"><h3>播放器预览</h3><button class="modal-close" onclick="document.getElementById('preview-modal').classList.remove('show')">✕</button></div>
        <div class="modal-body">
            <div class="preview-frame" style="aspect-ratio:16/8"><iframe id="preview-modal-iframe" src="" allowfullscreen></iframe></div>
        </div></div></div>

<script>
const __players = <?= json_encode($players, JSON_UNESCAPED_UNICODE) ?>;
const __TEST_URL = 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8';

function showToast(msg){var t=document.getElementById('toast');document.getElementById('toast-text').textContent=msg;t.classList.add('show');
    clearTimeout(window.__toastT);window.__toastT=setTimeout(function(){t.classList.remove('show')},2200);}

function togglePlayer(id,on){fetch('admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=toggle&id='+id+'&enabled='+(on?1:0)}).then(r=>r.json()).then(d=>{if(d.ok)showToast(on?'已启用':'已禁用');});}

function setDefault(id){fetch('admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=set_default&id='+id}).then(r=>r.json()).then(d=>{if(d.ok){showToast('已设为默认');setTimeout(function(){location.reload()},500);}});}

function deletePlayer(id){var p=__players.find(x=>x.id===id);var name=p?p.player_name+' ('+p.player_code+')':('ID='+id);
    if(!confirm('确定删除「'+name+'」？此操作不可恢复'))return;
    fetch('admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=delete&id='+id}).then(function(){location.href='admin.php?tab=<?= $tab ?>&deleted=1';});}

function openEditor(id){
    var modal=document.getElementById('editor-modal');var title=document.getElementById('editor-title');var tip=document.getElementById('edit-tip');
    if(id){var p=__players.find(x=>x.id===id);if(!p){alert('未找到');return;}title.textContent='编辑播放器';
        document.getElementById('f-id').value=p.id;document.getElementById('f-player_code').value=p.player_code||'';
        document.getElementById('f-player_name').value=p.player_name||'';document.getElementById('f-player_from').value=p.player_from||'';
        document.getElementById('f-player_remark').value=p.player_remark||'';document.getElementById('f-player_code_content').value=p.player_code_content||'';
        document.getElementById('f-enabled').checked=!!p.enabled;document.getElementById('f-default').checked=!!p.is_default;
        tip.textContent='更新时间: '+(p.update_time||'-');}
    else{title.textContent='新增播放器';document.getElementById('editor-form').reset();document.getElementById('f-id').value=0;
        document.getElementById('f-enabled').checked=true;tip.textContent='';}
    modal.classList.add('show');}
function closeEditor(){document.getElementById('editor-modal').classList.remove('show');}

function previewPlayer(id){var p=__players.find(x=>x.id===id);if(!p){alert('未找到');return;}
    var code=p.player_code||'';var url='play.php?code='+encodeURIComponent(code)+'&url='+encodeURIComponent(__TEST_URL);
    document.getElementById('preview-modal-iframe').src=url;document.getElementById('preview-modal').classList.add('show');}

function doPreview(){var pid=document.getElementById('preview-player').value;var url=document.getElementById('preview-url').value.trim();
    if(!url){alert('请填写播放地址');return;}var code='';
    __players.forEach(function(p){if(parseInt(p.id)===parseInt(pid))code=p.player_code;});
    var target='play.php?code='+encodeURIComponent(code)+'&url='+encodeURIComponent(url);
    document.getElementById('preview-iframe-wrap').innerHTML='<iframe src="'+target+'" allowfullscreen></iframe>';
    document.getElementById('preview-iframe-wrap').style.display='block';document.getElementById('preview-empty').style.display='none';
    document.getElementById('preview-open-link').href=target;}

function exportPlayers(){location.href='admin.php?action=export';}

document.querySelectorAll('input[name="import_type"]').forEach(function(r){r.addEventListener('change',function(){
    document.getElementById('import-single').style.display=this.value==='single'?'block':'none';});});

function doImport(){var content=document.getElementById('import-content').value.trim();var type='auto';
    document.querySelectorAll('input[name="import_type"]').forEach(function(r){if(r.checked)type=r.value;});
    if(!content && type!=='single'){alert('请粘贴播放器数据');return;}
    var body='action=import&import_type='+encodeURIComponent(type)+'&content='+encodeURIComponent(content);
    if(type==='single'){body+='&player_code='+encodeURIComponent(document.getElementById('f-s-code').value);
        body+='&player_name='+encodeURIComponent(document.getElementById('f-s-name').value);
        body+='&player_from='+encodeURIComponent(document.getElementById('f-s-from').value);}
    var btn=event.target;btn.disabled=true;btn.textContent='解析中...';
    fetch('admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){return r.json();}).then(function(d){btn.disabled=false;btn.textContent='🚀 解析并导入';
        if(d.ok){showToast(d.msg);setTimeout(function(){location.reload();},700);}else{alert('导入失败: '+d.msg);}})
    .catch(function(){btn.disabled=false;btn.textContent='🚀 解析并导入';alert('请求失败');});}

document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('.modal-backdrop.show').forEach(function(m){m.classList.remove('show');});}});
</script>
</body></html>
<?php }
