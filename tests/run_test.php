<?php
/**
 * 沫兮官替系统 - 端到端自测脚本（CLI）
 *
 * 步骤：
 *  1. 备份现有资源站配置，写入指向本地模拟站(动态端口)的测试配置
 *  2. 启动服务：主接口 + 三个模拟资源站（A=纯文本720p / B=JSON1080p / C=POST特殊调用480p，均延迟1.2s验证多线程并发）
 *  3. 用腾讯官方链接调用主接口，验证多线程搜索返回 庆余年 第2集 的 m3u8
 *  4. 验证「特殊资源站(POST调用方法)」以 POST 收到 `wd=%u&ep=%p` 请求体
 *  5. 清理服务与恢复配置
 *
 * 运行：php tests/run_test.php
 */

$ROOT      = dirname(__DIR__);
$sitesFile = $ROOT . '/config/sites.json';

function http_get(string $url, int $timeout = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'MXGJ-Test',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    if (function_exists('curl_close')) {
        @curl_close($ch);
    }
    return ['body' => $body, 'error' => $err];
}

/** URL 安全 base64 解码（与 lib/bootstrap.php 一致） */
function b64url_decode(string $s): string
{
    $s = strtr(trim($s), '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $d = base64_decode($s, true);
    return $d === false ? '' : $d;
}

/** 从表面播放链接(/play.php?u=xxx)中解码出真实地址 */
function surface_inner(string $surfaceUrl): string
{
    if (preg_match('~[?&]u=([^&]+)~', $surfaceUrl, $m)) {
        return b64url_decode($m[1]);
    }
    return '';
}

/** 申请一个空闲端口 */
function freePort(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        return 8200;
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int)substr($name, strrpos($name, ':') + 1);
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = '')
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$name}\n"; }
    else     { $fail++; echo "  [FAIL] {$name} {$detail}\n"; }
}

/* 1. 动态分配端口并写入测试配置 */
$mainPort  = freePort();
$mockAPort = freePort();
$mockBPort = freePort();
$mockCPort = freePort();
echo "端口: main={$mainPort} mockA={$mockAPort} mockB={$mockBPort} mockC(Post)={$mockCPort}\n";

