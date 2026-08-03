<?php
/**
 * vodapi.php - 苹果CMS V10 资源接口客户端
 * 搜索/详情请求格式（与 LibreTV 共用同一标准）：
 *   搜索: {api}?ac=videolist&wd=关键词&pg=页码
 *   详情: {api}?ac=videolist&ids=视频ID
 * 播放地址: vod_play_from = "源1$$$源2"; vod_play_url = "集名$url#集名$url$$$..."
 */

/**
 * 聚合搜索。sourceId='all' 时并发查询所有启用源。
 */
function vod_search($q, $sourceId = 'all', $page = 1, $useCache = true) {
    $sources = sources_all(true);
    if ($sourceId !== 'all') {
        $src = source_get((int)$sourceId);
        $sources = $src ? [$src] : [];
    }
    if (!$sources) return ['results' => [], 'sources' => [], 'error' => '没有可用的资源源'];

    $cacheKey = 'search:' . md5($sourceId . '|' . $q . '|' . $page);
    if ($useCache) {
        $cached = cache_get($cacheKey);
        if ($cached !== null) return $cached;
    }

    $reqs = [];
    $srcMap = [];
    foreach ($sources as $s) {
        $api = rtrim($s['api'], '/');
        if ($q === '' || $q === null) {
            $url = $api . '?ac=videolist&pg=' . (int)$page; // 最新
        } else {
            $url = $api . '?ac=videolist&wd=' . urlencode($q) . '&pg=' . (int)$page;
        }
        $key = 's' . $s['id'];
        $reqs[$key] = $url;
        $srcMap[$key] = $s;
    }

    $timeout = (int)cfg_get('search_timeout', 8);
    $resps = multi_get($reqs, $timeout);

    $results = [];
    $seen = [];
    $srcStat = [];
    foreach ($resps as $key => $r) {
        $s = $srcMap[$key];
        if ($r['http_code'] != 200 || $r['error']) { $srcStat[$s['name']] = '请求失败'; continue; }
        $parsed = parse_vod_response($r['body']);
        if ($parsed['code'] != 1 || empty($parsed['list'])) { $srcStat[$s['name']] = '无结果'; continue; }
        $srcStat[$s['name']] = count($parsed['list']) . ' 条';
        foreach ($parsed['list'] as $raw) {
            $item = normalize_item($raw, $s['id'], $s['name']);
            if (!$item) continue;
            $dk = mb_strtolower(trim($item['name']), 'UTF-8');
            if (isset($seen[$dk])) continue; // 同名去重，保留首个源
            $seen[$dk] = true;
            $results[] = $item;
        }
    }

    $out = ['results' => $results, 'sources' => $srcStat, 'error' => ''];
    cache_set($cacheKey, $out, (int)cfg_get('cache_ttl', 1800));
    return $out;
}

/**
 * 详情：返回带播放地址的条目
 */
function vod_detail($sourceId, $vodId, $useCache = true) {
    $src = source_get((int)$sourceId);
    if (!$src) return ['error' => '源不存在'];
    $cacheKey = 'detail:' . md5($sourceId . '|' . $vodId);
    if ($useCache) {
        $cached = cache_get($cacheKey);
        if ($cached !== null) return $cached;
    }
    $api = rtrim($src['api'], '/');
    $url = $api . '?ac=videolist&ids=' . urlencode($vodId) . '&pg=1';
    $r = http_get($url, (int)cfg_get('search_timeout', 8));
    if ($r['http_code'] != 200 || $r['error']) return ['error' => '请求失败: ' . $r['error']];
    $parsed = parse_vod_response($r['body']);
    if ($parsed['code'] != 1 || empty($parsed['list'])) return ['error' => '未找到详情'];
    $raw = $parsed['list'][0];
    $item = normalize_item($raw, $src['id'], $src['name']);
    $item['play'] = parse_play($raw['vod_play_from'] ?? '', $raw['vod_play_url'] ?? '');
    cache_set($cacheKey, $item, (int)cfg_get('detail_ttl', 7200));
    return $item;
}

function normalize_item($raw, $sourceId, $sourceName) {
    if (empty($raw['vod_name'])) return null;
    return [
        'source'      => (int)$sourceId,
        'source_name' => $sourceName,
        'id'          => (string)($raw['vod_id'] ?? ''),
        'name'        => (string)($raw['vod_name'] ?? ''),
        'pic'         => (string)($raw['vod_pic'] ?? ''),
        'remarks'     => (string)($raw['vod_remarks'] ?? ''),
        'type'        => (string)($raw['type_name'] ?? ($raw['vod_class'] ?? '')),
        'year'        => (string)($raw['vod_year'] ?? ''),
        'area'        => (string)($raw['vod_area'] ?? ''),
        'actor'       => (string)($raw['vod_actor'] ?? ''),
        'director'    => (string)($raw['vod_director'] ?? ''),
        'des'         => (string)($raw['vod_des'] ?? ($raw['vod_blurb'] ?? ($raw['vod_content'] ?? ''))),
        'time'        => (string)($raw['vod_time'] ?? ''),
    ];
}

/**
 * 解析播放地址为多源结构
 */
function parse_play($from, $url) {
    $froms = explode('$$$', $from);
    $urls = explode('$$$', $url);
    $n = max(count($froms), count($urls));
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $name = trim($froms[$i] ?? ('源' . ($i + 1)));
        $group = $urls[$i] ?? '';
        $eps = [];
        foreach (explode('#', $group) as $ep) {
            $ep = trim($ep);
            if ($ep === '') continue;
            $pos = strpos($ep, '$');
            if ($pos !== false) {
                $epName = substr($ep, 0, $pos);
                $epUrl = substr($ep, $pos + 1);
            } else {
                $epName = '';
                $epUrl = $ep;
            }
            if ($epUrl !== '') $eps[] = ['name' => $epName, 'url' => $epUrl];
        }
        $out[] = ['name' => $name, 'episodes' => $eps];
    }
    return $out;
}

/**
 * 测试一个源是否可用（采样一次搜索）
 */
function vod_test($api) {
    $api = rtrim($api, '/');
    $r = http_get($api . '?ac=videolist&pg=1', 8);
    if ($r['http_code'] != 200 || $r['error']) return ['ok' => false, 'msg' => 'HTTP ' . $r['http_code'] . ' ' . $r['error']];
    $parsed = parse_vod_response($r['body']);
    if ($parsed['code'] != 1) return ['ok' => false, 'msg' => '接口返回异常: ' . ($parsed['msg'] ?? '?')];
    return ['ok' => true, 'msg' => '正常, 共 ' . ($parsed['total'] ?? count($parsed['list'])) . ' 条数据'];
}
