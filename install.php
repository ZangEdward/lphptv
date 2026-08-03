<?php
/**
 * install.php - 首次安装：设置站点名、管理员密码，生成代理 token
 */
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/util.php';

if (is_installed()) {
    header('Location: index.php');
    exit;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site = trim($_POST['site_name'] ?? 'PHP 影视聚合');
    $pw = $_POST['password'] ?? '';
    $pw2 = $_POST['password2'] ?? '';
    if (strlen($pw) < 6) {
        $msg = '密码至少 6 位';
    } elseif ($pw !== $pw2) {
        $msg = '两次密码不一致';
    } else {
        cfg_set('installed', '1');
        cfg_set('site_name', $site);
        cfg_set('proxy_token', bin2hex(random_bytes(16)));
        cfg_set('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
        cfg_set('search_timeout', '8');
        cfg_set('cache_ttl', '1800');
        cfg_set('detail_ttl', '7200');
        header('Location: admin.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 - PHP 影视聚合</title>
<style>
body{background:#0f1115;color:#e6e6e6;font-family:system-ui,"PingFang SC","Microsoft YaHei",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#171a21;padding:32px 36px;border-radius:12px;width:380px;border:1px solid #2a2f3a}
h1{font-size:20px;margin:0 0 6px;color:#e50914}
p.sub{color:#9aa0aa;font-size:13px;margin:0 0 20px}
label{display:block;font-size:13px;margin:14px 0 6px;color:#cfd3da}
input{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #2a2f3a;background:#1f232c;color:#fff;font-size:14px}
button{margin-top:22px;width:100%;padding:12px;border:none;border-radius:8px;background:#e50914;color:#fff;font-size:15px;cursor:pointer}
.msg{color:#ff6b6b;font-size:13px;margin-top:10px}
.note{color:#9aa0aa;font-size:12px;margin-top:18px;line-height:1.6}
</style>
</head>
<body>
<div class="box">
  <h1>PHP 影视聚合 · 安装</h1>
  <p class="sub">设置管理员密码即可完成（SQLite 零配置）</p>
  <?php if ($msg): ?><div class="msg"><?php echo e($msg); ?></div><?php endif; ?>
  <form method="post">
    <label>站点名称</label>
    <input name="site_name" value="PHP 影视聚合" required>
    <label>管理员密码</label>
    <input name="password" type="password" required>
    <label>确认密码</label>
    <input name="password2" type="password" required>
    <button type="submit">安装并进入后台</button>
  </form>
  <div class="note">安装后请到「后台 → 资源管理」添加你的<strong>资源接口</strong>（苹果CMS V10 格式，例如 <code>https://你的源域名/api.php/provide/vod</code>）。</div>
</div>
</body>
</html>
