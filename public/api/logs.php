<?php
/**
 * 灯塔DNS拦截响应平台 - 日志API
 *
 * 提供访问日志的查询、统计、导出和清理功能。
 * 所有接口均需要管理员登录认证。
 *
 * 接口列表：
 *   GET  /api/logs.php?action=list     - 获取访问日志列表（支持多条件筛选）
 *   GET  /api/logs.php?action=stats    - 获取日志统计数据
 *   GET  /api/logs.php?action=export   - 导出日志（CSV/JSON）
 *   POST /api/logs.php?action=clean    - 清理旧日志（管理员）
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

// 管理员认证检查（所有接口均需登录）
$user = $auth->check();
if (!$user) {
    jsonError('未登录或会话已过期，请重新登录', -1, 401);
}

// 获取请求动作和方法
$action = sanitize($_GET['action'] ?? $_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// 路由分发
switch ($action) {

    // ==================== 获取访问日志列表 ====================
    case 'list':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        try {
            // 分页参数
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(
                $config['pagination']['max_per_page'] ?? 100,
                max(1, (int) ($_GET['per_page'] ?? ($config['pagination']['per_page'] ?? 20)))
            );

            // 筛选参数
            $dateFrom = sanitize($_GET['date_from'] ?? '');
            $dateTo = sanitize($_GET['date_to'] ?? '');
            $ip = sanitize($_GET['ip'] ?? '');
            $domain = sanitize($_GET['domain'] ?? '');
            $browser = sanitize($_GET['browser'] ?? '');
            $domainId = (int) ($_GET['domain_id'] ?? 0);

            // 构建WHERE条件
            $where = [];
            $params = [];

            // 日期范围筛选
            if (!empty($dateFrom)) {
                $dateFromValid = date('Y-m-d', strtotime($dateFrom));
                if ($dateFromValid) {
                    $where[] = 'l.created_at >= :date_from';
                    $params[':date_from'] = $dateFromValid . ' 00:00:00';
                }
            }

            if (!empty($dateTo)) {
                $dateToValid = date('Y-m-d', strtotime($dateTo));
                if ($dateToValid) {
                    $where[] = 'l.created_at <= :date_to';
                    $params[':date_to'] = $dateToValid . ' 23:59:59';
                }
            }

            // IP筛选
            if (!empty($ip)) {
                $ip = addcslashes($ip, '%_');
                $where[] = '(l.ip LIKE :ip1 OR l.real_ip LIKE :ip2)';
                $params[':ip1'] = '%' . $ip . '%';
                $params[':ip2'] = '%' . $ip . '%';
            }

            // 域名筛选（通过域名名称关联）
            if (!empty($domain)) {
                $domain = addcslashes($domain, '%_');
                $where[] = 'd.domain_name LIKE :domain';
                $params[':domain'] = '%' . $domain . '%';
            }

            // 域名ID筛选
            if ($domainId > 0) {
                $where[] = 'l.domain_id = :domain_id';
                $params[':domain_id'] = $domainId;
            }

            // 浏览器筛选
            if (!empty($browser)) {
                $browser = addcslashes($browser, '%_');
                $where[] = 'l.browser LIKE :browser';
                $params[':browser'] = '%' . $browser . '%';
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // 查询总数
            $totalSql = "
                SELECT COUNT(*) as total
                FROM access_logs l
                LEFT JOIN domains d ON l.domain_id = d.id
                {$whereClause}
            ";
            $totalResult = $db->queryOne($totalSql, $params);
            $total = (int) ($totalResult['total'] ?? 0);

            // 查询日志列表
            $offset = ($page - 1) * $perPage;
            $listSql = "
                SELECT l.*,
                       d.domain_name
                FROM access_logs l
                LEFT JOIN domains d ON l.domain_id = d.id
                {$whereClause}
                ORDER BY l.created_at DESC
                LIMIT " . (int)$perPage . " OFFSET " . (int)$offset . "
            ";

            $logs = $db->query($listSql, $params);

            jsonSuccess([
                'logs'     => $logs,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[日志API] 获取日志列表失败: ' . $e->getMessage());
            jsonError('获取日志列表失败', -1, 500);
        }
        break;

    // ==================== 获取日志统计数据 ====================
    case 'stats':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        try {
            // 今日访问数
            $todayCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM access_logs WHERE DATE(created_at) = CURDATE()"
            )['cnt'] ?? 0);

            // 本周访问数
            $weekCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM access_logs WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)"
            )['cnt'] ?? 0);

            // 总访问数
            $totalCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM access_logs"
            )['cnt'] ?? 0);

            // 今日独立IP数
            $todayUniqueIps = (int) ($db->queryOne(
                "SELECT COUNT(DISTINCT ip) as cnt FROM access_logs WHERE DATE(created_at) = CURDATE()"
            )['cnt'] ?? 0);

            // 总独立IP数
            $totalUniqueIps = (int) ($db->queryOne(
                "SELECT COUNT(DISTINCT ip) as cnt FROM access_logs"
            )['cnt'] ?? 0);

            // Top 10 访问IP
            $topIps = $db->query(
                "SELECT ip, COUNT(*) as visit_count,
                        MAX(created_at) as last_visit
                 FROM access_logs
                 GROUP BY ip
                 ORDER BY visit_count DESC
                 LIMIT 10"
            );

            // Top 10 访问域名
            $topDomains = $db->query(
                "SELECT d.domain_name, COUNT(*) as visit_count
                 FROM access_logs l
                 INNER JOIN domains d ON l.domain_id = d.id
                 GROUP BY l.domain_id
                 ORDER BY visit_count DESC
                 LIMIT 10"
            );

            // Top 10 浏览器
            $topBrowsers = $db->query(
                "SELECT browser, COUNT(*) as visit_count
                 FROM access_logs
                 WHERE browser IS NOT NULL AND browser != ''
                 GROUP BY browser
                 ORDER BY visit_count DESC
                 LIMIT 10"
            );

            // 近30天访问趋势
            $trendData = $db->query(
                "SELECT DATE(created_at) as date,
                        COUNT(*) as total_visits,
                        COUNT(DISTINCT ip) as unique_ips
                 FROM access_logs
                 WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date ASC"
            );

            // 今日按小时分布
            $hourlyToday = $db->query(
                "SELECT HOUR(created_at) as hour, COUNT(*) as count
                 FROM access_logs
                 WHERE DATE(created_at) = CURDATE()
                 GROUP BY HOUR(created_at)
                 ORDER BY hour ASC"
            );

            jsonSuccess([
                'today_count'      => $todayCount,
                'week_count'       => $weekCount,
                'total'            => $totalCount,
                'today_unique_ips' => $todayUniqueIps,
                'total_unique_ips' => $totalUniqueIps,
                'top_ips'          => $topIps,
                'top_domains'      => $topDomains,
                'top_browsers'     => $topBrowsers,
                'trend_data'       => $trendData,
                'hourly_today'     => $hourlyToday,
            ], '获取统计成功');

        } catch (\Throwable $e) {
            error_log('[日志API] 获取统计数据失败: ' . $e->getMessage());
            jsonError('获取统计数据失败', -1, 500);
        }
        break;

    // ==================== 导出日志 ====================
    case 'export':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // M-05: 导出操作添加CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $format = strtolower(sanitize($_POST['format'] ?? 'csv'));

            // 验证导出格式
            if (!in_array($format, ['csv', 'json'], true)) {
                jsonError('不支持的导出格式，支持: csv, json', 1);
            }

            // 筛选参数（与list接口一致，从POST读取）
            $dateFrom = sanitize($_POST['date_from'] ?? '');
            $dateTo = sanitize($_POST['date_to'] ?? '');
            $ip = sanitize($_POST['ip'] ?? '');
            $domain = sanitize($_POST['domain'] ?? '');
            $browser = sanitize($_POST['browser'] ?? '');
            $domainId = (int) ($_POST['domain_id'] ?? 0);

            // 构建WHERE条件
            $where = [];
            $params = [];

            if (!empty($dateFrom)) {
                $dateFromValid = date('Y-m-d', strtotime($dateFrom));
                if ($dateFromValid) {
                    $where[] = 'l.created_at >= :date_from';
                    $params[':date_from'] = $dateFromValid . ' 00:00:00';
                }
            }

            if (!empty($dateTo)) {
                $dateToValid = date('Y-m-d', strtotime($dateTo));
                if ($dateToValid) {
                    $where[] = 'l.created_at <= :date_to';
                    $params[':date_to'] = $dateToValid . ' 23:59:59';
                }
            }

            if (!empty($ip)) {
                $ip = addcslashes($ip, '%_');
                $where[] = '(l.ip LIKE :ip1 OR l.real_ip LIKE :ip2)';
                $params[':ip1'] = '%' . $ip . '%';
                $params[':ip2'] = '%' . $ip . '%';
            }

            if (!empty($domain)) {
                $domain = addcslashes($domain, '%_');
                $where[] = 'd.domain_name LIKE :domain';
                $params[':domain'] = '%' . $domain . '%';
            }

            if ($domainId > 0) {
                $where[] = 'l.domain_id = :domain_id';
                $params[':domain_id'] = $domainId;
            }

            if (!empty($browser)) {
                $browser = addcslashes($browser, '%_');
                $where[] = 'l.browser LIKE :browser';
                $params[':browser'] = '%' . $browser . '%';
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // 限制导出数量（最多10000条）
            $exportLimit = min(10000, max(1, (int) ($_POST['limit'] ?? 10000)));

            // 查询日志数据
            $exportSql = "
                SELECT l.id, l.ip, l.real_ip, l.browser, l.browser_version,
                       l.os, l.os_version, l.user_agent, l.requested_url,
                       l.request_method, l.referer, l.created_at,
                       d.domain_name
                FROM access_logs l
                LEFT JOIN domains d ON l.domain_id = d.id
                {$whereClause}
                ORDER BY l.created_at DESC
                LIMIT " . (int)$exportLimit . "
            ";

            $logs = $db->query($exportSql, $params);

            if (empty($logs)) {
                jsonError('没有符合条件的数据可导出', 1);
            }

            // 生成导出文件
            $filename = 'access_logs_' . date('Ymd_His');

            if ($format === 'csv') {
                // CSV导出
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
                header('Cache-Control: no-cache, no-store, must-revalidate');

                // 输出BOM（确保Excel正确识别UTF-8）
                echo "\xEF\xBB\xBF";

                // 输出CSV表头
                $headers = ['ID', 'IP', '真实IP', '浏览器', '浏览器版本', '操作系统', '系统版本',
                            '请求URL', '请求方法', '来源页面', '域名', '访问时间'];
                echo implode(',', $headers) . "\n";

                // 输出数据行
                foreach ($logs as $log) {
                    // CSV字段转义函数
                    $csvEscape = function($field) {
                        $field = (string)$field;
                        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false || strpos($field, "\r") !== false) {
                            return '"' . str_replace('"', '""', $field) . '"';
                        }
                        return $field;
                    };
                    $row = [
                        $csvEscape($log['id']),
                        $csvEscape($log['ip']),
                        $csvEscape($log['real_ip'] ?? ''),
                        $csvEscape($log['browser'] ?? ''),
                        $csvEscape($log['browser_version'] ?? ''),
                        $csvEscape($log['os'] ?? ''),
                        $csvEscape($log['os_version'] ?? ''),
                        $csvEscape($log['requested_url'] ?? ''),
                        $csvEscape($log['request_method'] ?? 'GET'),
                        $csvEscape($log['referer'] ?? ''),
                        $csvEscape($log['domain_name'] ?? ''),
                        $csvEscape($log['created_at']),
                    ];
                    echo implode(',', $row) . "\n";
                }

                // 记录审计日志
                recordLog('log_export', "导出CSV格式日志 {$filename}.csv", ['count' => count($logs), 'format' => 'csv', 'filters' => $_POST]);
                error_log("[日志API] 管理员 {$user['username']} 导出了CSV格式日志，共 " . count($logs) . " 条");

                exit;
            } else {
                // JSON导出
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '.json"');
                header('Cache-Control: no-cache, no-store, must-revalidate');

                echo json_encode([
                    'export_time' => date('Y-m-d H:i:s'),
                    'total_count' => count($logs),
                    'data'        => $logs,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                // 记录审计日志
                recordLog('log_export', "导出JSON格式日志 {$filename}.json", ['count' => count($logs), 'format' => 'json', 'filters' => $_POST]);
                error_log("[日志API] 管理员 {$user['username']} 导出了JSON格式日志，共 " . count($logs) . " 条");

                exit;
            }

        } catch (\Throwable $e) {
            error_log('[日志API] 导出日志失败: ' . $e->getMessage());
            jsonError('导出日志失败', -1, 500);
        }
        break;

    // ==================== 封禁IP ====================
    case 'ban_ip':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证（M-09: ban_ip是状态变更操作，验证后消耗令牌防止重放）
        if (!\Core\CsrfProtection::validateFromRequest(true)) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        // M-04: 角色权限检查 - 只有super_admin和security角色可以封禁IP
        if (!$auth->hasPermission('ipban')) {
            jsonError('权限不足，只有安全专员和超级管理员可以执行IP封禁操作', -1, 403);
        }

        try {
            // 获取并验证参数
            $ip = sanitize($_POST['ip'] ?? '');
            $banType = sanitize($_POST['ban_type'] ?? '');
            $reason = sanitize($_POST['reason'] ?? '');

            // 参数校验
            if (empty($ip)) {
                jsonError('IP地址不能为空', 1);
            }

            // 验证IP格式
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                jsonError('IP地址格式无效', 1);
            }

            if (!in_array($banType, ['temporary', 'permanent'], true)) {
                jsonError('封禁类型无效，支持: temporary, permanent', 1);
            }

            if (empty($reason)) {
                jsonError('封禁理由不能为空', 1);
            }

            // 计算过期时间
            $expiresAt = null;
            if ($banType === 'temporary') {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            }

            // 检查是否已存在该IP的active封禁记录
            $existingBan = $db->queryOne(
                "SELECT id FROM ip_bans WHERE ip = :ip AND status = 'active'",
                [':ip' => $ip]
            );

            if ($existingBan) {
                // 更新已有记录
                $db->update('ip_bans', [
                    'ban_type'   => $banType,
                    'reason'     => $reason,
                    'expires_at' => $expiresAt,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', [':id' => $existingBan['id']]);

                // 记录审计日志
                recordLog('ipban_update', "更新IP封禁: {$ip}", ['ip' => $ip, 'ban_id' => $existingBan['id'], 'ban_type' => $banType, 'reason' => $reason]);
                error_log("[日志API] 管理员 {$user['username']} 更新IP封禁: {$ip} (ID: {$existingBan['id']})");
            } else {
                // 插入新记录
                $db->insert('ip_bans', [
                    'ip'               => $ip,
                    'real_ip'          => $ip,
                    'ban_type'         => $banType,
                    'reason'           => $reason,
                    'status'           => 'active',
                    'expires_at'       => $expiresAt,
                    'banned_at'        => date('Y-m-d H:i:s'),
                    'banned_by'        => $user['id'],
                    'detection_source' => 'manual',
                    'created_at'       => date('Y-m-d H:i:s'),
                    'updated_at'       => date('Y-m-d H:i:s'),
                ]);

                // 记录审计日志
                recordLog('ipban_create', "封禁IP: {$ip}", ['ip' => $ip, 'ban_type' => $banType, 'reason' => $reason]);
                error_log("[日志API] 管理员 {$user['username']} 封禁IP: {$ip} (类型: {$banType})");
            }

            jsonSuccess(null, 'IP封禁成功');

        } catch (\Throwable $e) {
            error_log('[日志API] IP封禁失败: ' . $e->getMessage());
            jsonError('IP封禁失败', -1, 500);
        }
        break;

    // ==================== 清理旧日志 ====================
    case 'clean':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证（M-09: clean是状态变更操作，验证后消耗令牌防止重放）
        if (!\Core\CsrfProtection::validateFromRequest(true)) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        // M-04: 角色权限检查 - 只有super_admin可以清理日志
        if ($user['role'] !== 'super_admin') {
            jsonError('权限不足，只有超级管理员可以执行日志清理操作', -1, 403);
        }

        try {
            $days = (int) ($_POST['days'] ?? 30);

            // 参数校验
            if ($days < 1) {
                jsonError('清理天数不能小于1', 1);
            }
            if ($days < 7) {
                jsonError('为防止误操作，清理天数不能少于7天', 1);
            }
            if ($days > 365) {
                jsonError('清理天数不能超过365天', 1);
            }

            // 检查清理前的日志总数
            $beforeCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM access_logs"
            )['cnt'] ?? 0);

            // 执行清理
            $deletedCount = $db->execute(
                "DELETE FROM access_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );

            // 注意：OPTIMIZE TABLE 会锁表，大表可能导致服务不可用
            // 建议通过定时任务（cron）在低峰期执行
            // if ($deletedCount > 0) {
            //     $db->execute("OPTIMIZE TABLE access_logs");
            // }

            // 记录审计日志和操作日志
            recordLog('access_log_clean', "清理访问日志", ['deleted_count' => $deletedCount, 'days' => $days, 'before_total' => $beforeCount]);
            error_log("[日志API] 管理员 {$user['username']} 清理了 {$deletedCount} 条 {$days} 天前的日志");

            jsonSuccess([
                'deleted_count' => $deletedCount,
                'before_total'  => $beforeCount,
                'clean_days'    => $days,
            ], "成功清理 {$deletedCount} 条日志记录");

        } catch (\Throwable $e) {
            error_log('[日志API] 清理日志失败: ' . $e->getMessage());
            jsonError('清理日志失败', -1, 500);
        }
        break;

    // ==================== 未知操作 ====================
    default:
        jsonError('未知操作类型，支持的操作: list, stats, export, clean, ban_ip', -1, 400);
}
