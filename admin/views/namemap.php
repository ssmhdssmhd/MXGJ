<?php
/** 剧名映射管理 */
declare(strict_types=1);
$maps = $data['maps'] ?? [];
$platforms = ['tencent' => '腾讯视频', 'iqiyi' => '爱奇艺', 'youku' => '优酷', 'mgtv' => '芒果TV', 'bilibili' => '哔哩哔哩', 'sohu' => '搜狐', 'pptv' => 'PPTV', 'leshi' => '乐视', 'misc' => '通用/其他'];
?>
<div class="card">
  <h3 style="margin-bottom:12px">剧名映射（官方标识 → 剧名/集数）</h3>
  <p class="muted" style="font-size:12px;margin-bottom:8px">如腾讯链接的 cid=mzc00200zx8psx0 → 庆余年 / 第2集。命中后无需抓取官方页面。</p>
  <?php if ($maps): ?>
  <table>
    <tr><th>平台</th><th>官方标识(cid/vid/BV)</th><th>剧名</th><th>集数</th><th style="width:140px">操作</th></tr>
    <?php foreach ($maps as $m): ?>
      <tr>
        <td><?= e($platforms[$m['platform']] ?? $m['platform']) ?></td>
        <td class="mono"><?= e($m['vid']) ?></td>
        <td><?= e($m['name']) ?></td>
        <td><?= $m['episode'] > 0 ? '第' . (int)$m['episode'] . '集' : '默认第1集' ?></td>
        <td>
          <form class="inline" method="post" action="/admin/?page=namemap">
            <input type="hidden" name="action" value="name_delete"/>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>"/>
            <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('确认删除？')">删除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p class="muted">暂无映射，请在下方添加。</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-bottom:8px">添加映射</h3>
  <form method="post" action="/admin/?page=namemap">
    <input type="hidden" name="action" value="name_save"/>
    <input type="hidden" name="id" value=""/>
    <div class="grid2">
      <div>
        <label>平台</label>
        <select name="platform">
          <?php foreach ($platforms as $k => $label): ?>
            <option value="<?= $k ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>官方标识（cid / vid / BV号）</label><input type="text" name="vid" required placeholder="如 mzc00200zx8psx0"/></div>
      <div><label>剧名</label><input type="text" name="name" required placeholder="如 庆余年"/></div>
      <div><label>集数（0=默认第1集）</label><input type="number" name="episode" value="0" min="0"/></div>
    </div>
    <div class="mt"><button class="btn btn-primary" type="submit">添加</button></div>
  </form>
</div>
