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
    $detail = trim($_POST['detail'] ?? '');
    $enabled = !empty($_POST['enabled']) ? 1 : 0;
    $adult = !empty($_POST['adult']) ? 1 : 0;
    $id = (int)($_POST['id'] ?? 0);
    if ($name && $api) {
        if ($id) {
            source_update($id, compact('name','api','detail','enabled','adult'));
        } else {
            source_upsert_by_key(src_key_from_api($api), $name, $api, $enabled, $adult, $detail);
        }
    }
    header('Location: admin.php'); exit;
}

// 恢复内置默认源（DecoTV 格式 jingjian.txt）
if (($_GET['act'] ?? '') === 'restore_default') {
    $n = import_default_sources();
    header('Location: admin.php?restored=' . (int)$n); exit;
}

// 导入 txt 源（DecoTV 格式：Base58 或已解码 JSON），支持 链接 / 上传 / 粘贴
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_txt'])) {
    $raw = '';
    $srcLabel = '';
    // 1) 优先用订阅链接抓取
    $url = trim((string)($_POST['txt_url'] ?? ''));
    if ($url !== '') {
        $got = fetch_url_text($url);
        if ($got !== false && $got !== '') { $raw = $got; $srcLabel = '链接'; }
        else { $importMsg = '链接抓取失败（检查 URL 是否正确、服务器是否允许出网）'; }
    }
    // 2) 上传文件
    if ($raw === '' && !empty($_FILES['txt_file']['tmp_name']) && is_uploaded_file($_FILES['txt_file']['tmp_name'])) {
        $raw = (string)@file_get_contents($_FILES['txt_file']['tmp_name']);
        $srcLabel = '文件';
    }
    // 3) 直接粘贴
    if ($raw === '' && isset($_POST['txt_text'])) {
        $raw = (string)$_POST['txt_text'];
        $srcLabel = '粘贴';
    }
    $forceAdult = !empty($_POST['adult_all']) ? 1 : 0;
    if ($raw !== '' && !isset($importMsg)) {
        $parsed = parse_sources_txt($raw);
        $sort = (int)db()->query('SELECT COALESCE(MAX(sort),0) FROM sources')->fetchColumn();
        foreach ($parsed['sources'] as $s) {
            $adult = $forceAdult ? 1 : $s['is_adult'];
            source_upsert_by_key($s['key'], $s['name'], $s['api'], 1, $adult, $s['detail'], $sort++);
        }
        $n = count($parsed['sources']);
        $importMsg = ($srcLabel ? "[$srcLabel] " : '') . "已导入/更新 $n 个源（按 key 合并，不重复）" . ($forceAdult ? '，已标记为成人资源 🔞' : '');
    } elseif (!isset($importMsg)) {
        $importMsg = '没有收到内容';
    }
}

