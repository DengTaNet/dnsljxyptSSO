<?php
declare(strict_types=1);

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}

/**
 * 公共函数库
 *
 * 提供全局可用的工具函数，包括输入清理、CSRF 令牌、日期格式化、
 * 文件上传、JSON 响应等常用功能。
 */

// ==================== 自动加载 ====================

// 引入 Composer autoload（加载第三方依赖如 Logto SDK）
if (defined('BASEPATH') && file_exists(BASEPATH . '/vendor/autoload.php')) {
    require_once BASEPATH . '/vendor/autoload.php';
}

// 文件上传目录常量（相对于public目录的URL路径）
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', '/uploads/');
}

spl_autoload_register(function (string $class): void {
    $prefix = 'Core\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    // 防止路径遍历：验证类名不包含非法字符
    if (str_contains($relativeClass, '..') || str_contains($relativeClass, "\0")) {
        return;
    }
    $file = BASEPATH . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ==================== 调试模式初始化 ====================

/**
 * 初始化调试模式
 * 在所有请求入口文件（index.php、expired.php、violation.php、login.php等）的
 * 依赖加载之后调用此函数即可启用调试日志。
 */
function initDebug(): void
{
    if (!class_exists(\Core\Debug::class)) {
        return;
    }
    $basePath = defined('BASEPATH') ? BASEPATH : dirname(__DIR__);
    $configFile = $basePath . '/config/config.php';
    if (!file_exists($configFile)) {
        return;
    }
    $config = require $configFile;
    \Core\Debug::init($config);

    if (\Core\Debug::isEnabled()) {
        \Core\Debug::logRequestStart();
        // 注册请求结束时的日志汇总
        register_shutdown_function(function () {
            \Core\Debug::logRequestEnd();
        });
    }
}

/**
 * 记录调试日志（全局便捷函数）
 *
 * @param string $category 分类：sql/auth/api/error/system
 * @param string $message  日志消息
 * @param array  $context  上下文数据
 */
function debug_log(string $category, string $message, array $context = []): void
{
    if (class_exists(\Core\Debug::class)) {
        \Core\Debug::log($category, $message, $context);
    }
}

// ==================== 输入清理 ====================

/**
 * 输入清理函数
 *
 * 对用户输入进行多层安全过滤，防止 XSS 和 SQL 注入。
 * 包括：去除首尾空白、HTML 实体转义、移除不可见字符。
 *
 * @param string|int|float|null $input 待清理的输入
 * @return string 清理后的字符串
 */
function sanitize(string|int|float|null $input): string
{
    if ($input === null) {
        return '';
    }

    $input = (string) $input;

    // 去除首尾空白
    $input = trim($input);

    // 移除 NULL 字节
    $input = str_replace("\0", '', $input);

    // 移除多余的制表符和换行符（保留单个空格）
    $input = preg_replace('/\s+/', ' ', $input);

    // 注意：不在此处进行HTML转义，转义应在输出时进行（使用e()函数）
    // 这符合"输出时转义"的安全最佳实践

    return $input;
}

/**
 * 深度清理数组
 *
 * 递归清理数组中的所有值。
 *
 * @param array $array 待清理的数组
 * @return array 清理后的数组
 */
function sanitizeArray(array $array): array
{
    return array_map(function ($value) {
        return is_array($value) ? sanitizeArray($value) : sanitize($value);
    }, $array);
}

// ==================== CSRF 令牌 ====================

/**
 * QUAL-001: CSRF令牌函数已迁移至 Core\CsrfProtection 类
 * 以下函数保留向后兼容，内部调用 CsrfProtection 类
 */

/**
 * 生成 CSRF 令牌（兼容包装）
 * @return string
 */
function generateToken(): string
{
    return \Core\CsrfProtection::generateToken();
}

/**
 * 验证 CSRF 令牌（兼容包装）
 * @param string $token
 * @return bool
 */
function verifyToken(string $token): bool
{
    return \Core\CsrfProtection::validate($token);
}

/**
 * 获取当前 CSRF 令牌（兼容包装）
 * @return string
 */
function getCsrfToken(): string
{
    return \Core\CsrfProtection::getToken();
}

/**
 * 生成 CSRF 隐藏输入字段 HTML（兼容包装）
 * @return string
 */
function csrfField(): string
{
    return \Core\CsrfProtection::field();
}

// ==================== 日期时间 ====================

/**
 * 格式化日期时间
 *
 * 将日期时间字符串格式化为指定的显示格式。
 *
 * @param string|null $datetime 日期时间字符串（支持 Y-m-d H:i:s 格式）
 * @param string      $format   输出格式（默认 'Y-m-d H:i:s'）
 * @return string 格式化后的日期时间字符串，无效输入返回 '-'
 */
function formatDateTime(?string $datetime, string $format = 'Y-m-d H:i:s'): string
{
    if (empty($datetime)) {
        return '-';
    }

    try {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return '-';
        }
        return date($format, $timestamp);
    } catch (\Throwable) {
        return '-';
    }
}

/**
 * 友好时间显示
 *
 * 将日期时间转换为"刚刚"、"5分钟前"、"昨天"等友好格式。
 *
 * @param string|null $datetime 日期时间字符串
 * @return string 友好时间字符串
 */
function timeAgo(?string $datetime): string
{
    if (empty($datetime)) {
        return '未知';
    }

    try {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return '未知';
        }

        $now = time();
        $diff = $now - $timestamp;

        // 未来时间
        if ($diff < 0) {
            return formatDateTime($datetime);
        }

        // 不到1分钟
        if ($diff < 60) {
            return '刚刚';
        }

        // 不到1小时
        $minutes = intdiv($diff, 60);
        if ($minutes < 60) {
            return "{$minutes}分钟前";
        }

        // 不到24小时
        $hours = intdiv($diff, 3600);
        if ($hours < 24) {
            return "{$hours}小时前";
        }

        // 不到7天
        $days = intdiv($diff, 86400);
        if ($days < 7) {
            return match (true) {
                $days === 1 => '昨天',
                $days === 2 => '前天',
                default     => "{$days}天前",
            };
        }

        // 不到30天
        if ($days < 30) {
            $weeks = intdiv($days, 7);
            return "{$weeks}周前";
        }

        // 不到365天
        if ($days < 365) {
            $months = intdiv($days, 30);
            return "{$months}个月前";
        }

        // 超过1年
        $years = intdiv($days, 365);
        return "{$years}年前";
    } catch (\Throwable) {
        return '未知';
    }
}

