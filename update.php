<?php
/**
 * 沫兮官替系统 - 独立升级入口（手动触发自动更新）
 *
 * 用于后台自动更新异常时，只需访问：
 *   http://你的域名/update.php?key=升级密钥
 *
 * 说明：
 *  - 升级密钥默认为 config/settings.json 的 updater_key（未设置时用 admin_password）
 *  - 仅拉取 GitHub 仓库代码，保留 config/ 与 data/
 *  - 更新后文件与目录权限统一为 0777
 *  - 支持 ?dry=1 仅测速不执行，便于排查
 */

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$key = isset($_GET['key']) ? trim($_GET['key']) : '';
$dry = !empty($_GET['dry']);

echo "===== 沫兮官替系统 - 在线升级 =====\n";
echo '当前版本: ' . MXGJ_VERSION . "\n\n";

// 限定 POST/get 权限：若不提供 CLI 应从 key 校验
if (PHP_SAPI !== 'cli') {
    $st    = mxgj_settings();
    $upKey = isset($st['updater_key']) && $st['updater_key'] !== '' ? $st['updater_key'] : ($st['admin_password'] ?? '');
    if ($key === '' || $key !== $upKey) {
        echo "错误：升级密钥不合法（请在 URL 携带 ?key=升级密钥）\n";
        exit(1);
    }
}

if ($dry) {
    echo "[dry] 仅测速，不执行更新\n\n";
}

$result = Updater::run($key);

Logger::log('update', ($dry ? '[dry 测速] ' : '') . ($result['ok'] ? '在线更新成功' : '在线更新失败') . '：' . $result['msg'], $result['ok'] ? 'success' : 'error', ['applied' => $result['applied'] ?? false, 'steps' => $result['steps'] ?? [], 'speed' => $result['speed'] ?? []]);

foreach ($result['steps'] as $i => $step) {
    echo ($i + 1) . ") {$step}\n";
}
echo "\n";
echo "测速结果:\n";
if ($result['speed']) {
    foreach ($result['speed'] as $name => $ms) {
        echo "  - {$name}: {$ms}ms\n";
    }
} else {
    echo "  (无可用节点)\n";
}
echo "\n";
echo ($result['ok'] ? '✔ ' : '✘ ') . $result['msg'] . "\n";

exit($result['ok'] ? 0 : 1);