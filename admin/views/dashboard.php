<?php
/** 仪表盘 */
declare(strict_types=1);
$d = $data;
?>
<div class="stats">
  <div class="stat"><div class="num"><?= $d['siteEnabled'] ?>/<?= $d['siteTotal'] ?></div><div class="lbl">启用 / 资源站总数</div></div>
  <div class="stat"><div class="num"><?= $d['nameMapTotal'] ?></div><div class="lbl">剧名映射</div></div>
  <div class="stat"><div class="num"><?= $d['logOk'] ?>/<?= $d['logTotal'] ?></div><div class="lbl">官替成功 / 总请求</div></div>
  <div class="stat"><div class="num"><?= $d['settings']['concurrency'] ?></div><div class="lbl">资源站搜索并发</div></div>
</div>

<div class="card">
  <h3 style="margin-bottom:10px">Git 自动更新</h3>
  <table>
    <tr><th style="width:120px">远程仓库</th><td class="mono"><?= e($d['git']['remote'] ?: '未配置') ?></td></tr>
    <tr><th>当前分支</th><td class="mono"><?= e($d['git']['branch'] ?: '-') ?>（更新分支：<?= e($d['git']['configuredBranch'] ?: '-') ?>）</td></tr>
    <tr><th>本地版本</th><td class="mono"><?= e($d['git']['head'] ?: '-') ?> <?= $d['git']['isRepo'] ? '' : '<span class="badge badge-red">非git仓库</span>' ?></td></tr>
    <tr><th>上次更新</th><td>
      <?php if ($d['lastUpdate']): ?>
        <span class="badge <?= $d['lastUpdate']['status'] === 'success' ? 'badge-green' : 'badge-red' ?>"><?= e($d['lastUpdate']['status']) ?></span>
        <?= e($d['lastUpdate']['created_at']) ?> · <?= e($d['lastUpdate']['trigger']) ?>
        <div class="muted mt" style="white-space:pre-line"><?= e(mb_substr($d['lastUpdate']['message'], 0, 300)) ?></div>
      <?php else: ?>
        <span class="muted">尚无更新记录</span>
      <?php endif; ?>
    </td></tr>
  </table>
  <div class="mt">
    <form class="inline" method="post" action="/admin/">
      <input type="hidden" name="action" value="update_check"/>
      <button class="btn btn-ghost" type="submit">检查更新</button>
    </form>
    <form class="inline" method="post" action="/admin/" style="margin-left:8px">
      <input type="hidden" name="action" value="update_run"/>
      <button class="btn btn-primary" type="submit" onclick="return confirm('确认从远程仓库拉取最新代码并更新？')">立即更新</button>
    </form>
  </div>
</div>

<div class="card">
  <h3 style="margin-bottom:10px">最近官替请求</h3>
  <?php if ($d['recentLogs']): ?>
  <table>
    <tr><th>时间</th><th>剧名</th><th>集数</th><th>结果</th><th>匹配站点</th></tr>
    <?php foreach ($d['recentLogs'] as $log): ?>
      <tr>
        <td class="muted" style="white-space:nowrap"><?= e($log['created_at']) ?></td>
        <td><?= e($log['vod_name']) ?></td>
        <td><?= $log['episode'] ?: '-' ?></td>
        <td><span class="badge <?= $log['code'] === 200 ? 'badge-green' : 'badge-red' ?>">code=<?= (int)$log['code'] ?></span></td>
        <td><?= e($log['matched_site'] ?: '-') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p class="muted">暂无请求记录，可到前台输入官方链接测试。</p>
  <?php endif; ?>
</div>
