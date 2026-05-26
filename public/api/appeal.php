<?php
/**
 * 灯塔DNS拦截响应平台 - 申诉API
 *
 * 提供域名申诉功能，包括邮箱验证、申诉提交、申诉列表查看和审核。
 * 客户接口（发送验证码、提交申诉）无需管理员登录。
 * 管理员接口（列表查看、审核）需要登录认证。
 *
 * 接口列表：
 *   POST /api/appeal.php?action=request_verification - 发送邮箱验证码
 *   POST /api/appeal.php?action=verify_code         - 验证邮箱验证码
 *   POST /api/appeal.php?action=submit              - 提交申诉
 *   GET  /api/appeal.php?action=list                 - 获取申诉列表（管理员）
 *   POST /api/appeal.php?action=review               - 审核申诉（管理员）
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

/**
 * 邮箱脱敏：abc@example.com -> abc***@example.com
 */
function maskEmail(string $email): string
{
    $atPos = strpos($email, '@');
    if ($atPos === false) return '***';
    $local = substr($email, 0, max(1, min(3, $atPos)));
    return $local . '***' . substr($email, $atPos);
}

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

// 验证码有效期（秒）
define('APPEAL_CODE_LIFETIME', 600); // 10分钟
define('APPEAL_CODE_LENGTH', 6);

