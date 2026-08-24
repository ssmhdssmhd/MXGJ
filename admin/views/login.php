<?php
/** 后台登录页 */
declare(strict_types=1);
$flash = flash_take();
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>登录 - <?= e(config('app.name')) ?> 后台</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:linear-gradient(135deg,#0f172a,#1e1b4b);min-height:100vh;display:flex;align-items:center;justify-content:center;color:#e2e8f0}
.box{width:360px;background:rgba(15,23,42,.85);border:1px solid rgba(148,163,184,.2);border-radius:16px;padding:32px;box-shadow:0 20px 50px rgba(0,0,0,.5)}
.box h1{font-size:22px;text-align:center;margin-bottom:4px;background:linear-gradient(90deg,#a5b4fc,#f0abfc);-webkit-background-clip:text;background-clip:text;color:transparent}
.box .sub{text-align:center;font-size:12px;color:#64748b;margin-bottom:24px}
label{display:block;font-size:13px;color:#a5b4fc;margin:12px 0 6px}
input[type=text],input[type=password]{width:100%;padding:11px 13px;border-radius:10px;border:1px solid rgba(148,163,184,.3);background:rgba(30,41,59,.9);color:#f1f5f9;font-size:14px;outline:none}
input:focus{border-color:#818cf8}
button{width:100%;margin-top:20px;padding:12px;border:none;border-radius:10px;background:linear-gradient(90deg,#6366f1,#8b5cf6);color:#fff;font-size:15px;font-weight:700;cursor:pointer}
button:hover{opacity:.9}
.alert{margin-top:16px;padding:10px 12px;border-radius:8px;font-size:13px}
.alert-success{background:rgba(74,222,128,.12);color:#4ade80}
.alert-error{background:rgba(248,113,113,.12);color:#f87171}
.back{display:block;text-align:center;margin-top:16px;font-size:12px;color:#64748b;text-decoration:none}
</style>
</head>
<body>
<div class="box">
  <h1>沫兮官替系统</h1>
  <div class="sub">后台管理登录 · 默认账号 admin / admin123</div>
  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>
  <form method="post" action="/admin/">
    <input type="hidden" name="action" value="login"/>
    <label>用户名</label>
    <input type="text" name="username" autocomplete="username" required/>
    <label>密码</label>
    <input type="password" name="password" autocomplete="current-password" required/>
    <button type="submit">登 录</button>
  </form>
  <a class="back" href="/">← 返回前台</a>
</div>
</body>
</html>
