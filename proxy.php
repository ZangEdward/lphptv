<?php
/**
 * proxy.php - HLS/TS 代理
 * 作用：解决第三方 m3u8/ts 的跨域(CORS)问题，并改写 m3u8 清单里的分片地址使其也走本代理。
 * 鉴权：必须带正确的 ?t=token（安装时生成），避免被当作开放代理（SSRF 风险）。
 */
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/util.php';

header('X-Content-Type-Options: nosniff');

// 1) token 鉴权
$token = $_GET['t'] ?? '';
if ($token === '' || !hash_equals((string)cfg_get('proxy_token', ''), (string)$token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

// 1.5) 同源校验：token 对访客可见，故额外要求请求来自本站，防止被第三方站点当开放代理
if (!same_site_referer()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

// 2) 目标 URL 安全校验
$u = $_GET['u'] ?? '';
if ($u === '' || !is_safe_url($u)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'bad url';
    exit;
}

// 3) 拉取（支持 Range 透传，便于播放器拖拽）
$ch = curl_init($u);
$reqHeaders = ['User-Agent: ' . UA];
if (isset($_SERVER['HTTP_RANGE'])) $reqHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERAGENT => UA,
    CURLOPT_HTTPHEADER => $reqHeaders,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'upstream error';
    exit;
}

// 4) m3u8：重写清单；其它（ts/mp4）：原样转发
$isM3u8 = (stripos($ctype, 'mpegurl') !== false)
        || (stripos($ctype, 'x-m3u8') !== false)
        || preg_match('/\.m3u8(\?|$)/i', $u);

if ($isM3u8) {
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Cache-Control: no-cache');
    echo rewrite_m3u8($body, $u);
    exit;
}

header('Content-Type: ' . ($ctype ?: 'application/octet-stream'));
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');
echo $body;
exit;

/**
 * 同源校验：允许无 Referer（兼容部分浏览器），但一旦带 Referer/Origin 必须同源
 */
function same_site_referer() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($host)) return true;
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        return parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) === $host;
    }
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $rh = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        return $rh === $host;
    }
    return true;
}

/**
 * 重写 m3u8：把分片/子列表/KEY/MAP 的地址都改为走本代理
 */
function rewrite_m3u8($body, $base) {
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $out = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') { $out[] = $line; continue; }
        if (strpos($trim, '#') === 0) {
            if (preg_match('/URI="([^"]+)"/i', $trim, $m)) {
                $new = proxy_url(resolve_url($base, $m[1]));
                $trim = str_replace($m[1], $new, $trim);
            }
            $out[] = $trim;
            continue;
        }
        // 资源行（分片或子播放列表）
        $abs = resolve_url($base, $trim);
        $out[] = proxy_url($abs);
    }
    return implode("\n", $out);
}
