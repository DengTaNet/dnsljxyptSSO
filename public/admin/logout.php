<?php
/**
 * 灯塔DNS拦截响应平台 - 退出登录
 * 清除本地会话，支持 SSO 登出
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

// 获取配置
$config = require BASEPATH . '/config/config.php';
$db = Database::getInstance();
$ssoAuth = new SsoAuth($db, $config);

// 域名白名单检查（复用 SsoAuth 统一逻辑，白名单为空时不限制）
$ssoAuth->checkAdminDomain();

// 检查是否是 SSO 会话
$sessionConfig = $config['session'] ?? ['cookie_name' => 'lighthouse_session'];
$token = $_COOKIE[$sessionConfig['cookie_name'] ?? 'lighthouse_session'] ?? null;
$isSsoSession = false;

if ($token) {
    $session = $db->queryOne(
        "SELECT sso_session FROM sessions WHERE session_token = ?",
        [$token]
    );
    $isSsoSession = !empty($session['sso_session']);
}

// 清除本地认证
$ssoAuth = new SsoAuth($db, $config);
$ssoAuth->logout();

// 清除所有 Session 数据
$_SESSION = [];

// 删除 Session Cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 销毁 Session
session_destroy();

// 如果是 SSO 会话，跳转到 Logto 登出页面
if ($isSsoSession && $ssoAuth->isEnabled()) {
    try {
        $signOutUrl = $ssoAuth->getSignOutUrl();
        header('Location: ' . $signOutUrl);
        exit;
    } catch (\Throwable $e) {
        // SSO 登出失败，继续跳转到本地登录页
        error_log("[SSO登出错误] " . $e->getMessage());
    }
}

// 跳转到登录页
header('Location: /admin/login.php');
exit;
