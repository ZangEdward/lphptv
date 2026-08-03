<?php
/**
 * admin.php - 后台：源管理 / 设置 / 缓存清理 / 改密
 */
require_once __DIR__ . '/inc/bootstrap.php';

if (!is_installed()) { header('Location: install.php'); exit; }

// 退出
if (($_GET['act'] ?? '') === 'logout') { session_destroy(); header('Location: admin.php'); exit; }

// 登录
if (!is_admin()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_pw'])) {
        $hash = cfg_get('admin_hash', '');
        if ($hash && password_verify($_POST['login_pw'], $hash)) {
            $_SESSION['admin'] = 1;
            header('Location: admin.php'); exit;
        }
        $loginErr = '密码错误';
    }
    echo login_html($loginErr ?? '');
    exit;
}

// 已登录：处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_source'])) {
    $name = trim($_POST['name'] ?? '');
    $api = trim($_POST['api'] ?? '');
    $enabled = !empty($_POST['enabled']) ? 1 : 0;
    $adult = !empty($_POST['adult']) ? 1 : 0;
    $id = (int)($_POST['id'] ?? 0);
    if ($name && $api) {
        if ($id) source_update($id, compact('name','api','enabled','adult'));
        else source_add($name, $api, $enabled, $adult);
    }
    header('Location: admin.php'); exit;
}

if (($_GET['act'] ?? '') === 'delete' && isset($_GET['id'])) { source_delete((int)$_GET['id']); header('Location: admin.php'); exit; }

if (($_GET['act'] ?? '') === 'clearcache') { cache_clear(); header('Location: admin.php'); exit; }

// 源测试（已登录，AJAX）
if (($_GET['act'] ?? '') === 'test' && isset($_GET['id'])) {
    $s = source_get((int)$_GET['id']);
    json_out($s ? vod_test($s['api']) : ['ok' => false, 'msg' => '源不存在']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    cfg_set('site_name', trim($_POST['site_name'] ?? 'PHP 影视聚合'));
    cfg_set('search_timeout', max(3, (int)($_POST['search_timeout'] ?? 8)));
    cfg_set('cache_ttl', max(0, (int)($_POST['cache_ttl'] ?? 1800)));
    cfg_set('detail_ttl', max(0, (int)($_POST['detail_ttl'] ?? 7200)));
    header('Location: admin.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pw'])) {
    $np = $_POST['new_pw'] ?? '';
    if (strlen($np) >= 6) { cfg_set('admin_hash', password_hash($np, PASSWORD_DEFAULT)); $pwMsg = '密码已更新'; }
    else $pwMsg = '密码至少 6 位';
}

$edit = null;
if (($_GET['act'] ?? '') === 'edit' && isset($_GET['id'])) $edit = source_get((int)$_GET['id']);

$sources = sources_all(false);
$st = cfg_get_all();

echo admin_html($sources, $st, $edit ?? null, $pwMsg ?? '');

