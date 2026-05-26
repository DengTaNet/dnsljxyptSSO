<?php
/**
 * 灯塔DNS拦截响应平台 - 组织管理API
 *
 * 提供管理员列表、创建、更新、删除、启用/禁用等接口。
 * 仅超级管理员(super_admin)可操作。
 *
 * 接口列表：
 *   GET  /api/staff.php?action=list           - 获取管理员列表
 *   POST /api/staff.php?action=create         - 创建管理员
 *   POST /api/staff.php?action=update         - 更新管理员信息
 *   POST /api/staff.php?action=delete         - 禁用管理员
 *   POST /api/staff.php?action=toggle_status  - 启用/禁用管理员
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

// 获取当前用户
$currentUser = $auth->check();
if (!$currentUser) {
    jsonError('未登录或会话已过期', -1, 401);
}

// 仅超级管理员可操作组织管理
if ($currentUser['role'] !== 'super_admin') {
    jsonError('权限不足，仅超级管理员可进行组织管理', -1, 403);
}

// 获取请求动作和方法
$action = sanitize($_GET['action'] ?? $_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// 路由分发
switch ($action) {

    // ==================== 获取管理员列表 ====================
    case 'list':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        try {
            // 先检查permissions字段是否存在
            $cols = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'permissions'");
            $hasPermissions = !empty($cols);

            $selectCols = "id, username, email, role, status, created_at, updated_at, last_login, last_login_ip";
            if ($hasPermissions) {
                $selectCols .= ", permissions";
            }

            $admins = $db->query(
                "SELECT {$selectCols}
                 FROM admin_users
                 ORDER BY id ASC"
            );

            // 确保每条记录都有permissions字段
            foreach ($admins as &$admin) {
                if (!isset($admin['permissions'])) {
                    $admin['permissions'] = null;
                }
            }
            unset($admin);

            // 获取所有角色和模块信息用于前端展示
            $roles = Auth::getAllRoles();
            $modules = Auth::getAllModules();

            jsonSuccess([
                'list'    => $admins,
                'roles'   => $roles,
                'modules' => $modules,
            ], '获取成功');
        } catch (\Throwable $e) {
            error_log('[组织管理API] 获取列表失败: ' . $e->getMessage());
            jsonError('获取管理员列表失败', -1, 500);
        }
        break;

    // ==================== 创建管理员 ====================
    case 'create':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $username = trim(str_replace("\0", '', $_POST['username'] ?? ''));
            $email = trim(str_replace("\0", '', $_POST['email'] ?? ''));
            $role = trim($_POST['role'] ?? 'operator');
            $permissions = $_POST['permissions'] ?? '';

            // 参数校验
            if (empty($username)) {
                jsonError('用户名不能为空', 1);
            }
            if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
                jsonError('用户名长度应为3-50个字符', 1);
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonError('请输入有效的邮箱地址', 1);
            }

            // 验证角色
            $validRoles = array_keys(Auth::getAllRoles());
            if (!in_array($role, $validRoles)) {
                jsonError('无效的角色类型', 1);
            }

            // 检查用户名是否已存在
            $exists = $db->queryOne("SELECT id FROM admin_users WHERE username = ?", [$username]);
            if ($exists) {
                jsonError('用户名已存在', 2);
            }

            // 检查邮箱是否已存在
            $exists = $db->queryOne("SELECT id FROM admin_users WHERE email = ?", [$email]);
            if ($exists) {
                jsonError('邮箱已被使用', 2);
            }

            // 处理权限
            $permissionsValue = null;
            if (!empty($permissions)) {
                $permsArray = json_decode($permissions, true);
                if (is_array($permsArray)) {
                    // 验证权限模块名是否合法
                    $validModules = array_keys(Auth::getAllModules());
                    foreach ($permsArray as $p) {
                        if ($p !== '*' && !in_array($p, $validModules)) {
                            jsonError('包含无效的权限模块: ' . $p, 1);
                        }
                    }
                    $permissionsValue = json_encode($permsArray, JSON_UNESCAPED_UNICODE);
                }
            }

            // 本平台使用 SSO 登录，生成随机密码占位（用户通过 SSO 登录，不使用密码）
            $randomPassword = bin2hex(random_bytes(32));

            // 创建管理员
            $db->insert('admin_users', [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($randomPassword, PASSWORD_DEFAULT),
                'role'          => $role,
                'status'        => 1,
                'permissions'   => $permissionsValue,
            ]);

            recordLog([
                'action'      => 'create',
                'module'      => 'staff',
                'description' => '创建管理员: ' . $username . ' (角色: ' . $role . ')',
                'target_type' => 'staff',
                'target_name' => $username,
                'result'      => 'success',
            ]);

            jsonSuccess(null, '管理员创建成功');
        } catch (\Throwable $e) {
            error_log('[组织管理API] 创建管理员失败: ' . $e->getMessage());
            jsonError('创建管理员失败', -1, 500);
        }
        break;

    // ==================== 更新管理员信息 ====================
    case 'update':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                jsonError('无效的管理员ID', 1);
            }

            // 查找目标管理员
            $target = $db->queryOne("SELECT * FROM admin_users WHERE id = ?", [$id]);
            if (!$target) {
                jsonError('管理员不存在', 2);
            }

            // super_admin不可被其他角色编辑（但自己可以编辑自己）
            if ($target['role'] === 'super_admin' && $target['id'] !== $currentUser['id']) {
                jsonError('超级管理员不可被编辑', 3);
            }

            $username = trim(str_replace("\0", '', $_POST['username'] ?? ''));
            $email = trim(str_replace("\0", '', $_POST['email'] ?? ''));
            $role = trim($_POST['role'] ?? '');
            $permissions = $_POST['permissions'] ?? '';

            // 参数校验
            if (empty($username)) {
                jsonError('用户名不能为空', 1);
            }
            if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
                jsonError('用户名长度应为3-50个字符', 1);
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonError('请输入有效的邮箱地址', 1);
            }

            // 检查用户名是否被其他人使用
            $exists = $db->queryOne("SELECT id FROM admin_users WHERE username = ? AND id != ?", [$username, $id]);
            if ($exists) {
                jsonError('用户名已存在', 2);
            }

            // 检查邮箱是否被其他人使用
            $exists = $db->queryOne("SELECT id FROM admin_users WHERE email = ? AND id != ?", [$email, $id]);
            if ($exists) {
                jsonError('邮箱已被使用', 2);
            }

            // 构建更新数据（本平台使用 SSO 登录，不支持密码修改）
            $updateData = [
                'username' => $username,
                'email'    => $email,
            ];

            // 角色更新（不能修改自己的角色）
            if (!empty($role)) {
                $validRoles = array_keys(Auth::getAllRoles());
                if (!in_array($role, $validRoles)) {
                    jsonError('无效的角色类型', 1);
                }
                // 不允许将其他超级管理员降级
                if ($target['role'] === 'super_admin' && $role !== 'super_admin') {
                    jsonError('不能修改超级管理员的角色', 3);
                }
                // 不允许将自己降级
                if ($target['id'] === $currentUser['id'] && $role !== 'super_admin') {
                    jsonError('不能修改自己的角色', 3);
                }
                $updateData['role'] = $role;
            }

            // 权限更新
            if ($permissions !== '') {
                if (empty($permissions)) {
                    $updateData['permissions'] = null;
                } else {
                    $permsArray = json_decode($permissions, true);
                    if (is_array($permsArray)) {
                        $validModules = array_keys(Auth::getAllModules());
                        foreach ($permsArray as $p) {
                            if ($p !== '*' && !in_array($p, $validModules)) {
                                jsonError('包含无效的权限模块: ' . $p, 1);
                            }
                        }
                        $updateData['permissions'] = json_encode($permsArray, JSON_UNESCAPED_UNICODE);
                    }
                }
            }

            $db->update('admin_users', $updateData, 'id = ?', [$id]);

            // 记录操作日志（不记录密码明文）
            $logDescription = '更新管理员信息: ' . $target['username'];
            if (!empty($role)) {
                $logDescription .= ' (角色: ' . $role . ')';
            }
            recordLog([
                'action'      => 'update',
                'module'      => 'staff',
                'description' => $logDescription,
                'target_type' => 'staff',
                'target_id'   => (string)$id,
                'target_name' => $target['username'],
                'result'      => 'success',
            ]);

            jsonSuccess(null, '管理员信息更新成功');
        } catch (\Throwable $e) {
            error_log('[组织管理API] 更新管理员失败: ' . $e->getMessage());
            jsonError('更新管理员信息失败', -1, 500);
        }
        break;

    // ==================== 禁用管理员 ====================
    case 'delete':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                jsonError('无效的管理员ID', 1);
            }

            // 不能删除自己
            if ($id === $currentUser['id']) {
                jsonError('不能删除自己的账户', 3);
            }

            // 查找目标管理员
            $target = $db->queryOne("SELECT * FROM admin_users WHERE id = ?", [$id]);
            if (!$target) {
                jsonError('管理员不存在', 2);
            }

            // 不能删除超级管理员
            if ($target['role'] === 'super_admin') {
                jsonError('不能删除超级管理员', 3);
            }

            // M-14: 软删除 - 将管理员状态设为禁用（status=0），而非物理删除
            $db->update('admin_users', ['status' => 0], 'id = ?', [$id]);

            // 清除该管理员的所有会话
            $db->delete('sessions', 'user_id = ?', [$id]);

            error_log("[组织管理] 管理员 {$currentUser['username']} 禁用了管理员: {$target['username']}");

            recordLog([
                'action'      => 'delete',
                'module'      => 'staff',
                'description' => '禁用管理员(软删除): ' . $target['username'],
                'target_type' => 'staff',
                'target_id'   => (string)$id,
                'target_name' => $target['username'],
                'result'      => 'success',
            ]);

            jsonSuccess(null, '管理员已禁用');
        } catch (\Throwable $e) {
            error_log('[组织管理API] 删除管理员失败: ' . $e->getMessage());
            jsonError('删除管理员失败', -1, 500);
        }
        break;

    // ==================== 启用/禁用管理员 ====================
    case 'toggle_status':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                jsonError('无效的管理员ID', 1);
            }

            // 不能操作自己
            if ($id === $currentUser['id']) {
                jsonError('不能修改自己的状态', 3);
            }

            // 查找目标管理员
            $target = $db->queryOne("SELECT * FROM admin_users WHERE id = ?", [$id]);
            if (!$target) {
                jsonError('管理员不存在', 2);
            }

            // 不能操作其他超级管理员
            if ($target['role'] === 'super_admin') {
                jsonError('不能修改超级管理员的状态', 3);
            }

            // 切换状态
            $newStatus = $target['status'] == 1 ? 0 : 1;
            $db->update('admin_users', ['status' => $newStatus], 'id = ?', [$id]);

            // 如果禁用，清除其会话
            if ($newStatus == 0) {
                $db->delete('sessions', 'user_id = ?', [$id]);
            }

            recordLog([
                'action'      => 'update',
                'module'      => 'staff',
                'description' => ($newStatus == 1 ? '启用' : '禁用') . '管理员: ' . $target['username'],
                'target_type' => 'staff',
                'target_id'   => (string)$id,
                'target_name' => $target['username'],
                'result'      => 'success',
            ]);

            jsonSuccess([
                'new_status' => $newStatus,
                'status_text' => $newStatus == 1 ? '已启用' : '已禁用',
            ], $newStatus == 1 ? '管理员已启用' : '管理员已禁用');
        } catch (\Throwable $e) {
            error_log('[组织管理API] 切换状态失败: ' . $e->getMessage());
            jsonError('切换管理员状态失败', -1, 500);
        }
        break;

    // ==================== 未知操作 ====================
    default:
        jsonError('未知操作类型，支持的操作: list, create, update, delete, toggle_status', -1, 400);
}
