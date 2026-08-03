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
 * 判断是否成人内容（学习 LibreTV「黄色内容过滤」：按类型/名称/备注关键词识别）。
 * 即便源未显式标记 adult，也能在结果层面过滤伦理片/福利/写真等成人视频。
 */
function is_adult_item($item) {
    $type = strtolower((string)($item['type'] ?? ''));
    $name = strtolower((string)($item['name'] ?? ''));
    $remarks = strtolower((string)($item['remarks'] ?? ''));
    $hay = $type . ' ' . $name . ' ' . $remarks;

    // 1) 中文 / 多字隐晦词：子串匹配（中文无单词边界，子串即可）
    static $cn = [
        '伦理','倫理','福利','成人','写真','寫真','里番','黄片','黃片','色情','情色','性爱','性愛','激情','禁片',
        '限制级','限制級','三级','三級','18禁','18+','r18',
        '无码','無碼','有码','有碼','步兵','骑兵',
        '淫','调教','調教','内射','內射','中出','颜射','顏射','口爆','射精','自慰','潮吹','高潮','抽插','插进',
        '巨乳','美乳','爆乳','熟女','人妻','痴女','痴汉','痴漢','紧缚','緊縛','浣肠','浣腸','露出','裸体','全裸',
        '阴部','陰部','性器','私处','私處','女优','女優','援交','卖淫','賣淫','风俗','風俗','偷拍','偷窺','窥视',
        '肉便器','性奴','骚货','騷貨','色诱','诱拐','监禁','監禁','轮奸','輪姦','强奸','強姦','迷奸','迷姦',
        '乱伦','亂倫','近亲','近親','兽交','肛交','口交','群交','乱交','足交','乳交','性虐待','性虐','欲女',
    ];
    foreach ($cn as $k) {
        if (strpos($hay, $k) !== false) return true;
    }

    // 2) 拉丁短词：必须单词边界，避免 Avatar / Sussex / savage 等误判
    static $en = ['porn','xxx','hentai','adult','sex','jav','av'];
    foreach ($en as $k) {
        if (preg_match('/\b' . $k . '\b/', $hay)) return true;
    }

    // 3) 日本 AV 制品编号：2~4 个字母 + 可选连字符 + 2~4 位数字（如 ADN-602 / IPX-748 / CAWD-740 / DA83 / DB009）
    //    排除 19xx/20xx 年份（如 NBA-2024 / TV-2024 等非成人编号），避免误杀正常影片
    //    $hay 已转小写，故字母部分用 [a-z]（等价不区分大小写）
    if (preg_match('/\b[a-z]{2,4}-?(?!19|20)\d{2,4}\b/', $hay)) return true;

    return false;
}

/**
 * 聚合搜索。sourceId='all' 时并发查询所有启用源。
 */
