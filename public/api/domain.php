<?php
/**
 * 灯塔DNS拦截响应平台 - 域名管理API
 *
 * 提供域名的增删改查、批量操作和统计功能。
 * 所有接口均需要管理员登录认证。
 *
 * 接口列表：
 *   GET    /api/domain.php?action=list           - 获取域名列表（支持过期/违规筛选、搜索、分页）
 *   POST   /api/domain.php?action=create          - 创建新域名记录
 *   PUT    /api/domain.php?action=update&id=xxx   - 更新域名信息
 *   DELETE /api/domain.php?action=delete&id=xxx   - 删除域名记录
 *   POST   /api/domain.php?action=batch_delete    - 批量删除域名
 *   GET    /api/domain.php?action=stats           - 获取域名统计数据
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

    // ==================== 获取域名列表 ====================
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
            $type = sanitize($_GET['type'] ?? '');       // expired | violation
            $status = sanitize($_GET['status'] ?? '');    // active | released | pending_review
            $search = sanitize($_GET['search'] ?? '');    // 搜索关键词

            // 构建WHERE条件
            $where = [];
            $params = [];

            // 按类型筛选
            if (!empty($type) && in_array($type, ['expired', 'violation'], true)) {
                $where[] = 'd.type = :type';
                $params[':type'] = $type;
            }

            // 按状态筛选
            if (!empty($status) && in_array($status, ['active', 'inactive', 'released', 'pending_review'], true)) {
                $where[] = 'd.status = :status';
                $params[':status'] = $status;
            }

            // 搜索关键词（域名名称或客户邮箱）
            if (!empty($search)) {
                $search = addcslashes($search, '%_');
                $where[] = '(d.domain_name LIKE :search1 OR d.customer_email LIKE :search2)';
                $params[':search1'] = '%' . $search . '%';
                $params[':search2'] = '%' . $search . '%';
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // 查询总数
            $totalSql = "SELECT COUNT(*) as total FROM domains d {$whereClause}";
            $totalResult = $db->queryOne($totalSql, $params);
            $total = (int) ($totalResult['total'] ?? 0);

            // 查询域名列表
            $offset = ($page - 1) * $perPage;
            $listSql = "SELECT d.* FROM domains d {$whereClause} ORDER BY d.created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
            $domains = $db->query($listSql, $params);

            jsonSuccess([
                'domains'  => $domains,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[域名API] 获取列表失败: ' . $e->getMessage());
            jsonError('获取域名列表失败', -1, 500);
        }
        break;

    // ==================== 创建域名记录 ====================
    case 'create':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            // 获取并验证输入参数
            $domainName = sanitize($_POST['domain_name'] ?? '');
            $type = sanitize($_POST['type'] ?? '');
            $expireDate = sanitize($_POST['expire_date'] ?? '');
            $customerEmail = sanitize($_POST['customer_email'] ?? '');
            $violationReason = sanitize($_POST['violation_reason'] ?? '');

            // 参数校验
            if (empty($domainName)) {
                jsonError('域名不能为空', 1);
            }

            // 验证域名格式
            if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domainName)) {
                jsonError('域名格式无效', 1);
            }

            // 验证域名类型
            if (!in_array($type, ['expired', 'violation'], true)) {
                jsonError('域名类型无效，支持: expired, violation', 1);
            }

            // 验证过期日期（expired类型必填）
            if ($type === 'expired' && empty($expireDate)) {
                jsonError('到期域名必须填写过期日期', 1);
            }
            if (!empty($expireDate) && !strtotime($expireDate)) {
                jsonError('过期日期格式无效', 1);
            }

            // 验证违规原因（violation类型必填）
            if ($type === 'violation' && empty($violationReason)) {
                jsonError('审查域名必须填写违规原因', 1);
            }

            // 验证客户邮箱（可选，但格式需正确）
            if (!empty($customerEmail) && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                jsonError('客户邮箱格式无效', 1);
            }

            // 检查域名是否已存在
            $existing = $db->queryOne(
                "SELECT id, domain_name, type, status, expire_date, customer_email, customer_name
                 FROM domains WHERE domain_name = ? LIMIT 1",
                [$domainName]
            );
            if ($existing) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'code'    => 'DOMAIN_EXISTS',
                    'message' => '该域名已存在',
                    'data'    => ['existing' => $existing]
                ]);
                exit;
            }

            // 构建域名数据
            $data = [
                'domain_name'      => $domainName,
                'type'             => $type,
                'status'           => 'active',
                'expire_date'      => !empty($expireDate) ? $expireDate : null,
                'violation_reason' => !empty($violationReason) ? $violationReason : null,
                'customer_email'   => !empty($customerEmail) ? $customerEmail : null,
                'review_status'    => 'pending',
                'review_note'      => null,
                'release_date'     => null,
            ];

            // 插入新域名记录
            $newId = $db->insert('domains', $data);

            // 记录操作日志
            error_log("[域名API] 管理员 {$user['username']} 创建域名: {$domainName} (ID: {$newId})");
            recordLog([
                'action'      => 'create',
                'module'      => 'domains',
                'description' => '创建域名: ' . $domainName,
                'target_type' => 'domain',
                'target_id'   => (string)$newId,
                'target_name' => $domainName,
                'result'      => 'success',
            ]);

            jsonSuccess([
                'id' => $newId,
            ], '域名创建成功');

        } catch (\Throwable $e) {
            error_log('[域名API] 创建失败: ' . $e->getMessage());
            jsonError('域名创建失败', -1, 500);
        }
        break;

    // ==================== 更新域名信息 ====================
    case 'update':
        if ($method !== 'POST' && $method !== 'PUT') {
            jsonError('请求方法不允许，请使用POST或PUT', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            // 获取域名ID
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError('缺少域名ID', 1);
            }

            // 检查域名是否存在
            $domain = $db->queryOne("SELECT * FROM domains WHERE id = ?", [$id]);
            if (!$domain) {
                jsonError('域名不存在', 2, 404);
            }

            // 获取更新参数
            $domainName = isset($_POST['domain_name']) ? sanitize($_POST['domain_name']) : null;
            $expireDate = isset($_POST['expire_date']) ? sanitize($_POST['expire_date']) : null;
            $customerEmail = isset($_POST['customer_email']) ? sanitize($_POST['customer_email']) : null;
            $customerName = isset($_POST['customer_name']) ? sanitize($_POST['customer_name']) : null;
            $notes = isset($_POST['notes']) ? sanitize($_POST['notes']) : null;
            $violationReason = isset($_POST['violation_reason']) ? sanitize($_POST['violation_reason']) : null;
            $status = isset($_POST['status']) ? sanitize($_POST['status']) : null;
            // 空字符串视为未传值，避免误判
            if ($status !== null && $status === '') {
                $status = null;
            }
            $reviewDate = isset($_POST['review_date']) ? sanitize($_POST['review_date']) : null;

            // 如果是到期域名编辑，验证域名类型
            $isExpiredEdit = isset($_POST['expired_edit']) && $_POST['expired_edit'] === '1';
            if ($isExpiredEdit && $domain['type'] !== 'expired') {
                jsonError('该域名不是过期类型域名', 2, 400);
            }

            // 构建更新数据
            $updateData = [];

            if ($domainName !== null && !empty($domainName)) {
                // 验证域名格式
                if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domainName)) {
                    jsonError('域名格式无效', 1);
                }
                // 检查重名（排除自身）
                $dup = $db->queryOne("SELECT id FROM domains WHERE domain_name = ? AND id != ?", [$domainName, $id]);
                if ($dup) {
                    jsonError('该域名已存在', 2, 409);
                }
                $updateData['domain_name'] = $domainName;
            }

            if ($expireDate !== null) {
                if (!empty($expireDate) && !strtotime($expireDate)) {
                    jsonError('过期日期格式无效', 1);
                }
                $updateData['expire_date'] = !empty($expireDate) ? $expireDate : null;
            }

            if ($customerEmail !== null) {
                if (!empty($customerEmail) && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                    jsonError('客户邮箱格式无效', 1);
                }
                $updateData['customer_email'] = !empty($customerEmail) ? $customerEmail : null;
            }

            if ($customerName !== null) {
                $updateData['customer_name'] = !empty($customerName) ? $customerName : null;
            }

            if ($notes !== null) {
                $updateData['notes'] = !empty($notes) ? $notes : null;
            }

            if ($violationReason !== null) {
                $updateData['violation_reason'] = !empty($violationReason) ? $violationReason : null;
            }

            if ($status !== null) {
                // 验证状态值
                if (!in_array($status, ['active', 'inactive', 'released', 'pending_review'], true)) {
                    jsonError('无效的状态值', 1);
                }
                $updateData['status'] = $status;

                // 如果状态变更为已释放，记录释放时间
                if ($status === 'released') {
                    $updateData['release_date'] = date('Y-m-d H:i:s');
                }
            }

            if ($reviewDate !== null) {
                if (!empty($reviewDate) && !strtotime($reviewDate)) {
                    jsonError('审核日期格式无效', 1);
                }
                $updateData['review_date'] = !empty($reviewDate) ? $reviewDate . ' 00:00:00' : null;
            }

            $deleteDate = isset($_POST['delete_date']) ? sanitize($_POST['delete_date']) : null;
            if ($deleteDate !== null) {
                $updateData['delete_date'] = !empty($deleteDate) ? $deleteDate : null;
            }

            // 执行更新
            if (!empty($updateData)) {
                error_log("[域名API] 更新数据: " . json_encode($updateData) . " | ID: {$id}");
                $db->update('domains', $updateData, 'id = :id', [':id' => $id]);

                // 记录操作日志
                error_log("[域名API] 管理员 {$user['username']} 更新域名 ID: {$id}");
                recordLog([
                    'action'      => 'update',
                    'module'      => 'domains',
                    'description' => '更新域名: ' . ($domain['domain_name'] ?? '') . ' (ID: ' . $id . ')',
                    'target_type' => 'domain',
                    'target_id'   => (string)$id,
                    'target_name' => $domain['domain_name'] ?? '',
                    'result'      => 'success',
                ]);
            } else {
                jsonError('没有需要更新的字段', 1);
            }

            jsonSuccess(null, '域名更新成功');

        } catch (\Throwable $e) {
            error_log('[域名API] 更新失败: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine());
            jsonError('域名更新失败', -1, 500);
        }
        break;

    // ==================== 删除域名记录 ====================
    case 'delete':
        if ($method !== 'POST' && $method !== 'DELETE') {
            jsonError('请求方法不允许，请使用POST或DELETE', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            // 获取域名ID
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError('缺少域名ID', 1);
            }

            // 检查域名是否存在
            $domain = $db->queryOne("SELECT * FROM domains WHERE id = ?", [$id]);
            if (!$domain) {
                jsonError('域名不存在', 2, 404);
            }

            // 删除域名（关联的申辩记录会因外键级联删除）
            $db->delete('domains', 'id = :id', [':id' => $id]);

            // 记录操作日志
            error_log("[域名API] 管理员 {$user['username']} 删除域名: {$domain['domain_name']} (ID: {$id})");
            recordLog([
                'action'      => 'delete',
                'module'      => 'domains',
                'description' => '删除域名: ' . $domain['domain_name'],
                'target_type' => 'domain',
                'target_id'   => (string)$id,
                'target_name' => $domain['domain_name'],
                'result'      => 'success',
            ]);

            jsonSuccess(null, '域名已删除');

        } catch (\Throwable $e) {
            error_log('[域名API] 删除失败: ' . $e->getMessage());
            jsonError('域名删除失败', -1, 500);
        }
        break;

    // ==================== 批量删除域名 ====================
    case 'batch_delete':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            // 获取待删除的ID数组
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                jsonError('请选择要删除的域名', 1);
            }

            // 验证并过滤ID
            $validIds = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
            if (empty($validIds)) {
                jsonError('无效的域名ID', 1);
            }

            // 限制单次批量删除数量
            if (count($validIds) > 100) {
                jsonError('单次最多删除100条记录', 1);
            }

            // 构建IN条件占位符
            $placeholders = implode(',', array_fill(0, count($validIds), '?'));

            // 检查是否有活跃申辩关联到待删除的域名
            $activeAppeals = $db->queryOne(
                "SELECT COUNT(*) as cnt FROM appeals WHERE domain_id IN ({$placeholders}) AND status IN ('pending', 'processing')",
                array_values($validIds)
            );
            if ((int)($activeAppeals['cnt'] ?? 0) > 0) {
                jsonError('所选域名中存在待处理的申辩，请先处理相关申辩', 2);
            }

            // 执行批量删除
            $deletedCount = $db->execute(
                "DELETE FROM domains WHERE id IN ({$placeholders})",
                array_values($validIds)
            );

            // 记录操作日志
            error_log("[域名API] 管理员 {$user['username']} 批量删除 {$deletedCount} 个域名");
            recordLog([
                'action'      => 'batch_delete',
                'module'      => 'domains',
                'description' => '批量删除 ' . $deletedCount . ' 个域名',
                'target_type' => 'domain',
                'target_id'   => implode(',', $validIds),
                'target_name' => '批量删除',
                'result'      => 'success',
            ]);

            jsonSuccess([
                'deleted_count' => $deletedCount,
            ], "成功删除 {$deletedCount} 条记录");

        } catch (\Throwable $e) {
            error_log('[域名API] 批量删除失败: ' . $e->getMessage());
            jsonError('批量删除失败', -1, 500);
        }
        break;

    // ==================== 获取域名统计数据 ====================
    case 'stats':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        try {
            // 查询各类型域名数量
            $total = (int) ($db->queryOne("SELECT COUNT(*) as cnt FROM domains")['cnt'] ?? 0);
            $expiredCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM domains WHERE type = 'expired' AND status = 'active'"
            )['cnt'] ?? 0);
            $violationCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM domains WHERE type = 'violation' AND status = 'active'"
            )['cnt'] ?? 0);
            $reviewCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM domains WHERE status = 'pending_review'"
            )['cnt'] ?? 0);
            $releasedCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM domains WHERE status = 'released'"
            )['cnt'] ?? 0);

            // 近7天新增域名数
            $weekNewCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM domains WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )['cnt'] ?? 0);

            // 近30天新增域名数
            $monthNewCount = (int) ($db->queryOne(
                "SELECT COUNT(*) as cnt FROM domains WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            )['cnt'] ?? 0);

            jsonSuccess([
                'total'          => $total,
                'expired_count'  => $expiredCount,
                'violation_count'=> $violationCount,
                'review_count'   => $reviewCount,
                'released_count' => $releasedCount,
                'week_new_count' => $weekNewCount,
                'month_new_count'=> $monthNewCount,
            ], '获取统计成功');

        } catch (\Throwable $e) {
            error_log('[域名API] 获取统计失败: ' . $e->getMessage());
            jsonError('获取统计数据失败', -1, 500);
        }
        break;

    // ==================== 未知操作 ====================
    default:
        jsonError('未知操作类型，支持的操作: list, create, update, delete, batch_delete, stats', -1, 400);
}
