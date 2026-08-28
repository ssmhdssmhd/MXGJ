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

require_once __DIR__ . '/lib/bootstrap.php';

$key = isset($_GET['key']) ? trim($_GET['key']) : '';
$dry = !empty($_GET['dry']);
$isCli = PHP_SAPI === 'cli';

// CLI 模式保持纯文本输出
if ($isCli) {
    echo "===== 沫兮官替系统 - 在线升级 =====\n";
    echo '当前版本: ' . MXGJ_VERSION . "\n\n";
    $st    = mxgj_settings();
    $upKey = isset($st['updater_key']) && $st['updater_key'] !== '' ? $st['updater_key'] : ($st['admin_password'] ?? '');
    if ($key === '' || $key !== $upKey) { echo "错误：升级密钥不合法\n"; exit(1); }
    if ($dry) echo "[dry] 仅测速，不执行更新\n\n";
    $result = Updater::run($key);
    foreach ($result['steps'] as $i => $step) echo ($i + 1) . ") {$step}\n";
    echo "\n" . ($result['ok'] ? '✔ ' : '✘ ') . $result['msg'] . "\n";
    exit($result['ok'] ? 0 : 1);
}

// === Web 模式：深色主题 HTML 美化 ===
header('Content-Type: text/html; charset=utf-8');

