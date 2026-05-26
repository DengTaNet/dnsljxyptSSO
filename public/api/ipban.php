<?php
/**
 * 灯塔DNS拦截响应平台 - IP封禁API
 *
 * 提供IP封禁管理功能，包括添加封禁、解除封禁、查询封禁列表等。
 * 所有接口需要管理员登录认证。
 *
 * 接口列表：
 *   POST /api/ipban.php?action=add    - 添加IP封禁
 *   POST /api/ipban.php?action=remove - 解除IP封禁
 *   GET  /api/ipban.php?action=list   - 获取封禁列表
 *   GET  /api/ipban.php?action=check  - 检查IP是否被封禁
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

    // ==================== 添加IP封禁 ====================
    case 'add':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // FIX: 添加权限检查，统一使用 hasPermission('ipban')
        if (!$auth->hasPermission('ipban')) {
            jsonError('无权执行此操作', -1, 403);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $ip = sanitize($_POST['ip'] ?? '');
            $banType = sanitize($_POST['ban_type'] ?? 'temporary');
            $reason = sanitize($_POST['reason'] ?? '');

            // 参数校验
            if (empty($ip)) {
                jsonError('缺少IP地址', 1);
            }

            // 验证IP格式
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                jsonError('IP地址格式无效', 1);
            }

            if (empty($reason)) {
                jsonError('请输入封禁理由', 1);
            }

            if (mb_strlen($reason) > 500) {
                jsonError('封禁理由不能超过500个字符', 1);
            }

            // 验证封禁类型
            if (!in_array($banType, ['temporary', 'permanent'], true)) {
                jsonError('无效的封禁类型', 1);
            }

            // 检查是否已存在该IP的记录（含已解封/已过期），有则覆盖更新，无则新增
            $existing = $db->queryOne(
                "SELECT id FROM ip_bans WHERE ip = ?",
                [$ip]
            );

            // 计算过期时间
            $expiresAt = null;
            if ($banType === 'temporary') {
                $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24小时后
            }

            $banData = [
                'ip'               => $ip,
                'real_ip'          => getClientIp(),
                'detection_source' => 'manual',
                'ban_type'         => $banType,
                'reason'           => $reason,
                'banned_by'        => $user['id'],
                'status'           => 'active',
                'expires_at'       => $expiresAt,
                'banned_at'        => date('Y-m-d H:i:s'),
                'lifted_at'        => null,
                'lifted_by'        => null,
                'lift_reason'      => null,
            ];

            if ($existing) {
                $db->update('ip_bans', $banData, 'id = ?', [$existing['id']]);
                $banId = $existing['id'];
            } else {
                $banId = $db->insert('ip_bans', $banData);
            }

            // 记录日志
            error_log("[IP封禁] 管理员 {$user['username']} 封禁IP: {$ip}, 类型: {$banType}, 理由: {$reason}");
            recordLog([
                'action'      => 'ban',
                'module'      => 'ipban',
                'description' => '封禁IP: ' . $ip . ' (' . $banType . ')',
                'target_type' => 'ip',
                'target_id'   => (string)$banId,
                'target_name' => $ip,
                'result'      => 'success',
            ]);

            jsonSuccess([
                'ban_id' => $banId,
                'ip'     => $ip,
            ], 'IP封禁成功');

        } catch (\Throwable $e) {
            error_log('[IP封禁] 添加封禁失败: ' . $e->getMessage());
            jsonError('添加封禁失败', -1, 500);
        }
        break;

    // ==================== 解除IP封禁 ====================
    case 'remove':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // FIX: 添加权限检查，统一使用 hasPermission('ipban')
        if (!$auth->hasPermission('ipban')) {
            jsonError('无权执行此操作', -1, 403);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            // 支持 ban_id 或 id 参数（前端使用 id）
            $banId = (int) ($_POST['ban_id'] ?? $_POST['id'] ?? 0);

            if ($banId <= 0) {
                jsonError('缺少封禁ID', 1);
            }

            // 验证封禁记录存在
            $ban = $db->queryOne(
                "SELECT * FROM ip_bans WHERE id = ? AND status = 'active'",
                [$banId]
            );
            if (!$ban) {
                jsonError('封禁记录不存在或已解除', 2, 404);
            }

            // 解除封禁
            $db->execute(
                "UPDATE ip_bans SET status = 'lifted', lifted_by = ?, lifted_at = NOW() WHERE id = ?",
                [$user['id'], $banId]
            );

            // 记录日志
            error_log("[IP封禁] 管理员 {$user['username']} 解除IP封禁: {$ban['ip']}");
            recordLog([
                'action'      => 'unban',
                'module'      => 'ipban',
                'description' => '解除IP封禁: ' . $ban['ip'],
                'target_type' => 'ip',
                'target_id'   => (string)$banId,
                'target_name' => $ban['ip'],
                'result'      => 'success',
            ]);

            jsonSuccess(null, 'IP封禁已解除');

        } catch (\Throwable $e) {
            error_log('[IP封禁] 解除封禁失败: ' . $e->getMessage());
            jsonError('解除封禁失败', -1, 500);
        }
        break;

    // ==================== 批量解封IP ====================
    case 'batch_unban':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // 权限检查
        if (!$auth->hasPermission('ipban')) {
            jsonError('无权执行此操作', -1, 403);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $idsJson = $_POST['ids'] ?? '[]';
            $ids = json_decode($idsJson, true);

            if (empty($ids) || !is_array($ids)) {
                jsonError('缺少封禁ID列表', 1);
            }

            // 限制批量数量
            if (count($ids) > 100) {
                jsonError('单次批量解封不能超过100个', 1);
            }

            // 过滤有效ID
            $validIds = array_filter(array_map('intval', $ids));
            if (empty($validIds)) {
                jsonError('无效的封禁ID列表', 1);
            }

            $placeholders = implode(',', array_fill(0, count($validIds), '?'));

            // 验证封禁记录存在
            $existingBans = $db->query(
                "SELECT id, ip FROM ip_bans WHERE id IN ({$placeholders}) AND status = 'active'",
                $validIds
            );

            if (empty($existingBans)) {
                jsonError('封禁记录不存在或已解除', 2, 404);
            }

            $existingIds = array_column($existingBans, 'id');
            $existingIps = array_column($existingBans, 'ip');
            $placeholders2 = implode(',', array_fill(0, count($existingIds), '?'));

            // 解除封禁
            $db->execute(
                "UPDATE ip_bans SET status = 'lifted', lifted_by = ?, lifted_at = NOW() WHERE id IN ({$placeholders2})",
                array_merge([$user['id']], $existingIds)
            );

            $successCount = count($existingIds);
            $ipsStr = implode(', ', $existingIps);

            // 记录日志
            error_log("[IP封禁] 管理员 {$user['username']} 批量解除IP封禁: {$ipsStr} (共{$successCount}个)");
            recordLog([
                'action'      => 'batch_unban',
                'module'      => 'ipban',
                'description' => "批量解除IP封禁 (共{$successCount}个): {$ipsStr}",
                'target_type' => 'ip',
                'target_id'   => implode(',', $existingIds),
                'target_name' => $ipsStr,
                'result'      => 'success',
            ]);

            jsonSuccess([
                'success_count' => $successCount,
                'failed_count'  => count($validIds) - $successCount,
            ], "成功解除 {$successCount} 个IP封禁");

        } catch (\Throwable $e) {
            error_log('[IP封禁] 批量解除封禁失败: ' . $e->getMessage());
            jsonError('批量解除封禁失败', -1, 500);
        }
        break;

    // ==================== 获取封禁列表 ====================
    case 'list':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        try {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;

            // 查询总数
            $totalResult = $db->queryOne(
                "SELECT COUNT(*) as total FROM ip_bans WHERE status = 'active'"
            );
            $total = (int) ($totalResult['total'] ?? 0);

            // 查询封禁列表
            $bans = $db->query(
                "SELECT b.*, a.username AS banned_by_name
                 FROM ip_bans b
                 LEFT JOIN admin_users a ON b.banned_by = a.id
                 WHERE b.status = 'active'
                 ORDER BY b.created_at DESC
                 LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
            );

            jsonSuccess([
                'bans'      => $bans,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[IP封禁] 获取封禁列表失败: ' . $e->getMessage());
            jsonError('获取封禁列表失败', -1, 500);
        }
        break;

    // ==================== 检查IP是否被封禁 ====================
    case 'check':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        try {
            $ip = sanitize($_GET['ip'] ?? '');
            if (empty($ip)) {
                jsonError('缺少IP地址', 1);
            }

            // FIX: 使用 getClientIp() 替代 REMOTE_ADDR，保持与其他地方一致
            // 仅允许查询当前请求者自身的IP，防止信息泄露
            $requestIp = getClientIp();
            if ($ip !== $requestIp) {
                jsonError('仅允许查询当前请求者的IP', 1, 403);
            }

            $ban = $db->queryOne(
                "SELECT * FROM ip_bans WHERE ip = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
                [$ip]
            );

            // FIX: 限制返回字段，不暴露内部管理字段
            $banInfo = null;
            if (!empty($ban)) {
                $banInfo = [
                    'banned'     => true,
                    'expires_at' => $ban['expires_at'] ?? null,
                ];
            }

            jsonSuccess([
                'banned' => !empty($ban),
                'ban'    => $banInfo,
            ], '检查完成');

        } catch (\Throwable $e) {
            error_log('[IP封禁] 检查IP失败: ' . $e->getMessage());
            jsonError('检查IP失败', -1, 500);
        }
        break;

    // ==================== 未知操作 ====================
    default:
        jsonError('未知操作类型，支持的操作: add, remove, list, check', -1, 400);
}