function vod_search($q, $sourceId = 'all', $page = 1, $useCache = true) {
    $sources = frontend_sources();
    if ($sourceId !== 'all') {
        $src = source_get((int)$sourceId);
        // 成人开关关闭且指定源为成人源时，不返回（防止直链绕过下拉）
        if ($src && (cfg_get('show_adult') === '1' || empty($src['adult']))) {
            $sources = [$src];
        } else {
            $sources = [];
        }
    }
    if (!$sources) return ['results' => [], 'sources' => [], 'error' => '没有可用的资源源'];

    $cacheKey = 'search:' . md5($sourceId . '|' . $q . '|' . $page . '|' . (cfg_get('show_adult') === '1' ? '1' : '0'));
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

    $grouped = [];     // 归一化剧名 => 代表卡片（含 sources 多源列表）
    $pairSeen = [];    // 源+视频ID 去重（防单源内重复计入）
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
            $pair = $s['id'] . '_' . $item['id'];
            if (isset($pairSeen[$pair])) continue;
            $pairSeen[$pair] = true;
            // 按归一化剧名分组：同剧来自不同源只出一卡，源列表留在 sources 里供前端切换
            $tk = normalize_title($item['name']);
            if (!isset($grouped[$tk])) {
                $item['sources'] = [['source' => $s['id'], 'id' => $item['id'], 'source_name' => $s['name']]];
                $grouped[$tk] = $item;
            } else {
                $grouped[$tk]['sources'][] = ['source' => $s['id'], 'id' => $item['id'], 'source_name' => $s['name']];
            }
        }
    }
    $results = array_values($grouped);

    // 成人开关关闭时，在结果层面过滤成人视频（与 LibreTV 黄色内容过滤一致）
    if (cfg_get('show_adult') !== '1') {
        $results = array_values(array_filter($results, function ($it) {
            return !is_adult_item($it);
        }));
    }

    // 排序：按名称（与 LibreTV 一致；同剧已合并为一张卡，无需来源次级排序）
    usort($results, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
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
    $cacheKey = 'detail:' . md5($sourceId . '|' . $vodId . '|' . (cfg_get('show_adult') === '1' ? '1' : '0'));
    if ($useCache) {
        $cached = cache_get($cacheKey);
        if ($cached !== null) return $cached;
    }
    $api = rtrim($src['api'], '/');
    $url = $api . '?ac=videolist&ids=' . rawurlencode($vodId);  // 与 LibreTV 一致（无 &pg）
    $r = http_get($url, 10);   // 与 LibreTV 详情超时 10000ms 一致
    if ($r['http_code'] != 200 || $r['error']) return ['error' => '请求失败: ' . $r['error']];
    $parsed = parse_vod_response($r['body']);
    if ($parsed['code'] != 1 || empty($parsed['list'])) return ['error' => '未找到详情'];
    $raw = $parsed['list'][0];
    $item = normalize_item($raw, $src['id'], $src['name']);
    $item['play'] = parse_play($raw['vod_play_from'] ?? '', $raw['vod_play_url'] ?? '');

    // 成人开关关闭时，整源为成人或单条为成人内容则不允许打开
    if (cfg_get('show_adult') !== '1' && (!empty($src['adult']) || is_adult_item($item))) {
        return ['error' => '成人内容已隐藏（后台开启「成人内容」开关后可观看）'];
    }

    // LibreTV 兜底：若 vod_play_url 无可用地址，但 vod_content 含裸 .m3u8 直链，则提取
    // （LibreTV api.js: episodes.length===0 && vod_content → M3U8_PATTERN 正则提取）
    $hasAny = false;
    foreach ($item['play'] as $grp) { if (!empty($grp['episodes'])) { $hasAny = true; break; } }
    if (!$hasAny) {
        $content = (string)($raw['vod_content'] ?? '');
        if (preg_match_all('/\$(https?:\/\/[^\'"\s]+?\.m3u8)/', $content, $m)) {
            $eps = [];
            foreach ($m[1] as $u) { $eps[] = ['name' => '', 'url' => $u]; }
            if ($eps) $item['play'] = [['name' => '默认', 'episodes' => $eps]];
        }
    }

    cache_set($cacheKey, $item, (int)cfg_get('detail_ttl', 7200));
    return $item;
}

/**
 * 自动识别成人源：并发探测每个启用源的「最新列表」，若其返回内容含成人视频（is_adult_item），
 * 则把该源标记为 adult=1。这样「源」层面开关（frontend_sources）才能真正按源过滤，
 * 而不只依赖结果层面关键词过滤。返回被标记的源数量。
 */
function detect_adult_sources() {
    $sources = sources_all(true);
    if (!$sources) return 0;
    $reqs = []; $map = [];
    foreach ($sources as $s) {
        $api = rtrim($s['api'], '/');
        $reqs['s' . $s['id']] = $api . '?ac=videolist&pg=1';
        $map['s' . $s['id']] = $s;
    }
    $timeout = (int)cfg_get('search_timeout', 8);
    $resps = multi_get($reqs, $timeout);
    $marked = 0;
    foreach ($resps as $key => $r) {
        $s = $map[$key];
        if (empty($s) || $s['adult']) continue; // 已标记跳过
        if ($r['http_code'] != 200 || !empty($r['error'])) continue;
        $parsed = parse_vod_response($r['body']);
        if (empty($parsed['list'])) continue;
        foreach (array_slice($parsed['list'], 0, 30) as $raw) {
            $it = normalize_item($raw, $s['id'], $s['name']);
            if ($it && is_adult_item($it)) {
                source_update($s['id'], ['adult' => 1]);
                $marked++;
                break;
            }
        }
    }
    return $marked;
}

/**
 * 归一化剧名用于跨源合并：去掉年份/季集/分辨率/更新标记等噪点，小写去空格。
 * 让「同一部剧在不同源里的不同标题写法」尽量落到同一个键，从而首页只出一卡。
 */
function normalize_title($name) {
    $n = (string)($name ?? '');
    $n = preg_replace('/[\[\]【】()（）\s]/u', '', $n);
    $n = preg_replace('/[19|20]\d{2}/', '', $n);                              // 年份
    $n = preg_replace('/(第)?\d+(\.\d+)?(季|部|集|话|期)/u', '', $n);          // 第N季/部/集
    $n = preg_replace('/(HD|BD|4K|1080P|720P|全\d+集|更新至\d+集|完结)/i', '', $n); // 画质/集数标记
    $n = preg_replace('/[^\p{Han}A-Za-z0-9]/u', '', $n);                     // 仅留中英文数字
    return strtolower($n);
}

function normalize_item($raw, $sourceId, $sourceName) {
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