// 路由分发
switch ($action) {

    // ==================== 验证客户邮箱（申诉前身份验证） ====================
    case 'verify_email':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        // FIX: 添加CSRF校验，防止CSRF攻击导致用户IP被错误封禁
        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('安全验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $email = sanitize($_POST['email'] ?? '');
            $domainId = (int) ($_POST['domain_id'] ?? 0);

            // 参数校验
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonError('请输入有效的邮箱地址', 1);
            }

            if ($domainId <= 0) {
                jsonError('缺少域名ID', 1);
            }

            // 查询域名信息，获取关联的customer_email
            $domain = $db->queryOne(
                "SELECT customer_email FROM domains WHERE id = ?",
                [$domainId]
            );
            if (!$domain) {
                jsonError('域名不存在', 2, 404);
            }

            // 如果域名有关联customer_email，则验证输入的邮箱是否一致
            if (!empty($domain['customer_email'])) {
                if (strtolower($email) !== strtolower($domain['customer_email'])) {
                    // 记录验证失败次数
                    $failCount = (int)($_SESSION['verify_email_fail_count'] ?? 0) + 1;
                    $_SESSION['verify_email_fail_count'] = $failCount;

                    if ($failCount >= 3) {
                        // 错误次数>=3，自动封禁IP 24小时
                        $ip = getClientIp();
                        $now = date('Y-m-d H:i:s');
                        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

                        try {
                            $db->insert('ip_bans', [
                                'ip'               => $ip,
                                'real_ip'          => $ip,
                                'ban_type'         => 'temporary',
                                'reason'           => '操作异常',
                                'status'           => 'active',
                                'detection_source' => 'system',
                                'banned_at'        => $now,
                                'expires_at'       => $expiresAt,
                                'banned_by'        => 0,
                                'created_at'       => $now,
                                'updated_at'       => $now,
                            ]);
                        } catch (\Throwable $banEx) {
                            error_log('[申诉API] 自动封禁IP失败: ' . $banEx->getMessage());
                        }

                        // 清除失败计数
                        unset($_SESSION['verify_email_fail_count']);

                        error_log("[申诉API] 邮箱验证失败{$failCount}次，自动封禁IP: {$ip}，24小时");

                        jsonError('验证错误次数过多，您的IP已被临时封禁24小时', 3, 403);
                    }

                    jsonError('邮箱与域名关联的客户邮箱不一致', 2);
                }
            }
            // 如果域名没有关联customer_email（为空），则允许任何邮箱

            // 验证成功，清除失败计数
            unset($_SESSION['verify_email_fail_count']);

            // 查询该域名是否已有申诉记录
            $existingAppeal = $db->queryOne(
                "SELECT id, status, review_note, created_at, reviewed_at, appeal_content
                 FROM appeals
                 WHERE domain_id = ? AND customer_email = ?
                 ORDER BY created_at DESC
                 LIMIT 1",
                [$domainId, $email]
            );

            $appealInfo = null;
            if ($existingAppeal) {
                $appealInfo = [
                    'id'          => (int)$existingAppeal['id'],
                    'status'      => $existingAppeal['status'],
                    'review_note' => $existingAppeal['review_note'],
                    'created_at'  => $existingAppeal['created_at'],
                    'reviewed_at' => $existingAppeal['reviewed_at'],
                ];
            }

            jsonSuccess(['valid' => true, 'email' => $email, 'appeal' => $appealInfo], '邮箱验证通过');

        } catch (\Throwable $e) {
            error_log('[申诉API] 邮箱验证失败: ' . $e->getMessage());
            jsonError('邮箱验证失败', -1, 500);
        }
        break;

    // ==================== 发送邮箱验证码 ====================
    case 'request_verification':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('安全验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $domainId = (int) ($_POST['domain_id'] ?? 0);
            $customerEmail = sanitize($_POST['customer_email'] ?? '');

            // 参数校验
            if ($domainId <= 0) {
                jsonError('缺少域名ID', 1);
            }

            if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                jsonError('请输入有效的邮箱地址', 1);
            }

            // 验证域名存在且处于拦截状态
            $domain = $db->queryOne(
                "SELECT * FROM domains WHERE id = ? AND status = 'active'",
                [$domainId]
            );
            if (!$domain) {
                jsonError('域名不存在或已释放', 2, 404);
            }

            // 验证客户邮箱与域名记录中的邮箱匹配（如果域名有绑定邮箱）
            if (!empty($domain['customer_email'])) {
                if (strtolower($customerEmail) !== strtolower($domain['customer_email'])) {
                    jsonError('邮箱地址与域名绑定邮箱不匹配', 2);
                }
            }

            // 频率限制：同一邮箱60秒内只能发送一次（使用数据库频率限制器）
            $emailLimiter = new \Core\RateLimiter($db, 'appeal_email_' . md5($customerEmail), getClientIp(), 1, 60);
            if ($emailLimiter->isExceeded()) {
                $remaining = $emailLimiter->getResetTime();
                jsonError("请 {$remaining} 秒后再试", 3, 429);
            }

            // 基于IP的频率限制：同一IP每小时最多发送10次验证码（使用数据库频率限制器）
            $ipLimiter = new \Core\RateLimiter($db, 'appeal_verify_ip', getClientIp(), 10, 3600);
            if ($ipLimiter->isExceeded()) {
                $remaining = $ipLimiter->getResetTime();
                $remainingMinutes = max(1, (int)ceil($remaining / 60));
                jsonError("请求过于频繁，请{$remainingMinutes}分钟后再试", 3, 429);
            }

            // 生成验证码（长度由常量APPEAL_CODE_LENGTH定义）
            $minCode = (int) str_repeat('1', APPEAL_CODE_LENGTH);
            $maxCode = (int) str_repeat('9', APPEAL_CODE_LENGTH);
            $code = (string) random_int($minCode, $maxCode);

            // 存储验证码到Session
            $_SESSION['appeal_verification'] = [
                'domain_id'       => $domainId,
                'customer_email'  => $customerEmail,
                'code'            => $code,
                'expires_at'      => time() + APPEAL_CODE_LIFETIME,
                'created_at'      => time(),
            ];
            $_SESSION['appeal_code_sent_at'] = time();

            // 发送验证码邮件
            $emailSent = sendVerificationEmail($customerEmail, $code);

            if (!$emailSent) {
                jsonError('验证码发送失败，请稍后重试', -1, 500);
            }

            // 发送成功后增加频率限制计数器
            $emailLimiter->increment();
            $ipLimiter->increment();

            // 记录日志
            error_log("[申诉API] 验证码已发送至: " . maskEmail($customerEmail) . ", 域名ID: {$domainId}");

            jsonSuccess([
                'expires_in' => APPEAL_CODE_LIFETIME,
            ], '验证码已发送到您的邮箱');

        } catch (\Throwable $e) {
            error_log('[申诉API] 发送验证码失败: ' . $e->getMessage());
            jsonError('发送验证码失败', -1, 500);
        }
        break;

    // ==================== 验证邮箱验证码 ====================
    case 'verify_code':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('安全验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $domainId = (int) ($_POST['domain_id'] ?? 0);
            $customerEmail = sanitize($_POST['customer_email'] ?? '');
            $code = sanitize($_POST['code'] ?? '');

            // 参数校验
            if ($domainId <= 0) {
                jsonError('缺少域名ID', 1);
            }
            if (empty($customerEmail)) {
                jsonError('缺少邮箱地址', 1);
            }
            if (empty($code) || strlen($code) !== APPEAL_CODE_LENGTH) {
                jsonError('验证码格式无效', 1);
            }

            // 从Session获取验证信息
            $verification = $_SESSION['appeal_verification'] ?? null;

            if (!$verification) {
                jsonError('请先获取邮箱验证码', 2);
            }

            // 验证域名ID和邮箱匹配
            if ((int) $verification['domain_id'] !== $domainId
                || strtolower($verification['customer_email']) !== strtolower($customerEmail)
            ) {
                jsonError('验证信息不匹配', 2);
            }

            // 检查验证码是否过期
            if (time() > $verification['expires_at']) {
                unset($_SESSION['appeal_verification']);
                jsonError('验证码已过期，请重新获取', 2);
            }

            // 验证码比对（使用时间安全的比较函数）
            if (!hash_equals($verification['code'], $code)) {
                // 基于Session的失败次数限制
                $failCount = ($_SESSION['appeal_verify_fail_count'] ?? 0) + 1;
                $_SESSION['appeal_verify_fail_count'] = $failCount;

                if ($failCount >= 5) {
                    // 超过5次失败，清除验证码并要求重新发送
                    unset($_SESSION['appeal_verification']);
                    unset($_SESSION['appeal_verify_fail_count']);
                    jsonError('验证码错误次数过多，请重新获取验证码', 2);
                }

                jsonError('验证码错误', 2);
            }

            // 验证成功，清除失败计数器
            unset($_SESSION['appeal_verify_fail_count']);

            // 验证成功，生成验证Token（用于后续提交申诉）
            $verificationToken = bin2hex(random_bytes(32));
            $tokenExpires = time() + 3600; // Token有效期1小时

            // 更新Session
            $_SESSION['appeal_verification']['verified'] = true;
            $_SESSION['appeal_verification']['verified_at'] = time();
            $_SESSION['appeal_verification']['token'] = $verificationToken;
            $_SESSION['appeal_verification']['token_expires'] = $tokenExpires;

            // 设置聊天认证Token（独立存储，不受申诉流程清除影响）
            $_SESSION['chat_customer_token'] = [
                'token'   => $verificationToken,
                'email'   => $verification['customer_email'],
                'expires' => time() + 7200, // 2小时有效
            ];

            jsonSuccess([
                'verification_token' => $verificationToken,
                'expires_in'         => 3600,
            ], '邮箱验证成功');

        } catch (\Throwable $e) {
            error_log('[申诉API] 验证码校验失败: ' . $e->getMessage());
            jsonError('验证码校验失败', -1, 500);
        }
        break;

    // ==================== 提交申诉 ====================
    case 'submit':
        if ($method !== 'POST') {
            jsonError('请求方法不允许，请使用POST', -1, 405);
        }

        if (!\Core\CsrfProtection::validateFromRequest()) {
            jsonError('安全验证失败，请刷新页面重试', -1, 403);
        }

        try {
            $domainId = (int) ($_POST['domain_id'] ?? 0);
            $customerEmail = sanitize($_POST['customer_email'] ?? '');
            $appealContent = sanitize(strip_tags(trim($_POST['appeal_content'] ?? '')));

            // 参数校验
            if ($domainId <= 0) {
                jsonError('缺少域名ID', 1);
            }
            if (empty($customerEmail)) {
                jsonError('缺少邮箱地址', 1);
            }
            if (empty($appealContent)) {
                jsonError('请填写申辩内容', 1);
            }
            if (mb_strlen($appealContent) < 10) {
                jsonError('申辩内容不能少于10个字符', 1);
            }
            if (mb_strlen($appealContent) > 5000) {
                jsonError('申辩内容不能超过5000个字符', 1);
            }

            // 基于IP的频率限制：同一IP每天最多提交5次申辩（使用数据库频率限制器）
            $submitLimiter = new \Core\RateLimiter($db, 'appeal_submit_ip', getClientIp(), 5, 86400);
            if ($submitLimiter->isExceeded()) {
                $remaining = $submitLimiter->getResetTime();
                $remainingHours = max(1, (int)ceil($remaining / 3600));
                jsonError("提交过于频繁，请{$remainingHours}小时后再试", 3, 429);
            }

            // 验证域名存在且status='active'
            $domain = $db->queryOne(
                "SELECT * FROM domains WHERE id = ? AND status = 'active'",
                [$domainId]
            );
            if (!$domain) {
                jsonError('域名不存在或已释放', 2, 404);
            }

            // 检查是否已有待处理的申诉
            $existingAppeal = $db->queryOne(
                "SELECT id FROM appeals
                 WHERE domain_id = ? AND customer_email = ? AND status IN ('pending', 'processing')",
                [$domainId, $customerEmail]
            );
            if ($existingAppeal) {
                jsonError('该域名已有待处理的申诉，请勿重复提交', 2, 409);
            }

            // 处理证据文件上传（可选）
            $evidenceFiles = [];
            $uploadConfig = $config['upload'] ?? [];
            if (!empty($_FILES['evidence']) && isset($_FILES['evidence']['name'])) {
                $evidenceDir = $uploadConfig['evidence_upload_dir'] ?? BASEPATH . '/public/uploads/evidence/';
                $allowedTypes = $uploadConfig['allowed_files'] ?? ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', '7z', 'txt', 'jpg', 'jpeg', 'png', 'gif'];

                // 处理多文件上传
                $files = is_array($_FILES['evidence']['name'])
                    ? $_FILES['evidence']
                    : [
                        'name'     => [$_FILES['evidence']['name']],
                        'tmp_name' => [$_FILES['evidence']['tmp_name']],
                        'size'     => [$_FILES['evidence']['size']],
                        'error'    => [$_FILES['evidence']['error']],
                        'type'     => [$_FILES['evidence']['type']],
                    ];

                foreach ($files['name'] as $i => $name) {
                    $file = [
                        'name'     => $files['name'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'size'     => $files['size'][$i],
                        'error'    => $files['error'][$i],
                        'type'     => $files['type'][$i] ?? '',
                    ];

                    $result = uploadFile($file, 'evidence');
                    if ($result['success']) {
                        $evidenceFiles[] = $result['path'];
                    }
                }
            }

            // 读取客户姓名和电话（前端表单提交的）
            $customerName = sanitize($_POST['customer_name'] ?? '') ?: ($domain['customer_name'] ?? null);
            $customerPhone = sanitize($_POST['customer_phone'] ?? '') ?: null;

            // 创建申诉记录
            $appealId = $db->insert('appeals', [
                'domain_id'            => $domainId,
                'domain_name'          => $domain['domain_name'] ?? '',
                'customer_email'       => $customerEmail,
                'customer_name'        => $customerName,
                'customer_phone'       => $customerPhone,
                'verification_code'    => null,
                'verification_sent_at' => null,
                'verified_at'          => null,
                'is_verified'          => 0,
                'appeal_content'       => $appealContent,
                'appeal_type'          => $domain['type'] ?? 'expired',
                'evidence_files'       => !empty($evidenceFiles) ? json_encode($evidenceFiles) : null,
                'status'               => 'pending',
                'customer_ip'          => getClientIp(),
            ]);

            // 递增IP提交计数器（使用数据库频率限制器）
            $submitLimiter->increment();

            // 记录日志
            error_log("[申诉API] 新申诉提交: 域名ID={$domainId}, 邮箱=" . maskEmail($customerEmail) . ", 申诉ID={$appealId}");
            recordLog([
                'action'      => 'submit',
                'module'      => 'appeal',
                'description' => '客户提交申诉: ' . ($domain['domain_name'] ?? ''),
                'target_type' => 'appeal',
                'target_id'   => (string)$appealId,
                'target_name' => $domain['domain_name'] ?? '',
                'user_type'   => 'customer',
                'result'      => 'success',
            ]);

            jsonSuccess([
                'appeal_id' => $appealId,
            ], '申诉提交成功，请等待管理员审核');

        } catch (\Throwable $e) {
            error_log('[申诉API] 提交申诉失败: ' . $e->getMessage());
            jsonError('申诉提交失败', -1, 500);
        }
        break;

    // ==================== 获取申诉列表（管理员） ====================
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
            // 筛选参数
            $domainId = (int) ($_GET['domain_id'] ?? 0);
            $domainName = sanitize($_GET['domain_name'] ?? '');
            $status = sanitize($_GET['status'] ?? '');

            // 分页参数
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;

            // 构建WHERE条件
            $where = [];
            $params = [];

            if ($domainId > 0) {
                $where[] = 'a.domain_id = :domain_id';
                $params[':domain_id'] = $domainId;
            }

            if (!empty($domainName)) {
                $where[] = 'a.domain_name = :domain_name';
                $params[':domain_name'] = $domainName;
            }

            if (!empty($status) && in_array($status, ['pending', 'processing', 'approved', 'rejected'], true)) {
                $where[] = 'a.status = :status';
                $params[':status'] = $status;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // 查询总数
            $totalResult = $db->queryOne(
                "SELECT COUNT(*) AS total FROM appeals a {$whereClause}",
                $params
            );
            $total = (int) ($totalResult['total'] ?? 0);

            // 查询申诉列表（关联域名信息、审核人信息）
            $appeals = $db->query(
                "SELECT a.*,
                        d.domain_name,
                        d.type AS domain_type,
                        r.username AS reviewer_name
                 FROM appeals a
                 LEFT JOIN domains d ON a.domain_id = d.id
                 LEFT JOIN admin_users r ON a.reviewed_by = r.id
                 {$whereClause}
                 ORDER BY a.created_at DESC
                 LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
                $params
            );

            // 统计各状态数量（全局统计）
            $statsResult = $db->queryOne(
                "SELECT 
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                 FROM appeals"
            );
            $stats = [
                'total'      => $total,
                'pending'    => (int) ($statsResult['pending'] ?? 0),
                'processing' => (int) ($statsResult['processing'] ?? 0),
                'approved'   => (int) ($statsResult['approved'] ?? 0),
                'rejected'   => (int) ($statsResult['rejected'] ?? 0),
            ];

            jsonSuccess([
                'appeals'   => $appeals,
                'stats'     => $stats,
                'pagination'=> [
                    'total'     => $total,
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'last_page' => (int) ceil($total / $perPage) ?: 1,
                ],
            ], '获取成功');

        } catch (\Throwable $e) {
            error_log('[申诉API] 获取申诉列表失败: ' . $e->getMessage());
            jsonError('获取申诉列表失败', -1, 500);
        }
        break;

    // ==================== 审核申诉（管理员） ====================
    case 'review':
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
            $appealId = (int) ($_POST['appeal_id'] ?? 0);
            $status = sanitize($_POST['status'] ?? '');
            $reviewNote = sanitize($_POST['review_note'] ?? '');

            // 参数校验
            if ($appealId <= 0) {
                jsonError('缺少申诉ID', 1);
            }

            // 验证审核状态
            if (!in_array($status, ['approved', 'rejected'], true)) {
                jsonError('无效的审核状态，支持: approved, rejected', 1);
            }

            if (empty($reviewNote)) {
                jsonError('请填写审核备注', 1);
            }

            if (mb_strlen($reviewNote) > 500) {
                jsonError('审核备注不能超过500个字符', 1);
            }

            // 验证申诉存在且为待处理状态
            $appeal = $db->queryOne(
                "SELECT a.*, d.domain_name, d.type AS domain_type
                 FROM appeals a
                 LEFT JOIN domains d ON a.domain_id = d.id
                 WHERE a.id = ? AND a.status IN ('pending', 'processing')",
                [$appealId]
            );
            if (!$appeal) {
                jsonError('申诉不存在或已处理', 2, 404);
            }

            // 开始事务
            $db->beginTransaction();

            try {
                // 更新申诉状态
                $db->execute(
                    "UPDATE appeals
                     SET status = :status,
                         review_note = :review_note,
                         reviewed_by = :reviewed_by,
                         reviewed_at = NOW()
                     WHERE id = :id",
                    [
                        ':status'      => $status,
                        ':review_note' => $reviewNote,
                        ':reviewed_by' => $user['id'],
                        ':id'          => $appealId,
                    ]
                );

                // 如果申诉通过，自动释放域名
                if ($status === 'approved') {
                    $db->execute(
                        "UPDATE domains
                         SET status = 'released',
                             release_date = NOW(),
                             review_date = NOW(),
                             review_note = :review_note
                         WHERE id = :domain_id",
                        [
                            ':review_note' => '申诉通过: ' . $reviewNote,
                            ':domain_id'   => $appeal['domain_id'],
                        ]
                    );

                    // 创建系统消息通知客户（如果有关联的聊天会话）
                    $conversation = $db->queryOne(
                        "SELECT id FROM chat_conversations
                         WHERE domain_id = ? AND customer_email = ? AND status = 'active'
                         LIMIT 1",
                        [$appeal['domain_id'], $appeal['customer_email']]
                    );
                    if ($conversation) {
                        $db->insert('chat_messages', [
                            'conversation_id' => $conversation['id'],
                            'sender_type'     => 'system',
                            'sender_id'       => null,
                            'message'         => '您的申诉已通过审核，域名 ' . ($appeal['domain_name'] ?? '') . ' 已释放。',
                            'message_type'    => 'text',
                        ]);
                    }
                }

                // 如果申诉被拒绝，发送系统消息通知客户
                if ($status === 'rejected') {
                    $conversation = $db->queryOne(
                        "SELECT id FROM chat_conversations
                         WHERE domain_id = ? AND customer_email = ? AND status = 'active'
                         LIMIT 1",
                        [$appeal['domain_id'], $appeal['customer_email']]
                    );
                    if ($conversation) {
                        $db->insert('chat_messages', [
                            'conversation_id' => $conversation['id'],
                            'sender_type'     => 'system',
                            'sender_id'       => null,
                            'message'         => '您的申诉未通过审核。原因: ' . $reviewNote,
                            'message_type'    => 'text',
                        ]);
                    }
                }

                $db->commit();

            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }

            // 记录日志
            $statusText = $status === 'approved' ? '通过' : '拒绝';
            error_log("[申诉API] 管理员 {$user['username']} 审核申诉 ID={$appealId}: {$statusText}");
            recordLog([
                'action'      => 'review',
                'module'      => 'appeal',
                'description' => '审核申诉: ' . $statusText . ' - ' . ($appeal['domain_name'] ?? ''),
                'target_type' => 'appeal',
                'target_id'   => (string)$appealId,
                'target_name' => $appeal['domain_name'] ?? '',
                'result'      => 'success',
            ]);

            jsonSuccess([
                'appeal_id' => $appealId,
                'status'    => $status,
            ], '申诉审核完成');

        } catch (\Throwable $e) {
            error_log('[申诉API] 审核申诉失败: ' . $e->getMessage());
            jsonError('审核申诉失败', -1, 500);
        }
        break;

    // ==================== 查询域名是否有申诉记录（无需邮箱验证） ====================
    case 'has_appeal':
        if ($method !== 'GET') {
            jsonError('请求方法不允许，请使用GET', -1, 405);
        }

        try {
            $domainId = (int)($_GET['domain_id'] ?? 0);
            if ($domainId <= 0) jsonError('缺少域名ID', 1);

            $appeal = $db->queryOne(
                "SELECT id, status, review_note, created_at, reviewed_at FROM appeals WHERE domain_id = ? ORDER BY created_at DESC LIMIT 1",
                [$domainId]
            );
            // FIX: 限制返回字段，不暴露审核备注等内部信息
            jsonSuccess(['has_appeal' => !empty($appeal), 'appeal' => $appeal ? [
                'id' => (int)$appeal['id'],
                'status' => $appeal['status'],
                // 'review_note' => $appeal['review_note'], // 移除：不暴露内部审核备注
                'created_at' => $appeal['created_at'],
                // 'reviewed_at' => $appeal['reviewed_at'], // 移除：非必要信息
            ] : null]);
        } catch (\Throwable $e) {
            error_log('[申诉API] 查询申诉记录失败: ' . $e->getMessage());
            jsonError('查询申诉记录失败', -1, 500);
        }
        break;

    // ==================== 未知操作 ====================
    default:
        jsonError('未知操作类型，支持的操作: request_verification, verify_code, submit, list, review, has_appeal', -1, 400);
}
