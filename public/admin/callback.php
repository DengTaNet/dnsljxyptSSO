<?php
/**
 * 灯塔DNS拦截响应平台 - SSO 登录回调处理
 * 处理 Logto 登录回调
 */

use Core\Database;
use Core\SsoAuth;

define('BASEPATH', dirname(__DIR__, 2));

require_once BASEPATH . '/includes/functions.php';
initDebug();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 安装锁检查
if (!file_exists(BASEPATH . '/storage/install.lock')) {
    header('Location: /install.php');
    exit;
}

$db = Database::getInstance();
$config = require BASEPATH . '/config/config.php';
$ssoAuth = new SsoAuth($db, $config);

// 域名白名单检查（复用 SsoAuth 统一逻辑，白名单为空时不限制）
$ssoAuth->checkAdminDomain();

// 检查 SSO 是否启用
if (!$ssoAuth->isEnabled()) {
    // 使用 session 传递错误信息（避免 URL 泄露）
    $_SESSION['sso_error'] = 'SSO 登录未启用';
    header('Location: /admin/login.php');
    exit;
}

// 处理登录回调
$result = $ssoAuth->handleSignInCallback();

if ($result['success']) {
    // 登录成功，跳转到后台首页
    header('Location: /admin/');
    exit;
} else {
    // 登录失败，使用 session 传递错误信息（避免 URL 泄露）
    $_SESSION['sso_error'] = $result['message'] ?? '登录失败';
    header('Location: /admin/login.php');
    exit;
}
