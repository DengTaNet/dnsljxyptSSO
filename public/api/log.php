<?php
/**
 * 灯塔DNS拦截响应平台 - 操作日志API
 *
 * 提供操作日志记录、查询、统计和清空功能。
 * 所有接口均需要管理员认证。
 *
 * 接口列表：
 *   POST /api/log.php?action=record - 记录操作日志（需管理员认证）
 *   GET  /api/log.php?action=list   - 查询日志列表（需管理员认证）
 *   GET  /api/log.php?action=stats  - 获取统计信息（需管理员认证）
 *   POST /api/log.php?action=clear  - 清空日志（需super_admin权限）
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

    // ==================== 记录操作日志 ====================
    case 'record':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 认证检查：记录日志需要已登录
        $recordUser = $auth->check();
        if (!$recordUser) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // FIX: 添加CSRF校验，防止CSRF攻击注入虚假日志
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('安全验证失败，请刷新页面重试', -1, 403);
        }

        // IP级别频率限制：每分钟最多60条（使用数据库频率限制器）
        $clientIp = getClientIp();
        $logLimiter = new \Core\RateLimiter($db, 'log_record_ip', $clientIp, 60, 60);
        if ($logLimiter->isExceeded()) {
            jsonError('日志记录过于频繁，请稍后再试', -1, 429);
        }

        try {
            // 自动获取客户端信息
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $parsed = function_exists('parseUserAgent') ? parseUserAgent($ua) : [];

            // 判断用户类型：如果已登录后台则为admin，否则为customer
            $userType = 'customer';
            $userId = null;
            $userName = null;
            $userRole = null;

            $currentUser = $recordUser;
            if ($currentUser) {
                $userType = 'admin';
                $userId = (int)$currentUser['id'];
                $userName = $currentUser['username'] ?? '';
                $userRole = $currentUser['role'] ?? '';
            }

            // 检查是否为批量日志（JSON body）
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $rawBody = file_get_contents('php://input');
            $batchLogs = null;

            if (strpos($contentType, 'application/json') !== false && !empty($rawBody)) {
                $json = json_decode($rawBody, true);
                if (is_array($json) && isset($json['logs']) && is_array($json['logs'])) {
                    $batchLogs = $json['logs'];
                }
            }

            // 构建单条日志的通用字段
            $commonFields = [
                'user_id'       => $userId,
                'user_name'     => mb_substr($userName, 0, 50),
                'user_role'     => mb_substr($userRole, 0, 50),
                'user_type'     => $userType,
                'ip'            => getClientIp(),
                'real_ip'       => getClientIp(),
                'user_agent'    => mb_substr($ua, 0, 500),
                'browser'       => mb_substr($parsed['browser'] ?? '', 0, 100),
                'os'            => mb_substr($parsed['os'] ?? '', 0, 100),
                'created_at'    => date('Y-m-d H:i:s'),
            ];

            if ($batchLogs !== null) {
                // 批量写入上限检查（等保安全要求：防止资源耗尽攻击）
                $batchLimit = 100;
                if (count($batchLogs) > $batchLimit) {
                    jsonError("批量日志数量超过上限（最多 {$batchLimit} 条）", -1, 400);
                }
                // 批量写入
                foreach ($batchLogs as $logItem) {
                    if (!is_array($logItem)) continue;
                    $logResult = sanitize($logItem['result'] ?? 'success');
                    if (!in_array($logResult, ['success', 'failure', 'pending'], true)) {
                        $logResult = 'success';
                    }
                    $db->insert('logzx', array_merge($commonFields, [
                        'action'        => mb_substr(sanitize($logItem['action'] ?? 'unknown'), 0, 100),
                        'module'        => mb_substr(sanitize($logItem['module'] ?? ''), 0, 50),
                        'description'   => mb_substr(sanitize($logItem['description'] ?? ''), 0, 500),
                        'target_type'   => mb_substr(sanitize($logItem['target_type'] ?? ''), 0, 50),
                        'target_id'     => mb_substr(sanitize($logItem['target_id'] ?? ''), 0, 100),
                        'target_name'   => mb_substr(sanitize($logItem['target_name'] ?? ''), 0, 255),
                        'request_url'   => mb_substr(sanitize($logItem['request_url'] ?? ''), 0, 500),
                        'request_method'=> mb_substr(sanitize($logItem['request_method'] ?? ''), 0, 10),
                        'response_code' => isset($logItem['response_code']) ? (int)$logItem['response_code'] : null,
                        'duration'      => isset($logItem['duration']) && (int)$logItem['duration'] > 0 ? (int)$logItem['duration'] : null,
                        'result'        => $logResult,
                        'error_msg'     => mb_substr(sanitize($logItem['error_msg'] ?? ''), 0, 500),
                    ]));
                }
            } else {
                // 单条写入（表单方式）
                $logAction     = sanitize($_POST['action'] ?? 'unknown');
                $module        = sanitize($_POST['module'] ?? '');
                $description   = sanitize($_POST['description'] ?? '');
                $targetType    = sanitize($_POST['target_type'] ?? '');
                $targetId      = sanitize($_POST['target_id'] ?? '');
                $targetName    = sanitize($_POST['target_name'] ?? '');
                $requestUrl    = sanitize($_POST['request_url'] ?? '');
                $duration      = isset($_POST['duration']) ? (int)$_POST['duration'] : null;
                $result        = sanitize($_POST['result'] ?? 'success');
                $errorMsg      = sanitize($_POST['error_msg'] ?? '');
                $requestData   = $_POST['request_data'] ?? null;

                if (!in_array($result, ['success', 'failure', 'pending'], true)) {
                    $result = 'success';
                }

                $db->insert('logzx', array_merge($commonFields, [
                    'action'        => mb_substr($logAction, 0, 100),
                    'module'        => mb_substr($module, 0, 50),
                    'description'   => mb_substr($description, 0, 500),
                    'target_type'   => mb_substr($targetType, 0, 50),
                    'target_id'     => mb_substr($targetId, 0, 100),
                    'target_name'   => mb_substr($targetName, 0, 255),
                    'request_url'   => mb_substr($requestUrl ?: ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
                    'request_method'=> mb_substr($_SERVER['REQUEST_METHOD'] ?? 'POST', 0, 10),
                    'request_data'  => is_string($requestData) ? mb_substr($requestData, 0, 5000) : null,
                    'response_code' => http_response_code() ?: 200,
                    'duration'      => $duration !== null && $duration > 0 ? $duration : null,
                    'result'        => $result,
                    'error_msg'     => mb_substr($errorMsg, 0, 500),
                ]));
            }

            // 更新频率限制计数器（使用数据库频率限制器）
            $logLimiter->increment();

            jsonSuccess(null, '日志记录成功');

        } catch (\Throwable $e) {
            // 日志记录失败记录到系统错误日志，并向调用方返回失败状态
            error_log('[日志API] 记录失败: ' . $e->getMessage());
            jsonError('日志记录失败，请稍后重试', -1, 500);
        }
        break;

    // ==================== 查询日志列表 ====================
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
            // 分页参数
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;

            // 筛选参数
            $dateFrom  = sanitize($_GET['date_from'] ?? '');
            $dateTo    = sanitize($_GET['date_to'] ?? '');
            $userType  = sanitize($_GET['user_type'] ?? '');
            $logAction = sanitize($_GET['action'] ?? '');
            $module    = sanitize($_GET['module'] ?? '');
            $userName  = sanitize($_GET['user_name'] ?? '');
            $ip        = sanitize($_GET['ip'] ?? '');
            $targetType= sanitize($_GET['target_type'] ?? '');
            $result    = sanitize($_GET['result'] ?? '');
            $keyword   = sanitize($_GET['keyword'] ?? '');

            // 构建WHERE条件
            $where = ['1=1'];
            $params = [];

            if ($dateFrom) {
                $where[] = 'created_at >= ?';
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $where[] = 'created_at <= ?';
                $params[] = $dateTo . ' 23:59:59';
            }
            if ($userType && in_array($userType, ['admin', 'customer', 'system'], true)) {
                $where[] = 'user_type = ?';
                $params[] = $userType;
            }
            if ($logAction) {
                $where[] = 'action = ?';
                $params[] = $logAction;
            }
            if ($module) {
                $where[] = 'module = ?';
                $params[] = $module;
            }
            if ($userName) {
                $userName = addcslashes($userName, '%_');
                $where[] = 'user_name LIKE ?';
                $params[] = '%' . $userName . '%';
            }
            if ($ip) {
                $ip = addcslashes($ip, '%_');
                $where[] = '(ip LIKE ? OR real_ip LIKE ?)';
                $params[] = '%' . $ip . '%';
                $params[] = '%' . $ip . '%';
            }
            if ($targetType) {
                $where[] = 'target_type = ?';
                $params[] = $targetType;
            }
            if ($result && in_array($result, ['success', 'failure', 'pending'], true)) {
                $where[] = 'result = ?';
                $params[] = $result;
            }
            if ($keyword) {
                $keyword = addcslashes($keyword, '%_');
                $where[] = '(description LIKE ? OR target_name LIKE ? OR user_name LIKE ?)';
                $params[] = '%' . $keyword . '%';
                $params[] = '%' . $keyword . '%';
                $params[] = '%' . $keyword . '%';
            }

            $whereClause = implode(' AND ', $where);

            // 查询总数
            $totalResult = $db->queryOne(
                "SELECT COUNT(*) as total FROM logzx WHERE {$whereClause}",
                $params
            );
            $total = (int)($totalResult['total'] ?? 0);

            // 查询日志列表
            $logs = $db->query(
                "SELECT * FROM logzx WHERE {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );

            jsonSuccess([
                'list'     => $logs,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'last_page'=> max(1, (int)ceil($total / $perPage)),
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[日志API] 获取日志列表失败: ' . $e->getMessage());
            jsonError('获取日志列表失败', -1, 500);
        }
        break;

    // ==================== 获取统计信息 ====================
    case 'stats':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        try {
            $today = date('Y-m-d');

            // 今日操作总数
            $todayTotal = (int)($db->queryOne(
                "SELECT COUNT(*) as cnt FROM logzx WHERE created_at >= ?",
                [$today . ' 00:00:00']
            )['cnt'] ?? 0);

            // 今日管理员操作数
            $todayAdmin = (int)($db->queryOne(
                "SELECT COUNT(*) as cnt FROM logzx WHERE created_at >= ? AND user_type = 'admin'",
                [$today . ' 00:00:00']
            )['cnt'] ?? 0);

            // 今日客户操作数
            $todayCustomer = (int)($db->queryOne(
                "SELECT COUNT(*) as cnt FROM logzx WHERE created_at >= ? AND user_type = 'customer'",
                [$today . ' 00:00:00']
            )['cnt'] ?? 0);

            // 今日失败操作数
            $todayFailure = (int)($db->queryOne(
                "SELECT COUNT(*) as cnt FROM logzx WHERE created_at >= ? AND result = 'failure'",
                [$today . ' 00:00:00']
            )['cnt'] ?? 0);

            // 各模块操作数TOP10
            $moduleStats = $db->query(
                "SELECT module, COUNT(*) as cnt FROM logzx
                 WHERE created_at >= ? AND module IS NOT NULL AND module != ''
                 GROUP BY module ORDER BY cnt DESC LIMIT 10",
                [$today . ' 00:00:00']
            );

            // 各操作类型TOP10
            $actionStats = $db->query(
                "SELECT action, COUNT(*) as cnt FROM logzx
                 WHERE created_at >= ?
                 GROUP BY action ORDER BY cnt DESC LIMIT 10",
                [$today . ' 00:00:00']
            );

            // 日志总数
            $allTotal = (int)($db->queryOne("SELECT COUNT(*) as cnt FROM logzx")['cnt'] ?? 0);

            jsonSuccess([
                'today_total'    => $todayTotal,
                'today_admin'    => $todayAdmin,
                'today_customer' => $todayCustomer,
                'today_failure'  => $todayFailure,
                'module_stats'   => $moduleStats,
                'action_stats'   => $actionStats,
                'all_total'      => $allTotal,
            ], '获取统计成功');

        } catch (\Throwable $e) {
            error_log('[日志API] 获取统计失败: ' . $e->getMessage());
            jsonError('获取统计信息失败', -1, 500);
        }
        break;

    // ==================== 清空日志 ====================
    case 'clear':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // super_admin权限检查（先于CSRF验证，避免无权限用户消耗CSRF令牌）
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }
        if ($user['role'] !== 'super_admin') {
            jsonError('权限不足，仅超级管理员可清空日志', -1, 403);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $dateFrom = sanitize($_POST['date_from'] ?? '');
            $dateTo   = sanitize($_POST['date_to'] ?? '');

            // 记录清空日志审计（操作前记录，防止操作后无法记录）
            $clientIp = getClientIp();
            $logDetails = [
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
                'user_id'   => $user['id'] ?? 0,
                'username'  => $user['username'] ?? 'unknown',
            ];
            error_log("[审计日志] 清空日志操作: 用户={$logDetails['username']}, IP={$clientIp}, 时间范围={$dateFrom}~{$dateTo}");
            \Core\Debug::logAuth('log_clear', "清空日志: {$dateFrom}~{$dateTo}", $logDetails);

            if ($dateFrom || $dateTo) {
                // 按日期范围清空
                $where = [];
                $params = [];

                if ($dateFrom) {
                    $where[] = 'created_at >= ?';
                    $params[] = $dateFrom . ' 00:00:00';
                }
                if ($dateTo) {
                    $where[] = 'created_at <= ?';
                    $params[] = $dateTo . ' 23:59:59';
                }

                $whereClause = implode(' AND ', $where);
                $deletedCount = $db->execute(
                    "DELETE FROM logzx WHERE {$whereClause}",
                    $params
                );

                jsonSuccess([
                    'deleted_count' => $deletedCount,
                ], "已清空 {$deletedCount} 条日志记录");
            } else {
                // 清空全部日志
                $deletedCount = $db->execute("DELETE FROM logzx");

                jsonSuccess([
                    'deleted_count' => $deletedCount,
                ], "已清空全部 {$deletedCount} 条日志记录");
            }

        } catch (\Throwable $e) {
            error_log('[日志API] 清空日志失败: ' . $e->getMessage());
            jsonError('清空日志失败', -1, 500);
        }
        break;

    // ==================== 未知操作 ====================
    default:
        jsonError('未知操作类型，支持的操作: record, list, stats, clear', -1, 400);
}
