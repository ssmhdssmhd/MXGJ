<?php
/**
 * 沫兮官替系统 - 后台管理入口
 * 登录后管理：仪表盘 / 资源站 / 剧名映射 / 请求日志 / 更新日志 / 系统设置（含 Git 自动更新）
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Core\Auth;
use App\Core\Updater;

Auth::startSession();
$db = db();

// ---------- 动作处理 ----------
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    $ok = Auth::login($db, trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''));
    if ($ok) {
        flash_set('success', '登录成功');
    } else {
        flash_set('error', '用户名或密码错误');
    }
    header('Location: /admin/');
    exit;
}

if ($action === 'logout') {
    Auth::logout();
    header('Location: /admin/');
    exit;
}

if (!Auth::check()) {
    render_login();
}

// ---- 已登录动作 ----
if ($action === 'site_save') {
    $id = trim((string)($_POST['id'] ?? ''));
    $siteId = trim((string)($_POST['site_id'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $api = trim((string)($_POST['api'] ?? ''));
    $timeout = max(1, (int)($_POST['timeout'] ?? 8));
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    if ($name === '' || $api === '' || $siteId === '') {
        flash_set('error', '站点ID/名称/API 不能为空');
    } elseif ($id === '') {
        if ($db->first('SELECT id FROM mxgj_sites WHERE site_id = ?', [$siteId])) {
            flash_set('error', '站点ID已存在');
        } else {
            $db->insert('mxgj_sites', ['site_id' => $siteId, 'name' => $name, 'api' => $api, 'timeout' => $timeout, 'enabled' => $enabled]);
            flash_set('success', '资源站已添加');
        }
    } else {
        $db->update('mxgj_sites', ['site_id' => $siteId, 'name' => $name, 'api' => $api, 'timeout' => $timeout, 'enabled' => $enabled], 'id = ?', [$id]);
        flash_set('success', '资源站已更新');
    }
    header('Location: /admin/?page=sites');
    exit;
}

if ($action === 'site_delete') {
    $db->delete('mxgj_sites', 'id = ?', [(int)($_POST['id'] ?? 0)]);
    flash_set('success', '已删除');
    header('Location: /admin/?page=sites');
    exit;
}

if ($action === 'site_toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $db->first('SELECT enabled FROM mxgj_sites WHERE id = ?', [$id]);
    if ($row) {
        $db->update('mxgj_sites', ['enabled' => $row['enabled'] ? 0 : 1], 'id = ?', [$id]);
    }
    header('Location: /admin/?page=sites');
    exit;
}

if ($action === 'name_save') {
    $id = trim((string)($_POST['id'] ?? ''));
    $platform = trim((string)($_POST['platform'] ?? ''));
    $vid = trim((string)($_POST['vid'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $episode = max(0, (int)($_POST['episode'] ?? 0));
    if ($platform === '' || $vid === '' || $name === '') {
        flash_set('error', '平台/标识/剧名 不能为空');
    } elseif ($id === '') {
        if ($db->first('SELECT id FROM mxgj_name_map WHERE platform = ? AND vid = ?', [$platform, $vid])) {
            flash_set('error', '该平台标识已存在');
        } else {
            $db->insert('mxgj_name_map', ['platform' => $platform, 'vid' => $vid, 'name' => $name, 'episode' => $episode]);
            flash_set('success', '映射已添加');
        }
    } else {
        $db->update('mxgj_name_map', ['platform' => $platform, 'vid' => $vid, 'name' => $name, 'episode' => $episode], 'id = ?', [$id]);
        flash_set('success', '映射已更新');
    }
    header('Location: /admin/?page=namemap');
    exit;
}

if ($action === 'name_delete') {
    $db->delete('mxgj_name_map', 'id = ?', [(int)($_POST['id'] ?? 0)]);
    flash_set('success', '已删除');
    header('Location: /admin/?page=namemap');
    exit;
}

if ($action === 'settings_save') {
    $db->setSetting('concurrency', max(1, (int)($_POST['concurrency'] ?? 8)));
    $db->setSetting('default_timeout', max(1, (int)($_POST['default_timeout'] ?? 8)));
    $db->setSetting('git_branch', trim((string)($_POST['git_branch'] ?? '')));
    $db->setSetting('update_enabled', isset($_POST['update_enabled']) ? '1' : '0');
    if (isset($_POST['update_token']) && trim((string)$_POST['update_token']) !== '') {
        $db->setSetting('update_token', trim((string)$_POST['update_token']));
    }
    flash_set('success', '设置已保存');
    header('Location: /admin/?page=settings');
    exit;
}

if ($action === 'password_change') {
    $new = (string)($_POST['new_password'] ?? '');
    if (strlen($new) < 6) {
        flash_set('error', '新密码至少 6 位');
    } elseif (Auth::changePassword($db, $new)) {
        flash_set('success', '密码已修改');
    } else {
        flash_set('error', '密码修改失败');
    }
    header('Location: /admin/?page=settings');
    exit;
}

if ($action === 'update_check') {
    $updater = new Updater($db);
    $result = $updater->check();
    flash_set($result['status'] === 'failed' ? 'error' : 'success', $result['message'] . ($result['status'] === 'outdated' ? " (本地 {$result['local']} → 远程 {$result['remote']})" : ''));
    header('Location: /admin/?page=dashboard');
    exit;
}

if ($action === 'update_run') {
    $updater = new Updater($db);
    $result = $updater->update('admin');
    flash_set($result['status'] === 'success' ? 'success' : 'error', $result['message'] . (isset($result['output']) && $result['output'] !== '' ? "\n" . mb_substr($result['output'], 0, 500) : ''));
    header('Location: /admin/?page=dashboard');
    exit;
}

// ---------- 页面路由 ----------
$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard', 'sites', 'namemap', 'logs', 'updatelogs', 'settings'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

$updater = new Updater($db);

$data = [];
switch ($page) {
    case 'dashboard':
        $data = [
            'siteTotal' => (int)$db->value('SELECT COUNT(*) FROM mxgj_sites'),
            'siteEnabled' => (int)$db->value('SELECT COUNT(*) FROM mxgj_sites WHERE enabled = 1'),
            'nameMapTotal' => (int)$db->value('SELECT COUNT(*) FROM mxgj_name_map'),
            'logTotal' => (int)$db->value('SELECT COUNT(*) FROM mxgj_logs'),
            'logOk' => (int)$db->value('SELECT COUNT(*) FROM mxgj_logs WHERE code = 200'),
            'git' => $updater->info(),
            'lastUpdate' => $db->first('SELECT * FROM mxgj_update_logs ORDER BY id DESC LIMIT 1'),
            'recentLogs' => $db->select('SELECT * FROM mxgj_logs ORDER BY id DESC LIMIT 8'),
            'settings' => [
                'concurrency' => (int)$db->setting('concurrency', 8),
                'default_timeout' => (int)$db->setting('default_timeout', 8),
            ],
        ];
        break;
    case 'sites':
        $data['sites'] = $db->select('SELECT * FROM mxgj_sites ORDER BY id ASC');
        break;
    case 'namemap':
        $data['maps'] = $db->select('SELECT * FROM mxgj_name_map ORDER BY id DESC');
        break;
    case 'logs':
        $pageNum = max(1, (int)($_GET['p'] ?? 1));
        $perPage = 20;
        $total = (int)$db->value('SELECT COUNT(*) FROM mxgj_logs');
        $data['logs'] = $db->select('SELECT * FROM mxgj_logs ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNum - 1) * $perPage));
        $data['pageNum'] = $pageNum;
        $data['pages'] = max(1, (int)ceil($total / $perPage));
        $data['total'] = $total;
        break;
    case 'updatelogs':
        $data['logs'] = $db->select('SELECT * FROM mxgj_update_logs ORDER BY id DESC LIMIT 50');
        break;
    case 'settings':
        $data['settings'] = [
            'concurrency' => (int)$db->setting('concurrency', 8),
            'default_timeout' => (int)$db->setting('default_timeout', 8),
            'git_branch' => (string)$db->setting('git_branch', ''),
            'update_enabled' => (int)$db->setting('update_enabled', 1),
            'update_token' => (string)$db->setting('update_token', ''),
        ];
        $data['git'] = $updater->info();
        $data['dbDriver'] = $db->driver();
        break;
}

render_admin($page, $data);

// ---------- 视图函数 ----------
function render_login(): never
{
    require __DIR__ . '/views/login.php';
    exit;
}

function render_admin(string $page, array $data): void
{
    $active = $page;
    require __DIR__ . '/views/layout.php';
}