// ==================== 请求工具 ====================

/**
 * 从请求中获取域名
 *
 * 从 HTTP Host 头或 SERVER_NAME 中提取域名。
 *
 * @return string 域名字符串
 */
function getDomainFromRequest(): string
{
    // 优先从 SERVER_NAME 获取（不可被客户端伪造）
    $host = $_SERVER['SERVER_NAME'] ?? '';

    if (empty($host)) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
    }

    // 去除端口号
    $host = preg_replace('/:\d+$/', '', $host);

    return $host;
}

/**
 * M-06: 验证并获取安全的Host值
 *
 * 对HTTP_HOST进行格式验证，使用严格的域名格式正则（与安装向导一致）。
 * 只允许格式完整的域名标签，禁止标签以横线开头/结尾。
 *
 * @return string 经过格式验证的Host值，验证失败返回空字符串
 */
function validateHost(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';

    if (empty($host)) {
        return '';
    }

    // 去除端口号后验证域名格式
    $hostWithoutPort = preg_replace('/:\d+$/', '', $host);

    // 严格格式验证：要求完整的域名格式（每个标签不能以横线开头/结尾）
    // 与 install.php 域名白名单验证正则保持一致
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$/', $hostWithoutPort)) {
        return '';
    }

    // 额外检查：不能以点或横线开头/结尾
    if (preg_match('/^[\.\-]|[\.\-]$/', $hostWithoutPort)) {
        return '';
    }

    // 注意：后台域名白名单检查仅在 Auth::checkAdminDomain() 中执行
    // 前端页面（expired/violation/banned等）不受白名单限制

    return $host;
}

