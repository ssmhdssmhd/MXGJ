<?php
/**
 * 后台整体布局 + 内容分发
 * 可用变量：$active(string), $data(array), $db
 */
declare(strict_types=1);

$flash = flash_take();
$nav = [
    'dashboard' => '仪表盘',
    'sites' => '资源站管理',
    'namemap' => '剧名映射',
    'logs' => '请求日志',
    'updatelogs' => '更新日志',
    'settings' => '系统设置',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= e($nav[$active] ?? '') ?> - <?= e(config('app.name')) ?> 后台</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh}
.sidebar{width:220px;background:#0b1120;border-right:1px solid rgba(148,163,184,.12);padding:20px 0;flex-shrink:0}
.sidebar .brand{padding:0 20px 20px;font-size:18px;font-weight:800;background:linear-gradient(90deg,#a5b4fc,#f0abfc);-webkit-background-clip:text;background-clip:text;color:transparent}
.sidebar .brand small{display:block;font-size:11px;font-weight:400;color:#64748b;-webkit-text-fill-color:#64748b;background:none}
.sidebar a{display:block;padding:11px 20px;color:#94a3b8;text-decoration:none;font-size:14px;border-left:3px solid transparent}
.sidebar a:hover{color:#e2e8f0;background:rgba(99,102,241,.08)}
.sidebar a.active{color:#a5b4fc;background:rgba(99,102,241,.14);border-left-color:#818cf8}
.main{flex:1;padding:24px 28px;min-width:0}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.top h2{font-size:20px}
.top .user{font-size:13px;color:#64748b}
.card{background:rgba(15,23,42,.75);border:1px solid rgba(148,163,184,.15);border-radius:12px;padding:20px;margin-bottom:18px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.stat{background:rgba(15,23,42,.75);border:1px solid rgba(148,163,184,.15);border-radius:12px;padding:16px}
.stat .num{font-size:26px;font-weight:800;color:#a5b4fc}
.stat .lbl{font-size:12px;color:#64748b;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:9px 10px;text-align:left;border-bottom:1px solid rgba(148,163,184,.1);vertical-align:top}
th{color:#94a3b8;font-weight:600;white-space:nowrap}
td{word-break:break-all}
.btn{display:inline-block;padding:7px 14px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-primary{background:linear-gradient(90deg,#6366f1,#8b5cf6);color:#fff}
.btn-danger{background:rgba(248,113,113,.15);color:#f87171}
.btn-ghost{background:rgba(148,163,184,.12);color:#cbd5e1}
.btn-sm{padding:4px 10px;font-size:12px}
form.inline{display:inline}
input[type=text],input[type=password],input[type=number],select{width:100%;padding:9px 11px;border-radius:8px;border:1px solid rgba(148,163,184,.3);background:rgba(30,41,59,.9);color:#f1f5f9;font-size:13px;outline:none}
input:focus,select:focus{border-color:#818cf8}
label{display:block;font-size:12px;color:#a5b4fc;margin:10px 0 5px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.alert{padding:11px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;white-space:pre-line}
.alert-success{background:rgba(74,222,128,.12);color:#4ade80}
.alert-error{background:rgba(248,113,113,.12);color:#f87171}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px}
.badge-green{background:rgba(74,222,128,.15);color:#4ade80}
.badge-red{background:rgba(248,113,113,.15);color:#f87171}
.badge-gray{background:rgba(148,163,184,.15);color:#94a3b8}
.muted{color:#64748b}
.mt{margin-top:14px}
.page-link{padding:6px 11px;margin-right:6px;border-radius:7px;background:rgba(148,163,184,.12);color:#cbd5e1;text-decoration:none;font-size:13px}
.page-link.active{background:#6366f1;color:#fff}
@media(max-width:820px){.sidebar{width:64px}.sidebar a span,.sidebar .brand{display:none}.grid2{grid-template-columns:1fr}}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="brand">沫兮官替<small>管理后台 v<?= e(config('app.version')) ?></small></div>
  <?php foreach ($nav as $key => $label): ?>
    <a href="/admin/?page=<?= $key ?>" class="<?= $active === $key ? 'active' : '' ?>"><span><?= $label ?></span></a>
  <?php endforeach; ?>
  <a href="/" target="_blank"><span>前台首页 ↗</span></a>
</aside>
<main class="main">
  <div class="top">
    <h2><?= e($nav[$active] ?? '') ?></h2>
    <div class="user">管理员：<?= e(Auth::user()) ?> ·
      <form class="inline" method="post" action="/admin/"><input type="hidden" name="action" value="logout"/><button class="btn btn-ghost btn-sm" type="submit">退出登录</button></form>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <?php require __DIR__ . '/' . $active . '.php'; ?>
</main>
</body>
</html>
