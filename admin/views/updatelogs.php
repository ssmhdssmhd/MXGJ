<?php
/** 更新日志 */
declare(strict_types=1);
$logs = $data['logs'] ?? [];
?>
<div class="card">
  <h3 style="margin-bottom:12px">Git 自动更新日志</h3>
  <?php if ($logs): ?>
  <table>
    <tr><th>时间</th><th>状态</th><th>触发方式</th><th>commit</th><th>说明</th></tr>
    <?php foreach ($logs as $log): ?>
      <tr>
        <td class="muted" style="white-space:nowrap"><?= e($log['created_at']) ?></td>
        <td><span class="badge <?= $log['status'] === 'success' ? 'badge-green' : 'badge-red' ?>"><?= e($log['status']) ?></span></td>
        <td><?= e($log['trigger']) ?></td>
        <td class="mono"><?= e($log['before_commit'] ?: '-') ?> → <?= e($log['after_commit'] ?: '-') ?></td>
        <td style="white-space:pre-line;word-break:break-all"><?= e($log['message']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p class="muted">暂无更新记录。可到「仪表盘」点击「检查更新 / 立即更新」，或在服务器配置 cron 定时执行 <span class="mono">php cli/update.php</span>。</p>
  <?php endif; ?>
</div>
