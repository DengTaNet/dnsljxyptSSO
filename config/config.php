<?php
/**
 * 灯塔DNS拦截响应平台 - 配置文件（默认模板）
 *
 * 注意：此文件为默认配置模板。
 * 安装向导会自动生成 config/config.php，覆盖此文件。
 * 如果手动配置，请直接修改此文件。
 */

// 防止直接访问
if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}

return [

    /* ============================================================
     *  数据库配置
     *
     *  重要：数据库用户名和密码必须通过环境变量（DB_USER、DB_PASSWORD）
     *  或安装向导进行配置。不要在此文件中硬编码凭据。
     * ============================================================ */
    'database' => [
        'host'      => '127.0.0.1',
        'port'      => 3306,
        'dbname'    => 'dnslj',
        'user'      => getenv('DB_USER') ?: '',
        'password'  => getenv('DB_PASSWORD') ?: '',
        'prefix'    => '',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options'   => [
            2     => 2,     // PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            19    => 2,     // PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            20    => false, // PDO::ATTR_EMULATE_PREPARES => false
            1002  => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', // PDO::MYSQL_ATTR_INIT_COMMAND
        ],
    ],

    /* ============================================================
     *  站点配置
     * ============================================================ */
    'site' => [
        'name'                => '灯塔DNS拦截响应平台',
        'description'         => '本平台由灯塔风险控制与合规平台提供技术支持',
        'logo_path'           => '/assets/images/logo.png',
        'contact_email'       => 'support@dengtanet.com',
        'admin_url'           => '/admin/',
        'timezone'            => 'Asia/Shanghai',
        'admin_allowed_domains' => [],  // 后台访问域名白名单，空数组表示不限制。配置示例: ['dnslj.dengtanet.com', 'admin.example.com']
        'cdn_local'             => false, // 是否使用本地CDN资源（生产环境建议开启，需提前下载资源文件）
    ],

    /* ============================================================
     *  文件上传配置
     * ============================================================ */
    'upload' => [
        'max_size'        => 10 * 1024 * 1024, // 10MB
        'max_image_size'  => 5 * 1024 * 1024,  // 5MB（图片）
        'max_video_size'  => 50 * 1024 * 1024, // 50MB（视频）
        'allowed_images'  => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        'allowed_videos'  => ['mp4', 'avi', 'mov', 'mkv', 'webm'],
        'allowed_files'   => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', '7z', 'txt'],
        'chat_upload_dir' => BASEPATH . '/public/uploads/chat/',
        'evidence_upload_dir' => BASEPATH . '/public/uploads/evidence/',
    ],

    /* ============================================================
     *  会话配置
     *  等保2.0三级要求：会话超时时间不得超过30分钟
     * ============================================================ */
    'session' => [
        'lifetime'    => 1800,       // 会话有效期（秒），30分钟（等保2.0三级要求）
        'cookie_name' => 'lighthouse_session',
        'cookie_path' => '/',
        'cookie_domain' => '',
        'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) === 443),    // 自动检测HTTPS
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],

    /* ============================================================
     *  安全配置
     *  等保2.0三级要求：必须配置安全响应头
     * ============================================================ */
    'security' => [
        'csrf_enabled'     => true,
        'csrf_token_name'  => '_token',
        'csrf_token_expire'=> 3600,  // CSRF Token 过期时间（秒）
        // 安全响应头配置
        'headers' => [
            // Content-Security-Policy: 防止XSS攻击
            'content_security_policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net data:; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none';",
            // X-Content-Type-Options: 防止MIME类型嗅探
            'x_content_type_options' => 'nosniff',
            // X-Frame-Options: 防止点击劫持
            'x_frame_options' => 'DENY',
            // X-XSS-Protection: XSS过滤（旧浏览器兼容）
            'x_xss_protection' => '1; mode=block',
            // Referrer-Policy: 控制Referer信息泄露
            'referrer_policy' => 'strict-origin-when-cross-origin',
            // Permissions-Policy: 限制浏览器功能
            'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
        ],
    ],

    /* ============================================================
     *  日志配置
     * ============================================================ */
    'logging' => [
        'enabled'      => true,
        'log_dir'      => BASEPATH . '/storage/logs/',
        'log_level'    => 'info',  // debug, info, warning, error
        'max_log_days' => 90,
    ],

    /* ============================================================
     *  调试模式配置
     *  开启后所有数据库操作、API调用、认证等都会记录到日志文件
     *  日志目录：storage/logs/debug/
     *  注意：生产环境务必关闭！
     * ============================================================ */
    'debug' => [
        'enabled'     => true,    // 是否开启调试模式（默认开启，方便排查问题）
        'allowed_ips' => ['*'],   // 允许开启调试的IP白名单，['*'] 表示允许所有IP
        'log_dir'     => BASEPATH . '/storage/logs/debug/',
        'log_queries' => true,    // 记录所有SQL查询
        'log_auth'    => true,    // 记录认证操作
        'log_api'     => true,    // 记录API请求
        'log_errors'  => true,    // 记录所有错误和异常
        'max_file_size' => 50 * 1024 * 1024, // 单个日志文件最大50MB
        'max_files'   => 30,      // 最多保留30个日志文件
    ],

    /* ============================================================
     *  Logto SSO 配置
     *  文档: https://docs.logto.io/zh-CN/quick-starts/php
     * ============================================================ */
    'logto' => [
        'enabled'           => false,                         // 是否启用 SSO 登录（需配置好 Logto 后再开启）
        'endpoint'          => getenv('LOGTO_ENDPOINT') ?: '',  // Logto 服务端点
        'appId'             => getenv('LOGTO_APP_ID') ?: '',                        // 应用 ID
        'appSecret'         => getenv('LOGTO_APP_SECRET') ?: '',                // 应用密钥
        'redirectUri'       => '/admin/callback.php',         // 登录回调地址
        'postLogoutUri'     => '/admin/login.php',            // 登出后跳转地址
        'scopes'            => ['openid', 'profile', 'email'], // 请求的权限范围
        'autoCreateUser'    => true,                          // 自动创建不存在的用户
        'defaultRole'       => 'operator',                    // 自动创建用户的默认角色
    ],

];
