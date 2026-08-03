<?php
/**
 * sources_txt.php - 解析「源 txt」（与 DecoTV 完全一致的格式）
 *
 * 设计目标：让 LPHPTV 与 DecoTV / LunaTV 共用同一套"订阅 txt"格式，
 * 用户从 DecoTV 配置订阅里拿到的 .txt 可以直接粘贴进 LPHPTV。
 *
 * 格式说明（对照 DecoTV src/app/api/admin/config_subscription/fetch/route.ts）：
 *   1. txt 内容是 Base58（比特币字母表）编码的 JSON 串；
 *   2. Base58 解码后得到 JSON：
 *        {
 *          "api_site": {
 *            "<key>": { "key?": "...", "name": "...", "api": "https://.../api.php/provide/vod", "detail?": "https://...", "is_adult?": false },
 *            ...
 *          },
 *          "custom_category"?": [...],
 *          "lives"?": { ... }
 *        }
 *   3. 同时为兼容人工编辑，也支持直接粘贴「已解码的 JSON 文本」——自动探测。
 *
 * 注意：LPHPTV 用 `api` 同时承担搜索(?ac=videolist&wd=) 与详情(?ac=videolist&ids=)
 * 请求（即标准苹果CMS V10 提供接口），`detail` 仅作记录保留，不直接用于取详情，
 * 以保证各类源的详情链路稳定可用。
 */

// 内置默认源文件（与 DecoTV 订阅同源：jingjian.txt）
// 用 __DIR__ 计算，避免依赖 bootstrap 中定义的 ROOT（install.php 不含 bootstrap）
if (!defined('DEFAULT_SOURCES_TXT')) {
    define('DEFAULT_SOURCES_TXT', dirname(__DIR__) . '/sources/jingjian.txt');
}

/**
 * Base58 解码（比特币字母表：123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz）
 * 优先使用 bcmath；其次 gmp；都不存在则返回 false（此时调用方会当作纯 JSON 处理）。
 * 若输入含非 Base58 字符，直接返回 false（说明它不是 base58 串）。
 */
function base58_decode_php($input) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $input = trim((string)$input);
    if ($input === '') return '';

    // 含非 Base58 字符 → 不是 base58 串
    if (preg_match('/[^1-9A-HJ-NP-Za-km-z]/', $input)) {
        return false;
    }

    $base = 58;

    if (function_exists('bcadd')) {
        $num = '0';
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $idx = strpos($alphabet, $input[$i]);
            if ($idx === false) return false;
            $num = bcadd(bcmul($num, (string)$base), (string)$idx);
        }
        $bytes = '';
        while (bccomp($num, '0', 0) > 0) {
            $mod = bcmod($num, '256');
            $bytes = chr((int)$mod) . $bytes;
            $num = bcdiv($num, '256', 0);
        }
        for ($i = 0; $i < $len && $input[$i] === '1'; $i++) {
            $bytes = "\x00" . $bytes;
        }
        return $bytes;
    }

    if (function_exists('gmp_init')) {
        $num = gmp_init(0);
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $idx = strpos($alphabet, $input[$i]);
            if ($idx === false) return false;
            $num = gmp_add(gmp_mul($num, $base), $idx);
        }
        $bytes = '';
        while (gmp_cmp($num, 0) > 0) {
            list($num, $mod) = gmp_div_qr($num, 256);
            $bytes = chr((int)gmp_strval($mod)) . $bytes;
        }
        for ($i = 0; $i < $len && $input[$i] === '1'; $i++) {
            $bytes = "\x00" . $bytes;
        }
        return $bytes;
    }

    return false;
}

/**
 * 解析源 txt → 归一化结构
 * @return array{ sources: array[], custom_category: array, lives: array }
 */
function parse_sources_txt($text) {
    $text = trim((string)$text);
    if ($text === '') return ['sources' => [], 'custom_category' => [], 'lives' => []];

    $jsonStr = null;

    // 1) 尝试 Base58 解码
    $decoded = base58_decode_php($text);
    if ($decoded !== false && $decoded !== '') {
        $trim = ltrim($decoded);
        if (isset($trim[0]) && ($trim[0] === '{' || $trim[0] === '[')) {
            $jsonStr = $decoded;
        } elseif (mb_check_encoding($decoded, 'UTF-8') && strpos($decoded, 'api_site') !== false) {
            $jsonStr = $decoded;
        }
    }

    // 2) 退化：原样当作 JSON 文本
    if ($jsonStr === null) {
        $jsonStr = $text;
    }

    $obj = json_decode($jsonStr, true);
    if (!is_array($obj)) {
        return ['sources' => [], 'custom_category' => [], 'lives' => []];
    }

    $sources = [];
    $apiSite = $obj['api_site'] ?? [];
    if (is_array($apiSite)) {
        foreach ($apiSite as $key => $site) {
            if (!is_array($site)) continue;
            $api = trim((string)($site['api'] ?? ''));
            if ($api === '') continue; // api 是必填
            $sources[] = [
                'key'      => trim((string)($site['key'] ?? $key)),
                'name'     => trim((string)($site['name'] ?? $key)),
                'api'      => $api,
                'detail'   => trim((string)($site['detail'] ?? '')),
                'is_adult' => !empty($site['is_adult']) ? 1 : 0,
            ];
        }
    }

    return [
        'sources'        => $sources,
        'custom_category' => $obj['custom_category'] ?? [],
        'lives'          => $obj['lives'] ?? [],
    ];
}

/**
 * 从内置 jingjian.txt 导入默认源（按 key 合并，不重复）
 * @return int 导入/更新的源数量
 */
function import_default_sources($txtPath = DEFAULT_SOURCES_TXT) {
    if (!is_file($txtPath)) return 0;
    $txt = @file_get_contents($txtPath);
    if ($txt === false || $txt === '') return 0;
    $parsed = parse_sources_txt($txt);
    $sort = 0;
    $n = 0;
    foreach ($parsed['sources'] as $s) {
        // 默认全启用；保留 is_adult 标记
        source_upsert_by_key($s['key'], $s['name'], $s['api'], 1, $s['is_adult'], $s['detail'], $sort++);
        $n++;
    }
    return $n;
}

/**
 * 站点就绪后保证至少内置默认源一次（仅首次、且源表为空时）
 */
function ensure_default_sources() {
    if (!is_installed()) return;
    if (cfg_get('seeded_default') === '1') return;
    // 首次就绪：按 key 合并灌入内置默认源（jingjian.txt），
    // 即便用户已手动添加其它源也不会重复（key 唯一），以满足「默认内置我的 txt」。
    import_default_sources();
    cfg_set('seeded_default', '1');
}