/* ---------------- 视图 ---------------- */
function login_html($err) {
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>后台登录</title><style>body{background:#0f1115;color:#e6e6e6;font-family:system-ui,"PingFang SC",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#171a21;padding:30px 34px;border-radius:12px;width:340px;border:1px solid #2a2f3a}h1{font-size:18px;margin:0 0 18px;color:#e50914}
    input{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #2a2f3a;background:#1f232c;color:#fff;margin-top:6px}
    button{margin-top:18px;width:100%;padding:11px;border:none;border-radius:8px;background:#e50914;color:#fff;cursor:pointer}
    .err{color:#ff6b6b;font-size:13px;margin-top:10px}</style></head><body>
    <div class="box"><h1>后台登录</h1>' . ($err ? "<div class=\"err\">$err</div>" : '') .
    '<form method="post"><input name="login_pw" type="password" placeholder="管理员密码" required><button>登录</button></form>
    <p style="margin-top:14px;font-size:12px;color:#9aa0aa"><a href="index.php" style="color:#9aa0aa">← 返回首页</a></p></div></body></html>';
}

function admin_html($sources, $st, $edit, $pwMsg) {
    $rows = '';
    foreach ($sources as $s) {
        $rows .= '<tr>
          <td>'.e($s['id']).'</td><td>'.e($s['name']).'</td><td class="api">'.e($s['api']).'</td>
          <td>'.($s['enabled']?'<span class="on">启用</span>':'<span class="off">停用</span>').'</td>
          <td>'.($s['adult']?'成人':'-').'</td>
          <td class="acts">
            <a href="?act=edit&id='.$s['id'].'">编辑</a>
            <a href="javascript:testSrc('.$s['id'].')" id="t'.$s['id'].'">测试</a>
            <a href="?act=delete&id='.$s['id'].'" onclick="return confirm(\'删除该源？\')">删除</a>
          </td></tr>';
    }
    if (!$rows) $rows = '<tr><td colspan="6" class="empty">还没有添加任何资源源</td></tr>';

    $eName = $edit ? e($edit['name']) : '';
    $eApi = $edit ? e($edit['api']) : '';
    $eId = $edit ? (int)$edit['id'] : 0;
    $eEn = $edit && $edit['enabled'] ? 'checked' : (!$edit ? 'checked' : '');
    $eAd = $edit && $edit['adult'] ? 'checked' : '';

    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>后台 · PHP 影视聚合</title><style>
    body{background:#0f1115;color:#e6e6e6;font-family:system-ui,"PingFang SC",sans-serif;margin:0}
    header{padding:14px 22px;background:#171a21;border-bottom:1px solid #000;display:flex;justify-content:space-between;align-items:center}
    header a{color:#9aa0aa;margin-left:14px;font-size:14px}
    .wrap{max-width:1000px;margin:24px auto;padding:0 18px}
    h2{font-size:17px;margin:26px 0 12px;border-left:3px solid #e50914;padding-left:10px}
    table{width:100%;border-collapse:collapse;background:#171a21;border-radius:8px;overflow:hidden}
    th,td{padding:10px 12px;text-align:left;font-size:13px;border-bottom:1px solid #222}
    th{background:#1f232c;color:#9aa0aa}.api{word-break:break-all;color:#9aa0aa}.acts a{margin-right:10px;color:#5aa9ff}
    .on{color:#3ec46d}.off{color:#888}.empty{color:#888;text-align:center}
    .card{background:#171a21;padding:18px;border-radius:10px;margin-bottom:16px}
    label{display:inline-block;width:120px;font-size:13px;color:#cfd3da}
    input[type=text],input[type=number],input[type=password]{padding:8px 10px;border-radius:6px;border:1px solid #2a2f3a;background:#1f232c;color:#fff;width:360px;max-width:100%}
    button{margin-top:12px;padding:9px 18px;border:none;border-radius:6px;background:#e50914;color:#fff;cursor:pointer}
    .msg{color:#3ec46d;font-size:13px;margin-top:8px}
    </style></head><body>
    <header><strong>PHP 影视聚合 · 后台</strong>
      <div><a href="index.php">首页</a><a href="?act=logout">退出</a></div></header>
    <div class="wrap">

    <h2>资源管理（苹果CMS V10 接口）</h2>
    <div class="card"><form method="post">
      <input type="hidden" name="save_source" value="1">
      <input type="hidden" name="id" value="'.$eId.'">
      <div><label>名称</label><input type="text" name="name" value="'.$eName.'" required placeholder="如：演示源A"></div>
      <div style="margin-top:10px"><label>接口地址</label><input type="text" name="api" value="'.$eApi.'" required placeholder="https://域名/api.php/provide/vod"></div>
      <div style="margin-top:10px"><label>启用</label><input type="checkbox" name="enabled" '.$eEn.'></div>
      <div style="margin-top:8px"><label>成人内容</label><input type="checkbox" name="adult" '.$eAd.'></div>
      <button>'.($edit?'保存修改':'添加源').'</button>
      '.($edit?'<a href="admin.php" style="color:#9aa0aa;margin-left:12px">取消</a>':'').'
    </form></div>
    <table><tr><th>ID</th><th>名称</th><th>接口地址</th><th>状态</th><th>类型</th><th>操作</th></tr>'.$rows.'</table>

    <h2>系统设置</h2>
    <div class="card"><form method="post">
      <input type="hidden" name="save_settings" value="1">
      <div><label>站点名称</label><input type="text" name="site_name" value="'.e($st['site_name'] ?? '').'"></div>
      <div style="margin-top:10px"><label>单源超时(秒)</label><input type="number" name="search_timeout" value="'.e($st['search_timeout'] ?? 8).'"></div>
      <div style="margin-top:10px"><label>搜索缓存(秒)</label><input type="number" name="cache_ttl" value="'.e($st['cache_ttl'] ?? 1800).'"></div>
      <div style="margin-top:10px"><label>详情缓存(秒)</label><input type="number" name="detail_ttl" value="'.e($st['detail_ttl'] ?? 7200).'"></div>
      <button>保存设置</button>
      <a href="?act=clearcache" style="color:#ff6b6b;margin-left:14px" onclick="return confirm(\'清空搜索/详情缓存？\')">清空缓存</a>
    </form></div>

    <h2>修改密码</h2>
    <div class="card"><form method="post">
      <input type="hidden" name="change_pw" value="1">
      <div><label>新密码</label><input type="password" name="new_pw" required></div>
      <button>更新密码</button>'.($pwMsg?'<span class="msg">'.$pwMsg.'</span>':'').'
    </form></div>

    </div>
    <script>
    function testSrc(id){
      var a=document.getElementById(\'t\'+id); a.textContent=\'测试中…\';
      fetch(\'admin.php?act=test&id=\'+id).then(r=>r.json()).then(d=>{ a.textContent=(d.ok?\'✓ \':\'✗ \')+d.msg; setTimeout(()=>a.textContent=\'测试\',4000); })
      .catch(e=>{a.textContent=\'测试失败\';});
    }
    </script>
    </body></html>';
}
?>
