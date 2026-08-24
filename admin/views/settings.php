<?php
/** 系统设置 */
declare(strict_types=1);
$settings = $data['settings'] ?? [];
$git = $data['git'] ?? [];
$driver = $data['dbDriver'] ?? '';
?>
<div class="card">
  <h3 style="margin-bottom:12px">官替参数</h3>
  <form method="post" action="/admin/?page=settings">
    <input type="hidden" name="action" value="settings_save"/>
    <div class="grid2">
      <div><label>资源站搜索并发数（多线程）</label><input type="number" name="concurrency" value="<?= (int)$settings['concurrency'] ?>" min="1" max="64"/></div>
      <div><label>请求超时（秒）</label><input type="number" name="default_timeout" value="<?= (int)$settings['default_timeout'] ?>" min="1" max="60"/></div>
      <div><label>Git 更新分支</label><input type="text" name="git_branch" value="<?= e($settings['git_branch']) ?>" placeholder="如 main"/></div>
      <div><label>自动更新令牌（webhook 校验）</label><input type="text" name="update_token" value="<?= e($settings['update_token']) ?>"/></div>
    </div>
    <label style="margin-top:10px"><input type="checkbox" name="update_enabled" <?= $settings['update_enabled'] ? 'checked' : '' ?> style="width:auto"/> 允许通过后台 / webhook / cron 自动更新代码</label>
    <div class="mt"><button class="btn btn-primary" type="submit">保存设置</button></div>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:12px">自动更新配置说明</h3>
  <p class="muted" style="line-height:1.9;font-size:13px">
    1. <b>后台</b>：仪表盘点击「检查更新 / 立即更新」<br/>
    2. <b>Webhook</b>（GitHub/Gitee 等推送后自动拉取）：<br/>
    <span class="mono">POST /api/webhook/update?token=<?= e($settings['update_token']) ?></span><br/>
    3. <b>CLI / Cron</b>（推荐生产环境）：每 10 分钟自动拉取一次：<br/>
    <span class="mono">*/10 * * * * cd <?= e(dirname(__DIR__, 2)) ?> && /usr/bin/php cli/update.php &gt;&gt; runtime/cron.log 2&gt;&amp;1</span>
  </p>
  <table class="mt">
    <tr><th style="width:120px">Git 状态</th><td>
      git 可用：<?= $git['gitAvailable'] ? '<span class="badge badge-green">是</span>' : '<span class="badge badge-red">否</span>' ?> ·
      仓库：<?= $git['isRepo'] ? '<span class="badge badge-green">是</span>' : '<span class="badge badge-red">否</span>' ?> ·
      当前分支：<span class="mono"><?= e($git['branch'] ?: '-') ?></span> ·
      远程：<span class="mono"><?= e($git['remote'] ?: '-') ?></span> ·
      HEAD：<span class="mono"><?= e($git['head'] ?: '-') ?></span>
    </td></tr>
  </table>
</div>

<div class="card">
  <h3 style="margin-bottom:8px">数据库信息</h3>
  <p class="muted" style="font-size:13px">当前驱动：<span class="badge badge-gray"><?= e($driver) ?></span>（MySQL / SQLite 均由 config/config.php 配置，可随时切换，数据表会自动创建）</p>
</div>

<div class="card">
  <h3 style="margin-bottom:8px">修改后台密码</h3>
  <form method="post" action="/admin/?page=settings">
    <input type="hidden" name="action" value="password_change"/>
    <div class="grid2">
      <div><label>新密码（至少6位）</label><input type="password" name="new_password" required/></div>
    </div>
    <div class="mt"><button class="btn btn-primary" type="submit">修改密码</button></div>
  </form>
</div>
