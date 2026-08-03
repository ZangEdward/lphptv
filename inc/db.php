<?php
/**
 * db.php - SQLite 数据层（零配置）
 * 表：config(站点配置) / sources(资源源) / cache(搜索缓存) / favorites(收藏)
 */

function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . '/app.db';
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // 关闭外网写同步，提升并发稳定性
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
    init_db($pdo);
    return $pdo;
}

function init_db($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS config (
        k TEXT PRIMARY KEY,
        v TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        api TEXT NOT NULL,
        enabled INTEGER DEFAULT 1,
        adult INTEGER DEFAULT 0,
        sort INTEGER DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cache (
        k TEXT PRIMARY KEY,
        v TEXT,
        expire INTEGER
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vod_id TEXT,
        source TEXT,
        name TEXT,
        pic TEXT,
        data TEXT,
        added INTEGER
    )");
}

function cfg_get($k, $default = null) {
    $pdo = db();
    $st = $pdo->prepare('SELECT v FROM config WHERE k=?');
    $st->execute([$k]);
    $row = $st->fetch();
    return $row ? $row['v'] : $default;
}

function cfg_set($k, $v) {
    $pdo = db();
    $pdo->prepare('INSERT INTO config(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=?')
        ->execute([$k, $v, $v]);
}

function cfg_get_all() {
    $pdo = db();
    $rows = $pdo->query('SELECT k,v FROM config')->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['k']] = $r['v'];
    return $out;
}

/* ---------- sources ---------- */
function sources_all($enabledOnly = false) {
    $pdo = db();
    $sql = 'SELECT * FROM sources';
    if ($enabledOnly) $sql .= ' WHERE enabled=1';
    $sql .= ' ORDER BY sort ASC, id ASC';
    return $pdo->query($sql)->fetchAll();
}

function source_get($id) {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM sources WHERE id=?');
    $st->execute([$id]);
    return $st->fetch();
}

function source_add($name, $api, $enabled = 1, $adult = 0, $sort = 0) {
    $pdo = db();
    $pdo->prepare('INSERT INTO sources(name,api,enabled,adult,sort) VALUES(?,?,?,?,?)')
        ->execute([$name, $api, $enabled, $adult, $sort]);
    return $pdo->lastInsertId();
}

function source_update($id, $fields) {
    $pdo = db();
    $sets = []; $vals = [];
    foreach (['name','api','enabled','adult','sort'] as $f) {
        if (array_key_exists($f, $fields)) { $sets[] = "$f=?"; $vals[] = $fields[$f]; }
    }
    if (!$sets) return;
    $vals[] = $id;
    $pdo->prepare('UPDATE sources SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
}

function source_delete($id) {
    db()->prepare('DELETE FROM sources WHERE id=?')->execute([$id]);
}

/* ---------- cache ---------- */
function cache_get($k) {
    $pdo = db();
    $st = $pdo->prepare('SELECT v,expire FROM cache WHERE k=?');
    $st->execute([$k]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($row['expire'] && $row['expire'] < time()) {
        $pdo->prepare('DELETE FROM cache WHERE k=?')->execute([$k]);
        return null;
    }
    return json_decode($row['v'], true);
}

function cache_set($k, $v, $ttl = 3600) {
    $pdo = db();
    $expire = $ttl > 0 ? time() + $ttl : 0;
    $val = json_encode($v, JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO cache(k,v,expire) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v=?,expire=?')
        ->execute([$k, $val, $expire, $val, $expire]);
}

function cache_clear() {
    db()->exec('DELETE FROM cache');
}

/* ---------- favorites ---------- */
function fav_add($vod_id, $source, $name, $pic, $data) {
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM favorites WHERE vod_id=? AND source=?');
    $st->execute([$vod_id, $source]);
    if ($st->fetch()) return;
    $pdo->prepare('INSERT INTO favorites(vod_id,source,name,pic,data,added) VALUES(?,?,?,?,?,?)')
        ->execute([$vod_id, $source, $name, $pic, json_encode($data, JSON_UNESCAPED_UNICODE), time()]);
}

function fav_list() {
    return db()->query('SELECT * FROM favorites ORDER BY added DESC')->fetchAll();
}

function fav_remove($id) {
    db()->prepare('DELETE FROM favorites WHERE id=?')->execute([$id]);
}

function is_installed() {
    return cfg_get('installed') === '1';
}
