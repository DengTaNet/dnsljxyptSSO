<?php
/**
 * 灯塔DNS拦截响应平台 - 聊天API
 *
 * 提供管理员与客户之间的实时聊天功能，包括会话管理、消息收发、
 * 文件上传、未读消息计数等。
 * 管理员接口需要登录认证，客户接口通过验证Token认证。
 *
 * 接口列表：
 *   GET   /api/chat.php?action=list               - 获取会话列表（管理员）
 *   GET   /api/chat.php?action=messages&conversation_id=xxx - 获取会话消息历史
 *   POST  /api/chat.php?action=send                - 管理员发送消息
 *   GET   /api/chat.php?action=customer_list&verification_token=xxx - 客户获取会话列表
 *   GET   /api/chat.php?action=customer_messages&conversation_id=xxx&verification_token=xxx - 客户获取消息历史
 *   POST  /api/chat.php?action=customer_send       - 客户发送消息（需验证Token）
 *   POST  /api/chat.php?action=create              - 创建新会话
 *   POST  /api/chat.php?action=close&id=xxx        - 关闭会话
 *   GET   /api/chat.php?action=unread_count        - 获取未读消息总数
 *   POST  /api/chat.php?action=mark_read           - 标记消息已读
 *   POST  /api/chat.php?action=upload              - 上传聊天文件
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

// 获取上传配置
$uploadConfig = $config['upload'] ?? [];

/**
 * 自动关闭不活跃会话（10分钟无消息且无未读消息）
 * 在每次API请求时作为中间件调用
 */
function closeInactiveConversations($db): void
{
    try {
        // 查找所有active会话中，last_message_at超过10分钟且无未读管理员消息的会话
        $inactiveConversations = $db->query(
            "SELECT c.id FROM chat_conversations c
             WHERE c.status = 'active'
               AND c.last_message_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
               AND (c.unread_admin = 0 OR c.unread_admin IS NULL)"
        );

        foreach ($inactiveConversations as $conv) {
            // 再次确认没有未读消息（防止竞态条件）
            $unreadCount = $db->queryOne(
                "SELECT COUNT(*) as cnt FROM chat_messages
                 WHERE conversation_id = ? AND sender_type = 'customer' AND is_read = 0",
                [$conv['id']]
            );

            if ((int)($unreadCount['cnt'] ?? 0) === 0) {
                $db->execute(
                    "UPDATE chat_conversations SET status = 'closed', updated_at = NOW() WHERE id = ? AND status = 'active'",
                    [$conv['id']]
                );
                // 插入系统消息通知客户
                $db->insert('chat_messages', [
                    'conversation_id' => $conv['id'],
                    'sender_type'     => 'system',
                    'sender_id'       => null,
                    'message'         => '会话因长时间无消息已自动关闭，感谢您的咨询。',
                    'message_type'    => 'text',
                ]);
            }
        }
    } catch (\Throwable $e) {
        // 静默失败，不影响主流程
        error_log('[聊天API] 自动关闭不活跃会话失败: ' . $e->getMessage());
    }
}

