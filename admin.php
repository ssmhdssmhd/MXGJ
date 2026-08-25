<?php
/**
 * 沫兮官替系统 - 后台管理
 *
 * 无数据库，所有数据以 JSON 文件存储：
 *   config/settings.json  全局设置
 *   config/sites.json     资源站列表
 *   config/mapping.json   剧名/cid 映射表
 *   data/               缓存目录
 *
 * 密码默认 moxi123，登录后请在「设置」页修改。
 */

require __DIR__ . '/lib/bootstrap.php';

session_start();

$ACTION = $_POST['action'] ?? ($_GET['action'] ?? '');
$settingsFile = MXGJ_CONFIG . '/settings.json';
$sitesFile    = MXGJ_CONFIG . '/sites.json';
$mappingFile  = MXGJ_CONFIG . '/mapping.json';

/* 已登录标记 */
function isLoggedIn(): bool {
    return !empty($_SESSION['mxgj_admin']);
}

/* ---------------- 各操作处理 ---------------- */

// 默认：登录页或管理主界面
if ($ACTION === '') {
    if (!isLoggedIn()) {
        renderLogin();
    } else {
        renderDashboard();
    }
    exit;
}

// ---- 登录 ----
if ($ACTION === 'login') {
    $pwd = $_POST['password'] ?? '';
    $st  = mxgj_settings();
    if ($pwd !== '' && $pwd === $st['admin_password']) {
        $_SESSION['mxgj_admin'] = true;
        Logger::log('login', '后台登录成功', 'success', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        header('Location: admin.php');
        exit;
    }
    Logger::log('login', '后台登录失败（密码错误）', 'error', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    echo '<script>alert("密码错误");location.href="admin.php";</script>';
    exit;
}

if (!isLoggedIn()) {
    header('Location: admin.php');
    exit;
}

switch ($ACTION) {

    case 'save_sites':
        $list = isset($_POST['sites']) && is_array($_POST['sites']) ? array_values($_POST['sites']) : [];
        // 规整
        $clean = [];
        foreach ($list as $s) {
            $name = trim($s['name'] ?? '');
            $url  = trim($s['template'] ?? ($s['url'] ?? ''));
            if ($name === '' || $url === '') continue;
            $clean[] = [
                'name'     => $name,
                'url'      => $url,          // 兼容旧字段
                'template' => $url,          // 标准模板字段
                'proxy'    => trim($s['proxy'] ?? ''), // 跟随播放链接（中转前缀），仅该站启用
                'enabled'  => array_key_exists('enabled', $s) ? !empty($s['enabled']) : true, // 启用开关（默认启用）
            ];
        }
        mxgj_write_json($sitesFile, ['sites' => $clean]);
        Logger::log('operation', '保存资源站列表（共 ' . count($clean) . ' 个）', 'success');
        Logger::log('config', '资源站配置保存成功：' . count($clean) . ' 个站点', 'success', ['sites' => $clean]);
        header('Location: admin.php?tab=sites&saved=1');
        exit;

    case 'save_mapping':
        $oldMap = mxgj_read_json($mappingFile, []);
        $mapping = [
            'title' => [],
            'cid'   => [],
            'episode' => [],
        ];
        // 保留非编辑区块：库存盘点 stock 与快捷禁用列表 disabled
        if (!empty($oldMap['stock']))    $mapping['stock']    = $oldMap['stock'];
        if (!empty($oldMap['disabled'])) $mapping['disabled'] = $oldMap['disabled'];
        $titles = $_POST['map_title'] ?? [];
        $targets = $_POST['map_target'] ?? [];
        if (is_array($titles)) {
            foreach ($titles as $i => $t) {
                $t  = trim($t);
                $ta = trim($targets[$i] ?? '');
                if ($t === '' || $ta === '') continue;
                $mapping['title'][$t] = $ta;
            }
        }
        $cids   = $_POST['map_cid'] ?? [];
        $ctargs = $_POST['map_cid_target'] ?? [];
        if (is_array($cids)) {
            foreach ($cids as $i => $c) {
                $c  = trim($c);
                $ct = trim($ctargs[$i] ?? '');
                if ($c === '' || $ct === '') continue;
                $mapping['cid'][$c] = $ct;
            }
        }
        // 官方ID → 剧名+集数（key 形如 vid:xxx / cid:xxx）
        $ids   = $_POST['map_id'] ?? [];
        $enames = $_POST['map_ename'] ?? [];
        $eps    = $_POST['map_ep'] ?? [];
        if (is_array($ids)) {
            foreach ($ids as $i => $id) {
                $id  = trim($id);
                $en  = trim($enames[$i] ?? '');
                $ep  = (int)($eps[$i] ?? 0);
                if ($id === '' || $en === '' || $ep <= 0) continue;
                $mapping['episode'][$id] = ['name' => $en, 'episode' => $ep];
            }
        }
        mxgj_write_json($mappingFile, $mapping);
        Logger::log('operation', '保存映射表（title ' . count($mapping['title']) . ' / cid ' . count($mapping['cid']) . ' / episode ' . count($mapping['episode']) . '）', 'success');
        Logger::log('config', '映射表配置保存成功', 'success', ['count' => ['title' => count($mapping['title']), 'cid' => count($mapping['cid']), 'episode' => count($mapping['episode'])]]);
        header('Location: admin.php?tab=mapping&saved=1');
        exit;

    case 'save_settings':
        $st = mxgj_settings();
        $st['admin_password'] = trim($_POST['admin_password'] ?? '') !== ''
            ? $_POST['admin_password']
            : $st['admin_password'];
        $st['updater_key']    = trim($_POST['updater_key'] ?? '');
        $st['timeout']        = (int)($_POST['timeout'] ?? 15);
        $st['cache_ttl']      = (int)($_POST['cache_ttl'] ?? 600);
        $st['replace_domain'] = trim($_POST['replace_domain'] ?? '');
        // 资源站频率控制
        $sc = is_array($st['site_control'] ?? null) ? $st['site_control'] : [];
        $sc['search_interval']        = max(0, (int)($_POST['sc_search_interval'] ?? 10));
        $sc['heartbeat_enable']       = !empty($_POST['sc_heartbeat_enable']);
        $sc['heartbeat_interval']     = max(10, (int)($_POST['sc_heartbeat_interval'] ?? 300));
        $sc['heartbeat_timeout']      = max(1, (int)($_POST['sc_heartbeat_timeout'] ?? 5));
        $sc['heartbeat_max_fail']     = max(1, (int)($_POST['sc_heartbeat_max_fail'] ?? 3));
        $sc['cooldown_seconds']       = max(0, (int)($_POST['sc_cooldown_seconds'] ?? 600));
        $sc['rotation_enable']        = !empty($_POST['sc_rotation_enable']);
        $sc['rotation_interval']      = max(10, (int)($_POST['sc_rotation_interval'] ?? 300));
        $sc['max_sites_per_request']  = max(0, (int)($_POST['sc_max_sites_per_request'] ?? 0));
        $st['site_control'] = $sc;
        // 输出返回设置
        $outKeys = $_POST['out_k'] ?? [];
        $outVals = $_POST['out_v'] ?? [];
        $outOld  = is_array($st['output']['fields'] ?? null) ? $st['output']['fields'] : []; // 保留快捷开关状态
        $fields  = [];
        if (is_array($outKeys)) {
            foreach ($outKeys as $i => $k) {
                $k = trim((string)$k);
                $v = trim((string)($outVals[$i] ?? ''));
                if ($k === '' || $v === '') continue;
                $enabled = isset($outOld[$i]) && array_key_exists('enabled', $outOld[$i]) ? !empty($outOld[$i]['enabled']) : true;
                $fields[] = ['k' => $k, 'v' => $v, 'enabled' => $enabled];
            }
        }
        if ($fields === []) { // 至少保留 code 与 url，避免输出为空
            $fields = [
                ['k' => 'code', 'v' => 'code'],
                ['k' => 'msg',  'v' => 'url'],
                ['k' => 'url',  'v' => 'url'],
                ['k' => 'time', 'v' => 'time'],
                ['k' => 'KFZ',  'v' => '沫兮官替系统'],
            ];
        }
        $st['output'] = [
            'show_source' => !empty($_POST['out_show_source']),
            'fields'      => $fields,
        ];
        // 安全设置（欺诈/伪装）
        $sec = is_array($st['security'] ?? null) ? $st['security'] : [];
        $sec['obfuscate_enable'] = !empty($_POST['sec_obfuscate_enable']);
        $st['security'] = $sec;
        // App 设置（表面播放链接）
        $ap = is_array($st['app'] ?? null) ? $st['app'] : [];
        $ap['surface_enable'] = !empty($_POST['app_surface_enable']);
        $ap['surface_path']   = trim($_POST['app_surface_path'] ?? '') !== ''
            ? trim($_POST['app_surface_path'])
            : 'play.php';
        $ap['surface_mode']   = (($_POST['app_surface_mode'] ?? 'proxy') === 'redirect') ? 'redirect' : 'proxy';
        $st['app'] = $ap;
        mxgj_write_json($settingsFile, $st);
        Logger::log('operation', '保存系统设置', 'success');
        Logger::log('config', '系统设置保存成功', 'success', ['timeout' => $st['timeout'], 'cache_ttl' => $st['cache_ttl'], 'output_fields' => count($fields)]);
        // 更换密码后重登
        header('Location: admin.php?tab=settings&saved=1');
        exit;

    case 'heartbeat_now':
        // 立即触发一次资源站心跳探测
        $rep = SiteHealth::probeAllNow();
        $_SESSION['heartbeat_result'] = $rep;
        Logger::log('operation', '手动触发资源站心跳探测（' . ($rep['ok'] ? '完成' : '失败') . '）', $rep['ok'] ? 'success' : 'warn', ['reachable' => isset($rep['rows']) ? count(array_filter($rep['rows'], fn($r) => !empty($r['ok']))) : 0]);
        header('Location: admin.php?tab=settings&hb=1');
        exit;

    case 'health_reset':
        SiteHealth::reset();
        Logger::log('operation', '重置资源站健康状态', 'info');
        header('Location: admin.php?tab=settings&hr=1');
        exit;

    case 'logout':
        Logger::log('login', '后台退出登录', 'info', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        session_destroy();
        header('Location: admin.php');
        exit;

    case 'clear_cache':
        AdminHelper::clearCache();
        Logger::log('operation', '清空搜索缓存', 'info');
        header('Location: admin.php?tab=dashboard&cleared=1');
        exit;

    case 'test_site':
        // 前端 Ajax 测试单条资源站
        $title = trim($_POST['title'] ?? '');
        $ep    = max(1, (int)($_POST['episode'] ?? 1));
        $tpl   = trim($_POST['template'] ?? '');
        if ($title === '' || $tpl === '') {
            mxgj_json_out(['code' => 400, 'msg' => '缺少标题或模板']);
        }
        $site = ['name' => '测试', 'url' => $tpl, 'template' => $tpl];
        $res  = SiteSearcher::search([$site], $title, $ep, (int)mxgj_settings()['timeout']);
        Logger::log('operation', '测试资源站：' . $title . ' 第' . $ep . '集 → ' . ($res['code'] === 200 ? '命中' : $res['msg'] ?? '失败'), $res['code'] === 200 ? 'success' : 'warn', ['code' => $res['code']]);
        mxgj_json_out($res);

    case 'detect_site':
        // 检测苹果CMS10采集接口并自动构建模板
        $raw = trim($_POST['url'] ?? '');
        if ($raw === '') mxgj_json_out(['code' => 400, 'msg' => '请输入采集接口地址']);
        $det = SiteDetector::detect($raw, (int)mxgj_settings()['timeout']);
        Logger::log('operation', ($det['ok'] ? '检测资源站成功' : '检测资源站失败') . '：' . ($det['name'] ?? $raw), $det['ok'] ? 'success' : 'error', ['msg' => $det['msg'] ?? '']);
        mxgj_json_out($det);

    case 'save_site_one':
        // 弹窗里检测后确认保存（新增或按模板覆盖修改）
        $name_ = trim($_POST['name'] ?? '');
        $tpl_  = trim($_POST['template'] ?? '');
        if ($name_ === '' || $tpl_ === '') mxgj_json_out(['code' => 400, 'msg' => '缺少站点名称或模板']);
        $list = mxgj_sites();
        $hit  = null;
        foreach ($list as $i => $s) {
            if ((($s['template'] ?? ($s['url'] ?? '')) === $tpl_) || (($s['name'] ?? '') === $name_ && ($s['name'] ?? '') !== '')) {
                $hit = $i;
                break;
            }
        }
        $entry = ['name' => $name_, 'url' => $tpl_, 'template' => $tpl_];
        if ($hit !== null) {
            $entry['enabled'] = array_key_exists('enabled', $list[$hit]) ? !empty($list[$hit]['enabled']) : true; // 保留原启用状态
            $entry['proxy']   = (string)($list[$hit]['proxy'] ?? ''); // 保留跟随播放链接
            $list[$hit] = $entry;
            $mode = 'update';
        } else {
            $entry['enabled'] = true; // 新增默认启用
            $entry['proxy']   = trim($_POST['proxy'] ?? ''); // 跟随播放链接（中转前缀）
            $list[] = $entry;
            $mode = 'add';
        }
        mxgj_write_json($sitesFile, ['sites' => $list]);
        Logger::log('operation', ($mode === 'add' ? '添加' : '修改') . '资源站：' . $name_, 'success');
        Logger::log('config', ($mode === 'add' ? '新增' : '修改') . '资源站成功：' . $name_, 'success');
        mxgj_json_out(['code' => 200, 'ok' => true, 'msg' => ($mode === 'add' ? '已添加' : '已修改') . '资源站：' . $name_, 'sites' => count($list)]);

    case 'toggle_site':
        // 快捷启用/禁用单个资源站（即时生效，无需保存）
        $tpl_ = trim($_POST['template'] ?? '');
        $on   = !empty($_POST['enabled']);
        if ($tpl_ === '') mxgj_json_out(['code' => 400, 'msg' => '缺少资源站模板']);
        $list = mxgj_sites();
        $hit  = null;
        foreach ($list as $i => $s) {
            if (($s['template'] ?? ($s['url'] ?? '')) === $tpl_) { $hit = $i; break; }
        }
        if ($hit === null) mxgj_json_out(['code' => 404, 'msg' => '资源站不存在']);
        $list[$hit]['enabled'] = $on;
        mxgj_write_json($sitesFile, ['sites' => $list]);
        Logger::log('operation', ($on ? '启用' : '禁用') . '资源站：' . ($list[$hit]['name'] ?? ''), $on ? 'success' : 'warn', ['enabled' => $on]);
        Logger::log('config', ($on ? '启用' : '禁用') . '资源站配置：' . ($list[$hit]['name'] ?? ''), $on ? 'success' : 'warn');
        mxgj_json_out(['code' => 200, 'ok' => true, 'msg' => ($on ? '已启用' : '已禁用') . '资源站：' . ($list[$hit]['name'] ?? '')]);

    case 'toggle_mapping':
        // 快捷启用/禁用映射条目（sec=title/cid/episode，key=条目键，enabled=1/0）
        $sec = trim($_POST['sec'] ?? '');
        $key = trim($_POST['key'] ?? '');
        $on  = !empty($_POST['enabled']);
        if (!in_array($sec, ['title', 'cid', 'episode'], true) || $key === '') {
            mxgj_json_out(['code' => 400, 'msg' => '参数不合法']);
        }
        $map = mxgj_read_json($mappingFile, []);
        if (!isset($map['disabled']) || !is_array($map['disabled'])) $map['disabled'] = [];
        if (!isset($map['disabled'][$sec]) || !is_array($map['disabled'][$sec])) $map['disabled'][$sec] = [];
        // 不存在该条目则报错
        if (!isset($map[$sec][$key])) mxgj_json_out(['code' => 404, 'msg' => '映射条目不存在']);
        $d = &$map['disabled'][$sec];
        $idx = array_search($key, $d, true);
        if ($on && $idx !== false)     unset($d[$idx]);   // 启用：从禁用列表移除
        if (!$on && $idx === false)    $d[] = $key;       // 禁用：加入禁用列表
        $d = array_values($d);
        mxgj_write_json($mappingFile, $map);
        Logger::log('operation', ($on ? '启用' : '禁用') . '映射：' . $sec . ' → ' . $key, $on ? 'success' : 'warn');
        Logger::log('config', ($on ? '启用' : '禁用') . '映射条目：' . $sec . ' → ' . $key, $on ? 'success' : 'warn');
        mxgj_json_out(['code' => 200, 'ok' => true, 'msg' => ($on ? '已启用' : '已禁用') . '映射：' . $key]);

    case 'toggle_output':
        // 快捷启用/禁用输出返回字段（按索引定位）
        $idx  = (int)($_POST['idx'] ?? -1);
        $on   = !empty($_POST['enabled']);
        $st   = mxgj_settings();
        $fields = is_array($st['output']['fields'] ?? null) ? $st['output']['fields'] : [];
        if (!isset($fields[$idx])) mxgj_json_out(['code' => 404, 'msg' => '字段不存在']);
        $fields[$idx]['enabled'] = $on;
        $st['output']['fields'] = $fields;
        mxgj_write_json($settingsFile, $st);
        Logger::log('operation', ($on ? '启用' : '禁用') . '输出字段：' . ($fields[$idx]['k'] ?? ''), $on ? 'success' : 'warn');
        Logger::log('config', ($on ? '启用' : '禁用') . '输出字段「' . ($fields[$idx]['k'] ?? '') . '」', $on ? 'success' : 'warn');
        mxgj_json_out(['code' => 200, 'ok' => true, 'msg' => ($on ? '已启用' : '已禁用') . '输出字段：' . ($fields[$idx]['k'] ?? '')]);

    case 'toggle_setting':
        // 快捷开关设置项（site_control 的心跳/轮训、security 的欺诈伪装）
        $name = trim($_POST['name'] ?? '');
        $on   = !empty($_POST['enabled']);
        $st   = mxgj_settings();
        if ($name === 'heartbeat_enable' || $name === 'rotation_enable') {
            $sc = is_array($st['site_control'] ?? null) ? $st['site_control'] : [];
            $sc[$name] = $on;
            $st['site_control'] = $sc;
            $labels = ['heartbeat_enable' => '心跳检测', 'rotation_enable' => '资源站轮训'];
        } elseif ($name === 'obfuscate_enable') {
            $sec = is_array($st['security'] ?? null) ? $st['security'] : [];
            $sec['obfuscate_enable'] = $on;
            $st['security'] = $sec;
            $labels = ['obfuscate_enable' => '欺诈/伪装'];
        } elseif ($name === 'app_surface_enable') {
            $ap = is_array($st['app'] ?? null) ? $st['app'] : [];
            $ap['surface_enable'] = $on;
            $st['app'] = $ap;
            $labels = ['app_surface_enable' => '表面播放链接'];
        } else {
            mxgj_json_out(['code' => 400, 'msg' => '不支持的设置项']);
        }
        mxgj_write_json($settingsFile, $st);
        Logger::log('operation', ($on ? '开启' : '关闭') . '设置：' . ($labels[$name] ?? $name), $on ? 'success' : 'warn');
        Logger::log('config', ($on ? '开启' : '关闭') . '设置「' . ($labels[$name] ?? $name) . '」', $on ? 'success' : 'warn');
        mxgj_json_out(['code' => 200, 'ok' => true, 'msg' => ($on ? '已开启' : '已关闭') . '：' . ($labels[$name] ?? $name)]);

    case 'log_clear':
        // 清空日志（type=all 清空全部；否则清空指定类型）
        $type = trim($_POST['type'] ?? '');
        if ($type === 'all') {
            $res = Logger::clearAll();
            Logger::log('operation', '清空全部日志', 'info');
            mxgj_json_out(['code' => 200, 'ok' => true, 'msg' => '已清空全部日志', 'cleared' => $res]);
        }
        if (!isset(Logger::TYPES[$type])) {
            mxgj_json_out(['code' => 400, 'msg' => '未知日志类型']);
        }
        $n = Logger::clear($type);
        Logger::log('operation', '清空日志：' . Logger::TYPES[$type], 'info');
        mxgj_json_out(['code' => 200, 'ok' => true, 'msg' => '已清空「' . Logger::TYPES[$type] . '」共 ' . $n . ' 条', 'cleared' => $n]);

    case 'do_update':
        // 后台触发的在线更新（dry=1 时仅测速）
        $st  = mxgj_settings();
        $res = Updater::run($st['admin_password'], !empty($_POST['dry']));
        Logger::log('update', (!empty($_POST['dry']) ? '[测速] ' : '') . ($res['ok'] ? '更新成功' : '更新失败') . '：' . $res['msg'], $res['ok'] ? 'success' : 'error', ['applied' => $res['applied'] ?? false, 'steps' => $res['steps'] ?? [], 'speed' => $res['speed'] ?? []]);
        mxgj_json_out([
            'ok'    => $res['ok'],
            'applied' => $res['applied'] ?? false,
            'msg'   => $res['msg'],
            'steps' => $res['steps'],
            'speed' => $res['speed'],
        ]);

    case 'test_full':
        // 一键测试：AI 智能分析（解析 + 页面抓取）→ 自动固化映射 → 去资源站查找
        $url = trim($_POST['url'] ?? '');
        if ($url === '') mxgj_json_out(['code' => 400, 'msg' => '缺少链接']);
        $parsed = LinkParser::parse($url);
        $name   = $parsed['title'];
        $ep     = $parsed['episode'] > 0 ? $parsed['episode'] : 1;

        // 若链接无法直接识别，则抓取官方页面做 AI 分析
        $aiMode = '解析';
        if ($name === '') {
            $pg   = ['title' => '', 'episode' => 0];
            $pgk  = 'page:' . ($parsed['vid'] !== '' ? $parsed['vid'] : ($parsed['cid'] !== '' ? $parsed['cid'] : md5($url)));
            $pgr  = Cache::get($pgk);
            if (is_array($pgr) && !empty($pgr['title'])) {
                $pg = $pgr;
            } else {
                $pg = PageResolver::resolve($url, $ep, (int)mxgj_settings()['timeout']);
                if (!empty($pg['title'])) Cache::set($pgk, $pg, 600);
            }
            if (!empty($pg['title'])) {
                $name    = $pg['title'];
                $aiMode  = '页面抓取';
                if (!empty($pg['episode'])) $ep = (int)$pg['episode'];
                // AI 识别出集数后自动固化映射
                if ($ep > 0) mxgj_auto_mapping($parsed, $name, $ep);
            }
        }

        $res = SiteSearcher::search(mxgj_sites(), $name, $ep, (int)mxgj_settings()['timeout']);
        $mapped = mxgj_read_json(MXGJ_CONFIG . '/mapping.json', []);
        $mapKey = $parsed['vid'] !== '' ? 'vid:' . $parsed['vid'] : ($parsed['cid'] !== '' ? 'cid:' . $parsed['cid'] : '');
        $hasMap = isset($mapped['episode'][$mapKey]);
        mxgj_json_out([
            'code'    => $res['code'],
            'url'     => $res['url'],
            'msg'     => $res['msg'],
            'episode' => $ep,
            'debug'   => [
                'parsed'       => $parsed,
                'targetTitle'  => $name,
                'ai_mode'      => $aiMode,
                'auto_mapped'  => $aiMode === '页面抓取' ? $hasMap : false,
                'mapping_key'  => $mapKey !== '' ? $mapKey : null,
            ],
        ]);

    default:
        header('Location: admin.php');
        exit;
}

/* ---------------- 渲染函数 ---------------- */

function renderLogin()
{
    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>沫兮官替系统 - 登录</title>
        <style>
            body{font-family:system-ui,-apple-system,Segoe UI,Microsoft YaHei,sans-serif;background:#0f1420;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
            .card{background:#1b2233;padding:40px;border-radius:12px;width:320px;box-shadow:0 10px 40px rgba(0,0,0,.4)}
            h1{color:#fff;font-size:20px;margin:0 0 6px}
            p{color:#8a93a6;font-size:13px;margin:0 0 24px}
            input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #2c3550;background:#12172a;color:#fff;border-radius:8px;margin-bottom:16px;font-size:14px}
            button{width:100%;padding:12px;border:0;border-radius:8px;background:#4f7cff;color:#fff;font-size:15px;cursor:pointer}
            button:hover{background:#3f6ce8}
        </style>
    </head>
    <body>
        <form class="card" method="post">
            <h1><?= MXGJ_NAME ?></h1>
            <p>请输入后台管理密码</p>
            <input type="password" name="password" placeholder="管理密码" required autofocus>
            <input type="hidden" name="action" value="login">
            <button type="submit">登 录</button>
        </form>
    </body></html>
    <?php
}

function renderDashboard()
{
    global $settingsFile, $sitesFile, $mappingFile;
    $settings = mxgj_settings();
    $sites    = mxgj_sites();
    $mapping  = mxgj_read_json($mappingFile, ['title' => [], 'cid' => []]);
    $cacheCnt = AdminHelper::cacheCount();
    $tab = $_GET['tab'] ?? 'dashboard';
    $saved = isset($_GET['saved']);
    $cleared = isset($_GET['cleared']);
    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>沫兮官替系统 - 后台</title>
        <style>
            *{box-sizing:border-box}
            body{font-family:system-ui,-apple-system,Segoe UI,Microsoft YaHei,sans-serif;background:#0f1420;color:#e6e9f0;margin:0}
            .wrap{max-width:960px;margin:0 auto;padding:24px}
            header{display:flex;align-items:center;justify-content:space-between;padding:16px 0;border-bottom:1px solid #223;margin-bottom:20px}
            header h1{font-size:18px;margin:0;color:#fff}
            .logo{color:#4f7cff}
            .tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
            .tabs a{padding:8px 16px;border-radius:8px;text-decoration:none;color:#b8c0d2;background:#1b2233;font-size:14px}
            .tabs a.active{background:#4f7cff;color:#fff}
            .panel{background:#1b2233;border-radius:12px;padding:20px}
            table{width:100%;border-collapse:collapse;font-size:14px}
            td,th{padding:10px 8px;border-bottom:1px solid #242e48;text-align:left}
            th{color:#8a93a6;font-weight:600;font-size:12px}
            input[type=text],input[type=password],input[type=number],select{width:100%;padding:8px 10px;border:1px solid #2c3550;background:#12172a;color:#e6e9f0;border-radius:6px;font-size:13px}
            .btn{padding:10px 18px;border:0;border-radius:8px;background:#4f7cff;color:#fff;font-size:14px;cursor:pointer}
            .btn-green{background:#2ecc71}.btn-danger{background:#e74c3c}
            .btn:hover{opacity:.9}
            .small{font-size:12px;color:#8a93a6}
            .note{background:#243352;border-radius:8px;padding:12px;font-size:13px;line-height:1.7}
            .toast{display:none;position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#2ecc71;color:#fff;padding:10px 20px;border-radius:8px;font-size:14px}
            .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
            .form-grid .full{grid-column:1/-1}
            label{display:block;font-size:12px;color:#8a93a6;margin-bottom:6px}
            .stat-cards{display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap}
            .stat{background:#1b2233;border-radius:12px;padding:18px 22px;min-width:160px}
            .stat b{font-size:26px;color:#fff}
            .stat span{display:block;font-size:12px;color:#8a93a6;margin-top:4px}
            h2{font-size:16px;margin:0 0 16px;color:#fff}
            pre{background:#0d1118;padding:12px;border-radius:8px;overflow:auto;font-size:12px}
            .center{text-align:center}
            .row-disabled td{opacity:.5}
            .row-disabled input[type=text]{text-decoration:line-through}
            /* 启用/禁用开关 */
            .toggle{position:relative;display:inline-block;width:44px;height:24px;vertical-align:middle}
            .toggle input{display:none}
            .toggle .slider{position:absolute;cursor:pointer;inset:0;background:#3a4460;border-radius:24px;transition:.2s}
            .toggle .slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
            .toggle input:checked + .slider{background:#2ecc71}
            .toggle input:checked + .slider:before{transform:translateX(20px)}
        </style>
    </head>
    <body>
    <div class="wrap">
        <header>
            <h1><span class="logo">◆</span> <?= MXGJ_NAME ?> <span class="small">v<?= MXGJ_VERSION ?></span></h1>
            <form method="post" style="margin:0"><input type="hidden" name="action" value="logout"><button class="btn btn-danger">退出登录</button></form>
        </header>

        <div class="tabs">
            <a href="?tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>">概览</a>
            <a href="?tab=sites" class="<?= $tab==='sites'?'active':'' ?>">资源站</a>
            <a href="?tab=mapping" class="<?= $tab==='mapping'?'active':'' ?>">映射表</a>
            <a href="?tab=update" class="<?= $tab==='update'?'active':'' ?>">更新</a>
            <a href="?tab=logs" class="<?= $tab==='logs'?'active':'' ?>">日志</a>
            <a href="?tab=help" class="<?= $tab==='help'?'active':'' ?>">帮助</a>
            <a href="?tab=settings" class="<?= $tab==='settings'?'active':'' ?>">设置</a>
        </div>

        <div class="toast" id="toast">保存成功</div>

        <?php if ($saved): ?><script>document.getElementById('toast').style.display='block';setTimeout(()=>document.getElementById('toast').style.display='none',2000);</script><?php endif; ?>
        <?php if ($cleared): ?><script>alert('缓存已清空');</script><?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <?php renderOverview($sites, $cacheCnt, $mapping); ?>
        <?php elseif ($tab === 'sites'): ?>
            <?php renderSitesForm($sites); ?>
        <?php elseif ($tab === 'mapping'): ?>
            <?php renderMappingForm($mapping); ?>
        <?php elseif ($tab === 'update'): ?>
            <?php renderUpdateForm(); ?>
        <?php elseif ($tab === 'logs'): ?>
            <?php renderLogs(); ?>
        <?php elseif ($tab === 'help'): ?>
            <?php renderHelp(); ?>
        <?php elseif ($tab === 'settings'): ?>
            <?php renderSettingsForm($settings); ?>
        <?php endif; ?>
    </div>
    </body></html>
    <?php
}

function renderOverview($sites, $cacheCnt, $mapping)
{
    ?>
    <div class="stat-cards">
        <div class="stat"><b><?= count($sites) ?></b><span>资源站</span></div>
        <div class="stat"><b><?= $cacheCnt ?></b><span>缓存文件</span></div>
        <div class="stat"><b><?= count($mapping['title'] ?? []) + count($mapping['cid'] ?? []) ?></b><span>映射条目</span></div>
    </div>

    <div class="panel" style="margin-bottom:16px">
        <h2>接口说明</h2>
        <pre><code>GET /index.php?url=&lt官方链接&gt[&page=&lt集数&gt][&debug=1]
例：/index.php?url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce</code></pre>
    </div>

    <div class="panel" style="margin-bottom:16px">
        <h2>一键测试</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <input id="t-url" type="text" placeholder="粘贴官方播放链接" style="flex:1;min-width:300px">
            <button class="btn" onclick="testFull()">解析测试</button>
        </div>
        <pre id="t-out" style="margin-top:12px;display:none"></pre>
    </div>

    <div class="panel">
        <h2>当前资源站</h2>
        <?php if (!$sites): ?>
            <p class="small">尚未配置资源站，请到「资源站」页添加。</p>
        <?php else: ?>
        <table>
            <tr><th>站点名称</th><th>模板地址</th></tr>
            <?php foreach ($sites as $s): ?>
            <tr><td><?= htmlspecialchars($s['name']) ?></td><td class="small"><?= htmlspecialchars($s['template']) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <form method="post" style="margin-top:16px">
        <input type="hidden" name="action" value="clear_cache">
        <button class="btn btn-danger">清空搜索缓存</button>
    </form>

    <script>
    function testFull(){
        var url=document.getElementById('t-url').value;
        if(!url){alert('请输入链接');return;}
        document.getElementById('t-out').style.display='block';
        document.getElementById('t-out').textContent='解析中...';
        var fd=new FormData();fd.append('action','test_full');fd.append('url',url);
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            document.getElementById('t-out').textContent=JSON.stringify(d,null,2);
        }).catch(e=>document.getElementById('t-out').textContent='请求失败:'+e);
    }
    </script>
    <?php
}

function renderSitesForm($sites)
{
    ?>
    <div class="panel">
        <h2>资源站列表</h2>
        <div class="note" style="margin-bottom:16px">
            模板占位符说明：<b>%s</b>=剧名（不转码）、<b>%p</b>=集数、<b>%u/%t</b>=剧名（URL 编码）。<br>
            示例一（JSON 源）：<code>http://api.example.com/vod?wd=%u&ep=%p</code><br>
            示例二（列表页）：<code>http://your.site/search?wd=%s&page=%p</code><br>
            <b>跟随播放链接（中转前缀）</b>：仅该资源站命中时，在真实播放地址前拼接，如 <code>https://vv00.xyz?url=</code>
            → 返回 <code>https://vv00.xyz?url=真实地址</code>。留空则直接返回原地址。<br>
            支持 <code>{host}</code> 占位符（自动替换为当前域名）：填 <code>{host}/player.php?url=</code>
            → 返回 <code>https://你的域名/player.php?url=真实地址</code>（特殊资源站可由此套用本站播放器）。<br>
            每行「启用」开关<b>即时生效</b>（无需保存）：关闭后该资源站不再参与前台搜索与心跳探测。
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
            <button type="button" class="btn btn-green" onclick="openDetect()">⚡ 检测并自动添加（苹果CMS10采集接口）</button>
            <span class="note" style="margin:0;color:#8892ab">只需粘贴采集接口地址，系统自动探测并生成搜索模板，保存后即可被前台调用。</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="save_sites">
            <table id="site-tbl">
                <tr><th style="width:130px">站点名称</th><th>搜索地址模板（含 %s / %u / %p）</th><th>跟随播放链接（中转前缀）</th><th style="width:60px">启用</th><th style="width:120px"></th></tr>
                <?php if ($sites): foreach ($sites as $i => $s): ?>
                    <?php $sEnabled = !array_key_exists('enabled', $s) || !empty($s['enabled']); ?>
                    <tr class="<?= $sEnabled ? '' : 'row-disabled' ?>">
                        <td><input type="text" name="sites[<?= $i ?>][name]" value="<?= htmlspecialchars($s['name']) ?>"></td>
                        <td><input type="text" name="sites[<?= $i ?>][template]" value="<?= htmlspecialchars($s['template']) ?>" onclick="this.select()"></td>
                        <td><input type="text" name="sites[<?= $i ?>][proxy]" value="<?= htmlspecialchars($s['proxy'] ?? '') ?>" placeholder="如 https://vv00.xyz?url= 或 {host}/player.php?url= （留空不使用）"></td>
                        <td class="center">
                            <label class="toggle">
                                <input type="checkbox" class="quick-toggle" data-action="site" <?= $sEnabled ? 'checked' : '' ?>
                                       data-tpl="<?= htmlspecialchars($s['template'], ENT_QUOTES) ?>">
                                <span class="slider" title="<?= $sEnabled ? '已启用：参与搜索' : '已禁用：不参与搜索' ?>"></span>
                            </label>
                        </td>
                        <td>
                            <button type="button" class="btn" onclick="openDetect(<?= htmlspecialchars(json_encode([$s['name'], $s['template']])) ?>)">编辑</button>
                            <button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td><input type="text" name="sites[0][name]" placeholder="站点A"></td>
                        <td><input type="text" name="sites[0][template]" placeholder="http://api.example.com/vod?wd=%u&ep=%p"></td>
                        <td><input type="text" name="sites[0][proxy]" placeholder="如 https://vv00.xyz?url="></td>
                        <td class="center"><input type="checkbox" checked disabled title="新站点默认启用"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endif; ?>
            </table>
            <div style="margin:16px 0">
                <button type="button" class="btn" onclick="addRow()">+ 添加资源站（手动）</button>
                <button type="submit" class="btn btn-green">保存资源站</button>
            </div>
        </form>

        <h3>单条资源站测试</h3>
        <div class="form-grid">
            <div><label>剧名</label><input type="text" id="tt-title" placeholder="庆余年"></div>
            <div><label>集数</label><input type="number" id="tt-ep" value="1" min="1"></div>
            <div class="full"><label>模板地址</label><input type="text" id="tt-tpl" placeholder="http://api.example.com/vod?wd=%u&ep=%p"></div>
        </div>
        <button class="btn" onclick="testSite()">测试该资源站</button>
        <pre id="tt-out" style="display:none;margin-top:12px"></pre>
    </div>
    <script>
    var rowIndex=<?= count($sites) ?>;
    function addRow(){
        var tbl=document.getElementById('site-tbl');
        var tr=document.createElement('tr');
        tr.innerHTML='<td><input type="text" name="sites['+rowIndex+'][name]" placeholder="站点"></td>'+
            '<td><input type="text" name="sites['+rowIndex+'][template]" placeholder="http://..." onclick="this.select()"></td>'+
            '<td><input type="text" name="sites['+rowIndex+'][proxy]" placeholder="如 https://vv00.xyz?url="></td>'+
            '<td class="center"><input type="checkbox" checked disabled title="新站点默认启用"></td>'+
            '<td><button type="button" class="btn btn-danger" onclick="this.closest(\'tr\').remove()">删除</button></td>';
        tbl.appendChild(tr);rowIndex++;
    }
    /* ---- 通用快捷开关（即时生效，无需保存）：资源站 / 映射 / 输出字段 / 设置项 ---- */
    document.addEventListener('change', function(e){
        var cb=e.target;
        if(!cb.classList||!cb.classList.contains('quick-toggle'))return;
        var on=cb.checked, act=cb.getAttribute('data-action'), tr=cb.closest('tr');
        var fd=new FormData();
        fd.append('enabled',on?'1':'');
        if(act==='site'){ fd.append('action','toggle_site'); fd.append('template',cb.getAttribute('data-tpl')); }
        else if(act==='mapping'){ fd.append('action','toggle_mapping'); fd.append('sec',cb.getAttribute('data-sec')); fd.append('key',cb.getAttribute('data-key')); }
        else if(act==='output'){ fd.append('action','toggle_output'); fd.append('idx',cb.getAttribute('data-idx')); }
        else if(act==='setting'){ fd.append('action','toggle_setting'); fd.append('name',cb.getAttribute('data-name')); }
        else{ return; }
        fetch('admin.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
            if(d.ok){ if(tr)tr.classList.toggle('row-disabled',!on); }
            else{ cb.checked=!on; alert(d.msg||'切换失败'); }
        }).catch(function(){ cb.checked=!on; alert('网络错误，切换失败'); });
    });
    function testSite(){
        var title=document.getElementById('tt-title').value;
        var ep=document.getElementById('tt-ep').value;
        var tpl=document.getElementById('tt-tpl').value;
        if(!title||!tpl){alert('请填写剧名和模板');return;}
        var out=document.getElementById('tt-out');out.style.display='block';out.textContent='测试中...';
        var fd=new FormData();fd.append('action','test_site');fd.append('title',title);fd.append('episode',ep);fd.append('template',tpl);
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>out.textContent=JSON.stringify(d,null,2))
            .catch(e=>out.textContent='失败:'+e);
    }
    /* ---- 苹果CMS采集接口 检测 / 自动添加 / 修改 ---- */
    function openDetect(pre){
        if(pre&&pre.length===2){
            document.getElementById('dd-url').value=(pre[1]||'').replace(/[?&]ac=videolist[&]?|(?:\?|&)wd=%u[&]?/g,'');
            document.getElementById('dd-name').value=pre[0]||'';
            document.getElementById('dd-tpl').value=pre[1]||'';
            document.getElementById('dd-msg').textContent='已载入「编辑」模式：修正后点「重新检测」或直接修改保存。';
            document.getElementById('dd-msg').style.color='#7fc1ff';
        }else{
            document.getElementById('dd-url').value='';
            document.getElementById('dd-name').value='';
            document.getElementById('dd-tpl').value='';
            document.getElementById('dd-msg').textContent='粘贴苹果CMS10采集接口地址，点「检测」自动生成模板与名称。';
            document.getElementById('dd-msg').style.color='';
            document.getElementById('dd-hint').style.display='none';
        }
        document.getElementById('dd-modal').style.display='flex';
    }
    function closeDetect(){ document.getElementById('dd-modal').style.display='none'; }
    function detectSite(){
        var url=document.getElementById('dd-url').value.trim();
        if(!url){alert('请先填写采集接口地址');return;}
        var msg=document.getElementById('dd-msg');
        msg.textContent='检测中...';msg.style.color='#e2b93b';
        document.getElementById('dd-hint').style.display='none';
        var fd=new FormData();fd.append('action','detect_site');fd.append('url',url);
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
            if(d.ok){
                document.getElementById('dd-name').value=d.name||'';
                document.getElementById('dd-tpl').value=d.template||'';
                document.getElementById('dd-hint').style.display='block';
                var smp=d.sample||{};
                document.getElementById('dd-hint-name').textContent=smp.name||'(无)';
                document.getElementById('dd-hint-url').textContent=smp.url||'';
                msg.textContent='✔ 检测成功（'+d.type+'）：可获取真实资源，模板已生成，可修改后保存。';
                msg.style.color='#2ecc71';
            }else{
                msg.textContent='✘ 检测失败：'+(d.msg||'未知错误');
                msg.style.color='#e74c3c';
            }
        }).catch(function(e){msg.textContent='请求失败:'+e;msg.style.color='#e74c3c';});
    }
    function saveSiteOne(){
        var name=document.getElementById('dd-name').value.trim();
        var tpl=document.getElementById('dd-tpl').value.trim();
        if(!name||!tpl){alert('请填写站点名称和模板');return;}
        var msg=document.getElementById('dd-msg');
        var fd=new FormData();fd.append('action','save_site_one');fd.append('name',name);fd.append('template',tpl);
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
            if(d.ok){ msg.textContent='✔ '+d.msg+'，正在刷新...';msg.style.color='#2ecc71';
                setTimeout(function(){location.href='admin.php?tab=sites&saved=1';},500);
            }else{ msg.textContent='✘ '+(d.msg||'保存失败');msg.style.color='#e74c3c'; }
        }).catch(function(e){alert('请求失败:'+e);});
    }
    </script>

    <div id="dd-modal" style="display:none;position:fixed;inset:0;background:rgba(5,10,20,.7);z-index:999;align-items:center;justify-content:center">
        <div style="background:#141b2d;border:1px solid #2a3550;border-radius:12px;padding:22px;width:640px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.5)">
            <h3 style="margin:0 0 12px;font-size:15px">⚡ 检测并自动添加 / 修改资源站（苹果CMS10 采集接口）</h3>
            <div style="font-size:13px;color:#b8c0d2;margin-bottom:12px">只需粘贴采集接口地址，系统会自动探测返回格式、生成「搜索模板」与站点名称。</div>
            <div><label>采集接口地址</label>
                <input type="text" id="dd-url" placeholder="https://caiji.xxx.com/api.php/provide/vod/" style="width:100%;box-sizing:border-box">
            </div>
            <div style="margin-top:10px"><button type="button" class="btn btn-green" onclick="detectSite()" style="margin-right:8px">检测</button><button type="button" class="btn" onclick="closeDetect()">取消</button></div>
            <div id="dd-hint" style="display:none;background:#10172a;border:1px solid #223;border-radius:8px;padding:10px 12px;margin-top:12px;font-size:12px;color:#9aa4bc">
                样本：<span id="dd-hint-name"></span><br>首条地址：<code id="dd-hint-url" style="word-break:break-all"></code>
            </div>
            <div id="dd-msg" style="margin-top:12px;font-size:13px">粘贴苹果CMS10采集接口地址，点「检测」自动生成模板与名称。</div>
            <div style="margin-top:14px">
                <div style="margin-bottom:8px"><label>站点名称</label><input type="text" id="dd-name" style="width:100%;box-sizing:border-box"></div>
                <div><label>搜索模板（已自动生成，可微调）</label><input type="text" id="dd-tpl" style="width:100%;box-sizing:border-box" onclick="this.select()"></div>
            </div>
            <div style="margin-top:16px;text-align:right">
                <button type="button" class="btn" onclick="closeDetect()" style="margin-right:8px">关闭</button>
                <button type="button" class="btn btn-green" onclick="saveSiteOne()">✔ 确认保存</button>
            </div>
        </div>
    </div>
    <?php
}

function renderMappingForm($mapping)
{
    $titles  = $mapping['title'] ?? [];
    $cids    = $mapping['cid'] ?? [];
    $episode = $mapping['episode'] ?? [];
    ?>
    <div class="panel">
        <h2>官方ID映射（推荐）</h2>
        <div class="note" style="margin-bottom:16px">
            将官方链接的 <b>vid / cid</b> 直接映射到「剧名 + 集数」。格式：<code>vid:视频ID</code> 或 <code>cid:剧集ID</code>。
            示例：官方链接 <code>.../play?cid=mzc00200zx8psx0&vid=k4102szvyce</code> 为庆余年第2集，
            填写 ID = <code>vid:k4102szvyce</code>，剧名 = <code>庆余年</code>，集数 = <code>2</code>。
        </div>
        <form method="post">
            <input type="hidden" name="action" value="save_mapping">
            <table>
                <tr><th style="width:240px">ID（vid:xxx 或 cid:xxx）</th><th>剧名</th><th style="width:90px">集数</th><th style="width:60px">启用</th><th style="width:60px"></th></tr>
                <?php if ($episode): $i=0; foreach ($episode as $k => $v): $on = mxgj_mapping_enabled($mapping, 'episode', $k); ?>
                    <tr class="<?= $on ? '' : 'row-disabled' ?>">
                        <td><input type="text" name="map_id[]" value="<?= htmlspecialchars($k) ?>"></td>
                        <td><input type="text" name="map_ename[]" value="<?= htmlspecialchars($v['name'] ?? '') ?>"></td>
                        <td><input type="number" name="map_ep[]" value="<?= (int)($v['episode'] ?? 0) ?>" min="1"></td>
                        <td class="center">
                            <label class="toggle">
                                <input type="checkbox" class="quick-toggle" data-action="mapping" data-sec="episode" data-key="<?= htmlspecialchars($k, ENT_QUOTES) ?>" <?= $on ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php $i++; endforeach; else: ?>
                    <tr>
                        <td><input type="text" name="map_id[]" placeholder="vid:k4102szvyce"></td>
                        <td><input type="text" name="map_ename[]" placeholder="庆余年"></td>
                        <td><input type="number" name="map_ep[]" value="2" min="1"></td>
                        <td class="center"><input type="checkbox" checked disabled title="新条目默认启用"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endif; ?>
            </table>

            <h2 style="margin-top:24px">剧名映射</h2>
            <div class="note" style="margin-bottom:16px">
                当官方链接解析出的剧名与资源站使用的剧名不一致时使用。
                格式：<b>解析出的剧名</b> → <b>资源站剧名</b>。<br>
                <b>启用开关</b>：点击即时生效，关闭后该映射不再参与匹配（排查/临时关闭）。
            </div>
            <table>
                <tr><th>解析出的剧名</th><th>资源站剧名</th><th style="width:60px">启用</th><th style="width:60px"></th></tr>
                <?php if ($titles): ?>
                    <?php $i=0; foreach ($titles as $k => $v): $on = mxgj_mapping_enabled($mapping, 'title', $k); ?>
                        <tr class="<?= $on ? '' : 'row-disabled' ?>">
                            <td><input type="text" name="map_target[]" value="<?= htmlspecialchars($k) ?>"></td>
                            <td><input type="text" name="map_title[]" value="<?= htmlspecialchars($v) ?>"></td>
                            <td class="center">
                                <label class="toggle">
                                    <input type="checkbox" class="quick-toggle" data-action="mapping" data-sec="title" data-key="<?= htmlspecialchars($k, ENT_QUOTES) ?>" <?= $on ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                    <?php $i++; endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td><input type="text" name="map_target[]" placeholder="解析出的剧名"></td>
                        <td><input type="text" name="map_title[]" placeholder="资源站剧名"></td>
                        <td class="center"><input type="checkbox" checked disabled title="新条目默认启用"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endif; ?>
            </table>

            <h2 style="margin-top:24px">腾讯 cid 映射（仅剧名）</h2>
            <table>
                <tr><th>腾讯 cid</th><th>剧名（资源站使用）</th><th style="width:60px">启用</th><th style="width:60px"></th></tr>
                <?php if ($cids): ?>
                    <?php $i=0; foreach ($cids as $k => $v): $on = mxgj_mapping_enabled($mapping, 'cid', $k); ?>
                        <tr class="<?= $on ? '' : 'row-disabled' ?>">
                            <td><input type="text" name="map_cid[]" value="<?= htmlspecialchars($k) ?>"></td>
                            <td><input type="text" name="map_cid_target[]" value="<?= htmlspecialchars($v) ?>"></td>
                            <td class="center">
                                <label class="toggle">
                                    <input type="checkbox" class="quick-toggle" data-action="mapping" data-sec="cid" data-key="<?= htmlspecialchars($k, ENT_QUOTES) ?>" <?= $on ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                    <?php $i++; endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td><input type="text" name="map_cid[]" placeholder="mzc00200zx8psx0"></td>
                        <td><input type="text" name="map_cid_target[]" placeholder="庆余年"></td>
                        <td class="center"><input type="checkbox" checked disabled title="新条目默认启用"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endif; ?>
            </table>
            <div style="margin-top:16px"><button type="submit" class="btn btn-green">保存映射</button></div>
        </form>
    </div>
    <?php
}

function renderUpdateForm()
{
    $st = mxgj_settings();
    $upKey = isset($st['updater_key']) && $st['updater_key'] !== '' ? $st['updater_key'] : ($st['admin_password'] ?? '');
    ?>
    <div class="panel">
        <h2>在线更新</h2>
        <div class="note" style="margin-bottom:16px">
            从 GitHub 自动拉取最新代码进行升级。<br>
            · 更新前会删除当前代码文件（保留 <b>config/</b> 配置与 <b>data/</b> 缓存）<br>
            · 面向国内网络，自动检测多个 <b>GitHub 加速镜像</b> 并选择最快节点下载<br>
            · 升级后文件与子目录权限统一设为 <b>0777</b>
        </div>
        <div class="form-grid">
            <div><label>仓库</label><input type="text" value="<?= htmlspecialchars(($st['repo_owner'] ?? 'ssmhdssmhd')) . '/' . htmlspecialchars($st['repo_name'] ?? 'MXGJ') . '@' . htmlspecialchars($st['repo_branch'] ?? 'main') ?>" readonly></div>
            <div><label>升级密钥（update.php 用）</label><input type="text" value="<?= htmlspecialchars($upKey) ?>" readonly></div>
        </div>
        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn btn-green" onclick="doUpdate()">立即更新</button>
            <button class="btn" onclick="dryUpdate()">仅测速（不执行）</button>
        </div>
        <div id="up-out" style="display:none;margin-top:16px">
            <h2 style="font-size:14px">更新报告</h2>
            <pre id="up-pre" style="background:#0d1118;padding:12px;border-radius:8px;overflow:auto;font-size:12px"></pre>
        </div>
        <div class="note" style="margin-top:20px">
            手动升级地址：<code>http://你的域名/update.php?key=<?= htmlspecialchars($upKey) ?></code><br>
            当后台自动更新失败时，可直接访问该地址完成升级；加 <code>&dry=1</code> 仅测速排查。
        </div>
    </div>
    <script>
    function doUpdate(){ runUpdater(false); }
    function dryUpdate(){ runUpdater(true); }
    function runUpdater(dry){
        var out=document.getElementById('up-out'), pre=document.getElementById('up-pre');
        out.style.display='block'; pre.textContent=(dry?'[dry] 仅测速中...':'更新中，请稍候（含测速+下载+解压+替换+设置777），不要关闭页面...');
        var fd=new FormData(); fd.append('action','do_update'); if(dry)fd.append('dry','1');
        fetch('admin.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
            var s='';
            (d.steps||[]).forEach(function(x,i){s+=(i+1)+') '+x+'\n';});
            s+='\n测速：';
            if(d.speed&&Object.keys(d.speed).length){Object.keys(d.speed).forEach(function(k){s+='\n  '+k+': '+d.speed[k]+'ms';});}
            else{s+=' (无可用节点)';}
            s+='\n\n'+(!d.ok?'✘ ':'✔ ')+d.msg;
            pre.textContent=s;
            if(!d.ok){pre.style.borderLeft='3px solid #e74c3c';}else{pre.style.borderLeft='3px solid #2ecc71';}
        }).catch(function(e){pre.textContent='请求失败:'+e;});
    }
    </script>
    <?php
}

function renderLogs()
{
    $counts = Logger::counts();
    $type   = isset($_GET['type']) && isset(Logger::TYPES[$_GET['type']]) ? $_GET['type'] : 'operation';
    $rows   = Logger::read($type, 200);
    $levelColor = ['info' => '#8892ab', 'success' => '#2ecc71', 'warn' => '#e2b93b', 'error' => '#e74c3c'];
    $levelLabel = ['info' => '提示', 'success' => '成功', 'warn' => '警告', 'error' => '错误'];
    ?>
    <div class="stat-cards">
        <?php foreach (Logger::TYPES as $t => $label): ?>
            <a href="?tab=logs&type=<?= $t ?>" style="text-decoration:none;color:inherit">
                <div class="stat" style="<?= $t === $type ? 'outline:2px solid #4f7cff' : '' ?>">
                    <b><?= $counts[$t] ?></b><span><?= $label ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
            <h2 style="margin:0"><?= Logger::TYPES[$type] ?><span class="small" style="margin-left:8px">（最近 200 条 · 每类最多保留 500 条）</span></h2>
            <div style="display:flex;gap:8px">
                <button class="btn btn-danger" onclick="clearLog('all')">清空全部日志</button>
                <button class="btn btn-danger" onclick="clearLog('<?= $type ?>')">清空本类</button>
            </div>
        </div>
        <div class="note" style="margin:12px 0">
            系统自动记录：<b>登录日志</b>（登录成功/失败）、<b>操作日志</b>（后台增删改）、<b>更新日志</b>（在线升级结果）、
            <b>搜索调用日志</b>（前台每次搜索请求与命中情况）、<b>配置日志</b>（配置保存成功/失败）、<b>错误日志</b>（异常与拦截）。
            日志存放于 <code>data/logs/*.json</code>。
        </div>
        <?php if ($rows === []): ?>
            <div class="note" style="color:#8892ab">暂无日志记录。</div>
        <?php else: ?>
            <table>
                <tr><th style="width:150px">时间</th><th style="width:60px">级别</th><th>内容</th><th style="width:40px">详情</th></tr>
                <?php foreach ($rows as $r): ?>
                    <?php $lv = isset($r['level']) ? $r['level'] : 'info'; ?>
                    <tr>
                        <td class="small" style="white-space:nowrap"><?= htmlspecialchars($r['time_str'] ?? '') ?></td>
                        <td><span class="lvl" style="color:<?= $levelColor[$lv] ?? '#8892ab' ?>">● <?= $levelLabel[$lv] ?? $lv ?></span></td>
                        <td><?= htmlspecialchars($r['msg'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($r['extra'])): ?>
                                <button class="btn small" onclick="var p=this.nextElementSibling;p.style.display=p.style.display==='none'?'block':'none'">详情</button>
                                <pre style="display:none;margin-top:6px;font-size:11px"><?= htmlspecialchars(json_encode($r['extra'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                            <?php else: ?>
                                <span class="small">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    <script>
    function clearLog(type){
        if(!confirm('确定要清空该日志吗？'))return;
        var fd=new FormData();fd.append('action','log_clear');fd.append('type',type);
        fetch('admin.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
            alert(d.msg||'完成');location.href='admin.php?tab=logs'+(type!=='all'?'&type='+type:'');
        }).catch(function(e){alert('请求失败:'+e);});
    }
    </script>
    <?php
}

function renderHelp()
{
    $st = mxgj_settings();
    $cronCfg = is_array($st['cron'] ?? null) ? $st['cron'] : [];
    $cronKey = isset($cronCfg['key']) && trim((string)$cronCfg['key']) !== ''
        ? trim((string)$cronCfg['key'])
        : (isset($st['updater_key']) && trim((string)$st['updater_key']) !== ''
            ? trim((string)$st['updater_key']) : (string)($st['admin_password'] ?? ''));
    $seeds = isset($cronCfg['seed_links']) && is_array($cronCfg['seed_links']) ? $cronCfg['seed_links'] : [];
    $cronUrl = 'http://你的域名/cron/mapping.php?key=' . htmlspecialchars($cronKey);
    $logExists = is_file(MXGJ_DATA . '/cron_mapping.log');
    $recent = [];
    if ($logExists) {
        $lines = array_filter(array_map('trim', @file(MXGJ_DATA . '/cron_mapping.log') ?: []));
        $recent = array_slice($lines, 0, 5);
    }
    ?>
    <style>
        .help h2{font-size:16px;margin:26px 0 10px;border-left:4px solid #4f7cff;padding-left:10px}
        .help h2:first-child{margin-top:0}
        .help p,.help li{font-size:13px;line-height:1.8;color:#c6cde0}
        .help code{background:#1b2233;color:#7fc1ff;padding:1px 6px;border-radius:4px}
        .help pre{background:#0d1118;padding:12px;border-radius:8px;overflow:auto;font-size:12px;color:#9fdc9f}
        .help .box{background:#10172a;border:1px solid #223;border-radius:8px;padding:14px 16px;margin:10px 0}
        .help .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}
    </style>
    <div class="help panel">

        <h2>系统简介</h2>
        <p>沫兮官替系统：输入官方视频链接 → 自动解析「剧名 + 集数」→ 并发向后台配置的资源站搜索 → 返回可播放的 m3u8 地址（JSON）。全程无数据库，配置均存于 <code>config/</code>。</p>

        <h2>资源站「映射要求」说明</h2>
        <div class="box">
            <p style="margin:0 0 6px">市面上绝大多数影视资源站使用 <b>苹果 CMS 采集接口</b>（如西瓜等多站），对内容的标识要求是：</p>
            <ul style="margin:0;padding-left:18px">
                <li>按<b>剧名</b>搜索：<code>?ac=videolist&wd=剧名</code>（返回 <code>list[]</code>）</li>
                <li>剧内各集地址在 <code>vod_play_url</code>，格式为 <code>第1集$http://…#第2集$http://#…#第N集$http://</code></li>
                <li>即：资源站需要的是「<b>剧名 + 集数</b>」，并不关心官方平台的 <code>vid/cid</code></li>
            </ul>
            <p style="margin:8px 0 0">因此本系统用「映射表（<code>vid/cid → 剧名+集数</code>）」作为桥：把官方链接的 <code>vid/cid</code> 翻译成资源站能识别的「剧名+集数」，再按集数从 <code>vod_play_url</code> 提取 m3u8。</p>
        </div>

        <h2>资源站检测 & 自动添加（苹果CMS10）</h2>
        <div class="box">
            <p style="margin:0">「资源站」页点 <b>⚡ 检测并自动添加</b>，弹出输入框，只需要粘贴你手上的 <b>苹果CMS10 采集接口地址</b>
            （形如 <code>https://caiji.xxx.com/api.php/provide/vod/</code>），点「检测」即可：</p>
            <ul style="margin:6px 0;padding-left:18px">
                <li>自动校验接口可达性，并识别返回是否为苹果CMS列表（<code>list[]</code> 含可播放 http 地址），证明能取到<b>真实资源</b></li>
                <li>自动生成搜索模板 <code>?ac=videolist&wd=%u</code> 与可读站点名称，并预览样本剧名+首条真实地址</li>
                <li>确认后自动<b>新增</b>或按名称<b>修改</b>，保存到 <code>config/sites.json</code>，前台即刻并发调用</li>
            </ul>
            <p style="margin:8px 0 0">现有站点行上的「编辑」会打开同一弹窗并预填，便于修正后重新检测/保存。</p>
        </div>

        <h2>映射如何自动生成</h2>
        <div class="box">
            <ul style="margin:0;padding-left:18px">
                <li><b>首次访问兜底</b>：链接无法直接解析时，系统自动抓取官方页面识别剧名+集数，并<b>自动写入映射表</b>（下次直接命中、不再联网）。</li>
                <li><b>页面兜底失效</b>：返回 502 时，请到「映射表」手动添加该 <code>vid/cid</code> 对应的剧名与集数。</li>
                <li><b>剧名差异</b>：官方剧名与资源站不一致时，用「剧名映射」把官方名替换为资源站用的名。</li>
            </ul>
        </div>

        <h2>定时访问功能（cron 自动采集映射）</h2>
        <div class="box">
            <p>新增 <code>cron/mapping.php</code>：按周期自动做两件事，省去手动一条条加。</p>
            <ol style="margin:6px 0;padding-left:18px">
                <li><b>官方种子链接补全</b>：遍历 <code>settings.json → cron → seed_links</code>，对尚未映射的官方链接自动抓取剧名+集数并写入映射表（已存在的跳过、不覆盖人工配置）。</li>
                <li><b>资源站库存盘点</b>：并发拉取各资源站最新采集列表，把「在库剧名 + 集数范围」写入 <code>mapping.json → stock</code>，方便了解资源站有哪些剧可选。</li>
            </ol>
            <p style="margin:10px 0 4px"><b>① 配置种子链接</b></p>
            <p style="margin:0">编辑 <code>config/settings.json</code> 的 <code>cron.seed_links</code>，把你想追踪的官方剧集链接放进去（支持腾讯/爱奇艺/优酷/芒果/哔哩哔哩等）。当前已配 <?= count($seeds) ?> 条：</p>
            <pre><?php foreach ($seeds as $s) { echo htmlspecialchars($s) . "\n"; } ?></pre>
            <p style="margin:10px 0 4px"><b>② 使用方法（两种方式任选）</b></p>
            <p style="margin:0">· 手动/浏览器测试预览（不写库）：</p>
            <pre><?= $cronUrl ?>&amp;dry=1</pre>
            <p style="margin:0">· Linux crontab 定时（每小时一次，<code>quiet</code> 可抑制明细输出）：</p>
            <pre>0 * * * *  php <?= MXGJ_ROOT ?>/cron/mapping.php key=<?= htmlspecialchars($cronKey) ?> quiet</pre>
            <p style="margin:0">· 或通过 URL 定时：</p>
            <pre>curl -s "<?= $cronUrl ?>" >/dev/null</pre>
        </div>

        <h2>资源站调用频率控制（搜索 / 心跳 / 轮训）</h2>
        <div class="box">
            <p style="margin:0 0 6px">在「设置」页可配置三项策略，降低对资源站的调用频率，避免频繁/密集调用被屏蔽或禁用：</p>
            <ul style="margin:0;padding-left:18px">
                <li><b>搜索频率（search_interval）</b>：同一站两次实际调用最短间隔，间隔内再次需该站则跳过（依赖结果缓存），限制单站 QPS。</li>
                <li><b>心跳频率（heartbeat_interval）</b>：周期性并发探测各站可达性，连续失败 <code>heartbeat_max_fail</code> 次自动禁用，冷却 <code>cooldown_seconds</code> 后自动恢复重试；搜索只对「存活且未在冷却」的站发请求。</li>
                <li><b>轮训（rotation_interval + max_sites_per_request）</b>：每过一个轮训周期推进一次命中顺序（round-robin），并可限制每次最多并发请求几个站，把压力分散到不同站。</li>
            </ul>
            <p style="margin:8px 0 4px"><b>推荐值（具体配置，写在「设置」页保存即可）</b></p>
            <ul style="margin:0;padding-left:18px;color:#9fdc9f">
                <li>搜索间隔：<b>15 s</b>（同一站约每 15 秒最多一次）</li>
                <li>心跳间隔：<b>600 s</b>（每 10 分钟探测一次可达性）</li>
                <li>心跳单站超时：<b>5 s</b>；连续失败 <b>3 次</b>自动禁用</li>
                <li>被禁用冷却：<b>1800 s</b>（30 分钟后再自动恢复重试）</li>
                <li>轮驯周期：<b>600 s</b>（每 10 分钟切换一次命中顺序）</li>
                <li>每次最多并发请求：<b>4 个站</b>（把压力分散到不同站）</li>
            </ul>
            <p style="margin:8px 0 0">运行状态可视化：设置页 →「资源站健康状态」，可「立即心跳探测 / 重置健康状态」。</p>
        </div>

        <h2>定时任务运行记录</h2>
        <div class="box">
            <?php if (!$logExists || $recent === []): ?>
                <p style="margin:0">暂未执行过定时采集（或日志为空）。运行一次 <code>cron/mapping.php</code> 后会在此显示最近记录。</p>
            <?php else: ?>
                <pre><?php foreach ($recent as $line) { echo htmlspecialchars($line) . "\n"; } ?></pre>
                <p style="margin:6px 0 0;color:#8892ab">完整日志位于 <code>data/cron_mapping.log</code>（最多保留 200 条）</p>
            <?php endif; ?>
        </div>

        <h2>版本信息</h2>
        <div class="box">
            <p style="margin:0">当前版本：<b>v<?= MXGJ_VERSION ?></b> · 升级与更新说明见 <code>CHANGELOG.md</code> 顶部 / <code>README.md</code> 顶部「📌 更新说明」。</p>
        </div>
    </div>
    <?php
}

function renderSettingsForm($settings)
{
    $sc   = is_array($settings['site_control'] ?? null) ? $settings['site_control'] : [];
    $output = is_array($settings['output'] ?? null) ? $settings['output'] : [];
    $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];
    $app      = is_array($settings['app'] ?? null) ? $settings['app'] : [];
    $siteHealth = SiteHealth::healthTable();
    $hbResult = $_SESSION['heartbeat_result'] ?? null;
    unset($_SESSION['heartbeat_result']);
    ?>
    <div class="panel">
        <h2>系统设置</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_settings">
            <div><label>请求超时（秒）</label><input type="number" name="timeout" value="<?= (int)$settings['timeout'] ?>" min="1" max="60"></div>
            <div><label>缓存时长（秒，0=关闭）</label><input type="number" name="cache_ttl" value="<?= (int)$settings['cache_ttl'] ?>" min="0"></div>
            <div class="full"><label>域名替换/中转前缀（留空则直接返回资源站地址）</label><input type="text" name="replace_domain" value="<?= htmlspecialchars($settings['replace_domain']) ?>" placeholder="如 https://cdn.example.com/m3u8/"></div>
            <div class="full"><label>升级密钥（update.php 用，留空则回退管理密码）</label><input type="text" name="updater_key" value="<?= htmlspecialchars($settings['updater_key'] ?? '') ?>" placeholder="留空则使用管理密码"></div>
            <div class="full"><label>修改管理密码（留空保持不变）</label><input type="password" name="admin_password" placeholder="新密码"></div>

            <div class="full" style="margin-top:8px"><h3 style="margin:0 0 4px;font-size:14px">资源站调用频率控制（防刷屏/防被屏蔽）</h3></div>
            <div><label>搜索频率：同一站两次调用最短间隔（秒）</label><input type="number" name="sc_search_interval" value="<?= (int)($sc['search_interval'] ?? 10) ?>" min="0"></div>
            <div><label>心跳间隔（秒，多久探测一次可达性）</label><input type="number" name="sc_heartbeat_interval" value="<?= (int)($sc['heartbeat_interval'] ?? 300) ?>" min="10"></div>
            <div><label>心跳单站超时（秒）</label><input type="number" name="sc_heartbeat_timeout" value="<?= (int)($sc['heartbeat_timeout'] ?? 5) ?>" min="1"></div>
            <div><label>连续失败 N 次自动禁用</label><input type="number" name="sc_heartbeat_max_fail" value="<?= (int)($sc['heartbeat_max_fail'] ?? 3) ?>" min="1"></div>
            <div><label>被禁用冷却时间（秒，到期自动恢复）</label><input type="number" name="sc_cooldown_seconds" value="<?= (int)($sc['cooldown_seconds'] ?? 600) ?>" min="0"></div>
            <div><label>轮训周期（秒，每隔多久切换命中顺序）</label><input type="number" name="sc_rotation_interval" value="<?= (int)($sc['rotation_interval'] ?? 300) ?>" min="10"></div>
            <div><label>每次最多并发请求几个资源站（0=不限制）</label><input type="number" name="sc_max_sites_per_request" value="<?= (int)($sc['max_sites_per_request'] ?? 0) ?>" min="0"></div>
            <div class="full"><label style="margin-bottom:6px">开关（点击即时生效，无需保存）</label>
                <label style="margin-right:18px" class="toggle-label"><input type="checkbox" name="sc_heartbeat_enable" value="1" class="quick-toggle" data-action="setting" data-name="heartbeat_enable" <?= !empty($sc['heartbeat_enable']) ? 'checked' : '' ?>> 启用心跳检测</label>
                <label class="toggle-label"><input type="checkbox" name="sc_rotation_enable" value="1" class="quick-toggle" data-action="setting" data-name="rotation_enable" <?= !empty($sc['rotation_enable']) ? 'checked' : '' ?>> 启用资源站轮训</label>
            </div>

            <div class="full" style="margin-top:8px">
                <h3 style="margin:0 0 4px;font-size:14px">输出返回设置（自定义返回字段映射）</h3>
                <div class="note" style="margin:4px 0 8px">
                    自定义前台返回的 JSON 字段。<b>键名</b>（k）= 对外输出的字段名；<b>值来源</b>（v）可填系统字段名
                    （<code>code</code> 状态码 / <code>url</code> 播放链接 / <code>title</code> 影视剧名 / <code>episode</code> 集数 /
                    <code>time</code> 耗时ms / <code>site</code> 命中站点 / <code>source</code> 请求链接 / <code>msg</code> 提示），
                    或直接填<b>固定文本</b>（常量，如 <code>沫兮官替系统</code>）作为该字段的值。<br>
                    例如想返回 <code>JM=庆余年</code> <code>JJ=第2集</code>：键名填 <code>JM</code> 值来源填 <code>title</code>，键名 <code>JJ</code> 值来源填 <code>episode</code>。
                </div>
                <label style="margin:0"><input type="checkbox" name="out_show_source" value="1" <?= !empty($output['show_source']) ? 'checked' : '' ?>> 在返回中附带原始请求链接（默认隐藏）</label>
                <table id="out-tbl" style="margin-top:8px">
                    <tr><th style="width:140px">键名 k（输出字段）</th><th>值来源/常量 v</th><th style="width:60px">启用</th><th style="width:60px">操作</th></tr>
                    <?php if (!empty($output['fields'])): foreach ($output['fields'] as $i => $f): $on = !array_key_exists('enabled', $f) || !empty($f['enabled']); ?>
                        <tr class="<?= $on ? '' : 'row-disabled' ?>">
                            <td><input type="text" name="out_k[]" value="<?= htmlspecialchars($f['k']) ?>" placeholder="如 url / JM / JJ"></td>
                            <td><input type="text" name="out_v[]" value="<?= htmlspecialchars($f['v']) ?>" placeholder="如 code / url / title / episode / 常量文本" list="src-list"></td>
                            <td class="center">
                                <label class="toggle">
                                    <input type="checkbox" class="quick-toggle" data-action="output" data-idx="<?= $i ?>" <?= $on ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td><input type="text" name="out_k[]" placeholder="url"></td>
                            <td><input type="text" name="out_v[]" value="url" list="src-list"></td>
                            <td class="center"><input type="checkbox" checked disabled title="新字段默认启用"></td>
                            <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                    <?php endif; ?>
                </table>
                <datalist id="src-list">
                    <option value="code"></option><option value="msg"></option><option value="url"></option>
                    <option value="title"></option><option value="episode"></option><option value="site"></option>
                    <option value="source"></option><option value="time"></option>
                </datalist>
                <button type="button" class="btn" style="margin-top:8px" onclick="addOutRow()">+ 添加返回字段</button>
            </div>

            <div class="full" style="margin-top:8px">
                <h3 style="margin:0 0 4px;font-size:14px">App 设置（表面播放链接，推荐开启）</h3>
                <div class="note" style="margin:4px 0 8px">
                    开启后，返回的<b>播放链接统一伪装为当前域名下的播放入口</b>：
                    <code>https://当前域名/play.php?u=加密地址</code>。<br>
                    该链接<b>表面是一个链接</b>，浏览器打开即可播放，也能被 APP 识别并直接播放，同时<b>隐藏真实播放地址</b>。
                    播放方式：<b>代理转发</b>=本站转发视频流并重写 m3u8 切片，完全隐藏真实源、APP 可直接播放（推荐）；
                    <b>302 跳转</b>=直接跳转到真实地址（省流量，但真实地址在跳转后可见）。HTML 播放页在代理模式下会自动降级为跳转。
                </div>
                <label style="margin:0">
                    <input type="checkbox" name="app_surface_enable" value="1" class="quick-toggle" data-action="setting" data-name="app_surface_enable" <?= !empty($app['surface_enable']) ? 'checked' : '' ?>>
                    启用表面播放链接（返回统一为本站播放入口）
                </label>
                <div style="margin-top:8px"><label>播放入口路径</label><input type="text" name="app_surface_path" value="<?= htmlspecialchars($app['surface_path'] ?? 'play.php') ?>" placeholder="play.php"></div>
                <div style="margin-top:8px">
                    <label>播放方式</label>
                    <select name="app_surface_mode">
                        <option value="proxy" <?= (($app['surface_mode'] ?? 'proxy') === 'proxy') ? 'selected' : '' ?>>代理转发（隐藏真实地址，APP 可直接播放，推荐）</option>
                        <option value="redirect" <?= (($app['surface_mode'] ?? 'proxy') === 'redirect') ? 'selected' : '' ?>>302 跳转（省流量）</option>
                    </select>
                </div>
            </div>

            <div class="full" style="margin-top:8px">
                <h3 style="margin:0 0 4px;font-size:14px">安全设置（欺诈/伪装）</h3>
                <div class="note" style="margin:4px 0 8px">
                    开启后，返回链接中「跟随播放链接」的<b>中转前缀域名会被伪装为当前系统域名</b>，避免对外暴露真实中转/播放站点。
                    例：<code>https://vv00.xyz?url=真实地址</code> → <code>https://当前域名?url=真实地址</code>。
                    仅替换链接开头的中转域名，<b>不触碰真实播放地址参数</b>；若需「可正常打开播放的表面链接」，请优先使用上方 <b>App 设置</b>。
                    <b>默认关闭</b>，按需开启。
                </div>
                <label style="margin:0">
                    <input type="checkbox" name="sec_obfuscate_enable" value="1" class="quick-toggle" data-action="setting" data-name="obfuscate_enable" <?= !empty($security['obfuscate_enable']) ? 'checked' : '' ?>>
                    启用欺诈/伪装（将中转前缀域名替换为当前域名）
                </label>
            </div>

            <div class="full"><button type="submit" class="btn btn-green">保存设置</button></div>
        </form>
    </div>

    <div class="panel">
        <h2>资源站健康状态</h2>
        <div class="note" style="margin-bottom:12px">
            心跳/节流/轮训的实时运行状态（存储于 <code>data/site_health.json</code>）。
            连续失败达上限的站会被自动禁用并在冷却期后自动恢复重试。
        </div>
        <?php if ($hbResult && isset($hbResult['rows'])): ?>
            <div class="note" style="color:#7fc1ff;margin-bottom:10px">本次心跳（<?= htmlspecialchars($hbResult['at'] ?? '') ?>）：共 <?= count($hbResult['rows']) ?> 个站，<?= array_sum(array_column($hbResult['rows'], 'ok')) ?> 个可达。</div>
        <?php endif; ?>
        <?php if (!empty($_GET['hr'])): ?>
            <div class="note" style="color:#7fc1ff;margin-bottom:10px">已重置全部资源站健康状态。</div>
        <?php endif; ?>
        <table>
            <tr><th>资源站</th><th>状态</th><th>响应ms</th><th>连续失败</th><th>最近心跳</th></tr>
            <?php if ($siteHealth === []): ?>
                <tr><td colspan="5" style="color:#8892ab">暂无数据（尚未执行过心跳/搜索）</td></tr>
            <?php else: foreach ($siteHealth as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td>
                        <?php if ($row['enabled']): ?><span style="color:#2ecc71">可用</span>
                        <?php else: ?><span style="color:#e74c3c">禁用(冷却)</span><?php endif; ?>
                        <?= $row['reachable'] ? '·可达' : '·不可达' ?>
                    </td>
                    <td><?= (int)$row['ms'] ?></td>
                    <td><?= (int)$row['fail'] ?></td>
                    <td><?= $row['last_heartbeat'] ? date('H:i:s', (int)$row['last_heartbeat']) : '—' ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </table>
        <form method="post" style="margin-top:12px;display:flex;gap:10px">
            <button type="submit" class="btn" name="action" value="heartbeat_now">立即心跳探测</button>
            <button type="submit" class="btn" name="action" value="health_reset" onclick="return confirm('确定重置全部资源站健康状态？')">重置健康状态</button>
        </form>
        <div class="note" style="margin-top:16px">
            推荐值（兼顾可用性与防屏蔽）：搜索间隔 <b>15s</b>、心跳间隔 <b>600s</b>、心跳超时 <b>5s</b>、
            连续失败 <b>3</b> 次禁用、冷却 <b>1800s</b>、轮驯周期 <b>600s</b>、每请求并发 <b>4</b> 个站。
        </div>
    </div>

    <div class="note" style="margin-top:20px">
        默认密码：<code>moxi123</code>。请立即修改！<br>
        数据文件位于 <code>config/settings.json</code>、<code>config/sites.json</code>、<code>config/mapping.json</code>，可手动编辑。
    </div>
    <script>
    var outRow=<?= count($output['fields'] ?? []) ?>;
    function addOutRow(){
        var t=document.getElementById('out-tbl');
        var tr=document.createElement('tr');
        tr.innerHTML='<td><input type="text" name="out_k[]" placeholder="如 url / JM / JJ"></td>'+
            '<td><input type="text" name="out_v[]" placeholder="如 code / url / title / episode / 常量文本" list="src-list"></td>'+
            '<td class="center"><input type="checkbox" checked disabled title="新字段默认启用"></td>'+
            '<td><button type="button" class="btn btn-danger" onclick="this.closest(\'tr\').remove()">删除</button></td>';
        t.appendChild(tr);
    }
    </script>
    <?php
}

/* ---------------- 小工具 ---------------- */

class AdminHelper
{
    public static function cacheCount(): int
    {
        if (!is_dir(MXGJ_CACHE)) return 0;
        $n = 0;
        foreach (glob(MXGJ_CACHE . '/*.cache') ?: [] as $f) if (is_file($f)) $n++;
        return $n;
    }
    public static function clearCache(): void
    {
        if (!is_dir(MXGJ_CACHE)) return;
        foreach (glob(MXGJ_CACHE . '/*.cache') ?: [] as $f) @unlink($f);
    }
}