/**
 * 获取当前访问的域名（用于后台域名白名单检查）
 *
 * 使用 HTTP_HOST（客户端实际请求的域名）作为白名单判断依据。
 * SERVER_NAME 反映的是服务器配置的主域名，多个 DNS 别名解析到同一服务器时
 * SERVER_NAME 始终为配置值，会导致非白名单域名绕过检查。
 *
 * HTTP_HOST 经过严格格式验证（与 install.php 保持一致），可有效防止 Host 头注入。
 *
 * 验证失败返回空字符串时，checkAdminDomain() 中会因无法匹配任何白名单条目而拒绝访问，
 * 实现 fail-safe 安全策略。
 *
 * @return string 标准化后的小写域名字符串，验证失败返回空字符串
 */
function getCurrentAdminHost(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';

    if (empty($host)) {
        return '';
    }

    // 去除端口号并转为小写
    $host = strtolower(preg_replace('/:\d+$/', '', $host));

    // 严格格式验证：要求完整的域名格式（每个标签不能以横线开头/结尾）
    // 与 install.php 域名白名单验证正则保持一致，有效防止 Host 头注入攻击
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$/', $host)) {
        return '';
    }

    // 额外检查：不能以点或横线开头/结尾
    if (preg_match('/^[\.\-]|[\.\-]$/', $host)) {
        return '';
    }

    return $host;
}

/**
 * 判断是否为 AJAX 请求
 *
 * 通过检查 X-Requested-With 头或 Accept 头判断。
 *
 * @return bool 是 AJAX 请求返回 true
 */
function isAjax(): bool
{
    // 标准 AJAX 标识
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        return true;
    }

    // Fetch API 请求通常带有 Accept: application/json
    if (!empty($_SERVER['HTTP_ACCEPT'])
        && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
    ) {
        return true;
    }

    return false;
}

/**
 * 获取客户端真实 IP（快捷方法）
 *
 * 按优先级检测 CDN 代理头，直接信任所有 CDN 头获取真实 IP。
 *
 * @return string IP 地址
 */
function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // 按优先级检测 CDN 代理头
    $headers = [
        'HTTP_EO_CONNECTING_IP',       // 腾讯云 Edge ONE
        'HTTP_CF_CONNECTING_IP',       // Cloudflare
        'HTTP_TRUE_CLIENT_IP',         // Akamai
        'HTTP_X_TENCENTCDN_REAL_IP',   // 腾讯云CDN
        'HTTP_X_TENCENT_REAL_IP',      // 腾讯云
        'HTTP_X_REAL_IP',              // Nginx
        'HTTP_X_FORWARDED_FOR',        // 通用代理/CDN
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = explode(',', $_SERVER[$header])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return $remoteAddr;
}

/**
 * 获取当前请求的完整 URL
 *
 * @return string 完整 URL
 */
function getCurrentUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    return $scheme . '://' . $host . $uri;
}

// ==================== 响应工具 ====================

/**
 * JSON 响应输出
 *
 * 以 JSON 格式输出数据并终止脚本。
 *
 * @param array|object $data       响应数据
 * @param int          $statusCode HTTP 状态码（默认 200）
 * @param array        $headers    额外的响应头
 */