// 路由分发
switch ($action) {

    // ==================== 获取会话列表（管理员） ====================
    case 'list':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // 自动关闭不活跃会话
        closeInactiveConversations($db);

        try {
            // 分页参数
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));

            // 筛选参数
            $search = sanitize($_GET['search'] ?? '');
            $status = sanitize($_GET['status'] ?? '');

            // 构建WHERE条件
            $where = [];
            $params = [];

            if (!empty($search)) {
                $search = addcslashes($search, '%_');
                $where[] = '(c.customer_email LIKE :search1 OR c.customer_name LIKE :search2 OR c.subject LIKE :search3)';
                $params[':search1'] = '%' . $search . '%';
                $params[':search2'] = '%' . $search . '%';
                $params[':search3'] = '%' . $search . '%';
            }

            if (!empty($status) && in_array($status, ['active', 'closed'], true)) {
                $where[] = 'c.status = :status';
                $params[':status'] = $status;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // 查询总数
            $totalSql = "SELECT COUNT(*) as total FROM chat_conversations c {$whereClause}";
            $totalResult = $db->queryOne($totalSql, $params);
            $total = (int) ($totalResult['total'] ?? 0);

            // 查询会话列表（关联域名信息、未读消息数）
            $offset = ($page - 1) * $perPage;
            $listSql = "
                SELECT c.*,
                       d.domain_name,
                       a.username AS assigned_to_name,
                       (SELECT COUNT(*) FROM chat_messages cm
                        WHERE cm.conversation_id = c.id
                          AND cm.sender_type = 'customer'
                          AND cm.is_read = 0) AS unread_count
                FROM chat_conversations c
                LEFT JOIN domains d ON c.domain_id = d.id
                LEFT JOIN admin_users a ON c.assigned_to = a.id
                {$whereClause}
                ORDER BY c.last_message_at DESC, c.created_at DESC
                LIMIT " . (int)$perPage . " OFFSET " . (int)$offset . "
            ";

            $conversations = $db->query($listSql, $params);

            // 补充 customer_ip：如果为空，尝试从该会话的客户消息中获取IP
            foreach ($conversations as &$conv) {
                if (empty($conv['customer_ip'])) {
                    $ipMsg = $db->queryOne(
                        "SELECT customer_ip FROM chat_messages
                         WHERE conversation_id = ? AND sender_type = 'customer' AND customer_ip IS NOT NULL AND customer_ip != ''
                         ORDER BY created_at ASC LIMIT 1",
                        [$conv['id']]
                    );
                    if ($ipMsg && !empty($ipMsg['customer_ip'])) {
                        $conv['customer_ip'] = $ipMsg['customer_ip'];
                        // 回写到会话表
                        $db->execute(
                            "UPDATE chat_conversations SET customer_ip = ? WHERE id = ?",
                            [$ipMsg['customer_ip'], $conv['id']]
                        );
                    }
                }
            }
            unset($conv);

            jsonSuccess([
                'conversations' => $conversations,
                'total'         => $total,
                'page'          => $page,
                'per_page'      => $perPage,
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 获取会话列表失败: ' . $e->getMessage());
            jsonError('获取会话列表失败', -1, 500);
        }
        break;

    // ==================== 获取会话消息历史 ====================
    case 'messages':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // 自动关闭不活跃会话
        closeInactiveConversations($db);

        try {
            $conversationId = (int) ($_GET['conversation_id'] ?? $_GET['id'] ?? 0);
            if ($conversationId <= 0) {
                jsonError('缺少会话ID', 1);
            }

            // 验证会话存在
            $conversation = $db->queryOne(
                "SELECT * FROM chat_conversations WHERE id = ?",
                [$conversationId]
            );
            if (!$conversation) {
                jsonError('会话不存在', 2, 404);
            }

            // 分页参数
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));

            // 查询消息总数
            $totalResult = $db->queryOne(
                "SELECT COUNT(*) as total FROM chat_messages WHERE conversation_id = ?",
                [$conversationId]
            );
            $total = (int) ($totalResult['total'] ?? 0);

            // 查询消息列表
            $offset = ($page - 1) * $perPage;
            $messages = $db->query(
                "SELECT m.*,
                        CASE WHEN m.sender_type = 'admin' THEN a.username ELSE NULL END AS sender_name
                 FROM chat_messages m
                 LEFT JOIN admin_users a ON m.sender_type = 'admin' AND m.sender_id = a.id
                 WHERE m.conversation_id = ?
                 ORDER BY m.created_at ASC
                 LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
                [$conversationId]
            );

            // 自动标记客户消息为已读
            $db->execute(
                "UPDATE chat_messages SET is_read = 1, read_at = NOW()
                 WHERE conversation_id = ? AND sender_type = 'customer' AND is_read = 0",
                [$conversationId]
            );

            jsonSuccess([
                'messages'       => $messages,
                'total'          => $total,
                'page'           => $page,
                'per_page'       => $perPage,
                'conversation_id'=> $conversationId,
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 获取消息历史失败: ' . $e->getMessage());
            jsonError('获取消息历史失败', -1, 500);
        }
        break;

    // ==================== 管理员发送消息 ====================
    case 'send':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $conversationId = (int) ($_POST['conversation_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $messageType = sanitize($_POST['message_type'] ?? 'text');

            // 所有消息内容统一清理
            $message = trim($message);

            // 文本消息清理潜在危险标签
            if ($messageType === 'text') {
                $message = sanitize(strip_tags($message));
            }

            // 参数校验
            if ($conversationId <= 0) {
                jsonError('缺少会话ID', 1);
            }

            // 验证会话存在且活跃
            $conversation = $db->queryOne(
                "SELECT * FROM chat_conversations WHERE id = ? AND status = 'active'",
                [$conversationId]
            );
            if (!$conversation) {
                jsonError('会话不存在或已关闭', 2, 404);
            }

            // 验证消息类型
            $validTypes = ['text', 'image', 'video', 'file'];
            if (!in_array($messageType, $validTypes, true)) {
                jsonError('无效的消息类型', 1);
            }

            // 文本消息不能为空
            if ($messageType === 'text' && empty($message)) {
                jsonError('消息内容不能为空', 1);
            }

            // 消息长度限制
            if (mb_strlen($message) > 10000) {
                jsonError('消息内容过长，最多10000个字符', 1);
            }

            // 处理文件上传（如果有）
            $filePath = null;
            $fileName = null;
            $fileSize = null;

            if (in_array($messageType, ['image', 'video', 'file'], true) && !empty($_FILES['file'])) {
                $uploadResult = handleChatUpload($_FILES['file'], $messageType, $uploadConfig);
                if (!$uploadResult['success']) {
                    jsonError($uploadResult['message'], 1);
                }
                $filePath = $uploadResult['path'];
                $fileName = $uploadResult['original_name'];
                $fileSize = $uploadResult['size'];
            }

            // 插入消息
            $msgId = $db->insert('chat_messages', [
                'conversation_id' => $conversationId,
                'sender_type'     => 'admin',
                'sender_id'       => $user['id'],
                'message'         => $message,
                'message_type'    => $messageType,
                'file_path'       => $filePath,
                'file_name'       => $fileName,
                'file_size'       => $fileSize,
            ]);

            // 更新会话最后消息信息
            $preview = $messageType === 'text'
                ? mb_substr($message, 0, 200)
                : "[{$messageType}] " . ($fileName ?? '文件');
            $db->execute(
                "UPDATE chat_conversations
                 SET last_message_at = NOW(),
                     last_message_preview = ?,
                     unread_customer = unread_customer + 1,
                     updated_at = NOW()
                 WHERE id = ?",
                [$preview, $conversationId]
            );

            jsonSuccess([
                'message_id'      => $msgId,
                'conversation_id' => $conversationId,
                'message_type'    => $messageType,
                'file_path'       => $filePath,
            ], '消息发送成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 管理员发送消息失败: ' . $e->getMessage());
            jsonError('消息发送失败', -1, 500);
        }
        break;

    // ==================== 客户发送消息 ====================
    case 'customer_send':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // FIX: 添加CSRF校验，防止CSRF攻击
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('安全验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $conversationId = (int) ($_POST['conversation_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $messageType = sanitize($_POST['message_type'] ?? 'text');
            $verificationToken = sanitize($_POST['verification_token'] ?? '');
            $customerEmail = sanitize($_POST['customer_email'] ?? '');

            // 所有消息内容统一清理
            $message = trim($message);

            // 文本消息清理潜在危险标签
            if ($messageType === 'text') {
                $message = sanitize(strip_tags($message));
            }

            // FIX: 客户身份验证 - 支持verificationToken或customerEmail验证
            $tokenValid = false;
            $conversation = null;

            // 方式1: 通过verificationToken验证（申诉流程中已验证邮箱）
            if (!empty($verificationToken)) {
                if (isset($_SESSION['chat_customer_token'])) {
                    $sessionToken = $_SESSION['chat_customer_token'];
                    if (hash_equals($sessionToken['token'], $verificationToken)
                        && time() < ($sessionToken['expires'] ?? 0)
                    ) {
                        $tokenValid = true;
                        $customerEmail = $sessionToken['email'];
                    }
                }
            }

            // 方式2: 通过customerEmail验证（直接联系客服，未经过申诉流程）
            // 验证邮箱格式有效性，并检查是否与会话关联
            if (!$tokenValid && !empty($customerEmail)) {
                if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                    jsonError('请输入有效的邮箱地址', 1);
                }
                // 邮箱格式有效，允许通过邮箱验证
                $tokenValid = true;
            }

            // FIX: 如果没有有效的验证方式，拒绝请求
            if (!$tokenValid) {
                jsonError('身份验证失败，请输入有效的邮箱地址', 2, 403);
            }

            // 客户消息频率限制：每分钟最多发送5条消息（使用数据库频率限制器）
            $chatLimiter = new \Core\RateLimiter($db, 'chat_customer', getClientIp() . '_' . md5($verificationToken ?: $customerEmail), 5, 60);
            if ($chatLimiter->isExceeded()) {
                jsonError('消息发送过于频繁，请稍后再试', 3, 429);
            }

            // 验证会话存在且活跃（如果之前未通过email验证）
            if (!$conversation && $conversationId > 0) {
                $conversation = $db->queryOne(
                    "SELECT * FROM chat_conversations WHERE id = ? AND status = 'active'",
                    [$conversationId]
                );
            }

            // 如果会话不存在，自动创建新会话
            if (!$conversation) {
                // 从token中获取域名信息
                $domainName = trim(str_replace("\0", '', $_POST['domain'] ?? ''));
                // 域名格式验证
                if (!empty($domainName) && !preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domainName)) {
                    jsonError('域名格式无效', 1);
                }
                $domainId = 0;
                if (!empty($domainName)) {
                    $domainRecord = $db->queryOne(
                        "SELECT id FROM domains WHERE domain_name = ? AND status = 'active' LIMIT 1",
                        [$domainName]
                    );
                    if ($domainRecord) $domainId = (int) $domainRecord['id'];
                }

                $conversationId = $db->insert('chat_conversations', [
                    'domain_id'       => $domainId,
                    'domain_name'     => $domainName,
                    'customer_email'  => $customerEmail,
                    'customer_ip'     => getClientIp(),
                    'status'          => 'active',
                    'last_message_at' => date('Y-m-d H:i:s'),
                ]);

                $conversation = [
                    'id'             => $conversationId,
                    'customer_email' => $customerEmail,
                    'domain_id'      => $domainId,
                    'domain_name'    => $domainName,
                    'status'         => 'active',
                ];
            } else {
                // 验证客户身份与会话归属匹配
                if (!empty($conversation['customer_email']) && !empty($customerEmail)) {
                    if (strtolower($conversation['customer_email']) !== strtolower($customerEmail)) {
                        jsonError('无权访问该会话', 2, 403);
                    }
                }
            }

            // 验证消息类型
            $validTypes = ['text', 'image', 'video', 'file'];
            if (!in_array($messageType, $validTypes, true)) {
                jsonError('无效的消息类型', 1);
            }

            // 文本消息不能为空
            if ($messageType === 'text' && empty($message)) {
                jsonError('消息内容不能为空', 1);
            }

            // 消息长度限制
            if (mb_strlen($message) > 10000) {
                jsonError('消息内容过长，最多10000个字符', 1);
            }

            // 处理文件上传（如果有）
            $filePath = null;
            $fileName = null;
            $fileSize = null;

            if (in_array($messageType, ['image', 'video', 'file'], true) && !empty($_FILES['file'])) {
                $uploadResult = handleChatUpload($_FILES['file'], $messageType, $uploadConfig);
                if (!$uploadResult['success']) {
                    jsonError($uploadResult['message'], 1);
                }
                $filePath = $uploadResult['path'];
                $fileName = $uploadResult['original_name'];
                $fileSize = $uploadResult['size'];
            }

            // 插入消息
            $msgId = $db->insert('chat_messages', [
                'conversation_id' => $conversationId,
                'sender_type'     => 'customer',
                'sender_id'       => null,
                'message'         => $message,
                'message_type'    => $messageType,
                'file_path'       => $filePath,
                'file_name'       => $fileName,
                'file_size'       => $fileSize,
                'customer_ip'     => getClientIp(),
            ]);

            // 更新会话最后消息信息
            $preview = $messageType === 'text'
                ? mb_substr($message, 0, 200)
                : "[{$messageType}] " . ($fileName ?? '文件');
            $db->execute(
                "UPDATE chat_conversations
                 SET last_message_at = NOW(),
                     last_message_preview = ?,
                     unread_admin = unread_admin + 1,
                     updated_at = NOW()
                 WHERE id = ?",
                [$preview, $conversationId]
            );

            // 更新频率限制计数器（使用数据库频率限制器）
            $chatLimiter->increment();

            jsonSuccess([
                'message_id'      => $msgId,
                'conversation_id' => $conversationId,
                'message_type'    => $messageType,
                'file_path'       => $filePath,
            ], '消息发送成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 客户发送消息失败: ' . $e->getMessage());
            jsonError('消息发送失败', -1, 500);
        }
        break;

    // ==================== 客户获取会话列表 ====================
    case 'customer_list':
        // 只读公共接口，无需CSRF验证（仅查询数据，不修改状态）
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        try {
            $verificationToken = sanitize($_GET['verification_token'] ?? '');
            $customerEmail = sanitize($_GET['customer_email'] ?? '');

            // 验证客户身份：优先用token，其次用email
            if (!empty($verificationToken)) {
                if (isset($_SESSION['chat_customer_token'])) {
                    $sessionToken = $_SESSION['chat_customer_token'];
                    if (hash_equals($sessionToken['token'], $verificationToken)
                        && time() < ($sessionToken['expires'] ?? 0)
                    ) {
                        $customerEmail = $sessionToken['email'];
                    }
                }
            }
            if (empty($customerEmail)) {
                $customerEmail = 'guest_' . bin2hex(random_bytes(4));
            }

            $conversations = $db->query(
                "SELECT * FROM chat_conversations 
                 WHERE customer_email = ? AND status = 'active'
                 ORDER BY last_message_at DESC, created_at DESC
                 LIMIT 50",
                [$customerEmail]
            );

            jsonSuccess([
                'conversations' => $conversations,
                'total'         => count($conversations),
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 客户获取会话列表失败: ' . $e->getMessage());
            jsonError('获取会话列表失败', -1, 500);
        }
        break;

    // ==================== 客户获取消息历史 ====================
    case 'customer_messages':
        // 只读公共接口，无需CSRF验证（仅查询数据，不修改状态）
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        try {
            $conversationId = (int) ($_GET['conversation_id'] ?? 0);
            $verificationToken = sanitize($_GET['verification_token'] ?? '');
            $customerEmail = sanitize($_GET['customer_email'] ?? '');

            if ($conversationId <= 0) {
                jsonError('缺少会话ID', 1);
            }

            // 验证客户身份：优先用token，其次用email
            if (!empty($verificationToken)) {
                if (isset($_SESSION['chat_customer_token'])) {
                    $sessionToken = $_SESSION['chat_customer_token'];
                    if (hash_equals($sessionToken['token'], $verificationToken)
                        && time() < ($sessionToken['expires'] ?? 0)
                    ) {
                        $customerEmail = $sessionToken['email'];
                    }
                }
            }
            if (empty($customerEmail)) {
                $customerEmail = 'guest_' . bin2hex(random_bytes(4));
            }

            // 验证会话存在且活跃，并校验客户归属
            $conversation = $db->queryOne(
                "SELECT * FROM chat_conversations WHERE id = ? AND customer_email = ? AND status = 'active'",
                [$conversationId, $customerEmail]
            );
            if (!$conversation) {
                jsonError('会话不存在或无权访问', 2, 404);
            }

            // 获取消息
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $perPage;

            $messages = $db->query(
                "SELECT * FROM chat_messages
                 WHERE conversation_id = ?
                 ORDER BY created_at ASC
                 LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
                [$conversationId]
            );

            // 标记客户消息为已读
            $db->execute(
                "UPDATE chat_messages SET is_read = 1, read_at = NOW()
                 WHERE conversation_id = ? AND sender_type = 'admin' AND is_read = 0",
                [$conversationId]
            );

            jsonSuccess([
                'messages' => $messages,
                'conversation_id' => $conversationId,
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 客户获取消息失败: ' . $e->getMessage());
            jsonError('获取消息失败', -1, 500);
        }
        break;

    // ==================== 创建新会话 ====================
    case 'create':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $domainId = (int) ($_POST['domain_id'] ?? 0);
            $customerEmail = sanitize($_POST['customer_email'] ?? '');

            // 参数校验
            if ($domainId <= 0) {
                jsonError('缺少域名ID', 1);
            }

            if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                jsonError('请输入有效的客户邮箱', 1);
            }

            // 验证域名存在
            $domain = $db->queryOne("SELECT * FROM domains WHERE id = ?", [$domainId]);
            if (!$domain) {
                jsonError('域名不存在', 2, 404);
            }

            // 检查是否已存在活跃会话（同一域名+同一邮箱）
            $existing = $db->queryOne(
                "SELECT id FROM chat_conversations
                 WHERE domain_id = ? AND customer_email = ? AND status = 'active'",
                [$domainId, $customerEmail]
            );
            if ($existing) {
                jsonError('该客户已存在活跃会话', 2, 409);
            }

            // 创建会话
            $conversationId = $db->insert('chat_conversations', [
                'domain_id'      => $domainId,
                'customer_email' => $customerEmail,
                'customer_name'  => $domain['customer_name'] ?? null,
                'subject'        => '关于域名 ' . $domain['domain_name'] . ' 的咨询',
                'status'         => 'active',
                'assigned_to'    => $user['id'],
                'customer_ip'    => getClientIp(),
            ]);

            // 发送系统消息
            $db->insert('chat_messages', [
                'conversation_id' => $conversationId,
                'sender_type'     => 'system',
                'sender_id'       => null,
                'message'         => '会话已创建，域名: ' . $domain['domain_name'],
                'message_type'    => 'text',
            ]);

            jsonSuccess([
                'conversation_id' => $conversationId,
            ], '会话创建成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 创建会话失败: ' . $e->getMessage());
            jsonError('会话创建失败', -1, 500);
        }
        break;

    // ==================== 关闭会话 ====================
    case 'close':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError('缺少会话ID', 1);
            }

            // 验证会话存在
            $conversation = $db->queryOne(
                "SELECT * FROM chat_conversations WHERE id = ?",
                [$id]
            );
            if (!$conversation) {
                jsonError('会话不存在', 2, 404);
            }

            if ($conversation['status'] === 'closed') {
                jsonError('会话已关闭', 2);
            }

            // 关闭会话
            $db->execute(
                "UPDATE chat_conversations SET status = 'closed', updated_at = NOW() WHERE id = ?",
                [$id]
            );

            // 发送系统消息
            $db->insert('chat_messages', [
                'conversation_id' => $id,
                'sender_type'     => 'system',
                'sender_id'       => null,
                'sender_name'     => '系统',
                'message'         => '会话已被管理员关闭，感谢您的咨询。',
                'message_type'    => 'system',
            ]);

            recordLog([
                'action'      => 'close',
                'module'      => 'chat',
                'description' => '关闭会话: ' . ($conversation['customer_email'] ?? '') . ' (域名: ' . ($conversation['domain_name'] ?? '') . ')',
                'target_type' => 'conversation',
                'target_id'   => (string)$id,
                'target_name' => $conversation['customer_email'] ?? '',
                'result'      => 'success',
            ]);

            jsonSuccess(null, '会话已关闭');

        } catch (\Throwable $e) {
            error_log('[聊天API] 关闭会话失败: ' . $e->getMessage());
            jsonError('关闭会话失败', -1, 500);
        }
        break;

    // ==================== 获取未读消息总数 ====================
    case 'unread_count':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        try {
            // 查询所有活跃会话中客户发送的未读消息总数
            $result = $db->queryOne(
                "SELECT COUNT(*) as total
                 FROM chat_messages cm
                 INNER JOIN chat_conversations cc ON cm.conversation_id = cc.id
                 WHERE cm.sender_type = 'customer'
                   AND cm.is_read = 0
                   AND cc.status = 'active'"
            );
            $unreadTotal = (int) ($result['total'] ?? 0);

            // 按会话分组的未读数
            $unreadByConversation = $db->query(
                "SELECT cm.conversation_id, COUNT(*) as unread_count
                 FROM chat_messages cm
                 INNER JOIN chat_conversations cc ON cm.conversation_id = cc.id
                 WHERE cm.sender_type = 'customer'
                   AND cm.is_read = 0
                   AND cc.status = 'active'
                 GROUP BY cm.conversation_id"
            );

            jsonSuccess([
                'unread_total' => $unreadTotal,
                'by_conversation' => $unreadByConversation,
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 获取未读消息数失败: ' . $e->getMessage());
            jsonError('获取未读消息数失败', -1, 500);
        }
        break;

    // ==================== 标记消息已读 ====================
    case 'mark_read':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $conversationId = (int) ($_POST['conversation_id'] ?? 0);
            if ($conversationId <= 0) {
                jsonError('缺少会话ID', 1);
            }

            // 验证会话存在
            $conversation = $db->queryOne(
                "SELECT * FROM chat_conversations WHERE id = ?",
                [$conversationId]
            );
            if (!$conversation) {
                jsonError('会话不存在', 2, 404);
            }

            // 标记该会话中所有客户消息为已读
            $markedCount = $db->execute(
                "UPDATE chat_messages
                 SET is_read = 1, read_at = NOW()
                 WHERE conversation_id = ? AND sender_type = 'customer' AND is_read = 0",
                [$conversationId]
            );

            jsonSuccess([
                'marked_count' => $markedCount,
            ], '已标记为已读');

        } catch (\Throwable $e) {
            error_log('[聊天API] 标记已读失败: ' . $e->getMessage());
            jsonError('标记已读失败', -1, 500);
        }
        break;

    // ==================== 上传聊天文件 ====================
    case 'upload':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // 管理员认证
        $user = $auth->check();
        if (!$user) {
            jsonError('未登录或会话已过期，请重新登录', -1, 401);
        }

        // CSRF验证
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('CSRF Token验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $conversationId = (int) ($_POST['conversation_id'] ?? 0);
            if ($conversationId <= 0) {
                jsonError('缺少会话ID', 1);
            }

            // 验证会话存在且活跃
            $conversation = $db->queryOne(
                "SELECT * FROM chat_conversations WHERE id = ? AND status = 'active'",
                [$conversationId]
            );
            if (!$conversation) {
                jsonError('会话不存在或已关闭', 2, 404);
            }

            // 检查文件上传
            if (empty($_FILES['file'])) {
                jsonError('请选择要上传的文件', 1);
            }

            $file = $_FILES['file'];

            // 判断文件类型
            $safeFileName = basename(str_replace("\0", '', $file['name']));
            $ext = strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION));
            $allowedImages = $uploadConfig['allowed_images'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            $allowedVideos = $uploadConfig['allowed_videos'] ?? ['mp4', 'avi', 'mov', 'mkv', 'webm'];

            if (in_array($ext, $allowedImages, true)) {
                $messageType = 'image';
            } elseif (in_array($ext, $allowedVideos, true)) {
                $messageType = 'video';
            } else {
                $messageType = 'file';
            }

            // 处理上传
            $uploadResult = handleChatUpload($file, $messageType, $uploadConfig);
            if (!$uploadResult['success']) {
                jsonError($uploadResult['message'], 1);
            }

            jsonSuccess([
                'file_url'  => $uploadResult['url'],
                'file_path' => $uploadResult['path'],
                'file_type' => $messageType,
                'file_name' => $uploadResult['original_name'],
                'file_size' => $uploadResult['size'],
            ], '文件上传成功');

        } catch (\Throwable $e) {
            error_log('[聊天API] 文件上传失败: ' . $e->getMessage());
            jsonError('文件上传失败', -1, 500);
        }
        break;

    // ==================== 未知操作 ====================
    default:
        jsonError('未知操作类型，支持的操作: list, messages, send, customer_list, customer_messages, customer_send, create, close, unread_count, mark_read, upload', -1, 400);
}

