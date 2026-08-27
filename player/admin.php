<?php
/**
 * 沫兮官替系统 - 播放器后台管理
 *
 * 兼容苹果CMS10 MacPlayer 格式：
 *   - player_code:  播放器编码（英文标识，如 lgzym3u8）
 *   - player_name:  播放器名称（如 蓝光资源）
 *   - player_from:  来源/模板标识
 *   - player_code_content: 播放器JavaScript代码（注入 MacPlayer.Html + Show）
 *
 * 功能：增删改查 / 苹果CMS XML导入 / 在线预览 / 一键设默认 / 导出配置
 */

require __DIR__ . '/../lib/bootstrap.php';

session_start();

$ACTION = $_POST['action'] ?? ($_GET['action'] ?? '');
$playersFile = MXGJ_PLAYER_FILE;

/* 已登录标记（复用主系统密码） */
function playerIsLoggedIn(): bool {
    return !empty($_SESSION['player_admin']);
}

/* ---------------- 各操作处理 ---------------- */

// 默认：登录页或管理主界面
if ($ACTION === '') {
    if (!playerIsLoggedIn()) {
        playerRenderLogin();
    } else {
        playerRenderDashboard();
    }
    exit;
}

// ---- 登录 ----
if ($ACTION === 'login') {
    $pwd = $_POST['password'] ?? '';
    $st  = mxgj_settings();
    if ($pwd !== '' && $pwd === $st['admin_password']) {
        $_SESSION['player_admin'] = true;
        header('Location: admin.php');
        exit;
    }
    echo '<script>alert("密码错误");location.href="admin.php";</script>';
    exit;
}

if (!playerIsLoggedIn()) {
    header('Location: admin.php');
    exit;
}