function jsonResponse(array|object $data, int $statusCode = 200, array $headers = []): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    // 设置额外的响应头（过滤换行符防止HTTP头注入）
    foreach ($headers as $name => $value) {
        $name = str_replace(["\r", "\n"], '', $name);
        $value = str_replace(["\r", "\n"], '', $value);
        header("{$name}: {$value}");
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 成功 JSON 响应
 *
 * @param mixed  $data    响应数据
 * @param string $message 成功消息
 * @param int    $code    业务状态码
 */
function jsonSuccess(mixed $data = null, string $message = '操作成功', int $code = 0): void
{
    jsonResponse([
        'success' => true,
        'code'    => $code,
        'message' => $message,
        'data'    => $data,
    ]);
}

/**
 * 错误 JSON 响应
 *
 * @param string $message 错误消息
 * @param int    $code    业务状态码
 * @param int    $httpCode HTTP 状态码
 * @param mixed  $data    附加数据
 */
function jsonError(string $message = '操作失败', int $code = -1, int $httpCode = 400, mixed $data = null): void
{
    jsonResponse([
        'success' => false,
        'code'    => $code,
        'message' => $message,
        'data'    => $data,
    ], $httpCode);
}

// ==================== 文件上传 ====================

/**
 * 文件上传处理
 *
 * 处理文件上传，包括类型检查、大小限制、安全重命名等。
 *
 * @param array  $file      $_FILES 中的文件数组
 * @param string $directory 保存目录（相对于上传根目录）
 * @return array 上传结果 ['success' => bool, 'path' => string, 'message' => string]
 */
function uploadFile(array $file, string $directory = ''): array
{
    // 验证文件上传
    if (!isset($file['error']) || is_array($file['error'])) {
        return [
            'success' => false,
            'path'    => '',
            'message' => '无效的文件上传',
        ];
    }

    // 检查上传错误
    $errorMessage = match ((int) $file['error']) {
        UPLOAD_ERR_OK         => '',
        UPLOAD_ERR_INI_SIZE   => '文件大小超过了 php.ini 中 upload_max_filesize 的限制',
        UPLOAD_ERR_FORM_SIZE  => '文件大小超过了 HTML 表单中 MAX_FILE_SIZE 的限制',
        UPLOAD_ERR_PARTIAL    => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE    => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
        UPLOAD_ERR_CANT_WRITE => '文件写入磁盘失败',
        UPLOAD_ERR_EXTENSION  => '文件上传被扩展程序阻止',
        default               => '未知上传错误',
    };

    if (!empty($errorMessage)) {
        return [
            'success' => false,
            'path'    => '',
            'message' => $errorMessage,
        ];
    }

    // 检查文件大小
    $maxSize = defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return [
            'success' => false,
            'path'    => '',
            'message' => '文件大小超过限制（最大 ' . formatFileSize($maxSize) . '）',
        ];
    }

    // 检查文件类型
    $allowedTypes = defined('ALLOWED_UPLOAD_TYPES') ? ALLOWED_UPLOAD_TYPES : ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedTypes, true)) {
        return [
            'success' => false,
            'path'    => '',
            'message' => '不支持的文件类型，允许的类型：' . implode(', ', $allowedTypes),
        ];
    }

    // 验证 MIME 类型（二次验证）
    $allowedMimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (isset($allowedMimes[$extension]) && $mimeType !== $allowedMimes[$extension]) {
        return [
            'success' => false,
            'path'    => '',
            'message' => '文件 MIME 类型与扩展名不匹配',
        ];
    }

    // 对图片文件进行内容验证（防止伪装扩展名的恶意文件）
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    if (in_array($extension, $imageExtensions, true)) {
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return [
                'success' => false,
                'path'    => '',
                'message' => '文件内容不是有效的图片',
            ];
        }
    }

    // 构建保存路径（物理路径需要包含public目录）
    $uploadDir = defined('APP_ROOT') ? APP_ROOT . '/public/uploads/' : __DIR__ . '/../public/uploads/';
    $uploadDir = rtrim($uploadDir, '/');

    // SEC-023: 目录白名单验证，防止目录遍历攻击
    $allowedDirs = ['chat', 'evidence', 'images', 'avatars', 'temp'];
    $directory = trim($directory, '/\\');
    if ($directory !== '' && !in_array($directory, $allowedDirs, true)) {
        return [
            'success' => false,
            'path'    => '',
            'message' => '不允许上传到该目录',
        ];
    }

    $targetDir = $uploadDir . ($directory ? '/' . $directory : '');

    // 创建目录
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            return [
                'success' => false,
                'path'    => '',
                'message' => '无法创建上传目录',
            ];
        }
    }

    // 生成安全的文件名（使用日期目录 + 随机名）
    $dateDir = date('Y/m/d');
    $fullDir = $targetDir . '/' . $dateDir;
    if (!is_dir($fullDir)) {
        mkdir($fullDir, 0755, true);
    }

    $newFileName = uniqid('file_', true) . '.' . $extension;
    $destination = $fullDir . '/' . $newFileName;

    // 移动上传文件
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return [
            'success' => false,
            'path'    => '',
            'message' => '文件保存失败',
        ];
    }

    // 返回相对路径
    $relativePath = UPLOAD_DIR . ($directory ? trim($directory, '/') . '/' : '') . $dateDir . '/' . $newFileName;

    return [
        'success'   => true,
        'path'      => $relativePath,
        'full_path' => $destination,
        'filename'  => $newFileName,
        'extension' => $extension,
        'size'      => $file['size'],
        'message'   => '文件上传成功',
    ];
}

