<?php
/**
 * 灯塔DNS拦截响应平台 - 公共API
 *
 * 提供无需管理员认证的公共接口，用于首页统计展示等。
 *
 * 接口列表：
 *   GET /api/public.php?action=stats - 获取公共统计数据
 */

use Core\Database;

defined('BASEPATH') || define('BASEPATH', dirname(__DIR__, 2));

require_once BASEPATH . '/includes/functions.php';
initDebug();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

// L-07: 建议在生产环境中为公共API添加速率限制，
// 防止恶意或意外的高频请求消耗服务器资源。推荐方案：
// - 基于IP的速率限制（如每分钟最多60次请求）
// - 使用Redis或APCu实现滑动窗口计数器
// - 对于stats接口可适当放宽（已有60秒浏览器缓存）

try {
    $config = require BASEPATH . '/config/config.php';
    $db = Database::getInstance($config);
} catch (\Throwable $e) {
    jsonError('系统初始化失败', -1, 500);
}

$action = sanitize($_GET['action'] ?? '');

switch ($action) {
    case 'stats':
        try {
            // 域名总数（仅active状态）
            $domainStats = $db->queryOne(
                "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN type = 'expired' THEN 1 ELSE 0 END) as expired_count,
                    SUM(CASE WHEN type = 'violation' THEN 1 ELSE 0 END) as violation_count
                 FROM domains WHERE status = 'active'"
            );

            // 活跃IP封禁数
            $banStats = $db->queryOne(
                "SELECT COUNT(*) as total FROM ip_bans
                 WHERE status = 'active' AND (expires_at IS NULL OR expires_at > NOW())"
            );

            // 今日访问量
            $logStats = $db->queryOne(
                "SELECT COUNT(*) as total FROM access_logs
                 WHERE created_at >= CURDATE()"
            );

            jsonSuccess([
                'domains' => [
                    'total'     => (int) ($domainStats['total'] ?? 0),
                    'expired'   => (int) ($domainStats['expired_count'] ?? 0),
                    'violation' => (int) ($domainStats['violation_count'] ?? 0),
                ],
                'bans' => [
                    'active' => (int) ($banStats['total'] ?? 0),
                ],
                'logs' => [
                    'today' => (int) ($logStats['total'] ?? 0),
                ],
            ], '获取成功');
        } catch (\Throwable $e) {
            error_log('[公共API] 获取统计失败: ' . $e->getMessage());
            jsonError('获取统计数据失败', -1, 500);
        }
        break;

    default:
        jsonError('未知操作类型', -1, 400);
        break;
}