$st    = mxgj_settings();
$upKey = isset($st['updater_key']) && $st['updater_key'] !== '' ? $st['updater_key'] : ($st['admin_password'] ?? '');
$authOk = ($key !== '' && $key === $upKey);
$showForm = !$authOk;
$executed = false;
$result = null;
if ($authOk) {
    $executed = true;
    $result = Updater::run($key);
    Logger::log('update', ($dry ? '[dry 测速] ' : '') . ($result['ok'] ? '在线更新成功' : '在线更新失败') . '：' . $result['msg'], $result['ok'] ? 'success' : 'error', ['applied' => $result['applied'] ?? false, 'steps' => $result['steps'] ?? [], 'speed' => $result['speed'] ?? []]);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>沫兮官替 · 在线升级</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#0f172a 100%);min-height:100vh;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:rgba(30,41,59,0.85);backdrop-filter:blur(12px);border:1px solid rgba(99,102,241,0.2);border-radius:16px;padding:32px 40px;max-width:640px;width:100%;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5)}
.header{display:flex;align-items:center;gap:14px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid rgba(148,163,184,0.15)}
.logo{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;box-shadow:0 4px 12px rgba(99,102,241,0.4)}
.title{font-size:22px;font-weight:700;color:#f1f5f9;letter-spacing:0.5px}
.subtitle{font-size:13px;color:#94a3b8;margin-top:2px}
.version-bar{background:rgba(15,23,42,0.6);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;border:1px solid rgba(148,163,184,0.1)}
.version-label{font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px}
.version-num{font-family:"JetBrains Mono",Consolas,monospace;font-size:18px;color:#6366f1;font-weight:600}
.form-area{margin-bottom:20px}
.form-area label{display:block;font-size:13px;color:#cbd5e1;margin-bottom:8px}
.form-area input{width:100%;padding:12px 16px;background:rgba(15,23,42,0.7);border:1px solid rgba(99,102,241,0.3);border-radius:10px;color:#f1f5f9;font-size:14px;outline:none;transition:border-color 0.2s,box-shadow 0.2s}
.form-area input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,0.2)}
.btn-row{display:flex;gap:12px;margin-top:16px}
.btn{flex:1;padding:12px 20px;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;transition:transform 0.15s,box-shadow 0.2s}
.btn-primary{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;box-shadow:0 4px 14px rgba(99,102,241,0.4)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(99,102,241,0.5)}
.btn-ghost{background:rgba(148,163,184,0.1);color:#94a3b8;border:1px solid rgba(148,163,184,0.2)}
.steps{margin-bottom:20px}
.step{display:flex;gap:12px;padding:10px 0;font-size:13px;align-items:flex-start}
.step-dot{width:22px;height:22px;border-radius:50%;background:#6366f1;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0;margin-top:1px}
.step-text{color:#cbd5e1;line-height:1.5}
.result-box{border-radius:12px;padding:20px;margin-top:16px}
.result-ok{background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3)}
.result-err{background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3)}
.result-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.result-icon{font-size:24px}
.result-title{font-size:16px;font-weight:600}
.result-ok .result-title{color:#4ade80}
.result-err .result-title{color:#f87171}
.result-msg{font-size:14px;color:#e2e8f0;line-height:1.6}
.speed-section{margin-top:16px;padding-top:16px;border-top:1px solid rgba(148,163,184,0.15)}
.speed-title{font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px}
.speed-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px}
.speed-item{background:rgba(15,23,42,0.5);border-radius:8px;padding:8px 12px;font-size:12px;display:flex;justify-content:space-between}
.speed-name{color:#94a3b8}
.speed-val{color:#fbbf24;font-family:monospace}
.back-link{display:inline-block;margin-top:20px;color:#6366f1;text-decoration:none;font-size:13px;padding:8px 16px;border-radius:8px;background:rgba(99,102,241,0.1);transition:background 0.2s}
.back-link:hover{background:rgba(99,102,241,0.2)}
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;margin-left:8px}
.badge-dry{background:rgba(245,158,11,0.15);color:#fbbf24;border:1px solid rgba(245,158,11,0.3)}
.badge-new{background:rgba(34,197,94,0.15);color:#4ade80;border:1px solid rgba(34,197,94,0.3)}
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <div class="logo">🚀</div>
    <div>
      <div class="title">沫兮官替系统 · 在线升级</div>
      <div class="subtitle">MXGJ Updater</div>
    </div>
    <?php if ($dry): ?><span class="badge badge-dry">DRY RUN（仅测速）</span><?php endif; ?>
  </div>

  <div class="version-bar">
    <div>
      <div class="version-label">当前版本</div>
      <div class="version-num"><?php echo MXGJ_VERSION; ?></div>
    </div>
    <div style="text-align:right">
      <div class="version-label">状态</div>
      <div class="version-num" style="color:#64748b;font-size:14px"><?php echo $authOk ? '已认证' : '待认证'; ?></div>
    </div>
  </div>

  <?php if ($showForm): ?>
  <form method="get" class="form-area">
    <label>升级密钥 <span style="color:#64748b">(updater_key 或 admin_password)</span></label>
    <input type="password" name="key" placeholder="请输入升级密钥" value="<?php echo htmlspecialchars($key); ?>" autofocus>
    <div class="btn-row">
      <button type="submit" class="btn btn-primary">▶ 执行更新</button>
      <button type="submit" class="btn btn-ghost" name="dry" value="1">🔍 仅测速</button>
    </div>
  </form>
  <p style="font-size:12px;color:#64748b;margin-top:16px">⚠️ 首次访问请先在 URL 携带 ?key=xxx 进行认证。更新将拉取 GitHub 最新代码，保留 config/ 与 data/ 目录。</p>

  <?php elseif ($executed && $result): ?>
  <div class="steps">
    <?php foreach ($result['steps'] as $i => $step): ?>
    <div class="step">
      <div class="step-dot"><?php echo $i + 1; ?></div>
      <div class="step-text"><?php echo htmlspecialchars($step); ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="result-box <?php echo $result['ok'] ? 'result-ok' : 'result-err'; ?>">
    <div class="result-head">
      <span class="result-icon"><?php echo $result['ok'] ? '✅' : '❌'; ?></span>
      <span class="result-title"><?php echo $result['ok'] ? '更新成功' : '更新失败'; ?></span>
      <?php if (!empty($result['updated_version']) && $result['ok'] && $result['updated_version'] !== MXGJ_VERSION): ?>
      <span class="badge badge-new">→ <?php echo htmlspecialchars($result['updated_version']); ?></span>
      <?php endif; ?>
    </div>
    <div class="result-msg"><?php echo htmlspecialchars($result['msg']); ?></div>
  </div>

  <?php if (!empty($result['speed'])): ?>
  <div class="speed-section">
    <div class="speed-title">测速结果</div>
    <div class="speed-grid">
      <?php foreach ($result['speed'] as $name => $ms): ?>
      <div class="speed-item"><span class="speed-name"><?php echo htmlspecialchars($name); ?></span><span class="speed-val"><?php echo $ms; ?>ms</span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <a href="admin.php" class="back-link">← 返回后台管理</a>
  <?php endif; ?>
</div>
</body>
</html>
