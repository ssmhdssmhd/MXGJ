#!/usr/bin/env php
<?php
/**
 * 命令行自动更新（配合 cron / 手动触发）。
 * 用法：php cli/update.php
 * 退出码：0=成功/已最新，1=失败
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Core\Updater;

$updater = new Updater(db());
$result = $updater->update('cli');

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($result['status'] === 'success' || $result['status'] === 'up-to-date' ? 0 : 1);
