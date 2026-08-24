<?php
/** 请求日志 */
declare(strict_types=1);
$logs = $data['logs'] ?? [];
$pageNum = $data['pageNum'] ?? 1;
$pages = $data['pages'] ?? 1;
$total = $data['total'] ?? 0;
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h3>官替请求日志（共 <?= $total ?> 条）</h3>
    <div>
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="page-link <?= $i === $pageNum ? 'active' : '' ?>" href="/admin/?page=logs&p=<?= $i ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php if ($logs): ?>
  <table>
    <tr><th>时间</th><th>来源链接</th><th>平台</th><th>剧名</th><th>集</th><th>结果</th><th>匹配站点</th><th>耗时</th></tr>
    <?php foreach ($logs as $log): ?>
      <tr>
        <td class="muted" style="white-space:nowrap"><?= e($log['created_at']) ?></td>
        <td class="mono muted" style="max-width:260px"><?= e($log['source_url']) ?></td>
        <td><?= e($log['platform']) ?></td>
        <td><?= e($log['vod_name']) ?></td>
        <td><?= $log['episode'] ?: '-' ?></td>
        <td><span class="badge <?= $log['code'] === 200 ? 'badge-green' : 'badge-red' ?>">code=<?= (int)$log['code'] ?></span></td>
        <td><?= e($log['matched_site'] ?: '-') ?></td>
        <td class="muted"><?= $log['cost_ms'] ?>ms</td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p class="muted">暂无日志。</p>
  <?php endif; ?>
</div>
