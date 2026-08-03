<?php
/**
 * util.php - 网络请求 / 解析 / 安全辅助
 */

define('UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

/**
 * 单条 GET 请求（curl）
 * 默认带 UA + Accept: application/json，与 LibreTV 的采集请求头一致。
 */
function http_get($url, $timeout = 8, $headers = []) {
    $ch = curl_init($url);
    $all = array_merge(['User-Agent: ' . UA, 'Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => UA,
        CURLOPT_HTTPHEADER => $all,
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['body' => $body === false ? '' : $body, 'http_code' => $code, 'content_type' => $ctype, 'error' => $err];
}

/**
 * 并发 GET（curl_multi）—— 用于聚合搜索同时打多个源
 * @param array $items [key => url]
 * @return array key => ['body','http_code','content_type','error']
 * 默认带 UA + Accept: application/json，与 LibreTV 的采集请求头一致。
 */
function multi_get(array $items, $timeout = 8, $headers = []) {
    $mh = curl_multi_init();
    $handles = [];
    $all = array_merge(['User-Agent: ' . UA, 'Accept: application/json'], $headers);
    foreach ($items as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => UA,
            CURLOPT_HTTPHEADER => $all,
            CURLOPT_ENCODING => '',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
    $out = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $out[$key] = [
            'body' => $body === false ? '' : $body,
            'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'content_type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'error' => curl_error($ch),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * 解析 V10 接口响应：支持 JSON 与 XML 两种格式
 * 返回 ['code'=>, 'list'=>[...]] 或 ['code'=>0,'msg'=>...]
 */
function parse_vod_response($body) {
    $body = trim($body);
    if ($body === '') return ['code' => 0, 'msg' => 'empty'];
    $json = json_decode($body, true);
    if (is_array($json)) {
        $list = isset($json['list']) && is_array($json['list']) ? $json['list'] : [];
        return [
            'code' => isset($json['code']) ? (int)$json['code'] : 1,
            'msg'  => $json['msg'] ?? 'ok',
            'total'=> $json['total'] ?? count($list),
            'pagecount' => $json['pagecount'] ?? 1,
            'list' => $list,
        ];
    }
    // 尝试 XML
    $xml = @simplexml_load_string($body);
    if ($xml !== false) {
        $list = [];
        if (isset($xml->list->video)) {
            foreach ($xml->list->video as $v) {
                $list[] = xml_video_to_array($v);
            }
        } elseif (isset($xml->video)) {
            foreach ($xml->video as $v) $list[] = xml_video_to_array($v);
        }
        return ['code' => 1, 'msg' => 'xml', 'total' => count($list), 'pagecount' => 1, 'list' => $list];
    }
    return ['code' => 0, 'msg' => 'parse failed'];
}

function xml_video_to_array($v) {
    $a = [];
    foreach (['vod_id','vod_name','vod_pic','vod_remarks','type_name','vod_year','vod_area','vod_actor','vod_director','vod_des','vod_play_from','vod_play_url','vod_time'] as $f) {
        $a[$f] = isset($v->$f) ? (string)$v->$f : '';
    }
    return $a;
}

function is_public_ip($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
}

/**
 * 代理鉴权 token（安装时生成，存于 config 表）
 */
function proxy_token() {
    return cfg_get('proxy_token', '');
}

/**
 * 构造走本站的代理地址（供前端播放器使用）
 */
function proxy_url($url) {
    $token = proxy_token();
    return '/proxy.php?u=' . urlencode($url) . '&t=' . urlencode($token);
}

/**
 * SSRF 防护：仅允许 http/https，屏蔽内网/回环地址（带本请求内 DNS 缓存）
 */
function is_safe_url($url) {
    static $cache = [];
    if (isset($cache[$url])) return $cache[$url];

    $p = parse_url($url);
    if (!isset($p['scheme']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        return $cache[$url] = false;
    }
    $host = strtolower($p['host'] ?? '');
    if ($host === '') return $cache[$url] = false;

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return $cache[$url] = is_public_ip($host);
    }
    $ip = gethostbyname($host);
    if ($ip === $host || $ip === '') return $cache[$url] = false;
    return $cache[$url] = is_public_ip($ip);
}

/**
 * 解析相对地址为绝对地址（用于 m3u8 重写）
 */
function resolve_url($base, $rel) {
    if (preg_match('#^[a-z]+://#i', $rel)) return $rel;
    $bu = parse_url($base);
    $scheme = $bu['scheme'] ?? 'http';
    $host = $bu['host'] ?? '';
    $port = isset($bu['port']) ? ':' . $bu['port'] : '';
    $path = $bu['path'] ?? '/';
    if (strpos($rel, '//') === 0) return $scheme . ':' . $rel;
    if ($rel[0] === '/') return "$scheme://$host$port$rel";
    $dir = dirname($path);
    if ($dir === '/' || $dir === '\\') $dir = '';
    return "$scheme://$host$port" . rtrim($dir, '/') . '/' . ltrim($rel, '/');
}

/**
 * 安全过滤输出
 */
function e($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