switch ($ACTION) {

    // 保存所有播放器
    case 'save_players':
        $list = isset($_POST['players']) && is_array($_POST['players']) ? array_values($_POST['players']) : [];
        $now  = date('Y-m-d H:i:s');
        $clean = [];
        foreach ($list as $s) {
            $code = trim($s['player_code'] ?? '');
            $name = trim($s['player_name'] ?? '');
            if ($code === '' || $name === '') continue;
            $clean[] = [
                'id'                  => (int)($s['id'] ?? 0),
                'player_code'         => $code,
                'player_name'         => $name,
                'player_from'         => trim($s['player_from'] ?? ''),
                'player_remark'       => trim($s['player_remark'] ?? ''),
                'player_code_content' => $s['player_code_content'] ?? '',
                'enabled'             => !empty($s['enabled']),
                'is_default'          => !empty($s['is_default']),
                'create_time'         => trim($s['create_time'] ?? $now),
                'update_time'         => $now,
            ];
        }
        // 确保至少一个默认
        $hasDefault = false;
        foreach ($clean as &$p) {
            if (!empty($p['is_default'])) { $hasDefault = true; break; }
        }
        unset($p);
        if (!$hasDefault && !empty($clean)) {
            $clean[0]['is_default'] = true;
        }
        mxgj_save_players($clean);
        // 重置静态缓存
        unset($GLOBALS['_players_cache']);
        header('Location: admin.php?saved=1');
        exit;

    // 新增或编辑单条播放器
    case 'save_one':
        $id       = (int)($_POST['id'] ?? 0);
        $code     = trim($_POST['player_code'] ?? '');
        $name     = trim($_POST['player_name'] ?? '');
        if ($code === '' || $name === '') {
            echo '<script>alert("播放器编码和名称不能为空");history.back();</script>';
            exit;
        }
        $now      = date('Y-m-d H:i:s');
        $players  = mxgj_players();
        $found    = false;
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
                $found = true;
                break;
            }
        }
        unset($p);
        if (!$found) {
            // 新增
            $maxId = 0;
            foreach ($players as $p) $maxId = max($maxId, (int)($p['id'] ?? 0));
            $players[] = [
                'id'                  => $maxId + 1,
                'player_code'         => $code,
                'player_name'         => $name,
                'player_from'         => trim($_POST['player_from'] ?? ''),
                'player_remark'       => trim($_POST['player_remark'] ?? ''),
                'player_code_content' => $_POST['player_code_content'] ?? '',
                'enabled'             => !empty($_POST['enabled']),
                'is_default'          => !empty($_POST['is_default']),
                'create_time'         => $now,
                'update_time'         => $now,
            ];
        }
        // 唯一默认
        if (!empty($_POST['is_default'])) {
            foreach ($players as &$p) {
                if ((int)($p['id'] ?? 0) !== ($found ? $id : $maxId + 1)) {
                    $p['is_default'] = false;
                }
            }
            unset($p);
        }
        mxgj_save_players($players);
        header('Location: admin.php?saved=1');
        exit;

    // 删除单条
    case 'delete':
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            echo '<script>alert("参数错误");history.back();</script>';
            exit;
        }
        $players = mxgj_players();
        $found   = false;
        foreach ($players as $i => $p) {
            if ((int)($p['id'] ?? 0) === $id) {
                unset($players[$i]);
                $found = true;
                break;
            }
        }
        $players = array_values($players);
        // 删除后如果没有默认，设第一个为默认
        $hasDefault = false;
        foreach ($players as &$p) { if (!empty($p['is_default'])) { $hasDefault = true; break; } }
        unset($p);
        if (!$hasDefault && !empty($players)) {
            $players[0]['is_default'] = true;
        }
        mxgj_save_players($players);
        header('Location: admin.php?deleted=1');
        exit;

    // 快捷切换启用/禁用
    case 'toggle':
        header('Content-Type: application/json; charset=utf-8');
        $id  = (int)($_POST['id'] ?? 0);
        $on  = !empty($_POST['enabled']);
        $players = mxgj_players();
        foreach ($players as &$p) {
            if ((int)($p['id'] ?? 0) === $id) {
                $p['enabled'] = $on;
                break;
            }
        }
        unset($p);
        mxgj_save_players($players);
        echo json_encode(['ok' => true]);
        exit;

    // 一键设为默认
    case 'set_default':
        header('Content-Type: application/json; charset=utf-8');
        $id  = (int)($_POST['id'] ?? 0);
        $players = mxgj_players();
        foreach ($players as &$p) {
            $p['is_default'] = ((int)($p['id'] ?? 0) === $id);
        }
        unset($p);
        mxgj_save_players($players);
        echo json_encode(['ok' => true]);
        exit;

    // 苹果CMS10 XML/JSON 导入
    case 'import':
        header('Content-Type: application/json; charset=utf-8');
        $raw = trim($_POST['content'] ?? '');
        $type = trim($_POST['import_type'] ?? 'xml'); // xml / json / single
        if ($raw === '') {
            echo json_encode(['ok' => false, 'msg' => '请粘贴播放器数据']);
            exit;
        }
        $now = date('Y-m-d H:i:s');
        $newPlayers = [];

        if ($type === 'xml') {
            // 苹果CMS10 MacPlayer XML格式
            // <macplayer>
            //   <player_code>lgzym3u8</player_code>
            //   <player_name>蓝光资源</player_name>
            //   <player_from>lgzym3u8</player_from>
            //   <player_code_content>...</player_code_content>
            //   <player_remark></player_remark>
            // </macplayer>
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?><root>' . $raw . '</root>');
            if ($xml === false) {
                echo json_encode(['ok' => false, 'msg' => 'XML 解析失败']);
                exit;
            }
            $nodes = $xml->xpath('//macplayer');
            if (empty($nodes)) $nodes = $xml->xpath('//player');
            foreach ($nodes as $node) {
                $code = (string)($node->player_code ?? '');
                $name = (string)($node->player_name ?? '');
                if ($code === '' || $name === '') continue;
                $newPlayers[] = [
                    'player_code'         => $code,
                    'player_name'         => $name,
                    'player_from'         => (string)($node->player_from ?? ''),
                    'player_code_content' => (string)($node->player_code_content ?? ''),
                    'player_remark'       => (string)($node->player_remark ?? ''),
                ];
            }
        } elseif ($type === 'json') {
            // JSON格式（数组或单个）
            $data = json_decode($raw, true);
            if ($data === null) {
                echo json_encode(['ok' => false, 'msg' => 'JSON 解析失败']);
                exit;
            }
            $list = isset($data[0]) ? $data : [$data];
            foreach ($list as $item) {
                if (!is_array($item)) continue;
                $code = trim($item['player_code'] ?? $item['code'] ?? '');
                $name = trim($item['player_name'] ?? $item['name'] ?? '');
                if ($code === '' || $name === '') continue;
                $newPlayers[] = [
                    'player_code'         => $code,
                    'player_name'         => $name,
                    'player_from'         => trim($item['player_from'] ?? $item['from'] ?? ''),
                    'player_code_content' => $item['player_code_content'] ?? $item['code_content'] ?? '',
                    'player_remark'       => trim($item['player_remark'] ?? $item['remark'] ?? ''),
                ];
            }
        } else {
            // single：粘贴单个播放器代码（自动包装）
            $code = trim($_POST['player_code'] ?? '');
            $name = trim($_POST['player_name'] ?? '');
            if ($code === '' || $name === '') {
                echo json_encode(['ok' => false, 'msg' => '编码和名称不能为空']);
                exit;
            }
            $newPlayers[] = [
                'player_code'         => $code,
                'player_name'         => $name,
                'player_from'         => trim($_POST['player_from'] ?? ''),
                'player_code_content' => $raw,
                'player_remark'       => trim($_POST['player_remark'] ?? ''),
            ];
        }

        if (empty($newPlayers)) {
            echo json_encode(['ok' => false, 'msg' => '未解析到有效的播放器数据']);
            exit;
        }

        // 合并到现有列表（按 player_code 匹配覆盖）
        $existing = mxgj_players();
        $maxId = 0;
        foreach ($existing as $p) $maxId = max($maxId, (int)($p['id'] ?? 0));

        $importedCount = 0;
        foreach ($newPlayers as $np) {
            $hit = false;
            foreach ($existing as &$p) {
                if ($p['player_code'] === $np['player_code']) {
                    $p['player_name']         = $np['player_name'];
                    $p['player_from']         = $np['player_from'];
                    $p['player_code_content'] = $np['player_code_content'];
                    $p['player_remark']       = $np['player_remark'];
                    $p['update_time']         = $now;
                    $hit = true;
                    break;
                }
            }
            unset($p);
            if (!$hit) {
                $maxId++;
                $existing[] = array_merge([
                    'id'          => $maxId,
                    'enabled'     => true,
                    'is_default'  => false,
                    'create_time' => $now,
                    'update_time' => $now,
                ], $np);
            }
            $importedCount++;
        }

        mxgj_save_players($existing);
        echo json_encode(['ok' => true, 'msg' => '成功导入 ' . $importedCount . ' 个播放器', 'count' => $importedCount]);
        exit;

    // 导出所有播放器（JSON）
    case 'export':
        $players = mxgj_players();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="macplayer_backup_' . date('Ymd_His') . '.json"');
        echo json_encode(['players' => $players], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;

    // 登出
    case 'logout':
        session_destroy();
        header('Location: admin.php');
        exit;

    default:
        header('Location: admin.php');
        exit;
}

