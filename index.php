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
$site = e(site_name());
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $site; ?></title>
<link rel="stylesheet" href="static/style.css">
</head>
<body>

<header class="topbar">
  <div class="brand gradient-text" onclick="location.href='index.php'"><?php echo $site; ?></div>
  <nav class="nav">
    <a href="javascript:goHome()">首页</a>
    <a href="javascript:showFav()">收藏</a>
    <a href="admin.php">后台</a>
  </nav>
</header>

<main id="app">
  <section id="home" class="view">
    <div class="hero">
      <h1 class="gradient-text"><?php echo $site; ?></h1>
      <p>自由观影，畅享精彩 · 多源实时检索，一键播放</p>
    </div>

    <div class="search-center">
      <div class="search-bar">
        <input id="search" type="search" placeholder="搜索你喜欢的影视…" autocomplete="off" oninput="onSearchInput(event)">
        <button id="clearBtn" class="clear-btn" type="button" onclick="clearSearch()" aria-label="清空" style="display:none">✕</button>
        <button class="search-btn" type="button" onclick="submitSearch()">搜索</button>
      </div>
      <div id="recent" class="recent" aria-label="最近搜索"></div>
    </div>

    <div id="sourcePills" class="pills"></div>

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

<footer class="foot">
  <div class="foot-inner">
    <div class="gradient-text foot-brand"><?php echo $site; ?></div>
    <p class="disclaimer">免责声明：本站仅为视频搜索工具，不存储、上传或分发任何视频内容。所有视频均来自第三方 API 接口，如有侵权请联系相关内容提供方。请低调自用，勿公开传播。</p>
  </div>
</footer>

<script src="<?php echo $cdn; ?>/hls.js@1.5.13/dist/hls.min.js"></script>
<script src="<?php echo $cdn; ?>/dplayer@1.27.1/dist/DPlayer.min.js"></script>
<script src="static/app.js"></script>
</body>
</html>