// ==================== 聊天文件上传处理函数 ====================

/**
 * 处理聊天文件上传
 *
 * @param array $file        $_FILES 中的文件数组
 * @param string $messageType 消息类型 (image/video/file)
 * @param array $uploadConfig 上传配置
 * @return array 上传结果
 */
function handleChatUpload(array $file, string $messageType, array $uploadConfig): array
{
    // 验证文件上传
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => '无效的文件上传'];
    }

    // 检查上传错误
    $errorMessage = match ((int) $file['error']) {
        UPLOAD_ERR_OK         => '',
        UPLOAD_ERR_INI_SIZE   => '文件大小超过服务器限制',
        UPLOAD_ERR_FORM_SIZE  => '文件大小超过表单限制',
        UPLOAD_ERR_PARTIAL    => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE    => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
        UPLOAD_ERR_CANT_WRITE => '文件写入磁盘失败',
        UPLOAD_ERR_EXTENSION  => '文件上传被扩展程序阻止',
        default               => '未知上传错误',
    };

    if (!empty($errorMessage)) {
        return ['success' => false, 'message' => $errorMessage];
    }

    // 获取文件扩展名
    $fileName = basename(str_replace("\0", '', $file['name']));
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (empty($ext)) {
        return ['success' => false, 'message' => '无法识别文件类型'];
    }

    // 根据消息类型设置允许的扩展名和大小限制
    $allowedImages = $uploadConfig['allowed_images'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $allowedVideos = $uploadConfig['allowed_videos'] ?? ['mp4', 'avi', 'mov', 'mkv', 'webm'];
    $allowedFiles = $uploadConfig['allowed_files'] ?? ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', '7z', 'txt'];

    switch ($messageType) {
        case 'image':
            $allowedExts = $allowedImages;
            $maxSize = $uploadConfig['max_image_size'] ?? 5 * 1024 * 1024;
            break;
        case 'video':
            $allowedExts = $allowedVideos;
            $maxSize = $uploadConfig['max_video_size'] ?? 50 * 1024 * 1024;
            break;
        default:
            $allowedExts = array_merge($allowedFiles, $allowedImages);
            $maxSize = $uploadConfig['max_size'] ?? 10 * 1024 * 1024;
            break;
    }

    // 验证文件类型
    if (!in_array($ext, $allowedExts, true)) {
        return ['success' => false, 'message' => '不支持的文件类型: ' . $ext];
    }

    // SEC-022: MIME类型二次验证
    $mimeAllowedMap = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'mp4'  => ['video/mp4'],
        'avi'  => ['video/x-msvideo'],
        'mov'  => ['video/quicktime'],
        'mkv'  => ['video/x-matroska'],
        'webm' => ['video/webm'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'rar'  => ['application/vnd.rar'],
        '7z'   => ['application/x-7z-compressed'],
        'txt'  => ['text/plain'],
    ];
    if (isset($mimeAllowedMap[$ext])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!in_array($detectedMime, $mimeAllowedMap[$ext], true)) {
            return ['success' => false, 'message' => '文件MIME类型与扩展名不匹配'];
        }
    }

    // 验证文件大小
    if ($file['size'] > $maxSize) {
        return [
            'success' => false,
            'message' => '文件大小超过限制（最大 ' . formatFileSize($maxSize) . '）',
        ];
    }

    // 构建上传目录
    $uploadDir = $uploadConfig['chat_upload_dir'] ?? BASEPATH . '/public/uploads/chat/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    // 按日期分子目录
    $dateDir = date('Y/m/d');
    $fullDir = $uploadDir . $dateDir;
    if (!is_dir($fullDir)) {
        @mkdir($fullDir, 0755, true);
    }

    // 生成安全文件名
    $newFileName = uniqid('chat_', true) . '.' . $ext;
    $destination = $fullDir . '/' . $newFileName;

    // 移动上传文件
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'message' => '文件保存失败'];
    }

    // 返回结果
    $relativePath = '/uploads/chat/' . $dateDir . '/' . $newFileName;
    $url = $relativePath;

    return [
        'success'      => true,
        'path'         => $relativePath,
        'url'          => $url,
        'full_path'    => $destination,
        'original_name'=> $fileName,
        'extension'    => $ext,
        'size'         => $file['size'],
        'message'      => '上传成功',
    ];
}
