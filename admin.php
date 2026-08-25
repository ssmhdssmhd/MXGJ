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
        header('Location: admin.php');
        exit;
    }
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
            ];
        }
        mxgj_write_json($sitesFile, ['sites' => $clean]);
        header('Location: admin.php?tab=sites&saved=1');
        exit;

    case 'save_mapping':
        $mapping = [
            'title' => [],
            'cid'   => [],
            'episode' => [],
        ];
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
        mxgj_write_json($settingsFile, $st);
        // 更换密码后重登
        header('Location: admin.php?tab=settings&saved=1');
        exit;

    case 'logout':
        session_destroy();
        header('Location: admin.php');
        exit;

    case 'clear_cache':
        AdminHelper::clearCache();
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
        mxgj_json_out($res);

    case 'do_update':
        // 后台触发的在线更新（dry=1 时仅测速）
        $st  = mxgj_settings();
        $res = Updater::run($st['admin_password'], !empty($_POST['dry']));
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
            示例二（列表页）：<code>http://your.site/search?wd=%s&page=%p</code>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="save_sites">
            <table id="site-tbl">
                <tr><th style="width:200px">站点名称</th><th>搜索地址模板（含 %s / %u / %p）</th><th style="width:60px"></th></tr>
                <?php if ($sites): foreach ($sites as $i => $s): ?>
                    <tr>
                        <td><input type="text" name="sites[<?= $i ?>][name]" value="<?= htmlspecialchars($s['name']) ?>"></td>
                        <td><input type="text" name="sites[<?= $i ?>][template]" value="<?= htmlspecialchars($s['template']) ?>" onclick="this.select()"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td><input type="text" name="sites[0][name]" placeholder="站点A"></td>
                        <td><input type="text" name="sites[0][template]" placeholder="http://api.example.com/vod?wd=%u&ep=%p"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endif; ?>
            </table>
            <div style="margin:16px 0">
                <button type="button" class="btn" onclick="addRow()">+ 添加资源站</button>
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
            '<td><button type="button" class="btn btn-danger" onclick="this.closest(\'tr\').remove()">删除</button></td>';
        tbl.appendChild(tr);rowIndex++;
    }
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
        <form method="post">
            <input type="hidden" name="action" value="save_mapping">
            <table>
                <tr><th style="width:240px">ID（vid:xxx 或 cid:xxx）</th><th>剧名</th><th style="width:90px">集数</th><th style="width:60px"></th></tr>
                <?php if ($episode): $i=0; foreach ($episode as $k => $v): ?>
                    <tr>
                        <td><input type="text" name="map_id[]" value="<?= htmlspecialchars($k) ?>"></td>
                        <td><input type="text" name="map_ename[]" value="<?= htmlspecialchars($v['name'] ?? '') ?>"></td>
                        <td><input type="number" name="map_ep[]" value="<?= (int)($v['episode'] ?? 0) ?>" min="1"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php $i++; endforeach; else: ?>
                    <tr>
                        <td><input type="text" name="map_id[]" placeholder="vid:k4102szvyce"></td>
                        <td><input type="text" name="map_ename[]" placeholder="庆余年"></td>
                        <td><input type="number" name="map_ep[]" value="2" min="1"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endif; ?>
            </table>

            <h2 style="margin-top:24px">剧名映射</h2>
            <div class="note" style="margin-bottom:16px">
                当官方链接解析出的剧名与资源站使用的剧名不一致时使用。
                格式：<b>解析出的剧名</b> → <b>资源站剧名</b>。
            </div>
            <table>
                <tr><th>解析出的剧名</th><th>资源站剧名</th><th style="width:60px"></th></tr>
                <?php if ($titles): ?>
                    <?php $i=0; foreach ($titles as $k => $v): ?>
                        <tr>
                            <td><input type="text" name="map_target[]" value="<?= htmlspecialchars($k) ?>"></td>
                            <td><input type="text" name="map_title[]" value="<?= htmlspecialchars($v) ?>"></td>
                            <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                    <?php $i++; endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td><input type="text" name="map_target[]" placeholder="解析出的剧名"></td>
                        <td><input type="text" name="map_title[]" placeholder="资源站剧名"></td>
                        <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                <?php endif; ?>
            </table>

            <h2 style="margin-top:24px">腾讯 cid 映射（仅剧名）</h2>
            <table>
                <tr><th>腾讯 cid</th><th>剧名（资源站使用）</th><th style="width:60px"></th></tr>
                <?php if ($cids): ?>
                    <?php $i=0; foreach ($cids as $k => $v): ?>
                        <tr>
                            <td><input type="text" name="map_cid[]" value="<?= htmlspecialchars($k) ?>"></td>
                            <td><input type="text" name="map_cid_target[]" value="<?= htmlspecialchars($v) ?>"></td>
                            <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                    <?php $i++; endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td><input type="text" name="map_cid[]" placeholder="mzc00200zx8psx0"></td>
                        <td><input type="text" name="map_cid_target[]" placeholder="庆余年"></td>
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

function renderSettingsForm($settings)
{
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
            <div class="full"><button type="submit" class="btn btn-green">保存设置</button></div>
        </form>
        <div class="note" style="margin-top:20px">
            默认密码：<code>moxi123</code>。请立即修改！<br>
            数据文件位于 <code>config/settings.json</code>、<code>config/sites.json</code>、<code>config/mapping.json</code>，可手动编辑。
        </div>
    </div>
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