/* ---------------- 渲染函数 ---------------- */

function playerRenderLogin()
{
    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>播放器管理 - 登录</title>
        <style>
            body{font-family:system-ui,-apple-system,Segoe UI,Microsoft YaHei,sans-serif;background:#0f1420;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
            .card{background:#1b2233;padding:40px;border-radius:12px;width:340px;box-shadow:0 10px 40px rgba(0,0,0,.4)}
            h1{color:#fff;font-size:18px;margin:0 0 6px}
            p{color:#8a93a6;font-size:13px;margin:0 0 24px}
            input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #2c3550;background:#12172a;color:#fff;border-radius:8px;margin-bottom:16px;font-size:14px}
            button{width:100%;padding:12px;border:0;border-radius:8px;background:#4f7cff;color:#fff;font-size:15px;cursor:pointer}
            button:hover{background:#3f6ce8}
            .link{color:#8a93a6;font-size:12px;text-align:center;display:block;margin-top:16px;text-decoration:none}
            .link:hover{color:#4f7cff}
        </style>
    </head>
    <body>
        <form class="card" method="post">
            <h1>◆ 播放器管理后台</h1>
            <p>苹果CMS10 MacPlayer 兼容系统</p>
            <input type="password" name="password" placeholder="后台管理密码" required autofocus>
            <input type="hidden" name="action" value="login">
            <button type="submit">登 录</button>
            <a class="link" href="../admin.php">← 返回主系统管理</a>
        </form>
    </body></html>
    <?php
}

function playerRenderDashboard()
{
    $players = mxgj_players();
    $saved   = isset($_GET['saved']);
    $deleted = isset($_GET['deleted']);
    $tab     = $_GET['tab'] ?? 'list';
    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>播放器管理 - <?= MXGJ_NAME ?></title>
        <style>
            *{box-sizing:border-box}
            body{font-family:system-ui,-apple-system,Segoe UI,Microsoft YaHei,sans-serif;background:#0f1420;color:#e6e9f0;margin:0}
            .wrap{max-width:1200px;margin:0 auto;padding:24px}
            header{display:flex;align-items:center;justify-content:space-between;padding:16px 0;border-bottom:1px solid #223;margin-bottom:20px}
            header h1{font-size:18px;margin:0;color:#fff}
            .logo{color:#4f7cff}
            .sub{font-size:12px;color:#8a93a6;margin-left:8px}
            .top-actions{display:flex;gap:8px;align-items:center}
            .tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
            .tabs a{padding:8px 16px;border-radius:8px;text-decoration:none;color:#b8c0d2;background:#1b2233;font-size:14px}
            .tabs a.active{background:#4f7cff;color:#fff}
            .panel{background:#1b2233;border-radius:12px;padding:20px;margin-bottom:16px}
            table{width:100%;border-collapse:collapse;font-size:13px}
            td,th{padding:10px 8px;border-bottom:1px solid #242e48;text-align:left;vertical-align:top}
            th{color:#8a93a6;font-weight:600;font-size:12px;background:#12172a}
            input[type=text],textarea,select{width:100%;padding:8px 10px;border:1px solid #2c3550;background:#12172a;color:#e6e9f0;border-radius:6px;font-size:13px;font-family:inherit}
            textarea{min-height:120px;resize:vertical;font-family:Consolas,Monaco,monospace;font-size:12px}
            .btn{padding:8px 16px;border:0;border-radius:8px;background:#4f7cff;color:#fff;font-size:13px;cursor:pointer;white-space:nowrap}
            .btn-green{background:#2ecc71}.btn-danger{background:#e74c3c}.btn-gray{background:#3a4460}
            .btn:hover{opacity:.9}
            .btn-sm{padding:5px 10px;font-size:12px}
            .small{font-size:12px;color:#8a93a6}
            .note{background:#243352;border-radius:8px;padding:12px;font-size:13px;line-height:1.7}
            .toast{display:none;position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#2ecc71;color:#fff;padding:10px 20px;border-radius:8px;font-size:14px;z-index:999}
            h2{font-size:16px;margin:0 0 16px;color:#fff}
            h3{font-size:14px;margin:20px 0 10px;color:#b8c0d2}
            label{display:block;font-size:12px;color:#8a93a6;margin-bottom:4px}
            .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
            .form-grid .full{grid-column:1/-1}
            .center{text-align:center}
            .row-disabled td{opacity:.5}
            /* 开关 */
            .toggle{position:relative;display:inline-block;width:40px;height:22px;vertical-align:middle}
            .toggle input{display:none}
            .toggle .slider{position:absolute;cursor:pointer;inset:0;background:#3a4460;border-radius:22px;transition:.2s}
            .toggle .slider:before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
            .toggle input:checked + .slider{background:#2ecc71}
            .toggle input:checked + .slider:before{transform:translateX(18px)}
            /* 模态框 */
            .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center;padding:20px}
            .modal.show{display:flex}
            .modal-content{background:#1b2233;border-radius:12px;width:100%;max-width:720px;max-height:90vh;overflow-y:auto;padding:24px}
            .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
            .modal-header h2{margin:0}
            .close-btn{background:none;border:none;color:#8a93a6;font-size:24px;cursor:pointer}
            .close-btn:hover{color:#fff}
            .tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
            .tag-enabled{background:rgba(46,204,113,.2);color:#2ecc71}
            .tag-disabled{background:rgba(231,76,60,.2);color:#e74c3c}
            .tag-default{background:rgba(79,124,255,.2);color:#4f7cff}
            .code-preview{max-height:60px;overflow:hidden;font-family:Consolas,Monaco,monospace;font-size:11px;color:#7fc1ff;background:#0d1118;padding:6px;border-radius:4px;white-space:nowrap;text-overflow:ellipsis}
            .stat-cards{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
            .stat{background:#1b2233;border-radius:12px;padding:16px 20px;min-width:140px}
            .stat b{font-size:22px;color:#fff}
            .stat span{display:block;font-size:12px;color:#8a93a6;margin-top:2px}
            /* 预览iframe */
            .preview-box{background:#000;border-radius:8px;overflow:hidden;aspect-ratio:16/9;margin-top:12px}
            .preview-box iframe{width:100%;height:100%;border:0}
            .radio-group{display:flex;gap:12px;align-items:center;margin-bottom:12px}
            .radio-group label{display:flex;align-items:center;gap:4px;margin:0;font-size:13px;color:#b8c0d2}
        </style>
    </head>
    <body>
    <div class="wrap">
        <header>
            <h1><span class="logo">◆</span> 播放器管理 <span class="sub">MacPlayer 苹果CMS10 兼容</span></h1>
            <div class="top-actions">
                <a href="../admin.php" class="btn btn-gray btn-sm">← 主系统</a>
                <form method="post" style="margin:0;display:inline"><input type="hidden" name="action" value="logout"><button class="btn btn-danger btn-sm">退出</button></form>
            </div>
        </header>

        <div class="tabs">
            <a href="?tab=list" class="<?= $tab==='list'?'active':'' ?>">播放器列表</a>
            <a href="?tab=import" class="<?= $tab==='import'?'active':'' ?>">导入播放器</a>
            <a href="?tab=preview" class="<?= $tab==='preview'?'active':'' ?>">在线预览</a>
        </div>

        <div class="toast" id="toast"></div>
        <?php if ($saved): ?><script>showToast('保存成功');</script><?php endif; ?>
        <?php if ($deleted): ?><script>showToast('已删除');</script><?php endif; ?>

        <?php if ($tab === 'list'): ?>
            <!-- 概览统计 -->
            <div class="stat-cards">
                <div class="stat"><b><?= count($players) ?></b><span>播放器总数</span></div>
                <div class="stat"><b><?= count(array_filter($players, fn($p) => !empty($p['enabled']))) ?></b><span>已启用</span></div>
                <div class="stat"><b><?= count(array_filter($players, fn($p) => !empty($p['is_default']))) ?></b><span>默认播放器</span></div>
            </div>

            <!-- 播放器列表 -->
            <div class="panel">
                <h2>播放器列表
                    <button class="btn btn-sm btn-green" style="float:right" onclick="openEditor()">+ 新增播放器</button>
                    <button class="btn btn-sm" style="float:right;margin-right:8px" onclick="exportPlayers()">⬇ 导出全部</button>
                </h2>

                <table>
                    <thead>
                        <tr>
                            <th style="width:60px">ID</th>
                            <th style="width:150px">编码 (code)</th>
                            <th style="width:120px">名称</th>
                            <th style="width:120px">来源 (from)</th>
                            <th>备注 / 代码预览</th>
                            <th style="width:70px">默认</th>
                            <th style="width:70px">启用</th>
                            <th style="width:200px">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($players)): ?>
                            <tr><td colspan="8" class="center small" style="padding:40px">暂无播放器，点右上角「新增播放器」添加，或到「导入播放器」标签批量导入苹果CMS数据。</td></tr>
                        <?php else: ?>
                            <?php foreach ($players as $p): ?>
                            <tr class="<?= !empty($p['enabled']) ? '' : 'row-disabled' ?>">
                                <td><?= (int)($p['id'] ?? 0) ?></td>
                                <td><code style="background:#0d1118;padding:2px 6px;border-radius:3px;color:#7fc1ff;font-size:12px"><?= htmlspecialchars($p['player_code']) ?></code></td>
                                <td><?= htmlspecialchars($p['player_name']) ?></td>
                                <td class="small"><?= htmlspecialchars($p['player_from'] ?? '-') ?></td>
                                <td>
                                    <div class="small"><?= htmlspecialchars($p['player_remark'] ?? '') ?: '<span style="color:#555">无备注</span>' ?></div>
                                    <details class="small" style="margin-top:4px">
                                        <summary style="cursor:pointer;color:#7fc1ff">查看代码</summary>
                                        <div class="code-preview" style="max-height:120px;margin-top:4px"><?= htmlspecialchars(mb_substr($p['player_code_content'] ?? '', 0, 500)) ?><?= mb_strlen($p['player_code_content'] ?? '') > 500 ? '...' : '' ?></div>
                                    </details>
                                </td>
                                <td class="center">
                                    <?php if (!empty($p['is_default'])): ?>
                                        <span class="tag tag-default">默认</span>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-gray" onclick="setDefault(<?= (int)$p['id'] ?>)">设为默认</button>
                                    <?php endif; ?>
                                </td>
                                <td class="center">
                                    <label class="toggle">
                                        <input type="checkbox" <?= !empty($p['enabled']) ? 'checked' : '' ?> onchange="toggleEnabled(<?= (int)$p['id'] ?>, this.checked, this)">
                                        <span class="slider"></span>
                                    </label>
                                </td>
                                <td>
                                    <button class="btn btn-sm" onclick="openEditor(<?= (int)($p['id'] ?? 0) ?>)">编辑</button>
                                    <button class="btn btn-sm btn-gray" onclick="previewPlayer(<?= (int)($p['id'] ?? 0) ?>)">预览</button>
                                    <button class="btn btn-sm btn-danger" onclick="deletePlayer(<?= (int)($p['id'] ?? 0) ?>, '<?= htmlspecialchars($p['player_name']) ?>')">删除</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($tab === 'import'): ?>
            <div class="panel">
                <h2>导入苹果CMS10 播放器</h2>
                <div class="note" style="margin-bottom:16px">
                    支持以下格式批量导入，粘贴后点「解析并导入」即可。按 <code>player_code</code> 匹配，已存在则覆盖。
                </div>

                <div class="radio-group">
                    <label><input type="radio" name="import_type" value="xml" checked onchange="switchImportMode('xml')"> XML 格式（苹果CMS <code>macplayer</code> 标签）</label>
                    <label><input type="radio" name="import_type" value="json" onchange="switchImportMode('json')"> JSON 格式</label>
                    <label><input type="radio" name="import_type" value="single" onchange="switchImportMode('single')"> 单个代码粘贴</label>
                </div>

                <div id="import-xml">
                    <label>粘贴 MacPlayer XML 数据（可多个）</label>
                    <textarea id="import-content" placeholder='&lt;macplayer&gt;
  &lt;player_code&gt;lgzym3u8&lt;/player_code&gt;
  &lt;player_name&gt;蓝光资源&lt;/player_name&gt;
  &lt;player_from&gt;lgzym3u8&lt;/player_from&gt;
  &lt;player_code_content&gt;MacPlayer.Html = ...&lt;/player_code_content&gt;
  &lt;player_remark&gt;&lt;/player_remark&gt;
&lt;/macplayer&gt;

&lt;macplayer&gt;...&lt;/macplayer&gt;'></textarea>
                </div>

                <div id="import-single" style="display:none">
                    <div class="form-grid">
                        <div><label>编码 (player_code)</label><input type="text" id="s-code" placeholder="如 dplayer"></div>
                        <div><label>名称 (player_name)</label><input type="text" id="s-name" placeholder="如 DPlayer播放器"></div>
                        <div><label>来源 (player_from)</label><input type="text" id="s-from" placeholder="可留空"></div>
                        <div><label>备注</label><input type="text" id="s-remark" placeholder="可留空"></div>
                    </div>
                    <label style="margin-top:12px">播放器代码</label>
                    <textarea id="import-single-content" placeholder="MacPlayer.Html = '<iframe ...'; try{MacPlayer.Show()}catch(e){}"></textarea>
                </div>

                <div style="margin-top:16px;display:flex;gap:10px">
                    <button class="btn btn-green" onclick="doImport()">⚡ 解析并导入</button>
                    <span class="small" id="import-result"></span>
                </div>

                <div class="note" style="margin-top:20px">
                    <b>苹果CMS10 MacPlayer XML 完整示例：</b><br>
                    <code style="font-family:Consolas,Monaco,monospace;font-size:12px;display:block;white-space:pre-wrap;color:#7fc1ff;margin-top:8px">&lt;macplayer&gt;
  &lt;player_code&gt;lgzym3u8&lt;/player_code&gt;
  &lt;player_name&gt;蓝光资源&lt;/player_name&gt;
  &lt;player_from&gt;lgzym3u8&lt;/player_from&gt;
  &lt;player_code_content&gt;MacPlayer.Html = '&lt;iframe src="https://vv00.xyz?url='+MacPlayer.PlayUrl+'"&gt;&lt;/iframe&gt;';try{MacPlayer.Show()}catch(e){}&lt;/player_code_content&gt;
  &lt;player_remark&gt;通用播放器&lt;/player_remark&gt;
&lt;/macplayer&gt;</code>
                </div>
            </div>

        <?php elseif ($tab === 'preview'): ?>
            <div class="panel">
                <h2>在线预览播放器</h2>
                <div class="form-grid">
                    <div>
                        <label>选择播放器</label>
                        <select id="preview-player">
                            <?php foreach ($players as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['player_name']) ?> (<?= htmlspecialchars($p['player_code']) ?>)<?= !empty($p['enabled']) ? '' : ' - 已禁用' ?><?= !empty($p['is_default']) ? ' - 默认' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>测试播放地址</label>
                        <input type="text" id="preview-url" placeholder="https://example.com/video.m3u8" value="https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8">
                    </div>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px">
                    <button class="btn btn-green" onclick="doPreview()">▶ 播放预览</button>
                    <a class="btn btn-gray" id="preview-open-link" href="#" target="_blank">在新窗口打开</a>
                </div>
                <div id="preview-box" style="margin-top:16px">
                    <div class="preview-box" id="preview-iframe-wrap" style="display:flex;align-items:center;justify-content:center;color:#8a93a6;font-size:13px">
                        选择播放器和地址后点「播放预览」
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2>直接访问入口</h2>
                <table>
                    <tr><td class="small">前台通用入口</td><td><code>/player.php?url=播放地址</code></td></tr>
                    <tr><td class="small">指定播放器入口</td><td><code>/player/play.php?code=播放器编码&url=播放地址</code></td></tr>
                    <tr><td class="small">示例</td><td><code>/player/play.php?code=lgzym3u8&url=https%3A%2F%2Fexample.com%2Fvideo.m3u8</code></td></tr>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- 编辑弹窗 -->
    <div class="modal" id="edit-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="edit-title">新增播放器</h2>
                <button class="close-btn" onclick="closeEditor()">✕</button>
            </div>
            <form method="post" id="edit-form">
                <input type="hidden" name="action" value="save_one">
                <input type="hidden" name="id" id="f-id" value="0">
                <div class="form-grid">
                    <div><label>播放器编码 (player_code) *</label><input type="text" name="player_code" id="f-code" required placeholder="英文标识，如 dplayer"></div>
                    <div><label>播放器名称 (player_name) *</label><input type="text" name="player_name" id="f-name" required placeholder="显示名称，如 DPlayer播放器"></div>
                    <div><label>来源 (player_from)</label><input type="text" name="player_from" id="f-from" placeholder="可留空"></div>
                    <div><label>备注 (player_remark)</label><input type="text" name="player_remark" id="f-remark" placeholder="可留空"></div>
                </div>
                <label style="margin-top:12px">播放器代码 (player_code_content) — 必须设置 <code>MacPlayer.Html</code> 并调用 <code>MacPlayer.Show()</code></label>
                <textarea name="player_code_content" id="f-content" style="min-height:180px" placeholder="MacPlayer.Html = '<iframe src=\"'+MacPlayer.PlayUrl+'\"></iframe>'; try{MacPlayer.Show()}catch(e){}"></textarea>

                <div style="display:flex;gap:12px;margin-top:12px;align-items:center">
                    <label class="toggle"><input type="checkbox" name="enabled" id="f-enabled" checked><span class="slider"></span></label>
                    <span class="small">启用该播放器</span>
                    <label class="toggle"><input type="checkbox" name="is_default" id="f-default"><span class="slider"></span></label>
                    <span class="small">设为默认（前台未指定时使用）</span>
                </div>

                <div style="margin-top:20px;display:flex;gap:8px">
                    <button type="submit" class="btn btn-green">💾 保存</button>
                    <button type="button" class="btn btn-gray" onclick="closeEditor()">取消</button>
                    <span class="small" id="edit-tip" style="margin-left:auto"></span>
                </div>
            </form>
        </div>
    </div>

    <!-- 预览弹窗（小） -->
    <div class="modal" id="preview-modal">
        <div class="modal-content" style="max-width:800px">
            <div class="modal-header">
                <h2>播放器预览</h2>
                <button class="close-btn" onclick="document.getElementById('preview-modal').classList.remove('show')">✕</button>
            </div>
            <div class="preview-box" style="aspect-ratio:16/9">
                <iframe id="preview-modal-iframe" src="about:blank"></iframe>
            </div>
        </div>
    </div>

    <script>
    // Toast
    function showToast(msg){
        var t=document.getElementById('toast');
        t.textContent=msg;t.style.display='block';
        setTimeout(()=>t.style.display='none',2000);
    }

    // 编辑弹窗
    var __players = <?= json_encode($players) ?>;
    function openEditor(id){
        document.getElementById('f-id').value=0;
        document.getElementById('f-code').value='';
        document.getElementById('f-name').value='';
        document.getElementById('f-from').value='';
        document.getElementById('f-remark').value='';
        document.getElementById('f-content').value='MacPlayer.Html = \'<iframe width="100%" height="100%" src="\'+MacPlayer.PlayUrl+\'" frameborder="0" allowfullscreen></iframe>\'; try{MacPlayer.Show()}catch(e){}';
        document.getElementById('f-enabled').checked=true;
        document.getElementById('f-default').checked=false;
        document.getElementById('edit-title').textContent='新增播放器';
        document.getElementById('edit-tip').textContent='';

        if(id){
            var p=__players.find(function(x){return parseInt(x.id)===parseInt(id);});
            if(p){
                document.getElementById('f-id').value=p.id;
                document.getElementById('f-code').value=p.player_code||'';
                document.getElementById('f-name').value=p.player_name||'';
                document.getElementById('f-from').value=p.player_from||'';
                document.getElementById('f-remark').value=p.player_remark||'';
                document.getElementById('f-content').value=p.player_code_content||'';
                document.getElementById('f-enabled').checked=!!p.enabled;
                document.getElementById('f-default').checked=!!p.is_default;
                document.getElementById('edit-title').textContent='编辑播放器：'+p.player_name;
            }
        }
        document.getElementById('edit-modal').classList.add('show');
    }
    function closeEditor(){ document.getElementById('edit-modal').classList.remove('show'); }

    // 删除
    function deletePlayer(id,name){
        if(!confirm('确定删除播放器「'+name+'」？此操作不可恢复。'))return;
        var fd=new FormData();
        fd.append('action','delete');fd.append('id',id);
        fetch('admin.php',{method:'POST',body:fd}).then(function(){location.href='admin.php?tab=list&deleted=1'});
    }

    // 快捷开关启用
    function toggleEnabled(id,on,el){
        var fd=new FormData();
        fd.append('action','toggle');fd.append('id',id);fd.append('enabled',on?'1':'');
        fetch('admin.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
            if(!d.ok){el.checked=!on;alert('操作失败');}
        }).catch(function(){el.checked=!on;alert('网络错误');});
    }

    // 设默认
    function setDefault(id){
        var fd=new FormData();
        fd.append('action','set_default');fd.append('id',id);
        fetch('admin.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
            if(d.ok){location.reload();}else{alert('操作失败');}
        }).catch(function(){alert('网络错误');});
    }

    // 导出
    function exportPlayers(){
        window.location.href='admin.php?action=export';
    }

    // 导入
    function switchImportMode(type){
        document.getElementById('import-xml').style.display=(type==='single')?'none':'block';
        document.getElementById('import-single').style.display=(type==='single')?'block':'none';
    }
    function doImport(){
        var type=document.querySelector('input[name="import_type"]:checked').value;
        var result=document.getElementById('import-result');
        result.textContent='解析中...';result.style.color='#e2b93b';
        var fd=new FormData();
        fd.append('action','import');fd.append('import_type',type);
        if(type==='single'){
            fd.append('player_code',document.getElementById('s-code').value);
            fd.append('player_name',document.getElementById('s-name').value);
            fd.append('player_from',document.getElementById('s-from').value);
            fd.append('player_remark',document.getElementById('s-remark').value);
            fd.append('content',document.getElementById('import-single-content').value);
        }else{
            fd.append('content',document.getElementById('import-content').value);
        }
        fetch('admin.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
            if(d.ok){
                result.textContent='✔ '+d.msg;result.style.color='#2ecc71';
                setTimeout(function(){location.href='admin.php?tab=list';},1500);
            }else{
                result.textContent='✘ '+d.msg;result.style.color='#e74c3c';
            }
        }).catch(function(e){result.textContent='请求失败:'+e;result.style.color='#e74c3c';});
    }

    // 预览（弹窗小窗口）
    function previewPlayer(id){
        var p=__players.find(function(x){return parseInt(x.id)===parseInt(id);});
        if(!p){alert('未找到播放器');return;}
        var code=p.player_code||'';
        var testUrl='https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8';
        var url='/play.php?code='+encodeURIComponent(code)+'&url='+encodeURIComponent(testUrl);
        document.getElementById('preview-modal-iframe').src=url;
        document.getElementById('preview-modal').classList.add('show');
    }

    // 预览页（大）
    function doPreview(){
        var pid=document.getElementById('preview-player').value;
        var url=document.getElementById('preview-url').value.trim();
        if(!url){alert('请填写播放地址');return;}
        var code='';
        __players.forEach(function(p){if(parseInt(p.id)===parseInt(pid))code=p.player_code;});
        var target='/play.php?code='+encodeURIComponent(code)+'&url='+encodeURIComponent(url);
        document.getElementById('preview-iframe-wrap').innerHTML='<iframe src="'+target+'"></iframe>';
        document.getElementById('preview-open-link').href=target;
    }

    // ESC关闭弹窗
    document.addEventListener('keydown',function(e){
        if(e.key==='Escape'){
            document.querySelectorAll('.modal.show').forEach(function(m){m.classList.remove('show');});
        }
    });
    </script>
    </body></html>
    <?php
}
