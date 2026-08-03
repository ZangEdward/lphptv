<?php
/**
 * index.php - 入口与路由
 * 页面路由（输出 HTML 壳，前端 SPA 渲染）： home / detail
 * API 路由（返回 JSON）： api_search / api_detail / config / sources / fav
 */
require_once __DIR__ . '/inc/bootstrap.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}

$r = $_GET['r'] ?? '';

// ---------- API 路由 ----------
if ($r === 'api_search') {
    $q = trim($_GET['q'] ?? '');
    $source = $_GET['source'] ?? 'all';
    $page = max(1, (int)($_GET['page'] ?? 1));
    json_out(vod_search($q, $source, $page));
}

if ($r === 'api_detail') {
    $s = (int)($_GET['s'] ?? 0);
    $id = $_GET['id'] ?? '';
    if (!$s || $id === '') json_out(['error' => '参数缺失'], 400);
    json_out(vod_detail($s, $id));
}

if ($r === 'sources') {
    $list = array_map(function($x) { return ['id' => $x['id'], 'name' => $x['name']]; }, frontend_sources());
    json_out(['sources' => $list]);
}

if ($r === 'config') {
    json_out([
        'site_name'      => site_name(),
        'proxy_token'    => proxy_token(),
        'search_timeout' => (int)cfg_get('search_timeout', 8),
        'cache_ttl'      => (int)cfg_get('cache_ttl', 1800),
        'is_admin'       => is_admin(),
    ]);
}

if ($r === 'fav') {
    $act = $_GET['act'] ?? 'list';
    if ($act === 'list') {
        json_out(['list' => fav_list()]);
    }
    if ($act === 'add') {
        $d = json_decode(file_get_contents('php://input'), true);
        if ($d) fav_add($d['vod_id'] ?? '', $d['source'] ?? '', $d['name'] ?? '', $d['pic'] ?? '', $d);
        json_out(['ok' => true]);
    }
    if ($act === 'remove') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id) fav_remove($id);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false], 400);
}

// ---------- 页面（单页应用壳） ----------
$cdn = 'https://cdn.jsdelivr.net/npm';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e(site_name()); ?></title>
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand" onclick="location.href='index.php'"><?php echo e(site_name()); ?></div>
  <div class="search-wrap">
    <input id="search" type="search" placeholder="搜索影视名称…" autocomplete="off">
    <select id="sourceSel"></select>
  </div>
  <nav class="nav">
    <a href="index.php?r=home">首页</a>
    <a href="javascript:showFav()">收藏</a>
    <a href="admin.php">后台</a>
  </nav>
</header>

<main id="app">
  <section id="home" class="view">
    <div class="hero">
      <h1>在线影视聚合搜索</h1>
      <p>多源实时检索 · 一键播放</p>
    </div>
    <div id="results" class="grid"></div>
    <div id="loading" class="loading" style="display:none">加载中…</div>
    <div id="pager" class="pager"></div>
  </section>

  <section id="detail" class="view" style="display:none"></section>
  <section id="favView" class="view" style="display:none">
    <h2>我的收藏</h2>
    <div id="favList" class="grid"></div>
  </section>
</main>

<footer class="foot">纯 PHP 影视聚合 · 数据来自第三方资源接口 · 仅供学习</footer>

<script src="<?php echo $cdn; ?>/hls.js@1.5.13/dist/hls.min.js"></script>
<script src="<?php echo $cdn; ?>/dplayer@1.27.1/dist/DPlayer.min.js"></script>
<script src="static/app.js"></script>
</body>
</html>