// ==================== 邮件发送 ====================

/**
 * 发送验证邮件（模拟）
 *
 * 在实际生产环境中，应替换为真实的邮件发送逻辑（如 PHPMailer、SMTP 等）。
 * 当前为模拟实现，仅记录日志。
 *
 * @param string $email 收件人邮箱地址
 * @param string $code  验证码
 * @return bool 发送成功返回 true
 */
function sendVerificationEmail(string $email, string $code): bool
{
    // 验证邮箱格式
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // 验证码格式检查
    if (empty($code) || strlen($code) < 4) {
        return false;
    }

    // 记录邮件发送日志（模拟发送）- 验证码掩码处理，只显示前2位和后2位
    $logMessage = sprintf(
        "[邮件发送] 收件人: %s | 验证码: %s****%s | 时间: %s\n",
        $email,
        substr($code, 0, 2),
        substr($code, -2),
        date('Y-m-d H:i:s')
    );

    $logFile = defined('APP_ROOT') ? APP_ROOT . '/logs/email.log' : __DIR__ . '/../logs/email.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logFile, $logMessage, FILE_APPEND);

    // ============ 生产环境替换为真实邮件发送 ============
    // 示例（使用 PHPMailer）：
    //
    // $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    // $mail->isSMTP();
    // $mail->Host = SMTP_HOST;
    // $mail->Port = SMTP_PORT;
    // $mail->SMTPAuth = true;
    // $mail->Username = SMTP_USER;
    // $mail->Password = SMTP_PASS;
    // $mail->SMTPSecure = SMTP_ENCRYPTION;
    // $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
    // $mail->addAddress($email);
    // $mail->Subject = APP_NAME . ' - 验证码';
    // $mail->Body = "您的验证码是：{$code}，有效期10分钟。";
    // $mail->send();
    // ==================================================

    return true;
}

// ==================== 工具函数 ====================

/**
 * 格式化文件大小
 *
 * 将字节数转换为人类可读的格式（B、KB、MB、GB）。
 *
 * @param int $bytes 字节数
 * @param int $precision 小数位数（默认2）
 * @return string 格式化后的字符串
 */