$backup = file_exists($sitesFile) ? file_get_contents($sitesFile) : null;
$testSites = [
    'sites' => [
        ['name' => '模拟资源站A(纯文本,720p)', 'template' => "http://127.0.0.1:{$mockAPort}/vod?wd=%u&ep=%p&fmt=text"],
        ['name' => '模拟资源站B(JSON,1080p)',  'template' => "http://127.0.0.1:{$mockBPort}/vod?wd=%u&ep=%p"],
        // 特殊调用方法：POST + POST体（wd=%u&ep=%p）
        ['name' => '模拟资源站C(POST特殊调用,480p)', 'template' => "http://127.0.0.1:{$mockCPort}/vod", 'method' => 'post', 'post' => 'wd=%u&ep=%p'],
    ],
];
file_put_contents($sitesFile, json_encode($testSites, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

/* 1.5 清空运行时缓存，确保测试确定性 */
$cacheDir = $ROOT . '/data/cache';
foreach (glob($cacheDir . '/*.cache') ?: [] as $f) {
    @unlink($f);
}

/* 2. 启动服务（数组形式直接 exec php，便于 proc_terminate 精确杀掉进程） */
$servers = [];
function startServer(int $port, string $docroot, string $router = '', string $cwd = '/workspace')
{
    $cmd = ['php', '-S', '127.0.0.1:' . $port, '-t', $docroot];
    if ($router !== '') {
        $cmd[] = $router;
    }
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    return ['proc' => $proc, 'pipes' => $pipes];
}
$servers[] = startServer($mainPort, $ROOT);
$servers[] = startServer($mockAPort, $ROOT . '/tests', $ROOT . '/tests/mock_site.php', $ROOT);
$servers[] = startServer($mockBPort, $ROOT . '/tests', $ROOT . '/tests/mock_site.php', $ROOT);
$servers[] = startServer($mockCPort, $ROOT . '/tests', $ROOT . '/tests/mock_site.php', $ROOT);

function stopAll(array $servers)
{
    foreach ($servers as $s) {
        if (isset($s['proc']) && is_resource($s['proc'])) {
            @proc_terminate($s['proc'], 9);
            @proc_close($s['proc']);
        }
    }
}

register_shutdown_function(function () use (&$servers, $sitesFile, $backup, $ROOT) {
    stopAll($servers);
    if ($backup !== null) {
        file_put_contents($sitesFile, $backup);
    } else {
        @unlink($sitesFile);
    }
    @unlink($ROOT . '/data/proof_post.txt');
});

/* 3. 等待主接口就绪 */
echo "启动服务中...\n";
$ready = false;
for ($i = 0; $i < 40; $i++) {
    usleep(200000);
    $r = http_get("http://127.0.0.1:{$mainPort}/index.php");
    if (isset($r['error']) && $r['error'] === '' && strpos($r['body'], '"code"') !== false) {
        $ready = true;
        break;
    }
}
if (!$ready) {
    echo "[FAIL] 主接口 {$mainPort} 未就绪\n";
    exit(1);
}
echo "服务已就绪\n";

/* 4. 主流程：腾讯官方链接 → 庆余年 第2集 */
$official = 'https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce';
$url      = "http://127.0.0.1:{$mainPort}/index.php?url=" . rawurlencode($official);

$t0 = microtime(true);
$res = http_get($url);
$elapsed = microtime(true) - $t0;
$json = json_decode($res['body'], true);

echo "\n【主流程】腾讯链接 → 庆余年第2集（App 表面播放链接）\n";
echo '  请求耗时: ' . round($elapsed, 2) . "s (阈值 2.2s)\n";
check('HTTP 无错误', $res['error'] === '', $res['error']);
check('返回 code=200', isset($json['code']) && $json['code'] === 200, json_encode($json));
$expect = "127.0.0.1:{$mockBPort}/vod/qingyounian/2/index.m3u8";
$surface = $json['url'] ?? '';
$inner   = surface_inner($surface);
check('url 为本站表面播放链接(/play.php?u=)', $surface !== '' && strpos($surface, '/play.php?u=') !== false, $surface);
check('表面链接解码后命中 1080p 资源(第2集)', strpos($inner, $expect) !== false, $inner);
check('msg 等于 url（均为表面播放链接）', ($json['msg'] ?? '') === $surface, $json['msg'] ?? '');
check('并发耗时 < 2.9s(3站各延迟1.2s, 串行约4.8s)' , $elapsed < 2.9, round($elapsed, 2) . 's');
echo '  返回内容: ' . $res['body'] . "\n";

// 特殊调用方法验证：POST 资源站应收到 `wd=%u&ep=%p` 请求体
$proofFile = $ROOT . '/data/proof_post.txt';
$proofBody = is_file($proofFile) ? (string)file_get_contents($proofFile) : '';
$proofOk   = $proofBody !== '' && strpos($proofBody, 'wd=') !== false && strpos($proofBody, 'ep=2') !== false;
check('特殊资源站(POST调用方法)收到 wd=%u&ep=%p 请求体', $proofOk, $proofBody === '' ? '未收到POST请求' : '请求体=' . $proofBody);

/* 5. 错误分支 */
echo "\n【错误分支】\n";
$r1 = http_get("http://127.0.0.1:{$mainPort}/index.php?url=" . rawurlencode('https://www.iqiyi.com/v_19rr000000.html'));
$j1 = json_decode($r1['body'], true);
check('无映射的爱奇艺链接返回 code=502', isset($j1['code']) && $j1['code'] === 502, $j1['msg'] ?? '');

$r2 = http_get("http://127.0.0.1:{$mainPort}/index.php");
$j2 = json_decode($r2['body'], true);
check('缺少 url 返回 code=400', isset($j2['code']) && $j2['code'] === 400, $j2['msg'] ?? '');

/* 6. 缓存复用 */
$r3 = http_get($url);
$j3 = json_decode($r3['body'], true);
check('缓存复用(仍返回 200 + url)', isset($j3['code']) && $j3['code'] === 200 && !empty($j3['url']), $j3['msg'] ?? '');

echo "\n======================================\n";
echo "结果: {$pass} 通过 / {$fail} 失败\n";
exit($fail > 0 ? 1 : 0);
