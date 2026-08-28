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
            $method = mxgj_lower(trim($s['method'] ?? ''));
            if (!in_array($method, ['post'], true)) $method = 'get';
            $parse = mxgj_lower(trim($s['parse'] ?? ''));
            if (!in_array($parse, ['json', 'text', 'apple'], true)) $parse = '';
            $clean[] = [
                'name'       => $name,
                'url'        => $url,          // 兼容旧字段
                'template'   => $url,          // 标准模板字段
                'proxy'      => trim($s['proxy'] ?? ''), // 跟随播放链接（中转前缀），仅该站启用
                'enabled'    => array_key_exists('enabled', $s) ? !empty($s['enabled']) : true, // 启用开关（默认启用）
                'is_special' => array_key_exists('is_special', $s) ? !empty($s['is_special']) : false, // 特殊资源站：返回 URL 自动套本地 /player/ 播放器
                // 特殊调用方法（可留空走默认）
                'method'     => $method,       // get=GET（默认） / post=POST（特殊调用）
                'headers'    => trim($s['headers'] ?? ''), // 自定义请求头：每行 `Key: Value`
                'post'       => trim($s['post'] ?? ''),     // POST 请求体模板（含 %u/%s/%p）
                'parse'      => $parse,        // 返回解析模式：空/json/text/apple
            ];
        }
        mxgj_write_json($sitesFile, ['sites' => $clean]);
        Logger::log('operation', '保存资源站列表（共 ' . count($clean) . ' 个）', 'success');
        Logger::log('config', '资源站配置保存成功：' . count($clean) . ' 个站点', 'success', ['sites' => $clean]);
        mxgj_purge_runtime(); // 保存后自动清理运行时数据
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
                $t  = trim($t ?? '');
                $ta = trim($targets[$i] ?? '');
                if ($t === '' || $ta === '') continue;
                $mapping['title'][$t] = $ta;
            }
        }
        $cids   = $_POST['map_cid'] ?? [];
        $ctargs = $_POST['map_cid_target'] ?? [];
        if (is_array($cids)) {
            foreach ($cids as $i => $c) {
                $c  = trim($c ?? '');
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
                $id  = trim($id ?? '');
                $en  = trim($enames[$i] ?? '');
                $ep  = (int)($eps[$i] ?? 0);
                if ($id === '' || $en === '' || $ep <= 0) continue;
                $mapping['episode'][$id] = ['name' => $en, 'episode' => $ep];
            }
        }
        mxgj_write_json($mappingFile, $mapping);
        Logger::log('operation', '保存映射表（title ' . count($mapping['title']) . ' / cid ' . count($mapping['cid']) . ' / episode ' . count($mapping['episode']) . '）', 'success');
        Logger::log('config', '映射表配置保存成功', 'success', ['count' => ['title' => count($mapping['title']), 'cid' => count($mapping['cid']), 'episode' => count($mapping['episode'])]]);
        mxgj_purge_runtime(); // 保存后自动清理运行时数据
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
        mxgj_write_json($settingsFile, $st);
        Logger::log('operation', '保存系统设置', 'success');
        Logger::log('config', '系统设置保存成功', 'success', ['timeout' => $st['timeout'], 'cache_ttl' => $st['cache_ttl'], 'output_fields' => count($fields)]);
        // 自动清理运行时数据（缓存 / 站点健康 / 日志），让新配置立即生效
        mxgj_purge_runtime();
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

    case 'captcha_fetch':
        // 🔐 获取资源站的验证码图片（SiteDetector Phase 2.5）
        $rawUrl = trim($_POST['url'] ?? '');
        if ($rawUrl === '') mxgj_json_out(['ok' => false, 'msg' => '缺少接口地址']);
        $host = (string)parse_url($rawUrl, PHP_URL_HOST);
        $template = SiteDetector::buildTemplate($rawUrl);
        $need = SiteDetector::needsSearchCaptcha($host, $template, (int)mxgj_settings()['timeout']);
        if (!$need) {
            mxgj_json_out(['ok' => false, 'msg' => '这个接口不需要验证码（空列表和关键词搜索都正常）']);
        }
        // 把 cookie jar 从临时位置复制到持久位置
        $persistJar = SiteSearcher::hostCookieFile($rawUrl);
        @mkdir(dirname($persistJar), 0755, true);
        @copy($need['cookie_jar'], $persistJar);
        mxgj_json_out([
            'ok'          => true,
            'captcha_img' => $need['captcha_img'],
            'api_host'    => $host,
            'jar_path'    => $persistJar,
            'msg'         => '🔐 检测到搜索验证 — 请在浏览器打开图片输入验证码',
        ]);

    case 'captcha_submit':
        // 🔐 提交验证码到苹果CMS verify 接口
        $host       = trim($_POST['host'] ?? '');
        $codeInput  = trim($_POST['code'] ?? '');
        $template   = trim($_POST['template'] ?? '');
        if ($host === '' || $codeInput === '') mxgj_json_out(['ok' => false, 'msg' => '缺少 host 或验证码']);
        if ($template === '') $template = "https://{$host}/api.php/provide/vod/?ac=videolist&wd=%u";

        $jar = SiteSearcher::hostCookieFile($template);
        $headers = [
            'Accept: text/html,application/json,*/*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Referer: https://' . $host . '/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122',
        ];

        // 苹果CMS10 验证码验证路径（多种可能）
        $verifyPaths = [
            "/index.php/vod/verify/code/{$codeInput}.html",
            "/index.php/vod/verify/?code={$codeInput}",
            "/index.php/vod/verify.html?code={$codeInput}",
            "/api.php?ac=verify&code={$codeInput}",
            "/index.php?s=/vod/verify&code={$codeInput}",
        ];
        $ch = curl_init();
        $lastResp = ''; $lastCode = 0;
        foreach ($verifyPaths as $path) {
            $url = "https://{$host}{$path}";
            curl_setopt_array($ch, [
                CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastResp .= "
{$url} → HTTP {$code}: " . substr((string)$body, 0, 120);
        }
        @curl_close($ch);

        // 验证后用 cookie jar 再搜一次关键词，确认 cookie 生效
        $verifyUrl = str_replace('wd=%u', 'wd=' . urlencode('斗罗大陆'), $template);
        $final = SiteSearcher::search([['name'=>'解锁验证','template'=>$template,'url'=>$template]], '斗罗大陆', 1, 8);

        if (!empty($final['url']) || ($final['code'] ?? 0) === 200) {
            Logger::log('operation', "🔐 资源站 {$host} 搜索验证解锁成功", 'success', ['path' => $jar]);
            mxgj_json_out([
                'ok'       => true,
                'msg'      => "✅ 解锁成功！Cookie 已保存到 {$jar}，后续搜索自动带上",
                'url'      => $final['url'] ?? '',
                'jar_size' => filesize($jar) ?: 0,
            ]);
        }

        // 还没成功 —— 清理 cookie jar 让用户重试
        @unlink($jar);
        mxgj_json_out([
            'ok'  => false,
            'msg' => '❌ 验证码可能错误（Cookie 已清除，请重新输入）',
            'debug' => $lastResp,
        ]);

    case 'get_templates':
        // 📋 获取所有搜索接口模板（预置 + 用户自定义）
        mxgj_json_out(['ok' => true, 'templates' => mxgj_search_templates()]);

    case 'save_templates':
        // 📝 保存用户自定义模板
        $userList = json_decode((string)($_POST['templates'] ?? '[]'), true);
        if (!is_array($userList)) mxgj_json_out(['ok' => false, 'msg' => 'JSON 格式错误']);
        mxgj_write_json(MXGJ_CONFIG . '/search_templates_user.json', $userList);
        mxgj_json_out(['ok' => true, 'msg' => '已保存']);

    case 'render_from_template':
        // 🎯 根据模板 + host 生成搜索 URL
        $tid = trim((string)($_POST['template_id'] ?? ''));
        $host = trim((string)($_POST['host'] ?? ''));
        if ($tid === '' || $host === '') mxgj_json_out(['ok' => false, 'msg' => '缺少 template_id 或 host']);
        if ($tid === 'custom') {
            mxgj_json_out(['ok' => true, 'template' => '', 'hint' => 'custom 模板需要手动填写完整 URL']);
        }
        $url = mxgj_render_search_template($tid, $host);
        mxgj_json_out(['ok' => true, 'template' => $url]);

    case 'guess_template':
        // 🔍 从裸 URL 反推模板 + host
        $raw = trim((string)($_POST['url'] ?? ''));
        if ($raw === '') mxgj_json_out(['ok' => false, 'msg' => '缺少 url']);
        [$tid, $host] = mxgj_guess_search_template($raw);
        mxgj_json_out(['ok' => true, 'template_id' => $tid, 'host' => $host]);

    case 'captcha_clear':
        // 🔐 手动清除 cookie jar
        $host = trim($_POST['host'] ?? '');
        if ($host === '') mxgj_json_out(['ok' => false, 'msg' => '缺少 host']);
        $jar = SiteSearcher::hostCookieFile("https://{$host}/api.php");
        @unlink($jar);
        Logger::log('operation', "🔐 清除 {$host} 的搜索验证 Cookie", 'info');
        mxgj_json_out(['ok' => true, 'msg' => "已清除 {$host} 的 cookie"]);

    case 'test_direct':
        // 一键测试 - 资源站直链（m3u8）：自动检测剧名/集数 → 写入映射表
        $url  = trim($_POST['url'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $ep   = max(0, (int)($_POST['episode'] ?? 0));
        $save = !empty($_POST['save']);
        if ($url === '') mxgj_json_out(['code' => 400, 'msg' => '请输入资源站直链']);

        // === 自动检测 ===
        $detected = admin_parse_direct_url($url);
        // 用户手动输入覆盖自动检测
        if ($name !== '') $detected['name'] = $name;
        if ($ep > 0)      $detected['episode'] = $ep;

        $result = [
            'code'    => 200,
            'ok'      => true,
            'url'     => $url,
            'detected' => $detected,
            'saved'   => false,
            'msg'     => '检测完成，请确认后点击写入映射表',
        ];

        if ($save && $detected['name'] !== '') {
            $mapping = mxgj_read_json($mappingFile, ['title' => [], 'cid' => [], 'episode' => []]);
            $newTitle  = !empty($detected['title_raw']) && $detected['title_raw'] !== $detected['name'];
            $titleKey  = $detected['title_raw'] ?? $detected['name'];
            $epKey     = 'url:' . md5($url);

            $savedMap = [];
            // 1) 写入剧名映射（如果检测到原始名且和修正名不同）
            if ($newTitle) {
                $mapping['title'][$titleKey] = $detected['name'];
                $savedMap[] = "title[$titleKey] → {$detected['name']}";
            }
            // 2) 写入 episode 映射
            if ($detected['episode'] > 0) {
                if (!isset($mapping['episode']) || !is_array($mapping['episode'])) {
                    $mapping['episode'] = [];
                }
                $mapping['episode'][$epKey] = ['name' => $detected['name'], 'episode' => $detected['episode']];
                $savedMap[] = "episode[$epKey] → {$detected['name']} 第{$detected['episode']}集";
            }
            mxgj_write_json($mappingFile, $mapping);
            $result['saved'] = true;
            $result['saved_map'] = $savedMap;
            $result['msg'] = '映射表已写入 ' . count($savedMap) . ' 条';
            Logger::log('operation', '资源站直链自动写入映射：' . $detected['name'] . ' 第' . $detected['episode'] . '集', 'success', ['entries' => $savedMap]);
        }

        // 尝试用检测到的剧名去搜（验证）
        if ($detected['name'] !== '' && $detected['episode'] > 0) {
            $verify = SiteSearcher::search(mxgj_sites(), $detected['name'], $detected['episode'], (int)mxgj_settings()['timeout']);
            $result['verify_search'] = [
                'code' => $verify['code'],
                'hit'  => !empty($verify['url']),
                'url'  => $verify['url'] ?? '',
                'msg'  => $verify['msg'] ?? '',
            ];
        }

        mxgj_json_out($result);

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
        // 特殊调用方法（可留空走默认）
        $method = mxgj_lower(trim($_POST['method'] ?? ''));
        if (!in_array($method, ['post'], true)) $method = 'get';
        $parse = mxgj_lower(trim($_POST['parse'] ?? ''));
        if (!in_array($parse, ['json', 'text', 'apple'], true)) $parse = '';
        $entry['method']  = $method;
        $entry['headers'] = trim($_POST['headers'] ?? '');
        $entry['post']    = trim($_POST['post'] ?? '');
        $entry['parse']   = $parse;
        if ($hit !== null) {
            $entry['enabled']    = array_key_exists('enabled', $list[$hit]) ? !empty($list[$hit]['enabled']) : true; // 保留原启用状态
            $entry['proxy']      = (string)($list[$hit]['proxy'] ?? ''); // 保留跟随播放链接
            $entry['is_special'] = array_key_exists('is_special', $list[$hit]) ? !empty($list[$hit]['is_special']) : false; // 保留特殊站标记
            // 保留原有特殊调用方法配置（弹窗未提交这些字段时不覆盖）
            $entry['method']     = (string)($list[$hit]['method'] ?? $entry['method']);
            $entry['headers']    = (string)($list[$hit]['headers'] ?? $entry['headers']);
            $entry['post']       = (string)($list[$hit]['post'] ?? $entry['post']);
            $entry['parse']      = (string)($list[$hit]['parse'] ?? $entry['parse']);
            $list[$hit] = $entry;
            $mode = 'update';
        } else {
            $entry['enabled']    = true; // 新增默认启用
            $entry['proxy']      = trim($_POST['proxy'] ?? ''); // 跟随播放链接（中转前缀）
            $entry['is_special'] = !empty($_POST['is_special']); // 特殊资源站标记
            $list[] = $entry;
            $mode = 'add';
        }
        mxgj_write_json($sitesFile, ['sites' => $list]);
        mxgj_purge_runtime(); // 保存后自动清理运行时数据
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
        mxgj_purge_runtime(); // 保存后自动清理运行时数据
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
        mxgj_purge_runtime(); // 快捷开关也要清缓存
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
        mxgj_purge_runtime(); // 快捷开关也要清缓存
        Logger::log('operation', ($on ? '启用' : '禁用') . '输出字段：' . ($fields[$idx]['k'] ?? ''), $on ? 'success' : 'warn');
        Logger::log('config', ($on ? '启用' : '禁用') . '输出字段「' . ($fields[$idx]['k'] ?? '') . '」', $on ? 'success' : 'warn');
        mxgj_json_out(['code' => 200, 'ok' => true, 'msg' => ($on ? '已启用' : '已禁用') . '输出字段：' . ($fields[$idx]['k'] ?? '')]);

    case 'toggle_setting':
        // 快捷开关设置项（site_control 的心跳/轮训）
        $name = trim($_POST['name'] ?? '');
        $on   = !empty($_POST['enabled']);
        $st   = mxgj_settings();
        if ($name === 'heartbeat_enable' || $name === 'rotation_enable') {
            $sc = is_array($st['site_control'] ?? null) ? $st['site_control'] : [];
            $sc[$name] = $on;
            $st['site_control'] = $sc;
            $labels = ['heartbeat_enable' => '心跳检测', 'rotation_enable' => '资源站轮训'];
        } else {
            mxgj_json_out(['code' => 400, 'msg' => '不支持的设置项']);
        }
        mxgj_write_json($settingsFile, $st);
        mxgj_purge_runtime(); // 快捷开关也要清缓存
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

    case 'search_site_api':
        // 🔍 资源站查看：直接调苹果CMS videolist 接口，返回搜索结果 + 播放源 + 集数链接
        $idx   = (int)($_POST['idx'] ?? -1);
        $wd    = trim((string)($_POST['wd'] ?? ''));
        $pg    = max(1, (int)($_POST['pg'] ?? 1));
        $limit = min(50, max(1, (int)($_POST['limit'] ?? 20)));

        if ($idx < 0) mxgj_json_out(['ok' => false, 'msg' => '请先选择一个资源站']);
        $sites = mxgj_sites();
        if (!isset($sites[$idx])) mxgj_json_out(['ok' => false, 'msg' => '资源站不存在（已被删除）']);
        // wd 允许为空 — 空时返回该资源站最新资源

        $site     = $sites[$idx];
        $template = $site['template'] ?? ($site['url'] ?? '');
        if (empty($template)) mxgj_json_out(['ok' => false, 'msg' => '该资源站无可用 URL 模板']);

        // 构建请求 URL
        $url = str_replace('%u', urlencode($wd), $template);
        if (strpos($url, 'ac=videolist') !== false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . "pg={$pg}&limit={$limit}";
        }

        $timeout = max(3, (int)(mxgj_settings()['timeout'] ?? 15));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_REFERER        => 'https://test.com/',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        mxgj_apply_proxy($ch, $url);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($body === false)  mxgj_json_out(['ok' => false, 'msg' => '请求失败：' . ($curlErr ?: '网络错误')]);
        if ($httpCode !== 200) mxgj_json_out(['ok' => false, 'msg' => "HTTP {$httpCode}"]);

        $data = json_decode($body, true);
        if (!$data) mxgj_json_out(['ok' => false, 'msg' => '返回内容不是有效 JSON', 'raw' => substr($body, 0, 200)]);

        $list  = $data['list'] ?? [];
        $items = [];
        foreach ($list as $v) {
            // 解析播放源 + 集数链接
            $sources = [];
            $pf = $v['vod_play_from'] ?? '';
            $pu = $v['vod_play_url'] ?? '';
            if ($pf && $pu) {
                $names = explode('$$$', $pf);
                $urls  = explode('$$$', $pu);
                foreach ($names as $ni => $name) {
                    if (!isset($urls[$ni])) continue;
                    $episodes = [];
                    foreach (explode('#', $urls[$ni]) as $part) {
                        $kv = explode('$', $part, 2);
                        if (count($kv) === 2 && trim($kv[1]) !== '') {
                            $episodes[] = ['name' => trim($kv[0]), 'url' => trim($kv[1])];
                        }
                    }
                    if ($episodes) {
                        $sources[] = ['name' => trim($name), 'episodes' => $episodes];
                    }
                }
            }
            $items[] = [
                'id'        => $v['vod_id'] ?? 0,
                'name'      => $v['vod_name'] ?? '',
                'sub'       => $v['vod_sub'] ?? '',
                'remarks'   => $v['vod_remarks'] ?? '',
                'pic'       => $v['vod_pic'] ?? '',
                'type'      => $v['type_name'] ?? '',
                'area'      => $v['vod_area'] ?? '',
                'year'      => $v['vod_year'] ?? '',
                'director'  => $v['vod_director'] ?? '',
                'actor'     => $v['vod_actor'] ?? '',
                'time'      => $v['vod_time'] ?? '',
                'content'   => $v['vod_content'] ?? '',
                'total'     => $v['vod_total'] ?? 0,
                'sources'   => $sources,
            ];
        }

        mxgj_json_out([
            'ok'        => true,
            'site_name' => $site['name'] ?? '',
            'search_url'=> $url,
            'total'     => count($items),
            'pg'        => $pg,
            'items'     => $items,
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
        <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,shrink-to-fit=no">
        <title>沫兮官替系统 - 登录</title>
        <style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Microsoft YaHei,sans-serif;background:linear-gradient(135deg,#0f1420 0%,#1a2236 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff;padding:20px}
.card{background:rgba(27,34,51,.95);padding:40px 32px;border-radius:16px;width:100%;max-width:340px;box-shadow:0 20px 60px rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.06);backdrop-filter:blur(10px)}
.card h1{font-size:22px;margin:0 0 6px;color:#fff;text-align:center}
.card p{font-size:13px;color:#8a93a6;margin:0 0 28px;text-align:center}
.card input{width:100%;box-sizing:border-box;padding:13px 14px;border:1px solid #2c3550;background:#12172a;color:#fff;border-radius:10px;margin-bottom:16px;font-size:14px;outline:none;transition:border-color .15s,box-shadow .15s}
.card input:focus{border-color:#4f7cff;box-shadow:0 0 0 3px rgba(79,124,255,.2)}
.card button{width:100%;padding:13px;border:0;border-radius:10px;background:linear-gradient(135deg,#4f7cff,#6366f1);color:#fff;font-size:15px;cursor:pointer;font-weight:500;transition:all .15s}
.card button:hover{box-shadow:0 6px 18px rgba(79,124,255,.4)}
.card .err{color:#ef4444;font-size:13px;text-align:center;margin-bottom:14px}
@media(max-width:480px){.card{padding:30px 22px}.card h1{font-size:18px}}
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


/**
 * 从资源站直链 URL (m3u8) 自动检测剧名和集数
 *
 * @param string $url m3u8 直链
 * @return array{name:string,episode:int,title_raw:string}
 */
function admin_parse_direct_url(string $url): array
{
    $parts = parse_url($url);
    $path  = $parts['path'] ?? '';
    $segs  = array_values(array_filter(explode('/', $path)));

    // === 集数检测 ===
    $ep = 0;
    $epPatterns = [
        '~-(\d{1,3})(?:集|集高清|高清|\.m3u8|高清版|$)~i',
        '~(?:episode|ep|第|play|season)[-_]?(\d{1,3})~i',
        '~^(\d{1,3})\.m3u8$~i',
        '~_(\d{1,3})\.~',
        '~[-/](\d{1,3})\.m3u8~i',
    ];
    foreach ($epPatterns as $pat) {
        foreach ($segs as $seg) {
            if (preg_match($pat, $seg, $m)) {
                $ep = (int)$m[1];
                break 2;
            }
        }
    }

    // === 剧名检测 ===
    $skipWords = ['video','videos','drama','movie','media','cdn','hls','index','m3u8','episode','ep','season','play','stream','hls_stream','vod'];
    $best = '';
    foreach ($segs as $seg) {
        // 去掉扩展名
        $clean = preg_replace('/\.[a-z0-9]+$/i', '', $seg);
        // 去掉末尾集数
        $clean = preg_replace('/[-_\s]?\d{1,3}(?:集|高清|\.m3u8|高清版)?$/i', '', $clean);
        // 跳过纯数字
        if (preg_match('/^\d+$/', $clean)) continue;
        // 跳过短词/常见英文目录
        if (in_array(strtolower($clean), $skipWords, true)) continue;
        if (strlen($clean) < 2) continue;
        // 优先选含中文的
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $clean)) {
            $best = $clean;
            break;
        }
        if (strlen($clean) > strlen($best)) $best = $clean;
    }

    // 如果没从路径检测到，尝试从 query string 里找
    if ($best === '' && !empty($parts['query'])) {
        parse_str($parts['query'], $qs);
        foreach (['name','title','vod','wd','keyword'] as $k) {
            if (!empty($qs[$k])) { $best = $qs[$k]; break; }
        }
    }

    return [
        'name'      => $best,
        'episode'   => $ep,
        'title_raw' => '',  // m3u8 直链没有原始官方剧名，留空
    ];
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
    <?php
    $tabLabels = [
        'dashboard'   => ['label' => '概览',         'icon' => '📊', 'crumb' => '首页'],
        'sites'       => ['label' => '资源站配置',   'icon' => '🌐', 'crumb' => '资源配置'],
        'sites_view'  => ['label' => '资源站查看',   'icon' => '🔍', 'crumb' => '资源配置'],
        'mapping'     => ['label' => '映射表',       'icon' => '🗂️', 'crumb' => '资源配置'],
        'update'      => ['label' => '在线更新',     'icon' => '⬆️', 'crumb' => '系统更新'],
        'logs'        => ['label' => '日志',         'icon' => '📋', 'crumb' => '日志中心'],
        'help'        => ['label' => '帮助',         'icon' => '❓', 'crumb' => '使用帮助'],
        'settings'    => ['label' => '设置',         'icon' => '⚙️', 'crumb' => '系统设置'],
    ];
    $currentLabel = $tabLabels[$tab]['label'] ?? '概览';
    $currentIcon  = $tabLabels[$tab]['icon']  ?? '📊';
    $currentCrumb = $tabLabels[$tab]['crumb'] ?? '首页';
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,shrink-to-fit=no">
<title><?= MXGJ_NAME ?> · <?= $currentLabel ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:#f0f2f5;color:#1f2937;font-size:14px}
a{color:inherit;text-decoration:none}
.layout{display:flex;min-height:100vh}
/* ====== 侧边栏（桌面）====== */
.sidebar{width:208px;background:#1e2533;color:#e5e7eb;display:flex;flex-direction:column;flex-shrink:0;border-right:1px solid #2a3347;position:fixed;top:0;left:0;bottom:0;z-index:50}
.sidebar-brand{padding:20px 16px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.06)}
.logo-box{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#4f7cff,#6366f1);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0}
.brand-info{flex:1;min-width:0}
.brand-name{font-size:15px;font-weight:700;color:#fff;line-height:1.2}
.brand-ver{font-size:11px;color:#6b7a94;margin-top:3px}
.sidebar-scroll{flex:1;overflow-y:auto;padding:10px 8px}
.sidebar-scroll::-webkit-scrollbar{width:4px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:#2a3347;border-radius:2px}
.nav-group{margin-bottom:14px}
.nav-group-title{font-size:11px;font-weight:600;color:#4b5b76;text-transform:uppercase;letter-spacing:.8px;padding:6px 10px 8px}
.nav-item{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;color:#9ca3af;font-size:13.5px;transition:all .12s;margin-bottom:2px}
.nav-item:hover{background:rgba(255,255,255,.05);color:#e5e7eb}
.nav-item.active{background:#4f7cff;color:#fff}
.nav-item .icon{font-size:15px;width:18px;text-align:center}
.sidebar-foot{padding:14px 16px;border-top:1px solid rgba(255,255,255,.06);text-align:center}
.foot-text{font-size:10.5px;color:#4b5b76}
/* ====== 主区域 ====== */
.main{flex:1;margin-left:208px;display:flex;flex-direction:column;min-height:100vh}
.topbar{height:54px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;padding:0 24px;flex-shrink:0;box-shadow:0 1px 2px rgba(0,0,0,.03);position:sticky;top:0;z-index:30}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13.5px;color:#6b7280}
.breadcrumb .sep{color:#d1d5db}
.breadcrumb .current{color:#111827;font-weight:600}
.topbar-right{display:flex;align-items:center;gap:12px}
.topbar-right .version-tag{background:#eef2ff;color:#4f7cff;font-size:12px;padding:4px 10px;border-radius:12px;font-weight:500}
.user-area{display:flex;align-items:center;gap:10px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#4f7cff,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600}
.logout-btn{background:none;border:none;color:#6b7280;font-size:12.5px;cursor:pointer;padding:6px 10px;border-radius:6px}
.logout-btn:hover{background:#f3f4f6;color:#e74c3c}
.content{flex:1;padding:24px;background:#f5f7fa}
.page-title{font-size:19px;font-weight:700;color:#111827;margin-bottom:3px}
.page-subtitle{font-size:12.5px;color:#6b7280;margin-bottom:18px}
.panel{background:#fff;border-radius:10px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);border:1px solid #ebeef2;margin-bottom:16px}
.panel h2{font-size:15.5px;font-weight:700;color:#111827;margin:0 0 14px}
.panel h3{font-size:14px;font-weight:600;color:#374151;margin:0 0 10px}
.stat-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:18px}
.stat-card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04);border:1px solid #ebeef2;display:flex;align-items:center;gap:14px}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.stat-icon.green{background:linear-gradient(135deg,#10b981,#059669)}
.stat-icon.blue{background:linear-gradient(135deg,#3b82f6,#2563eb)}
.stat-icon.orange{background:linear-gradient(135deg,#f59e0b,#d97706)}
.stat-icon.red{background:linear-gradient(135deg,#ef4444,#dc2626)}
.stat-icon.purple{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}
.stat-info b{font-size:26px;color:#111827;display:block;font-weight:700}
.stat-info span{font-size:12px;color:#6b7280}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:10px 10px;border-bottom:1px solid #f0f2f5;text-align:left}
th{color:#6b7280;font-weight:600;font-size:12px;background:#fafbfc;white-space:nowrap}
tr:hover td{background:#fafbfc}
.site-special{min-width:300px;display:grid;grid-template-columns:1fr 1fr;gap:6px}
.site-special select{min-width:0}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;padding:7px 10px;border:1px solid #e5e7eb;background:#fff;color:#1f2937;border-radius:6px;font-size:13px;outline:none;transition:border-color .15s,box-shadow .15s}
input[type=text]:focus,input[type=password]:focus,input[type=number]:focus,select:focus{border-color:#4f7cff;box-shadow:0 0 0 3px rgba(79,124,255,.12)}
label{display:block;font-size:12px;color:#6b7280;margin-bottom:6px;font-weight:500}
.btn{padding:7px 16px;border:0;border-radius:6px;background:#4f7cff;color:#fff;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s}
.btn:hover{background:#3f6ce8;box-shadow:0 2px 6px rgba(79,124,255,.2)}
.btn:active{transform:scale(.98)}
.btn-green{background:#10b981}.btn-green:hover{background:#059669}
.btn-danger{background:#ef4444}.btn-danger:hover{background:#dc2626}
.small{font-size:12px;color:#6b7280}
.note{background:#f0f4ff;border-left:3px solid #4f7cff;border-radius:6px;padding:12px 14px;font-size:13px;line-height:1.7;color:#374151}
pre{background:#1e293b;color:#94a3b8;padding:12px 14px;border-radius:8px;overflow:auto;font-size:12px;line-height:1.6}
.toast{display:none;position:fixed;top:70px;left:50%;transform:translateX(-50%);background:#10b981;color:#fff;padding:10px 22px;border-radius:8px;font-size:14px;z-index:999;box-shadow:0 6px 18px rgba(16,185,129,.3)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .full{grid-column:1/-1}
.center{text-align:center}
.row-disabled td{opacity:.45}
.row-disabled input[type=text]{text-decoration:line-through}
.toggle{position:relative;display:inline-block;width:38px;height:20px;vertical-align:middle}
.toggle input{display:none}
.toggle .slider{position:absolute;cursor:pointer;inset:0;background:#d1d5db;border-radius:20px;transition:.2s}
.toggle .slider:before{content:'';position:absolute;width:14px;height:14px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 2px rgba(0,0,0,.15)}
.toggle input:checked + .slider{background:#10b981}
.toggle input:checked + .slider:before{transform:translateX(18px)}
.add-row-btn{margin-top:10px;padding:6px 14px;background:#f0f4ff;border:1px dashed #4f7cff;color:#4f7cff;border-radius:6px;font-size:12.5px;cursor:pointer;transition:all .15s}
.add-row-btn:hover{background:#e0e8ff;border-style:solid}
/* ====== Tab 切换 ====== */
.t-tab{padding:8px 16px;background:none;border:none;font-size:13.5px;color:#6b7280;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .15s;font-weight:500}
.t-tab:hover{color:#1f2937}
.t-tab.active{color:#4f7cff;border-bottom-color:#4f7cff}
.tab-pane{animation:fadein .2s ease}
@keyframes fadein{from{opacity:0;transform:translateY(-2px)}to{opacity:1;transform:none}}
/* ====== 移动端（≤768px）====== */
@media(max-width:768px){
    body{overflow-x:hidden}
    .sidebar{display:none}
    .main{margin-left:0;padding-bottom:70px}
    .topbar{height:48px;padding:0 14px}
    .breadcrumb{font-size:12.5px;gap:4px;max-width:55%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .topbar-right .version-tag{display:none}
    .user-area .logout-btn{display:none}
    .content{padding:14px;background:#f5f7fa}
    .page-title{font-size:17px}
    .page-subtitle{font-size:11.5px;margin-bottom:14px}
    .panel{padding:14px;margin-bottom:12px;border-radius:8px}
    .panel h2{font-size:14.5px;margin-bottom:10px}
    .stat-cards{grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
    .stat-card{padding:12px;gap:10px}
    .stat-icon{width:40px;height:40px;font-size:18px;border-radius:10px}
    .stat-info b{font-size:20px}
    .form-grid{grid-template-columns:1fr;gap:12px}
    .panel > table{display:block;overflow-x:auto;-webkit-overflow-scrolling:touch}
    .panel > table td,.panel > table th{padding:8px;font-size:12.5px;white-space:nowrap}
    .site-special{min-width:260px}
    .btn{padding:6px 12px;font-size:12.5px}
    .add-row-btn{width:100%}
    .note{padding:10px 12px;font-size:12.5px;line-height:1.6}
}
/* ====== 手机底部 Tab 栏 ====== */
.mobile-tabbar{display:none}
@media(max-width:768px){
    .mobile-tabbar{display:flex;position:fixed;bottom:0;left:0;right:0;height:60px;background:#fff;border-top:1px solid #e5e7eb;z-index:100;padding-bottom:env(safe-area-inset-bottom)}
    .mobile-tabbar a{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#9ca3af;font-size:10.5px;gap:2px;text-decoration:none;transition:color .15s}
    .mobile-tabbar a.active{color:#4f7cff}
    .mobile-tabbar a .m-icon{font-size:20px;line-height:1}
}
/* 超小屏 ≤400px */
@media(max-width:400px){
    .stat-cards{grid-template-columns:1fr 1fr}
    .stat-info b{font-size:18px}
    .stat-info span{font-size:11px}
}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="logo-box">◆</div>
    <div class="brand-info">
      <div class="brand-name"><?= MXGJ_NAME ?></div>
      <div class="brand-ver">v<?= MXGJ_VERSION ?></div>
    </div>
  </div>
  <div class="sidebar-scroll">
    <div class="nav-group">
      <div class="nav-group-title">概览</div>
      <a class="nav-item <?= $tab==='dashboard'?'active':'' ?>" href="?tab=dashboard"><span class="icon">📊</span><span>数据概览</span></a>
    </div>
    <div class="nav-group">
      <div class="nav-group-title">资源配置</div>
      <a class="nav-item <?= $tab==='sites'?'active':'' ?>" href="?tab=sites"><span class="icon">🌐</span><span>资源站配置</span></a>
      <a class="nav-item <?= $tab==='sites_view'?'active':'' ?>" href="?tab=sites_view"><span class="icon">🔍</span><span>资源站查看</span></a>
      <a class="nav-item <?= $tab==='mapping'?'active':'' ?>" href="?tab=mapping"><span class="icon">🗂️</span><span>映射表</span></a>
    </div>
    <div class="nav-group">
      <div class="nav-group-title">系统</div>
      <a class="nav-item <?= $tab==='update'?'active':'' ?>" href="?tab=update"><span class="icon">⬆️</span><span>在线更新</span></a>
      <a class="nav-item <?= $tab==='logs'?'active':'' ?>" href="?tab=logs"><span class="icon">📋</span><span>日志中心</span></a>
      <a class="nav-item <?= $tab==='settings'?'active':'' ?>" href="?tab=settings"><span class="icon">⚙️</span><span>系统设置</span></a>
      <a class="nav-item <?= $tab==='help'?'active':'' ?>" href="?tab=help"><span class="icon">❓</span><span>使用帮助</span></a>
    </div>
  </div>
  <div class="sidebar-foot">
    <span class="foot-text">MXGJ · 沫兮官替系统</span>
  </div>
</aside>
<div class="main">
<div class="topbar">
<div class="breadcrumb"><span>🏠</span><span class="sep">/</span><span><?= $currentCrumb ?></span><span class="sep">/</span><span class="current"><?= $currentLabel ?></span></div>
<div class="topbar-right">
<span class="version-tag">v<?= MXGJ_VERSION ?></span>
<div class="user-area">
<div class="user-avatar">MX</div>
<form method="post" style="margin:0"><input type="hidden" name="action" value="logout"><button class="logout-btn" type="submit">退出</button></form>
</div>
</div>
</div>
<div class="content">
<div class="page-title"><?= $currentIcon ?> <?= $currentLabel ?></div>
<div class="page-subtitle"><?= $currentCrumb ?></div>
<div class="toast" id="toast">保存成功</div>
<?php if ($saved): ?><script>document.getElementById('toast').style.display='block';setTimeout(()=>document.getElementById('toast').style.display='none',2000);</script><?php endif; ?>
<?php if ($cleared): ?><script>alert('缓存已清空');</script><?php endif; ?>
<?php if ($tab === 'dashboard'): ?>
<?php renderOverview($sites, $cacheCnt, $mapping); ?>
<?php elseif ($tab === 'sites'): ?>
<?php renderSitesForm($sites); ?>
<?php elseif ($tab === 'sites_view'): ?>
<?php renderSiteListView($sites); ?>
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
</div>
</div>
<!-- 移动端底部 Tab 栏 -->
<nav class="mobile-tabbar">
  <a class="<?= $tab==='dashboard'?'active':'' ?>" href="?tab=dashboard"><span class="m-icon">📊</span><span>概览</span></a>
  <a class="<?= $tab==='sites'?'active':'' ?>" href="?tab=sites"><span class="m-icon">🌐</span><span>配置</span></a>
  <a class="<?= $tab==='sites_view'?'active':'' ?>" href="?tab=sites_view"><span class="m-icon">🔍</span><span>查看</span></a>
  <a class="<?= $tab==='mapping'?'active':'' ?>" href="?tab=mapping"><span class="m-icon">🗂️</span><span>映射</span></a>
  <a class="<?= $tab==='logs'?'active':'' ?>" href="?tab=logs"><span class="m-icon">📋</span><span>日志</span></a>
  <a class="<?= $tab==='settings'?'active':'' ?>" href="?tab=settings"><span class="m-icon">⚙️</span><span>设置</span></a>
</nav>
<script>
var __autoDirty=null, __saveTimer=null;
function __setSaveStatus(text,color){
    var el=document.getElementById('save-status'),dot=document.getElementById('save-indicator');
    if(el)el.textContent=text;
    if(dot){
        dot.style.background=color||'#4ade80';
        dot.style.boxShadow='0 0 8px rgba('+(color==='#fbbf24'?'251,191,36':color==='#ef4444'?'239,68,68':'74,222,128')+',0.5)';
    }
}
function __markDirty(e){
    if(e.target.closest('.quick-toggle'))return;
    var f=e.target.form||(e.target.closest?e.target.closest('form'):null);
    if(f&&f.classList&&f.classList.contains('auto-save')){ __autoDirty=f; __setSaveStatus('有未保存的修改','#fbbf24'); }
}
function __trySave(){
    if(__saveTimer)clearTimeout(__saveTimer);
    __saveTimer=setTimeout(function(){
        var f=__autoDirty;if(!f)return;
        __autoDirty=null;__saveTimer=null;
        try{ __setSaveStatus('保存中...','#fbbf24'); f.submit(); }catch(err){ __setSaveStatus('保存失败','#ef4444'); }
    },250);
}
document.addEventListener('input',__markDirty);
document.addEventListener('change',__markDirty);
document.addEventListener('click',function(e){
    if(e.target.closest('.quick-toggle'))return;
    var inForm=e.target.closest?e.target.closest('form.auto-save'):null;
    if(inForm){
        if(e.target.closest('button[type="button"]')){ __autoDirty=inForm; }
        return;
    }
    __trySave();
});
window.addEventListener('blur',function(){ __trySave(); });
</script>
</body></html>

<?php
}

function renderOverview($sites, $cacheCnt, $mapping)
{
    ?>
    <div class="stat-cards">
        <div class="stat-card"><div class="stat-icon green">🌐</div><div class="stat-info"><b><?= count($sites) ?></b><span>资源站</span></div></div>
        <div class="stat-card"><div class="stat-icon orange">💾</div><div class="stat-info"><b><?= $cacheCnt ?></b><span>缓存文件</span></div></div>
        <div class="stat-card"><div class="stat-icon purple">🗂️</div><div class="stat-info"><b><?= count($mapping['title'] ?? []) + count($mapping['cid'] ?? []) ?></b><span>映射条目</span></div></div>
    </div>

    <div class="panel" style="margin-bottom:16px">
        <h2>接口说明</h2>
        <pre><code>GET /index.php?url=&lt官方链接&gt[&page=&lt集数&gt][&debug=1]
例：/index.php?url=https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce</code></pre>
    </div>

    <div class="panel" style="margin-bottom:16px">
        <h2>一键测试</h2>
        <div class="tab-bar" style="display:flex;gap:0;margin-bottom:14px;border-bottom:1px solid #e5e7eb">
            <button type="button" class="t-tab active" data-tab="official" onclick="switchTab('official')">🎯 官方链接 → 资源站搜索</button>
            <button type="button" class="t-tab" data-tab="direct" onclick="switchTab('direct')">📡 资源站直链 → 写入映射</button>
        </div>
        <!-- Tab 1: 官方链接 -->
        <div class="tab-pane" id="tab-official">
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <input id="t-url" type="text" placeholder="粘贴官方播放链接（腾讯/爱奇艺/优酷/芒果...）" style="flex:1;min-width:300px">
                <button class="btn" onclick="testFull()">🔍 解析测试</button>
            </div>
            <div class="small" style="margin-top:8px;color:#6b7280">自动解析官方链接 → 提取 vid/cid/剧名/集数 → 去全部资源站并发搜索</div>
        </div>
        <!-- Tab 2: 资源站直链 (m3u8) -->
        <div class="tab-pane" id="tab-direct" style="display:none">
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                <input id="d-url" type="text" placeholder="粘贴 .m3u8 直链地址" style="flex:1;min-width:300px" oninput="debounceDetect()">
                <button class="btn btn-green" onclick="detectDirect()">🔎 自动检测</button>
            </div>
            <div id="d-form" style="display:none;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin-bottom:12px">
                <div class="small" style="margin-bottom:10px;color:#4f7cff;font-weight:500">✓ 检测结果 —— 请确认并修正</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label>剧名（资源站使用）</label>
                        <input id="d-name" type="text" placeholder="如：庆余年">
                    </div>
                    <div>
                        <label>集数</label>
                        <input id="d-ep" type="number" min="1" max="999" placeholder="如：2">
                    </div>
                </div>
                <div style="margin-top:10px;font-size:12px;color:#6b7280" id="d-raw"></div>
                <div style="margin-top:12px;display:flex;gap:8px">
                    <button class="btn btn-green" onclick="saveDirect()">💾 写入映射表</button>
                    <button class="btn" style="background:#6b7280" onclick="detectDirect()">重新检测</button>
                </div>
            </div>
        </div>
        <pre id="t-out" style="margin-top:12px;display:none;max-height:300px;overflow:auto"></pre>
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
    /* === Tab 切换 === */
    function switchTab(name){
        document.querySelectorAll('.t-tab').forEach(b=>b.classList.toggle('active',b.dataset.tab===name));
        document.querySelectorAll('.tab-pane').forEach(p=>p.style.display='none');
        document.getElementById('tab-'+name).style.display='block';
        document.getElementById('t-out').style.display='none';
    }
    /* === 资源站直链检测 === */
    var __detectTimer=null;
    function debounceDetect(){
        if(__detectTimer)clearTimeout(__detectTimer);
        __detectTimer=setTimeout(detectDirect,400);
    }
    function detectDirect(){
        var url=document.getElementById('d-url').value.trim();
        if(!url){document.getElementById('d-form').style.display='none';return;}
        var d=document.getElementById('d-form');d.style.display='block';
        d.querySelector('.small').textContent='⏳ 自动检测中...';
        var fd=new FormData();fd.append('action','test_direct');fd.append('url',url);
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
            if(!res.ok){d.querySelector('.small').textContent='❌ '+res.msg;return;}
            var dt=res.detected;
            document.getElementById('d-name').value=dt.name||'';
            document.getElementById('d-ep').value=dt.episode||'';
            document.getElementById('d-raw').textContent='检测结果: 剧名="'+(dt.name||'未识别')+'" 集数='+(dt.episode||'未识别')+'（可手动修正后保存）';
            d.querySelector('.small').textContent='✓ 检测结果 —— 请确认并修正';
            // 显示完整调试
            document.getElementById('t-out').style.display='block';
            document.getElementById('t-out').textContent='=== 检测结果 ===\n剧名: '+(dt.name||'(未识别，需手动填写)')+'\n集数: '+(dt.episode||0)+'\n\n=== 验证搜索 ===\n'+JSON.stringify(res.verify_search||{},null,2);
        }).catch(e=>{d.querySelector('.small').textContent='❌ 请求失败: '+e;});
    }
    function saveDirect(){
        var url=document.getElementById('d-url').value.trim();
        var name=document.getElementById('d-name').value.trim();
        var ep=parseInt(document.getElementById('d-ep').value)||0;
        if(!url||!name){alert('请填写剧名和直链地址');return;}
        var fd=new FormData();
        fd.append('action','test_direct');
        fd.append('url',url);
        fd.append('name',name);
        fd.append('episode',ep);
        fd.append('save','1');
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
            var out=document.getElementById('t-out');out.style.display='block';
            if(res.saved){
                out.textContent='✅ '+res.msg+'\n\n写入内容:\n'+(res.saved_map||[]).map(m=>'  - '+m).join('\n')+'\n\n原始返回:\n'+JSON.stringify(res,null,2);
            }else{
                out.textContent='❌ 保存失败: '+(res.msg||'未知错误')+'\n\n'+JSON.stringify(res,null,2);
            }
        }).catch(e=>alert('请求失败: '+e));
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
            <b>特殊调用方法</b>：（默认 GET）如资源站要求 POST 提交、自定义请求头或特殊返回格式，可在「调用方法」列选择
            <code>POST</code> 并填写「POST体」（如 <code>wd=%u&ep=%p</code>，含占位符）、「自定义Header」（每行 <code>Key: Value</code>）、
            「返回解析」（自动 / JSON / 纯文本 / 苹果CMS）。<br>
            每行「启用」开关<b>即时生效</b>（无需保存）：关闭后该资源站不再参与前台搜索与心跳探测。
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
            <button type="button" class="btn btn-green" onclick="openDetect()">⚡ 检测并自动添加（苹果CMS10采集接口）</button>
            <span class="note" style="margin:0;color:#8892ab">只需粘贴采集接口地址，系统自动探测并生成搜索模板，保存后即可被前台调用。</span>
            <button type="button" class="btn" style="background:#6366f1" onclick="openTemplatePicker()">📋 从模板生成（选框架→填host）</button>
        </div>
        <div class="note" style="margin:0 0 16px;padding:8px 12px;font-size:12px;color:#7fc1ff">
            💡 修改任意字段后，<b>点击页面空白处自动保存</b>；也可点击下方 <b>「💾 立即保存」</b> 按钮手动触发。保存时自动清理缓存、站点健康与日志。
        </div>
        <form method="post" class="auto-save" id="form-sites">
            <input type="hidden" name="action" value="save_sites">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;padding:12px 16px;background:linear-gradient(135deg,#1e293b 0%,#1e1b4b 100%);border-radius:10px;border:1px solid rgba(99,102,241,0.25)">
                <div style="display:flex;align-items:center;gap:10px">
                    <span id="save-indicator" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#4ade80;box-shadow:0 0 8px rgba(74,222,128,0.5)"></span>
                    <span style="font-size:13px;color:#cbd5e1">状态：<b id="save-status">已就绪</b></span>
                </div>
                <div style="display:flex;gap:10px;align-items:center">
                    <button type="button" class="btn" onclick="addRow()" style="background:#22c55e;color:#fff">➕ 添加资源站</button>
                    <button type="submit" id="btn-save-top" style="padding:10px 24px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:10px;font-weight:600;font-size:14px;cursor:pointer;box-shadow:0 4px 14px rgba(99,102,241,0.4);transition:transform 0.15s,box-shadow 0.2s">
                        💾 立即保存
                    </button>
                </div>
            </div>
            <table id="site-tbl">
                <tr><th style="width:110px">站点名称</th><th>搜索地址模板（含 %s / %u / %p）</th><th>跟随播放链接（中转前缀）</th><th>特殊调用方法</th><th style="width:80px">特殊站</th><th style="width:60px">启用</th><th style="width:120px"></th></tr>
                <?php if ($sites): foreach ($sites as $i => $s): ?>
                    <?php $sEnabled = !array_key_exists('enabled', $s) || !empty($s['enabled']); ?>
                    <?php $sMethod = mxgj_lower(trim($s['method'] ?? '')); ?>
                    <?php $sParse  = mxgj_lower(trim($s['parse'] ?? '')); ?>
                    <?php $sSpecial = !empty($s['is_special']); ?>
                    <tr class="<?= $sEnabled ? '' : 'row-disabled' ?>">
                        <td><input type="text" name="sites[<?= $i ?>][name]" value="<?= htmlspecialchars($s['name']) ?>"></td>
                        <td><input type="text" name="sites[<?= $i ?>][template]" value="<?= htmlspecialchars($s['template']) ?>" onclick="this.select()"></td>
                        <td><input type="text" name="sites[<?= $i ?>][proxy]" value="<?= htmlspecialchars($s['proxy'] ?? '') ?>" placeholder="如 https://vv00.xyz?url= （留空不使用）"></td>
                        <td class="site-special">
                            <select name="sites[<?= $i ?>][method]" title="HTTP 调用方法">
                                <option value="get" <?= $sMethod === 'post' ? '' : 'selected' ?>>GET</option>
                                <option value="post" <?= $sMethod === 'post' ? 'selected' : '' ?>>POST</option>
                            </select>
                            <select name="sites[<?= $i ?>][parse]" title="返回解析模式（留空=自动识别）">
                                <option value="" <?= $sParse === '' ? 'selected' : '' ?>>自动</option>
                                <option value="json" <?= $sParse === 'json' ? 'selected' : '' ?>>JSON</option>
                                <option value="text" <?= $sParse === 'text' ? 'selected' : '' ?>>纯文本</option>
                                <option value="apple" <?= $sParse === 'apple' ? 'selected' : '' ?>>苹果CMS</option>
                            </select>
                            <input type="text" name="sites[<?= $i ?>][headers]" value="<?= htmlspecialchars($s['headers'] ?? '') ?>" placeholder="自定义Header（每行 Key: Value）">
                            <input type="text" name="sites[<?= $i ?>][post]" value="<?= htmlspecialchars($s['post'] ?? '') ?>" placeholder="POST体（如 wd=%u&ep=%p）">
                        </td>
                        <td class="center">
                            <label class="toggle" title="开启后：该站返回的 URL 自动套 本地//player/ 播放器">
                                <input type="checkbox" name="sites[<?= $i ?>][is_special]" value="1" <?= $sSpecial ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </td>
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
                        <td class="site-special">
                            <select name="sites[0][method]"><option value="get" selected>GET</option><option value="post">POST</option></select>
                            <select name="sites[0][parse]"><option value="">自动</option><option value="json">JSON</option><option value="text">纯文本</option><option value="apple">苹果CMS</option></select>
                            <input type="text" name="sites[0][headers]" placeholder="自定义Header（每行 Key: Value）">
                            <input type="text" name="sites[0][post]" placeholder="POST体（如 wd=%u&ep=%p）">
                        </td>
                        <td class="center"><input type="checkbox" name="sites[0][is_special]" value="1" title="特殊资源站：自动套本地播放器"></td>
                        <td class="center"><input type="checkbox" checked disabled title="新站点默认启用"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endif; ?>
            </table>
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
            '<td class="site-special">'+
                '<select name="sites['+rowIndex+'][method]"><option value="get" selected>GET</option><option value="post">POST</option></select>'+
                '<select name="sites['+rowIndex+'][parse]"><option value="">自动</option><option value="json">JSON</option><option value="text">纯文本</option><option value="apple">苹果CMS</option></select>'+
                '<input type="text" name="sites['+rowIndex+'][headers]" placeholder="自定义Header（每行 Key: Value）">'+
                '<input type="text" name="sites['+rowIndex+'][post]" placeholder="POST体（如 wd=%u&ep=%p）">'+
            '</td>'+
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
    /* ===== 📋 搜索接口模板选择器 ===== */
    function openTemplatePicker() {
        fetch('admin.php',{method:'POST',body:new FormData([['action','get_templates']])})
          .then(r=>r.json()).then(function(d){
            showTemplatePickerModal(d.templates);
        });
    }
    function showTemplatePickerModal(tpls) {
        var opts = tpls.map(function(t){
            var mark = t.built_in ? '📦' : '✨';
            var hint = t.is_html ? ' <span style="color:#7fc1ff;font-size:11px">HTML</span>' : ' <span style="color:#2ecc71;font-size:11px">JSON</span>';
            return '<option value="'+t.id+'">'+mark+' '+t.name+hint+'</option>';
        }).join('');
        var html='<div id="tpl-modal" style="position:fixed;inset:0;background:rgba(5,10,20,.85);z-index:9999;display:flex;align-items:center;justify-content:center">'+
            '<div style="background:#141b2d;border:1px solid #2a3550;border-radius:12px;padding:22px;width:560px;max-width:92vw">'+
            '<h3 style="margin:0 0 6px;color:#fff">📋 选择搜索接口模板</h3>'+
            '<p style="font-size:12px;color:#9aa4bc;margin:0 0 14px">选一个框架类型，填入资源站域名，自动生成搜索模板 URL。<br>大部分资源站都是<b>苹果CMS10</b>，推荐第一个「前端搜索页」。</p>'+
            '<div style="margin-bottom:12px"><label>框架类型</label><select id="tpl-id" style="width:100%;box-sizing:border-box" onchange="updateTplPreview()">'+opts+'</select></div>'+
            '<div style="margin-bottom:12px"><label>资源站域名 <span style="color:#8892ab;font-size:11px">(只填 host，不要 http://)</span></label>'+
            '<input id="tpl-host" type="text" placeholder="如 api.wsyzy.net" style="width:100%;box-sizing:border-box" oninput="updateTplPreview()"></div>'+
            '<div style="margin-bottom:12px"><label>预览生成的搜索模板</label>'+
            '<div id="tpl-preview" style="background:#0b1120;border:1px solid #2a3550;border-radius:6px;padding:10px;font-size:12px;color:#7fc1ff;word-break:break-all">请先选择模板并填入域名</div></div>'+
            '<div id="tpl-desc" style="font-size:12px;color:#8892ab;margin-bottom:14px"></div>'+
            '<div style="text-align:right">'+
            '<button type="button" class="btn" onclick="document.getElementById(\'tpl-modal\').remove()" style="margin-right:8px">取消</button>'+
            '<button type="button" class="btn btn-green" onclick="applyTpl()">生成并打开检测</button></div>'+
            '</div></div>';
        document.body.insertAdjacentHTML('beforeend', html);
        setTimeout(function(){ document.getElementById('tpl-host').focus(); }, 100);
        // 缓存模板列表到 window
        window.__tplList = tpls;
        updateTplPreview();
    }
    function updateTplPreview() {
        var sel = document.getElementById('tpl-id').value;
        var host = document.getElementById('tpl-host').value.trim();
        var cur = (window.__tplList||[]).find(function(t){return t.id===sel;})||{};
        document.getElementById('tpl-desc').textContent = cur.desc || '';
        if (cur.id === 'custom') {
            document.getElementById('tpl-preview').textContent = '请手动在检测弹窗里填写完整模板 URL';
            return;
        }
        if (!host) {
            document.getElementById('tpl-preview').textContent = cur.pattern.replace('{host}','域名').replace('{kw}','关键词');
            return;
        }
        var url = cur.pattern.replace('{host}', host).replace('{kw}', '%u');
        document.getElementById('tpl-preview').textContent = url;
    }
    function applyTpl() {
        var sel = document.getElementById('tpl-id').value;
        var host = document.getElementById('tpl-host').value.trim();
        if (!host) { alert('请先填域名'); return; }
        var fd = new FormData();
        fd.append('action','render_from_template');
        fd.append('template_id', sel);
        fd.append('host', host);
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
            document.getElementById('tpl-modal').remove();
            if (!d.ok) { alert(d.msg); return; }
            openDetect([host, d.template]);
        }).catch(e=>alert('生成失败: '+e));
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
        msg.textContent='Phase 1/2：基础列表探测中...';msg.style.color='#e2b93b';
        document.getElementById('dd-hint').style.display='none';
        document.getElementById('dd-warn').style.display='none';
        document.getElementById('dd-phase').style.display='none';
        var fd=new FormData();fd.append('action','detect_site');fd.append('url',url);
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
            document.getElementById('dd-hint').style.display='block';
            var smp=d.sample||{};
            document.getElementById('dd-hint-name').textContent=smp.name||'(无)';
            document.getElementById('dd-hint-url').textContent=smp.url||'';

            // 显示阶段详情（v3 新增）
            var phase=d.phase||{};
            if(Object.keys(phase).length>0){
                var pEl=document.getElementById('dd-phase');
                pEl.style.display='block';
                pEl.innerHTML='<div style="font-weight:500;margin-bottom:6px">🔬 三阶段检测详情</div>' +
                    '<div style="font-size:11.5px;color:#b8c0d2;line-height:1.9">' +
                    (phase.phase1?'<div>Phase 1 · 基础列表: ' + phase.phase1 + '</div>':'') +
                    (phase.phase15?'<div>Phase 1.5 · 会话预热: ' + phase.phase15 + '</div>':'') +
                    (phase.phase2?'<div>Phase 2 · 关键词搜索: ' + phase.phase2 + '</div>':'') +
                    '</div>';
            }

            if(!d.ok){
                msg.textContent='✘ Phase 1 就挂了：'+(d.msg||'未知错误');
                msg.style.color='#e74c3c';
                return;
            }
            document.getElementById('dd-name').value=d.name||'';
            document.getElementById('dd-tpl').value=d.template||'';

            // 关键词搜索探测结果
            var probe=d.search_probe||{};
            if(d.searchable){
                msg.textContent='✅ 通过！基础列表 + 关键词搜索均正常';
                msg.style.color='#2ecc71';
                var sEl=document.getElementById('dd-search');
                sEl.style.display='block';
                var overlapTxt=probe.overlap!==undefined?'（与空列表重合度仅 '+Math.round(probe.overlap*100)+'% — 确实返回了不同内容）':'';
                sEl.innerHTML='<span style="color:#2ecc71">●</span> 关键词搜索：<b>正常</b> ✔<br>' +
                    '<span style="color:#6b7a94;font-size:11px">策略: '+(probe.strategy||'')+overlapTxt+'</span>';
            }else{
                // 基础OK但搜索不行 —— 警告！
                msg.textContent='⚠️ 基础列表OK，但关键词搜索不可用！';
                msg.style.color='#f59e0b';
                var wEl=document.getElementById('dd-warn');
                wEl.style.display='block';
                wEl.innerHTML='<b>🚨 关键词搜索不可用</b><br>' +
                    '原因：'+(d.warn_detail||probe.msg||'接口不支持关键词查询')+'<br>' +
                    '可能情况：① 此接口真不支持搜索 ② 接口开启了搜索验证码（需要手动解锁）<br>' +
                    '<button type="button" class="btn" style="background:#f59e0b;margin-top:8px" onclick="unlockCaptcha(encodeURIComponent(document.getElementById(\'dd-tpl\').value))">🔓 尝试手动解锁搜索验证</button>';
                var sEl=document.getElementById('dd-search');
                sEl.style.display='block';
                sEl.innerHTML='<span style="color:#f59e0b">●</span> 关键词搜索：<b>不可用</b> ❌<br>' +
                    '<span style="color:#6b7a94;font-size:11px">'+(probe.msg||'')+'</span>';
            }
        }).catch(function(e){msg.textContent='请求失败:'+e;msg.style.color='#e74c3c';});
    }
    /* ===== 🔐 搜索验证码解锁 ===== */
    function unlockCaptcha(tpl){
        // Step 1: fetch 验证码图片
        var fd=new FormData();fd.append('action','captcha_fetch');fd.append('url',tpl);
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
            if(!d.ok){alert(d.msg);return;}
            var host=d.api_host;
            // 弹出验证码解锁对话框
            var html='<div id="cap-modal" style="position:fixed;inset:0;background:rgba(5,10,20,.85);z-index:9999;display:flex;align-items:center;justify-content:center">' +
                '<div style="background:#141b2d;border:1px solid #2a3550;border-radius:12px;padding:22px;width:420px;max-width:92vw">' +
                '<h3 style="margin:0 0 12px;color:#fff">🔐 手动解锁搜索验证</h3>' +
                '<p style="font-size:12px;color:#b8c0d2;margin:0 0 14px">接口 <b>'+host+'</b> 开启了苹果CMS10 搜索验证码，请在下方输入验证码图片上的数字。解锁后 Cookie 自动保存 15 分钟。</p>' +
                '<div style="text-align:center;margin-bottom:14px">' +
                '<img id="cap-img" src="'+d.captcha_img+'" style="max-width:200px;border-radius:8px;border:1px solid #2a3550;padding:8px;background:#fff" onerror="this.style.display=\'none\';document.getElementById(\'cap-img-fallback\').style.display=\'block\'">' +
                '<div id="cap-img-fallback" style="display:none;background:#2a3550;border-radius:8px;padding:16px;color:#9aa4bc;font-size:12px">❌ 自动拉取验证码图片失败<br>请在浏览器中访问 <a href="https://'+host+'/" target="_blank" style="color:#7fc1ff">https://'+host+'/</a> 刷新首页后再试</div>' +
                '</div>' +
                '<input id="cap-code" type="text" inputmode="numeric" placeholder="输入验证码数字" style="width:100%;box-sizing:border-box;text-align:center;font-size:18px;letter-spacing:8px">' +
                '<div id="cap-msg" style="margin-top:10px;font-size:12px"></div>' +
                '<div style="margin-top:16px;text-align:right">' +
                '<button type="button" class="btn" onclick="document.getElementById(\'cap-modal\').remove()" style="margin-right:8px">取消</button>' +
                '<button type="button" class="btn btn-green" onclick="submitCaptcha(\''+host+'\',\''+encodeURIComponent(tpl)+'\')">提交验证</button>' +
                '</div></div></div>';
            document.body.insertAdjacentHTML('beforeend', html);
        }).catch(e=>alert('请求失败:'+e));
    }
    function submitCaptcha(host,tpl){
        var code=document.getElementById('cap-code').value.trim();
        if(!code){alert('请输入验证码');return;}
        var msg=document.getElementById('cap-msg');
        msg.textContent='验证中...';msg.style.color='#e2b93b';
        var fd=new FormData();
        fd.append('action','captcha_submit');
        fd.append('host',host);
        fd.append('code',code);
        fd.append('template',decodeURIComponent(tpl));
        fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
            if(d.ok){
                msg.textContent='✅ '+d.msg;msg.style.color='#2ecc71';
                setTimeout(function(){document.getElementById('cap-modal').remove();detectSite();},1500);
            }else{
                msg.textContent='❌ '+d.msg;msg.style.color='#e74c3c';
            }
        }).catch(e=>{msg.textContent='❌ 请求失败:'+e;msg.style.color='#e74c3c';});
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
                <div><b>Phase 1 - 基础探测：</b></div>
                <div>样本剧名：<span id="dd-hint-name" style="color:#7fc1ff"></span></div>
                <div>首条地址：<code id="dd-hint-url" style="word-break:break-all;font-size:11px"></code></div>
                <div id="dd-search" style="margin-top:8px;padding-top:8px;border-top:1px dashed #2a3550;display:none"></div>
            </div>
            <div id="dd-phase" style="display:none;margin-top:10px;background:#0c111d;border:1px solid #1f2940;border-radius:8px;padding:10px 12px;font-size:12px;color:#9aa4bc"></div>
            <div id="dd-warn" style="display:none;margin-top:10px;background:rgba(245,158,11,.12);border:1px solid #f59e0b;border-radius:8px;padding:10px 12px;font-size:12px;color:#fbbf24;line-height:1.6"></div>
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

function renderSiteListView($sites)
{
    $enabledSites = array_filter($sites, function($s) { return !empty($s['enabled']); });
    $siteOptions  = [];
    foreach ($sites as $i => $s) {
        $siteOptions[$i] = ($s['name'] ?? '未命名') . (!empty($s['enabled']) ? '' : ' [已禁用]');
    }
    ?>
<style>
/* ====== 资源站查看 Tab 专用样式 ====== */
.sv-toolbar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;align-items:center}
.sv-select,.sv-input,.sv-btn{padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;background:#fff}
.sv-select{min-width:160px}
.sv-input{flex:1;min-width:180px}
.sv-input:focus,.sv-select:focus{border-color:#4f7cff;box-shadow:0 0 0 2px rgba(79,124,255,.15)}
.sv-btn{cursor:pointer;border:none;color:#fff;background:#4f7cff;white-space:nowrap}
.sv-btn:hover{background:#3b6ae6}
.sv-btn:disabled{background:#94a3b8;cursor:not-allowed}
.sv-btn-sec{background:#64748b}
.sv-btn-sec:hover{background:#475569}

.sv-table-wrap{background:#fff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden}
.sv-table{width:100%;border-collapse:collapse;font-size:13px}
.sv-table th{background:#f8fafc;text-align:left;padding:10px 12px;font-weight:600;color:#374151;border-bottom:1px solid #e2e8f0;font-size:12px}
.sv-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;color:#475569}
.sv-table tr:last-child td{border-bottom:none}
.sv-table tr:hover td{background:#f8fafc}
.sv-table .sv-name{font-weight:600;color:#1e293b;cursor:pointer}
.sv-table .sv-name:hover{color:#4f7cff;text-decoration:underline}
.sv-table .sv-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;background:#eef2ff;color:#4f7cff}
.sv-table .sv-type{color:#64748b}
.sv-table .sv-time{color:#94a3b8;font-size:12px;white-space:nowrap}
.sv-table .sv-detail-btn{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px}
.sv-table .sv-detail-btn:hover{opacity:.9}

.sv-empty{text-align:center;padding:60px 20px;color:#94a3b8}
.sv-empty .sv-emoji{font-size:48px;margin-bottom:12px}
.sv-empty .sv-text{font-size:14px}

/* Modal */
.sv-modal-mask{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
.sv-modal-mask.show{display:flex}
.sv-modal{background:#fff;border-radius:12px;max-width:720px;width:100%;max-height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.sv-modal-head{padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.sv-modal-head h3{margin:0;font-size:16px;color:#1e293b;flex:1}
.sv-modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;padding:4px 8px;border-radius:4px}
.sv-modal-close:hover{background:#f1f5f9}
.sv-modal-body{padding:16px 20px;overflow-y:auto;flex:1}
.sv-modal-foot{padding:12px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0}

.sv-info-row{display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.sv-info-item{font-size:12.5px;color:#64748b}
.sv-info-item b{color:#374151}
.sv-desc{font-size:13px;color:#475569;line-height:1.7;margin-bottom:16px;background:#f8fafc;padding:12px;border-radius:8px}
.sv-source{margin-bottom:14px}
.sv-source-title{font-weight:600;color:#1e293b;font-size:13.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.sv-source-title .dot{width:8px;height:8px;border-radius:50%;background:#4f7cff;flex-shrink:0}
.sv-eps{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:6px}
.sv-ep{display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;transition:all .15s}
.sv-ep:hover{background:#eef2ff;border-color:#4f7cff}
.sv-ep .ep-name{color:#374151}
.sv-ep .ep-copy{background:none;border:none;cursor:pointer;color:#4f7cff;font-size:11px;padding:2px 4px;border-radius:3px}
.sv-ep .ep-copy:hover{background:#e0e7ff}

.sv-loading{text-align:center;padding:40px;color:#94a3b8;font-size:13px}

/* 手机适配 */
@media (max-width: 768px){
    .sv-toolbar{flex-direction:column;align-items:stretch}
    .sv-select{min-width:auto}
    .sv-table{font-size:12px}
    .sv-table th,.sv-table td{padding:8px 6px}
    .sv-table .sv-type{display:none}
    .sv-modal{max-width:100%;max-height:100%;border-radius:0}
    .sv-eps{grid-template-columns:repeat(auto-fill,minmax(90px,1fr))}
}
</style>

<div class="panel" style="padding:16px">
    <h2 style="margin:0 0 4px;font-size:16px;color:#1e293b">🔍 资源站查看</h2>
    <p style="margin:0 0 14px;font-size:12.5px;color:#94a3b8">选一个资源站 → 搜索剧名 → 点详情看所有播放源和集数链接</p>

    <!-- 工具栏 -->
    <div class="sv-toolbar">
        <select class="sv-select" id="sv-site" onchange="svSearch()">
            <option value="-1">-- 选择资源站 --</option>
            <?php foreach ($siteOptions as $i => $name): ?>
                <option value="<?= $i ?>" <?= empty($sites[$i]['enabled']) ? 'disabled' : '' ?>>
                    <?= htmlspecialchars($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input class="sv-input" id="sv-wd" type="text" placeholder="输入剧名关键词，如：斗罗大陆" onkeydown="if(event.key==='Enter')svSearch()">
        <button class="sv-btn" onclick="svSearch()">🔍 搜索</button>
        <button class="sv-btn sv-btn-sec" onclick="svReset()">重置</button>
    </div>

    <!-- 搜索结果 -->
    <div id="sv-result">
        <div class="sv-empty">
            <div class="sv-emoji">🎬</div>
            <div class="sv-text">选择一个资源站，输入剧名开始搜索吧</div>
        </div>
    </div>
</div>

<!-- 详情弹窗 -->
<div class="sv-modal-mask" id="sv-modal" onclick="if(event.target===this)svCloseModal()">
    <div class="sv-modal">
        <div class="sv-modal-head">
            <h3 id="sv-modal-title">视频详情</h3>
            <button class="sv-modal-close" onclick="svCloseModal()">✕</button>
        </div>
        <div class="sv-modal-body" id="sv-modal-body">
            <div class="sv-loading">加载中...</div>
        </div>
        <div class="sv-modal-foot">
            <button class="sv-btn sv-btn-sec" onclick="svCloseModal()">关闭</button>
        </div>
    </div>
</div>

<script>
let svCurrentSite = -1;
let svCurrentPg = 1;
let svCurrentWd = '';
let svTotal = 0;

// 全局 toast 函数（可被 svCopy / svCopyFallback 等复用）
function toast(msg, type){
    const t = document.getElementById('toast');
    if(!t){ alert(msg); return; }
    type = type || 'ok';
    t.textContent = msg;
    t.style.background = type === 'warn' ? '#f59e0b' : (type === 'err' ? '#ef4444' : '#10b981');
    t.style.display = 'block';
    clearTimeout(window._svToastT);
    window._svToastT = setTimeout(function(){ t.style.display='none'; }, 1800);
}

function svSearch(pg){
    const idx = parseInt(document.getElementById('sv-site').value);
    const wd  = document.getElementById('sv-wd').value.trim();
    if(idx < 0){ toast('请先选择一个资源站','warn'); return; }
    // wd 允许为空 — 空时返回该资源站最新资源

    svCurrentSite = idx;
    svCurrentWd = wd;
    svCurrentPg = pg || 1;

    const box = document.getElementById('sv-result');
    box.innerHTML = '<div class="sv-loading">⏳ 正在请求资源站，请稍候...</div>';

    const fd = new FormData();
    fd.append('action','search_site_api');
    fd.append('idx', idx);
    fd.append('wd', wd);
    fd.append('pg', svCurrentPg);
    fd.append('limit', 20);

    fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        svRenderResult(d);
    }).catch(function(e){
        box.innerHTML = '<div class="sv-empty"><div class="sv-emoji">😵</div><div class="sv-text">请求失败：'+e+'</div></div>';
    });
}

function svRenderResult(d){
    const box = document.getElementById('sv-result');
    if(!d.ok){
        box.innerHTML = '<div class="sv-empty"><div class="sv-emoji">😶</div><div class="sv-text">'+(d.msg||'搜索失败')+'</div>'
            + (d.search_url ? '<div style="margin-top:10px;font-size:11px;color:#cbd5e1;word-break:break-all">URL: '+d.search_url+'</div>' : '')
            + '</div>';
        return;
    }
    const items = d.items || [];
    svTotal = items.length;

    if(items.length === 0){
        box.innerHTML = '<div class="sv-empty"><div class="sv-emoji">🤷</div><div class="sv-text">没有找到"'+svCurrentWd+'"相关的视频</div></div>';
        return;
    }

    let html = '<div style="margin-bottom:10px;font-size:12.5px;color:#64748b">🔎 '+d.site_name+' 共找到 <b style="color:#1e293b">'+items.length+'</b> 条结果'
        + '<div style="float:right">';
    if(svCurrentPg > 1) html += '<button class="sv-btn sv-btn-sec" style="padding:4px 10px;font-size:12px" onclick="svSearch('+(svCurrentPg-1)+')">← 上一页</button> ';
    if(items.length === 20) html += '<button class="sv-btn" style="padding:4px 10px;font-size:12px" onclick="svSearch('+(svCurrentPg+1)+')">下一页 →</button>';
    html += '</div></div>';

    html += '<div class="sv-table-wrap"><table class="sv-table"><thead><tr>'
        + '<th>片名</th><th class="sv-type">类别</th><th>地区</th><th>年份</th><th>更新时间</th><th>详情</th>'
        + '</tr></thead><tbody>';

    items.forEach(function(it, i){
        const remarks = it.remarks ? '<span class="sv-badge">'+it.remarks+'</span>' : '';
        html += '<tr>'
            + '<td><span class="sv-name" onclick="svOpenDetail('+i+')">'+svEscape(it.name)+'</span> '
            + remarks + (it.sub ? '<span style="color:#f59e0b;font-size:11px"> '+svEscape(it.sub)+'</span>' : '') + '</td>'
            + '<td class="sv-type">'+svEscape(it.type)+'</td>'
            + '<td>'+svEscape(it.area)+'</td>'
            + '<td>'+svEscape(it.year)+'</td>'
            + '<td class="sv-time">'+svEscape(it.time)+'</td>'
            + '<td><button class="sv-detail-btn" onclick="svOpenDetail('+i+')">查看 →</button></td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';

    // 缓存 items 给详情弹窗用
    window._svItems = items;
    box.innerHTML = html;
}

function svReset(){
    document.getElementById('sv-site').value = -1;
    document.getElementById('sv-wd').value = '';
    document.getElementById('sv-result').innerHTML = '<div class="sv-empty"><div class="sv-emoji">🎬</div><div class="sv-text">选择一个资源站，输入剧名开始搜索吧</div></div>';
}

function svOpenDetail(i){
    const it = window._svItems[i];
    if(!it) return;

    document.getElementById('sv-modal-title').textContent = it.name + (it.sub ? ' '+it.sub : '');
    let body = '';

    // 基本信息
    body += '<div class="sv-info-row">'
        + '<div class="sv-info-item"><b>类别：</b>'+svEscape(it.type)+'</div>'
        + '<div class="sv-info-item"><b>地区：</b>'+svEscape(it.area)+'</div>'
        + '<div class="sv-info-item"><b>年份：</b>'+svEscape(it.year)+'</div>'
        + '<div class="sv-info-item"><b>更新：</b>'+svEscape(it.time)+'</div>'
        + '</div>';
    if(it.actor)    body += '<div class="sv-info-item" style="margin-bottom:6px"><b>主演：</b>'+svEscape(it.actor)+'</div>';
    if(it.director) body += '<div class="sv-info-item" style="margin-bottom:6px"><b>导演：</b>'+svEscape(it.director)+'</div>';
    if(it.content)  body += '<div class="sv-desc">'+svEscape(it.content)+'</div>';

    // 播放源列表
    const sources = it.sources || [];
    if(sources.length === 0){
        body += '<div style="color:#f59e0b;padding:12px;background:#fffbeb;border-radius:6px">⚠️ 这个资源站没有返回可播放的源链接（可能需要先保存到苹果CMS 再调用）</div>';
    } else {
        body += '<div style="font-size:12px;color:#94a3b8;margin-bottom:10px">📺 共 '+sources.length+' 个播放源，点击集数旁 📋 复制播放链接</div>';
        sources.forEach(function(src, si){
            body += '<div class="sv-source">'
                + '<div class="sv-source-title"><span class="dot"></span>'+svEscape(src.name)+' <span style="color:#94a3b8;font-weight:normal;font-size:11px">('+(src.episodes.length)+' 集)</span></div>'
                + '<div class="sv-eps">';
            src.episodes.forEach(function(ep){
                body += '<div class="sv-ep"><span class="ep-name">'+svEscape(ep.name)+'</span>'
                    + '<button class="ep-copy" onclick="svCopy(this, '+JSON.stringify(ep.url)+')" title="复制链接">📋</button></div>';
            });
            body += '</div></div>';
        });
    }

    document.getElementById('sv-modal-body').innerHTML = body;
    document.getElementById('sv-modal').classList.add('show');
}

function svCloseModal(){
    document.getElementById('sv-modal').classList.remove('show');
}

function svCopy(btn, url){
    btn.textContent = '⏳';
    // 优先用现代 API（HTTPS / 新浏览器）
    if(navigator.clipboard && window.isSecureContext){
        navigator.clipboard.writeText(url).then(function(){
            btn.textContent = '✅';
            btn.style.color = '#10b981';
            toast('链接已复制到剪贴板');
            setTimeout(function(){ btn.textContent='📋'; btn.style.color='#4f7cff'; }, 1000);
        }).catch(function(){ svCopyFallback(btn,url); });
    }else{
        svCopyFallback(btn, url);
    }
}
function svCopyFallback(btn, url){
    // execCommand('copy') fallback — 兼容 HTTP + 旧手机浏览器
    try{
        const ta = document.createElement('textarea');
        ta.value = url;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, url.length);
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if(ok){
            btn.textContent = '✅';
            btn.style.color = '#10b981';
            toast('链接已复制到剪贴板');
        }else{
            btn.textContent = '❌';
            btn.style.color = '#ef4444';
            toast('复制失败，请手动长按复制', 'warn');
        }
    }catch(e){
        btn.textContent = '❌';
        btn.style.color = '#ef4444';
        toast('复制失败：'+e.message, 'warn');
    }
    setTimeout(function(){ btn.textContent='📋'; btn.style.color='#4f7cff'; }, 1500);
}

function svEscape(s){
    if(!s) return '';
    return String(s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

// 点击遮罩关闭 + ESC 关闭
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') svCloseModal();
});
</script>

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
        <div class="note" style="margin:0 0 16px;padding:8px 12px;font-size:12px;color:#7fc1ff">
            💡 修改任意字段后，<b>点击页面空白处自动保存</b>；也可点击下方 <b>「💾 立即保存」</b> 按钮手动触发。保存时自动清理缓存、站点健康与日志。
        </div>
        <form method="post" class="auto-save" id="form-mapping">
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

            <button type="button" class="add-row-btn" onclick="addEpisodeRow()">＋ 添加集数映射</button>

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

            <button type="button" class="add-row-btn" onclick="addTitleRow()">＋ 添加剧名映射</button>

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

            <button type="button" class="add-row-btn" onclick="addCidRow()">＋ 添加 cid 映射</button>
    <script>
    function addEpisodeRow(){
        var tbl=document.querySelectorAll('#form-mapping table')[0];
        var tr=document.createElement('tr');
        tr.innerHTML='<td><input type="text" name="map_id[]" placeholder="vid:xxx"></td>'+
            '<td><input type="text" name="map_ename[]" placeholder="剧名"></td>'+
            '<td><input type="number" name="map_ep[]" value="1" min="1"></td>'+
            '<td class="center"><input type="checkbox" checked disabled></td>'+
            '<td><button type="button" class="btn btn-danger" onclick="this.closest(\'tr\').remove()">删除</button></td>';
        tbl.appendChild(tr);
    }
    function addTitleRow(){
        var tbl=document.querySelectorAll('#form-mapping table')[1];
        var tr=document.createElement('tr');
        tr.innerHTML='<td><input type="text" name="map_target[]" placeholder="解析出的剧名"></td>'+
            '<td><input type="text" name="map_title[]" placeholder="资源站剧名"></td>'+
            '<td class="center"><input type="checkbox" checked disabled></td>'+
            '<td><button type="button" class="btn btn-danger" onclick="this.closest(\'tr\').remove()">删除</button></td>';
        tbl.appendChild(tr);
    }
    function addCidRow(){
        var tbl=document.querySelectorAll('#form-mapping table')[2];
        var tr=document.createElement('tr');
        tr.innerHTML='<td><input type="text" name="map_cid[]" placeholder="腾讯cid"></td>'+
            '<td><input type="text" name="map_cid_target[]" placeholder="剧名"></td>'+
            '<td class="center"><input type="checkbox" checked disabled></td>'+
            '<td><button type="button" class="btn btn-danger" onclick="this.closest(\'tr\').remove()">删除</button></td>';
        tbl.appendChild(tr);
    }
    </script>
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
    $siteHealth = SiteHealth::healthTable();
    $hbResult = $_SESSION['heartbeat_result'] ?? null;
    unset($_SESSION['heartbeat_result']);
    ?>
    <div class="panel">
        <h2>系统设置</h2>
        <div class="note" style="margin:0 0 16px;padding:8px 12px;font-size:12px;color:#7fc1ff">
            已取消「保存」按钮 —— 每次修改后<b>点击页面空白处即自动保存</b>，保存时会自动清理缓存、站点健康与日志。
        </div>
        <form method="post" class="form-grid auto-save" id="form-settings">
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
