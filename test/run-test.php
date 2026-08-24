<?php
/**
 * 端到端测试（CLI）：
 *   1. 启动本地 Mock 资源站
 *   2. 启动官替系统（PHP 内置服务器）
 *   3. 官替接口 /api/vod 断言 code=200 且匹配第2集 m3u8
 *   4. 健康检查 / 资源站测试 / 后台页面 / webhook 更新接口
 * 用法：php test/run-test.php
 */

declare(strict_types=1);

const MOCK_PORT = 26531;
const MAIN_PORT = 3001;
const BASE = 'http://127.0.0.1:' . MAIN_PORT;
const EXAMPLE_URL = 'https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce';

function httpGet(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['body' => is_string($body) ? $body : '', 'http' => (int)($info['http_code'] ?? 0), 'err' => $err];
}

function httpPost(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['body' => is_string($body) ? $body : '', 'http' => (int)($info['http_code'] ?? 0), 'err' => $err];
}

$failures = [];
function check(string $label, bool $cond): void
{
    global $failures;
    echo '    ' . ($cond ? 'PASS' : 'FAIL') . "  $label\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

echo "==> 启动本地 Mock 资源站 (port $MOCK_PORT) ...\n";
$mock = proc_open('php -S 127.0.0.1:' . MOCK_PORT . ' test/mock-router.php', [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipesMock, dirname(__DIR__));
if (is_resource($mock)) {
    fclose($pipesMock[0]);
}
// 等待端口就绪
for ($i = 0; $i < 30; $i++) {
    usleep(200000);
    $r = @fsockopen('127.0.0.1', MOCK_PORT);
    if ($r) { fclose($r); break; }
}

echo "==> 启动官替系统 (port $MAIN_PORT) ...\n";
$main = proc_open('php -S 127.0.0.1:' . MAIN_PORT . ' router.php', [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipesMain, dirname(__DIR__));
if (is_resource($main)) {
    fclose($pipesMain[0]);
}
for ($i = 0; $i < 30; $i++) {
    usleep(200000);
    $r = @fsockopen('127.0.0.1', MAIN_PORT);
    if ($r) { fclose($r); break; }
}

try {
    echo "==> 1. 健康检查 /api/health\n";
    $r = httpGet(BASE . '/api/health');
    $health = json_decode($r['body'], true);
    check('health 返回 200', $r['http'] === 200 && ($health['code'] ?? 0) === 200);
    echo '    版本: ' . ($health['data']['version'] ?? '-') . ' / 数据库: ' . ($health['data']['db'] ?? '-') . " / PHP: " . ($health['data']['php'] ?? '-') . "\n";

    echo "==> 2. 官替接口 /api/vod（庆余年 第2集）\n";
    $r = httpGet(BASE . '/api/vod?url=' . urlencode(EXAMPLE_URL));
    $res = json_decode($r['body'], true);
    if ($r['http'] !== 200 || !is_array($res)) {
        echo "    响应异常: HTTP " . $r['http'] . " " . mb_substr($r['body'], 0, 300) . "\n";
    } else {
        echo '    返回: ' . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    check('code === 200', ($res['code'] ?? null) === 200);
    check('返回 m3u8 直链', is_string($res['url'] ?? null) && str_contains($res['url'], '.m3u8'));
    check('匹配到第2集', ($res['data']['episode'] ?? null) === 2);
    check('匹配站点为 Mock', ($res['data']['matchedSite'] ?? '') === '本地Mock资源站(测试用)');

    echo "==> 3. 参数覆盖（name/ep）\n";
    $r = httpGet(BASE . '/api/vod?url=' . urlencode('https://v.qq.com/x/cover/unknowndemo.html') . '&name=' . urlencode('庆余年') . '&ep=4');
    $res2 = json_decode($r['body'], true);
    check('覆盖 ep=4 命中第4集', ($res2['data']['episode'] ?? null) === 4 && str_contains($res2['url'] ?? '', '04.m3u8'));

    echo "==> 4. 无匹配资源返回 404\n";
    $r = httpGet(BASE . '/api/vod?url=' . urlencode('https://v.qq.com/x/cover/x.html') . '&name=' . urlencode('不存在的剧'));
    $res3 = json_decode($r['body'], true);
    check('code === 404', ($res3['code'] ?? null) === 404);

    echo "==> 5. 资源站连通性 /api/sites/test\n";
    $r = httpGet(BASE . '/api/sites/test');
    $sites = json_decode($r['body'], true);
    check('sites/test 正常', is_array($sites) && ($sites['code'] ?? 0) === 200);

    echo "==> 6. 后台登录页\n";
    $r = httpGet(BASE . '/admin/');
    check('后台页面可访问(200)', $r['http'] === 200);
    check('包含登录表单', str_contains($r['body'], '沫兮官替系统') && str_contains($r['body'], 'action') && str_contains($r['body'], 'login'));

    echo "==> 7. webhook 更新接口鉴权\n";
    $r1 = httpPost(BASE . '/api/webhook/update?token=wrong');
    $j1 = json_decode($r1['body'], true);
    check('错误令牌被拒绝(403)', $r1['http'] === 403 || ($j1['code'] ?? 0) === 403);

    // 从数据库中读取当前更新令牌，验证正确令牌可触发更新
    $token = null;
    $cfg = require dirname(__DIR__) . '/config/config.php';
    if (($cfg['db']['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $cfg['db']['sqlite']);
        $token = $pdo->query('SELECT v FROM mxgj_settings WHERE k="update_token"')->fetchColumn();
    } else {
        $pdo = new PDO('mysql:host=' . $cfg['db']['host'] . ';port=' . $cfg['db']['port'] . ';dbname=' . $cfg['db']['name'] . ';charset=utf8mb4', $cfg['db']['user'], $cfg['db']['pass']);
        $token = $pdo->query('SELECT v FROM mxgj_settings WHERE k="update_token"')->fetchColumn();
    }
    $r2 = httpPost(BASE . '/api/webhook/update?token=' . urlencode((string)$token));
    $j2 = json_decode($r2['body'], true);
    check('正确令牌触发更新并返回结果', is_array($j2) && isset($j2['status']) && in_array($j2['status'], ['success', 'failed', 'up-to-date'], true));
    echo '    更新结果: ' . json_encode($j2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    echo "\n" . ($failures === [] ? '[PASS] 全部通过，代码可运行、接口正常' : '[FAIL] 存在 ' . count($failures) . ' 个未通过断言');
    echo "\n";
} finally {
    if (is_resource($main)) {
        @fclose($pipesMain[1]);
        @fclose($pipesMain[2]);
        proc_terminate($main, 9);
        proc_close($main);
    }
    if (is_resource($mock)) {
        @fclose($pipesMock[1]);
        @fclose($pipesMock[2]);
        proc_terminate($mock, 9);
        proc_close($mock);
    }
}

exit($failures === [] ? 0 : 1);
