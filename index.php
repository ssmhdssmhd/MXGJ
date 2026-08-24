<?php
/**
 * 沫兮官替系统 - 前台入口
 * 路由：
 *   GET/POST /api/vod           官替核心接口
 *   GET       /api/health       健康检查
 *   POST      /api/webhook/update  Git 自动更新（webhook 触发）
 *   GET       /api/sites/test   资源站连通性测试
 *   GET       /                 前端页面
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Updater;
use App\Core\VodService;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Update-Token');
    exit;
}

// 健康检查
if ($path === '/api/health') {
    json_out([
        'code' => 200,
        'msg' => 'ok',
        'data' => [
            'name' => config('app.name'),
            'version' => config('app.version'),
            'time' => now_sql(),
            'db' => db()->driver(),
            'php' => PHP_VERSION,
        ],
    ]);
}

// 官替核心接口
if ($path === '/api/vod') {
    if ($method === 'POST') {
        $params = array_merge(request_json(), $_GET);
    } else {
        $params = $_GET;
    }
    $service = new VodService(db());
    json_out($service->handle($params));
}

// 资源站连通性测试
if ($path === '/api/sites/test') {
    $sites = db()->select('SELECT * FROM mxgj_sites WHERE enabled = 1 ORDER BY id ASC');
    $results = [];
    foreach ($sites as $site) {
        $url = rtrim((string)$site['api'], '/') . '?ac=detail&wd=' . urlencode('测试');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int)$site['timeout'] * 1000,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (MXGJ)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $start = microtime_ms();
        $body = curl_exec($ch);
        $cost = microtime_ms() - $start;
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $apiCode = null;
        if (is_string($body) && $body !== '') {
            $j = json_decode($body, true);
            $apiCode = is_array($j) ? ($j['code'] ?? null) : null;
        }
        $results[] = [
            'id' => $site['site_id'],
            'name' => $site['name'],
            'api' => $site['api'],
            'ok' => $err === '' && $httpCode >= 200 && $httpCode < 300,
            'http' => $httpCode,
            'apiCode' => $apiCode,
            'costMs' => $cost,
            'reason' => $err,
        ];
    }
    json_out(['code' => 200, 'msg' => 'ok', 'data' => ['total' => count($results), 'results' => $results]]);
}

// Git 自动更新 webhook
if ($path === '/api/webhook/update' && $method === 'POST') {
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_UPDATE_TOKEN'] ?? '');
    $expected = (string)db()->setting('update_token', '');
    if ($token === '' || !hash_equals($expected, (string)$token)) {
        json_out(['code' => 403, 'msg' => '无效的更新令牌'], 403);
    }
    if ((int)db()->setting('update_enabled', 1) !== 1) {
        json_out(['code' => 400, 'msg' => '自动更新已停用']);
    }
    $updater = new Updater(db());
    json_out($updater->update('webhook'));
}

// 前端页面
if ($path === '/' || $path === '/index.php') {
    render_front_page();
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['code' => 404, 'msg' => '接口不存在: ' . $path], JSON_UNESCAPED_UNICODE);
exit;

/**
 * 前端页面
 */
