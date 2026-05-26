<?php
/**
 * 灯塔DNS拦截响应平台 - 到期域名入口
 * 当用户直接访问 /expired 时加载此文件
 */

define('BASEPATH', dirname(__DIR__));

// 安装检测
$installLock = BASEPATH . '/storage/install.lock';
if (!file_exists($installLock)) {
    header('Location: /install.php');
    exit;
}

// 加载依赖
require_once BASEPATH . '/includes/functions.php';
initDebug();

// 启动会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 设置时区
date_default_timezone_set(config('site.timezone', 'Asia/Shanghai'));

// 获取当前请求的域名（M-06: 使用validateHost进行格式验证）
$host = validateHost();
if (empty($host)) {
    http_response_code(400);
    die('Bad Request');
}

// 查询域名信息
$domain = null;
try {
    $domain = db()->queryOne(
        "SELECT * FROM domains WHERE domain_name = ? AND status = 'active' AND type = 'expired'",
        [$host]
    );
} catch (\Throwable $e) {
    error_log('[expired.php] 数据库查询失败: ' . $e->getMessage());
}

// 加载过期页面模板
include BASEPATH . '/public/expired_template.php';
