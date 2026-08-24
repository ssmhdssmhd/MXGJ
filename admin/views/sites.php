<?php
/** 资源站管理 */
declare(strict_types=1);
$sites = $data['sites'] ?? [];
?>
<div class="card">
  <h3 style="margin-bottom:12px">资源站列表（苹果CMS / 海洋CMS provide/vod 接口）</h3>
  <?php if ($sites): ?>
  <table>
    <tr><th style="width:60px">启用</th><th>ID</th><th>名称</th><th>API 地址</th><th>超时(s)</th><th style="width:140px">操作</th></tr>
    <?php foreach ($sites as $s): ?>
      <tr>
        <td>
          <form class="inline" method="post" action="/admin/">
            <input type="hidden" name="action" value="site_toggle"/>
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>"/>
            <button class="btn btn-sm <?= $s['enabled'] ? 'btn-primary' : 'btn-ghost' ?>" type="submit"><?= $s['enabled'] ? '启用' : '停用' ?></button>
          </form>
        </td>
        <td class="mono"><?= e($s['site_id']) ?></td>
        <td><?= e($s['name']) ?></td>
        <td class="mono muted"><?= e($s['api']) ?></td>
        <td><?= (int)$s['timeout'] ?></td>
        <td>
          <form class="inline" method="post" action="/admin/?page=sites">
            <input type="hidden" name="action" value="site_delete"/>
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>"/>
            <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('确认删除该资源站？')">删除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p class="muted">暂无资源站，请在下方添加。</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-bottom:4px">添加资源站</h3>
  <p class="muted" style="font-size:12px;margin-bottom:6px">API 示例：https://你的域名/api.php/provide/vod/（系统会自动追加 ?ac=detail&wd=剧名）</p>
  <form method="post" action="/admin/?page=sites">
    <input type="hidden" name="action" value="site_save"/>
    <input type="hidden" name="id" value=""/>
    <div class="grid2">
      <div><label>站点ID（唯一，如 site_a）</label><input type="text" name="site_id" required/></div>
      <div><label>站点名称</label><input type="text" name="name" required/></div>
      <div><label>API 接口地址</label><input type="text" name="api" required placeholder="https://your-site.com/api.php/provide/vod/"/></div>
      <div><label>超时（秒）</label><input type="number" name="timeout" value="8" min="1"/></div>
    </div>
    <label style="margin-top:10px"><input type="checkbox" name="enabled" checked style="width:auto"/> 启用该资源站</label>
    <div class="mt"><button class="btn btn-primary" type="submit">添加</button></div>
  </form>
</div>