function render_front_page(): never
{
    header('Content-Type: text/html; charset=utf-8');
    $version = config('app.version');
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>沫兮官替系统</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 50%,#4c1d95 100%);min-height:100vh;color:#e2e8f0;padding:32px 16px}
.wrap{max-width:860px;margin:0 auto}
.header{text-align:center;margin-bottom:28px}
.header h1{font-size:30px;letter-spacing:2px;background:linear-gradient(90deg,#a5b4fc,#f0abfc);-webkit-background-clip:text;background-clip:text;color:transparent}
.header p{margin-top:8px;font-size:14px;color:#94a3b8}
.card{background:rgba(15,23,42,.7);border:1px solid rgba(148,163,184,.15);border-radius:16px;padding:24px;backdrop-filter:blur(6px);box-shadow:0 10px 30px rgba(0,0,0,.35)}
label{display:block;font-size:13px;color:#a5b4fc;margin-bottom:6px;font-weight:600}
.row{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}
input[type=text]{flex:1;min-width:240px;padding:12px 14px;border-radius:10px;border:1px solid rgba(148,163,184,.3);background:rgba(30,41,59,.9);color:#f1f5f9;font-size:14px;outline:none}
input[type=text]:focus{border-color:#818cf8}
.btn{padding:12px 26px;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;background:linear-gradient(90deg,#6366f1,#8b5cf6);color:#fff}
.btn:hover{transform:translateY(-1px)}
.btn:disabled{opacity:.55;cursor:not-allowed}
.hint{font-size:12px;color:#64748b;margin-top:4px;line-height:1.7}
.result{margin-top:20px}
.result .title{font-size:13px;color:#a5b4fc;margin-bottom:8px;font-weight:600}
pre{background:rgba(2,6,23,.85);border:1px solid rgba(148,163,184,.15);border-radius:10px;padding:14px;font-size:13px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;line-height:1.6;color:#7dd3fc}
.badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;margin-left:6px}
.badge-ok{background:rgba(74,222,128,.15);color:#4ade80}
.badge-err{background:rgba(248,113,113,.15);color:#f87171}
.copy{margin-top:10px;font-size:13px;color:#93c5fd;cursor:pointer;display:inline-block}
.footer{text-align:center;margin-top:26px;font-size:12px;color:#64748b}
.footer a{color:#93c5fd;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>沫兮官替系统</h1>
    <p>输入官方视频链接，自动多线程检索后台资源站，返回可替换的直链(m3u8)</p>
  </div>
  <div class="card">
    <form id="form">
      <label for="url">官方视频链接 *</label>
      <div class="row"><input type="text" id="url" placeholder="https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce" value="https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce"/></div>
      <div class="row">
        <div style="flex:1;min-width:200px"><label for="name">剧名（可选）</label><input type="text" id="name" style="width:100%" placeholder="如: 庆余年"/></div>
        <div style="flex:0 0 140px"><label for="ep">集数（可选）</label><input type="text" id="ep" placeholder="如: 2"/></div>
        <button type="submit" class="btn" id="submitBtn">开始官替</button>
      </div>
      <div class="hint">自动识别平台并抽取 cid/vid 反查剧名与集数；识别不到时可手动填写覆盖。资源站与剧名映射在后台管理中配置。</div>
    </form>
    <div class="result" id="result" style="display:none">
      <div class="title">官替结果 <span class="badge" id="badge"></span></div>
      <pre id="output"></pre>
      <span class="copy" id="copyBtn">复制 JSON</span>
    </div>
  </div>
  <div class="footer">沫兮官替系统 v{$version} · <a href="/admin/">后台管理</a></div>
</div>
<script>
const form=document.getElementById('form'),resultBox=document.getElementById('result'),output=document.getElementById('output'),badge=document.getElementById('badge'),submitBtn=document.getElementById('submitBtn'),copyBtn=document.getElementById('copyBtn');
form.addEventListener('submit',async e=>{e.preventDefault();
const url=document.getElementById('url').value.trim(),name=document.getElementById('name').value.trim(),ep=document.getElementById('ep').value.trim();
if(!url){alert('请输入官方视频链接');return}
submitBtn.disabled=true;submitBtn.textContent='检索中...';resultBox.style.display='none';
const qs=new URLSearchParams({url});if(name)qs.set('name',name);if(ep)qs.set('ep',ep);
try{const r=await fetch('/api/vod?'+qs.toString());render(await r.json());}catch(err){render({code:500,msg:'请求失败: '+err.message});}
submitBtn.disabled=false;submitBtn.textContent='开始官替';});
function render(d){resultBox.style.display='block';output.textContent=JSON.stringify(d,null,2);
if(d.code===200){badge.className='badge badge-ok';badge.textContent='匹配成功';}else{badge.className='badge badge-err';badge.textContent='code '+d.code;}}
copyBtn.addEventListener('click',()=>{navigator.clipboard.writeText(output.textContent).then(()=>{copyBtn.textContent='已复制 ✓';setTimeout(()=>copyBtn.textContent='复制 JSON',1500);});});
</script>
</body>
</html>
HTML;
    exit;
}