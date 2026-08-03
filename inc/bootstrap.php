<?php
/**
 * bootstrap.php - 公共引导：常量、会话、自动加载、鉴权辅助
 */
if (session_status() === PHP_SESSION_NONE) session_start();

define('ROOT', dirname(__DIR__));
define('INC', ROOT . '/inc');
define('DATA_DIR', ROOT . '/data');
define('DB_FILE', DATA_DIR . '/app.db');

require_once INC . '/db.php';
require_once INC . '/util.php';
require_once INC . '/vodapi.php';

// 站点基本信息
function site_name() { return cfg_get('site_name', 'PHP 影视聚合'); }

// 管理员是否已登录（基于安装时设置的密码）
function is_admin() { return !empty($_SESSION['admin']); }

// 输出 JSON 并结束
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 简单 CSRF/来源校验（可选）
function same_origin() {
    if (empty($_SERVER['HTTP_ORIGIN'])) return true;
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
    return $originHost === $host;
}