// 一键开关成人资源（前台可见性）
if (($_GET['act'] ?? '') === 'toggle_adult') {
    $cur = cfg_get('show_adult') === '1' ? '1' : '0';
    cfg_set('show_adult', $cur === '1' ? '0' : '1');
    header('Location: admin.php?adult=' . cfg_get('show_adult'));
    exit;
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

$restoredMsg = null;
if (isset($_GET['restored'])) {
    $n = (int)$_GET['restored'];
    $restoredMsg = $n > 0 ? "已恢复内置默认源，共 {$n} 个" : '内置默认源文件缺失';
}

$adultMsg = null;
if (isset($_GET['adult'])) {
    $adultMsg = $_GET['adult'] === '1' ? '已开启：前台显示成人内容 🔞' : '已关闭：前台隐藏成人内容';
}

echo admin_html($sources, $st, $edit ?? null, $pwMsg ?? '', $importMsg ?? null, $restoredMsg, $adultMsg);

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

function admin_html($sources, $st, $edit, $pwMsg, $importMsg = null, $restoredMsg = null, $adultMsg = null) {
    $rows = '';
    foreach ($sources as $s) {
        $rows .= '<tr>
          <td>'.e($s['id']).'</td><td>'.e($s['name']).($s['src_key']?' <span class="k" title="key">'.e($s['src_key']).'</span>':'').'</td><td class="api">'.e($s['api']).'</td>
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
    $eDetail = $edit ? e($edit['detail'] ?? '') : '';
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
    .k{display:inline-block;margin-left:6px;font-size:11px;color:#6b7280;background:#1f232c;padding:1px 6px;border-radius:4px;vertical-align:middle}
    textarea{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
    .card{background:#171a21;padding:18px;border-radius:10px;margin-bottom:16px}
    label{display:inline-block;width:120px;font-size:13px;color:#cfd3da}
    input[type=text],input[type=number],input[type=password]{padding:8px 10px;border-radius:6px;border:1px solid #2a2f3a;background:#1f232c;color:#fff;width:360px;max-width:100%}
    button{margin-top:12px;padding:9px 18px;border:none;border-radius:6px;background:#e50914;color:#fff;cursor:pointer}
    .msg{color:#3ec46d;font-size:13px;margin-top:8px}
    .adultbar{background:#171a21;border:1px solid #2a2f3a;border-left:3px solid #ff6b6b;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;display:flex;align-items:center;flex-wrap:wrap;gap:10px}
    .adultbar .hint{color:#9aa0aa;font-size:12px}
    .switch{display:inline-block;min-width:46px;text-align:center;padding:5px 14px;border-radius:20px;font-size:13px;text-decoration:none;font-weight:600}
    .switch.on{background:#3ec46d;color:#0f1115}
    .switch.off{background:#2a2f3a;color:#9aa0aa}
    </style></head><body>
    <header><strong>PHP 影视聚合 · 后台</strong>
      <div><a href="index.php">首页</a><a href="?act=logout">退出</a></div></header>
    <div class="wrap">

    <h2>资源管理（苹果CMS V10 接口）</h2>
    <div class="adultbar">成人内容（🔞）前台显示：
      <a href="?act=toggle_adult" class="switch <?php echo (cfg_get(\'show_adult\')===\'1\'?\'on\':\'off\'); ?>"><?php echo (cfg_get(\'show_adult\')===\'1\'?\'开\':\'关\'); ?></a>
      <span class="hint">一键开关：关闭后前台搜索/选源均不含成人源（源仍在后台，可单独管理）</span>
      '.($adultMsg?'<span class="msg">'.e($adultMsg).'</span>':'').'
    </div>
    <div class="card"><form method="post">
      <input type="hidden" name="save_source" value="1">
      <input type="hidden" name="id" value="'.$eId.'">
      <div><label>名称</label><input type="text" name="name" value="'.$eName.'" required placeholder="如：演示源A"></div>
      <div style="margin-top:10px"><label>接口地址</label><input type="text" name="api" value="'.$eApi.'" required placeholder="https://域名/api.php/provide/vod"></div>
      <div style="margin-top:10px"><label>详情接口(可选)</label><input type="text" name="detail" value="'.$eDetail.'" placeholder="留空则用接口地址取详情"></div>
      <div style="margin-top:10px"><label>启用</label><input type="checkbox" name="enabled" '.$eEn.'></div>
      <div style="margin-top:8px"><label>成人内容</label><input type="checkbox" name="adult" '.$eAd.'></div>
      <button>'.($edit?'保存修改':'添加源').'</button>
      '.($edit?'<a href="admin.php" style="color:#9aa0aa;margin-left:12px">取消</a>':'').'
    </form></div>
    <table><tr><th>ID</th><th>名称</th><th>接口地址</th><th>状态</th><th>类型</th><th>操作</th></tr>'.$rows.'</table>

    <h2>导入 txt 源（DecoTV 格式）</h2>
    <div class="card"><form method="post" enctype="multipart/form-data">
      <input type="hidden" name="import_txt" value="1">
      <div style="font-size:13px;color:#cfd3da;line-height:1.6">支持 DecoTV / LunaTV 配置订阅的 Base58 .txt，也支持已解码的 JSON。解析后按源 key 合并，已存在的同名 key 会更新而不会重复。<br>格式：<code style="color:#9aa0aa">{"api_site":{"key":{"name":"...","api":"https://.../api.php/provide/vod","detail?":"...","is_adult?":false}}}</code></div>
      <div style="margin-top:12px"><label style="width:auto;display:block">订阅链接（URL）</label>
        <input type="text" name="txt_url" placeholder="https://.../jingjian.txt  （直接抓取整份 DecoTV 订阅 txt）" style="width:100%"></div>
      <div style="margin-top:10px"><label style="width:auto;display:block">或粘贴 txt / JSON</label>
        <textarea name="txt_text" rows="6" placeholder="在此粘贴 DecoTV 订阅 .txt 内容，或已解码的 JSON"></textarea></div>
      <div style="margin-top:10px"><label style="width:auto;display:block">或上传 .txt 文件</label>
        <input type="file" name="txt_file" accept=".txt,application/json,text/plain"></div>
      <div style="margin-top:10px"><label style="width:auto;display:inline-block;color:#ff9aa2"><input type="checkbox" name="adult_all" style="width:auto;vertical-align:middle"> 🔞 导入为成人资源（强制标记全部为成人）</label></div>
      <div style="margin-top:14px">
        <button>导入</button>
        <a href="?act=restore_default" style="color:#5aa9ff;margin-left:16px" onclick="return confirm(\'恢复内置 jingjian.txt 默认源？同名 key 会更新，不会重复\')">恢复内置默认源</a>
        '.($importMsg?'<span class="msg">'.e($importMsg).'</span>':'').'
        '.($restoredMsg?'<span class="msg">'.e($restoredMsg).'</span>':'').'
      </div>
    </form></div>

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
