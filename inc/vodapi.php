<?php
/**
 * vodapi.php - 苹果CMS V10 资源接口客户端
 * 采集流程完全参照 LibreSpark/LibreTV（js/api.js）：
 *   - 搜索 URL： {api}?ac=videolist&wd=<encodeURIComponent(q)>&pg=N  （PHP 用 rawurlencode 等价 encodeURIComponent）
 *   - 详情 URL： {api}?ac=videolist&ids=<id>
 *   - 请求头：   User-Agent(Chrome) + Accept: application/json
 *   - 单源超时： 8000ms；多源并发（curl_multi）；单源失败不影响其它源（返回空）
 *   - 去重：     按「源ID_视频ID」去重（与 LibreTV 一致：不同源的同一影片分别保留，便于切换源）
 *   - 排序：     先按名称、再按来源（与 LibreTV localeCompare 一致）
 * 播放地址解析（与 LibreTV 一致）：
 *   vod_play_from = "源1$$$源2"; vod_play_url = "集名$url#集名$url$$$源2的集..."
 *   按 $$$ 分组、# 分集、$ 分隔「集名$地址」，返回多源可切换结构。
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

    $qe = rawurlencode($q);          // 等价于 JS encodeURIComponent
    $pg = (int)$page;
    $reqs = [];
    $srcMap = [];
    foreach ($sources as $s) {
        $api = rtrim($s['api'], '/');
        if ($q === '' || $q === null) {
            $url = $api . '?ac=videolist&pg=' . $pg; // 最新
        } else {
            $url = $api . '?ac=videolist&wd=' . $qe . '&pg=' . $pg;
        }
        $key = 's' . $s['id'];
        $reqs[$key] = $url;
        $srcMap[$key] = $s;
    }

    $timeout = (int)cfg_get('search_timeout', 8);  // 与 LibreTV AGGREGATED_SEARCH_CONFIG.timeout=8000 一致
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
            // 去重键 = 源 + 视频ID（与 LibreTV 一致：不同源的同一影片分别保留）
            $dk = $s['id'] . '_' . $item['id'];
            if (isset($seen[$dk])) continue;
            $seen[$dk] = true;
            $results[] = $item;
        }
    }

    // 排序：先按名称、再按来源（与 LibreTV 一致）
    usort($results, function ($a, $b) {
        $c = strcmp($a['name'], $b['name']);
        return $c !== 0 ? $c : strcmp($a['source_name'], $b['source_name']);
    });

    // 结果上限：49 源全开时体量很大，给个上限防止响应过大（LibreTV 有 maxResults 同理）
    $max = (int)cfg_get('max_results', 300);
    if ($max > 0 && count($results) > $max) $results = array_slice($results, 0, $max);

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
    $url = $api . '?ac=videolist&ids=' . rawurlencode($vodId);  // 与 LibreTV 一致（无 &pg）
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