function formatFileSize(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * 生成随机字符串
 *
 * @param int $length 字符串长度（默认16）
 * @return string 随机字符串
 */
function randomString(int $length = 16): string
{
    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
}

/**
 * 截断字符串
 *
 * 超过指定长度时截断并添加省略号。
 *
 * @param string $string   原始字符串
 * @param int    $length   最大长度（默认100）
 * @param string $suffix   后缀（默认 '...'）
 * @return string 截断后的字符串
 */
function truncateString(string $string, int $length = 100, string $suffix = '...'): string
{
    if (mb_strlen($string, 'UTF-8') <= $length) {
        return $string;
    }

    return mb_substr($string, 0, $length, 'UTF-8') . $suffix;
}

/**
 * 安全的 JSON 编码
 *
 * 处理中文和特殊字符的 JSON 编码。
 *
 * @param mixed $data 待编码的数据
 * @return string JSON 字符串
 */
function safeJsonEncode(mixed $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

// ==================== 配置辅助函数 ====================

/**
 * 获取配置值
 *
 * 从配置文件中读取配置项，支持点号分隔的嵌套键。
 * 配置文件返回关联数组，使用静态缓存避免重复读取。
 *
 * @param string $key 配置键名（如 'site.name'）
 * @param mixed $default 默认值
 * @return mixed 配置值
 */
function config(string $key, mixed $default = null): mixed
{
    static $configCache = null;
    if ($configCache === null) {
        $configFile = BASEPATH . '/config/config.php';
        if (file_exists($configFile)) {
            $configCache = require $configFile;
        } else {
            $configCache = [];
        }
    }
    if (empty($configCache)) {
        return $default;
    }
    $keys = explode('.', $key);
    $value = $configCache;
    foreach ($keys as $k) {
        if (!is_array($value) || !array_key_exists($k, $value)) {
            return $default;
        }
        $value = $value[$k];
    }
    return $value;
}

/**
 * 获取数据库实例（快捷方法）
 *
 * @return \Core\Database
 */
function db(): \Core\Database
{
    return \Core\Database::getInstance();
}

/**
 * HTML转义输出（快捷方法）
 *
 * @param string $string 待转义的字符串
 * @return string 转义后的字符串
 */
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * 获取站点Logo路径
 */
function getSiteLogo(): string
{
    $basePath = defined('BASEPATH') ? BASEPATH : dirname(__DIR__);
    if (file_exists($basePath . '/public/assets/images/logo.png')) {
        return '/assets/images/logo.png';
    }
    if (file_exists($basePath . '/public/assets/images/logo.jpg')) {
        return '/assets/images/logo.jpg';
    }
    return '/assets/images/logo.png';
}

/**
 * 解析User-Agent字符串，提取浏览器和操作系统信息
 *
 * @param string $ua User-Agent字符串
 * @return array 包含 browser, browser_version, os, os_version 的数组
 */
function parseUserAgent(string $ua): array {
    $result = ['browser' => '', 'browser_version' => '', 'os' => '', 'os_version' => ''];
    if (empty($ua)) return $result;

    // 浏览器检测
    if (preg_match('/Edg\/([\d.]+)/', $ua, $m)) { $result['browser'] = 'Edge'; $result['browser_version'] = $m[1]; }
    elseif (preg_match('/Chrome\/([\d.]+)/', $ua, $m)) { $result['browser'] = 'Chrome'; $result['browser_version'] = $m[1]; }
    elseif (preg_match('/Firefox\/([\d.]+)/', $ua, $m)) { $result['browser'] = 'Firefox'; $result['browser_version'] = $m[1]; }
    elseif (preg_match('/Safari\/([\d.]+)/', $ua, $m) && !preg_match('/Chrome/', $ua)) { $result['browser'] = 'Safari'; $result['browser_version'] = $m[1]; }
    elseif (preg_match('/MSIE ([\d.]+)/', $ua, $m)) { $result['browser'] = 'IE'; $result['browser_version'] = $m[1]; }

    // 操作系统检测
    if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
        $result['os'] = 'Windows';
        $versions = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
        $result['os_version'] = $versions[$m[1]] ?? $m[1];
    } elseif (preg_match('/Mac OS X ([\d_.]+)/', $ua, $m)) { $result['os'] = 'macOS'; $result['os_version'] = str_replace('_', '.', $m[1]); }
    elseif (preg_match('/Android ([\d.]+)/', $ua, $m)) { $result['os'] = 'Android'; $result['os_version'] = $m[1]; }
    elseif (preg_match('/iPhone OS ([\d_]+)/', $ua, $m)) { $result['os'] = 'iOS'; $result['os_version'] = str_replace('_', '.', $m[1]); }
    elseif (preg_match('/Linux/', $ua)) { $result['os'] = 'Linux'; }

    return $result;
}

/**
 * 获取Favicon路径
 */
function getFavicon(): string
{
    $basePath = defined('BASEPATH') ? BASEPATH : dirname(__DIR__);
    if (file_exists($basePath . '/public/assets/images/favicon.png')) {
        return '/assets/images/favicon.png';
    }
    return '/assets/images/favicon.ico';
}

/**
 * 记录操作日志到 logzx 表
 *
 * 通用辅助函数，可在任何后端代码中调用以记录操作日志。
 * 日志记录失败不会影响主业务流程（静默捕获异常）。
 *
 * @param array $data 日志数据，支持以下字段：
 *   - action        string 操作类型（如: login, create, delete, update 等）
 *   - module        string 功能模块（如: domains, chat, staff, ipban, appeal 等）
 *   - description   string 操作描述
 *   - target_type   string 操作对象类型（如: domain, staff, ip, appeal, conversation 等）
 *   - target_id     string 操作对象ID
 *   - target_name   string 操作对象名称
 *   - user_type     string 用户类型（admin/customer/system），默认根据登录状态自动判断
 *   - user_id       int    用户ID
 *   - user_name     string 用户名
 *   - user_role     string 用户角色
 *   - request_url   string 请求URL
 *   - request_method string 请求方法
 *   - request_data  string 请求数据(JSON)
 *   - response_code int    响应状态码
 *   - duration      int    操作耗时(毫秒)
 *   - result        string 操作结果（success/failure/pending）
 *   - error_msg     string 错误信息
 */
function recordLog(array $data): void
{
    static $db = null;
    if ($db === null) {
        try {
            $db = \Core\Database::getInstance();
        } catch (\Throwable $e) {
            return;
        }
    }
    try {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $parsed = function_exists('parseUserAgent') ? parseUserAgent($ua) : [];

        // 如果未指定用户信息，尝试从当前会话获取
        if (!isset($data['user_type']) || !isset($data['user_id'])) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            // 尝试通过Auth获取当前用户
            if (class_exists(\Core\Auth::class)) {
                try {
                    $config = defined('BASEPATH') ? require BASEPATH . '/config/config.php' : [];
                    $auth = new \Core\Auth($db, $config);
                    $currentUser = $auth->check();
                    if ($currentUser) {
                        $data['user_type'] = $data['user_type'] ?? 'admin';
                        $data['user_id'] = $data['user_id'] ?? (int)$currentUser['id'];
                        $data['user_name'] = $data['user_name'] ?? ($currentUser['username'] ?? '');
                        $data['user_role'] = $data['user_role'] ?? ($currentUser['role'] ?? '');
                    }
                } catch (\Throwable $e) {
                    // 静默处理
                }
            }
        }

        $db->insert('logzx', array_merge([
            'ip'            => function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? ''),
            'real_ip'       => function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent'    => mb_substr($ua, 0, 500),
            'browser'       => mb_substr($parsed['browser'] ?? '', 0, 100),
            'os'            => mb_substr($parsed['os'] ?? '', 0, 100),
            'created_at'    => date('Y-m-d H:i:s'),
        ], $data));
    } catch (\Throwable $e) {
        error_log('recordLog failed: ' . $e->getMessage());
    }
}
