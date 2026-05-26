<?php
/**
 * 灯塔DNS拦截响应平台 - 认证API
 *
 * 提供管理员退出、登录状态检查等认证相关接口。
 * 本平台使用 SSO 登录，不支持密码登录。
 * 所有接口均返回JSON格式响应。
 *
 * 接口列表：
 *   POST /api/auth.php?action=logout   - 退出登录
 *   GET  /api/auth.php?action=check    - 检查登录状态
 */

use Core\Database;
use Core\Auth;

// 防止直接访问
defined('BASEPATH') || define('BASEPATH', dirname(__DIR__, 2));

// 加载依赖
require_once BASEPATH . '/includes/functions.php';
initDebug();

// 启动Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// 初始化数据库和认证实例
try {
    $config = require BASEPATH . '/config/config.php';
    $db = Database::getInstance($config);
    $auth = new Auth($db, $config);
} catch (\Throwable $e) {
    jsonError('系统初始化失败，请稍后重试', -1, 500);
}

// 获取请求动作和方法
$action = sanitize($_GET['action'] ?? $_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// 路由分发
switch ($action) {

    // ==================== 退出登录 ====================
    case 'logout':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败', -1, 403);
        }

        try {
            // 获取当前用户信息用于日志记录
            $currentUser = $auth->check();
            $auth->logout();
            // 清除CSRF令牌
            \Core\CsrfProtection::clearAll();

            // 记录退出登录日志
            if ($currentUser) {
                recordLog([
                    'action'      => 'logout',
                    'module'      => 'auth',
                    'description' => '管理员退出登录',
                    'target_type' => 'staff',
                    'target_id'   => (int)$currentUser['id'],
                    'target_name' => $currentUser['username'],
                    'result'      => 'success',
                ]);
            }

            jsonSuccess(null, '已退出登录');
        } catch (\Throwable $e) {
            error_log('[认证API] 退出登录失败: ' . $e->getMessage());
            jsonError('退出登录失败', -1, 500);
        }
        break;

    // ==================== 检查登录状态 ====================
    case 'check':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        // L-05: 建议在生产环境中为check接口添加速率限制，
        // 防止高频轮询消耗服务器资源。推荐方案：
        // - 使用Redis实现滑动窗口限流（如60秒内最多30次请求）
        // - 或使用APCu计数器实现简单的固定窗口限流
        // - 对于未登录的check请求可适当降低限制频率

        try {
            $user = $auth->check();
            if ($user) {
                jsonSuccess([
                    'logged_in' => true,
                    'user_info' => [
                        'id'       => $user['id'],
                        'username' => $user['username'],
                        'email'    => $user['email'],
                        'role'     => $user['role'],
                    ],
                ], '已登录');
            } else {
                jsonSuccess([
                    'logged_in' => false,
                    'user_info' => null,
                ], '未登录');
            }
        } catch (\Throwable $e) {
            error_log('[认证API] 状态检查失败: ' . $e->getMessage());
            jsonError('状态检查失败', -1, 500);
        }
        break;

    // ==================== 未知操作 ====================
    default:
        jsonError('未知操作类型，支持的操作: logout, check', -1, 400);
}
