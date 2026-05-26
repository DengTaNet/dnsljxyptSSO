<?php
/**
 * 灯塔DNS拦截响应平台 - SSO 认证类 (Logto 集成)
 *
 * 使用 Logto SDK 实现单点登录功能。
 */

namespace Core;

class SsoAuth
{
    /** @var mixed LogtoClient 实例（仅在 SDK 可用时创建） */
    private mixed $client = null;
    private Database $db;
    private array $config;
    private bool $enabled;
    private bool $sdkAvailable;

    public function __construct(Database $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
        // 使用字符串字面量替代 ::class，避免 SDK 未安装时的静态分析错误
        // 功能等价：class_exists('Logto\\Sdk\\LogtoClient') === class_exists(\Logto\Sdk\LogtoClient::class)
        $this->sdkAvailable = class_exists('Logto\\Sdk\\LogtoClient')
            && class_exists('Logto\\Sdk\\LogtoConfig');

        // SSO 启用条件：配置开启 + SDK 可用 + 必要配置项非空
        $logtoConfig = $config['logto'] ?? [];
        $this->enabled = ($logtoConfig['enabled'] ?? false) === true
            && $this->sdkAvailable
            && !empty($logtoConfig['endpoint'])
            && !empty($logtoConfig['appId'])
            && !empty($logtoConfig['appSecret']);

        if ($this->enabled) {
            $this->initLogtoClient();
        }
    }

    /**
     * 初始化 Logto 客户端
     */
    private function initLogtoClient(): void
    {
        $logtoConfig = $this->config['logto'] ?? [];
        $scopes = $logtoConfig['scopes'] ?? ['openid', 'profile', 'email'];

        // 使用字符串字面量替代 ::class，避免 SDK 未安装时的静态分析错误
        $logtoClientClass = 'Logto\\Sdk\\LogtoClient';
        $logtoConfigClass = 'Logto\\Sdk\\LogtoConfig';

        $this->client = new $logtoClientClass(
            new $logtoConfigClass(
                endpoint: $logtoConfig['endpoint'] ?? '',
                appId: $logtoConfig['appId'] ?? '',
                appSecret: $logtoConfig['appSecret'] ?? '',
                scopes: $scopes,
            )
        );
    }

    /**
     * 检查 SSO 是否已启用
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 获取登录 URL，重定向到 Logto 登录页
     */
    public function getSignInUrl(): string
    {
        if (!$this->enabled || !$this->client) {
            throw new \RuntimeException('SSO 未启用或配置错误');
        }

        $redirectUri = $this->getFullRedirectUri();
        return $this->client->signIn($redirectUri);
    }

    /**
     * 获取登出 URL
     */
    public function getSignOutUrl(): string
    {
        if (!$this->enabled || !$this->client) {
            throw new \RuntimeException('SSO 未启用或配置错误');
        }

        $postLogoutUri = $this->getFullPostLogoutUri();
        return $this->client->signOut($postLogoutUri);
    }

    /**
     * 处理登录回调
     */
    public function handleSignInCallback(): array
    {
        if (!$this->enabled || !$this->client) {
            return [
                'success' => false,
                'message' => 'SSO 未启用',
            ];
        }

        try {
            $this->client->handleSignInCallback();

            if (!$this->client->isAuthenticated()) {
                return [
                    'success' => false,
                    'message' => '登录验证失败',
                ];
            }

            // 获取用户信息
            $userInfo = $this->client->getIdTokenClaims();

            // 提取用户信息
            $email = $userInfo->email ?? null;
            $username = $userInfo->username ?? $userInfo->name ?? $email;
            $sub = $userInfo->sub ?? null;  // Logto 用户唯一标识

            if (empty($sub)) {
                return [
                    'success' => false,
                    'message' => '无法获取用户标识',
                ];
            }

            // 查找或创建本地用户
            $admin = $this->findOrCreateUser($sub, $email, $username, $userInfo);

            if (!$admin) {
                return [
                    'success' => false,
                    'message' => '用户同步失败，请联系管理员',
                ];
            }

            // 创建本地会话
            $sessionResult = $this->createLocalSession($admin);

            if (!$sessionResult['success']) {
                return [
                    'success' => false,
                    'message' => $sessionResult['message'],
                ];
            }

            return [
                'success' => true,
                'message' => '登录成功',
                'user'    => $admin,
            ];

        } catch (\Throwable $e) {
            error_log("[SSO登录错误] " . $e->getMessage());
            return [
                'success' => false,
                'message' => '登录处理失败，请稍后重试或联系管理员。',
            ];
        }
    }

    /**
     * 查找或创建本地用户
     */
    private function findOrCreateUser(string $sub, ?string $email, ?string $username, object $_userInfo): ?array
    {
        // 首先通过 SSO 用户标识查找
        $admin = $this->db->queryOne(
            "SELECT * FROM admin_users WHERE sso_id = ? AND status = 1",
            [$sub]
        );

        if ($admin) {
            // 更新用户信息（仅当 SSO 返回了有效值时才更新）
            $updateData = [
                'sso_provider'=> 'logto',
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
            if (!empty($email)) {
                $updateData['email'] = $email;
            }
            if (!empty($username)) {
                $updateData['username'] = $username;
            }
            $this->db->update(
                'admin_users',
                $updateData,
                'id = ?',
                [$admin['id']]
            );
            return $admin;
        }

        // 如果通过邮箱能找到用户，绑定 SSO（需管理员配置允许）
        $autoBind = $this->config['logto']['autoBindByEmail'] ?? false;
        if ($autoBind && !empty($email)) {
            $admin = $this->db->queryOne(
                "SELECT * FROM admin_users WHERE email = ? AND status = 1 AND sso_id IS NULL",
                [$email]
            );

            if ($admin) {
                // 绑定 SSO 到现有账户
                $this->db->update(
                    'admin_users',
                    [
                        'sso_id'       => $sub,
                        'sso_provider' => 'logto',
                        'updated_at'   => date('Y-m-d H:i:s'),
                    ],
                    'id = ?',
                    [$admin['id']]
                );
                return $admin;
            }
        }

        // 自动创建新用户
        $autoCreate = $this->config['logto']['autoCreateUser'] ?? true;
        if (!$autoCreate) {
            return null;
        }

        // 生成唯一用户名
        $baseUsername = $username ?: ('sso_' . substr($sub, 0, 8));
        $finalUsername = $this->generateUniqueUsername($baseUsername);

        $defaultRole = $this->config['logto']['defaultRole'] ?? 'operator';

        // 角色白名单验证，防止通过配置注入非法角色
        $allowedRoles = ['super_admin', 'admin', 'operator', 'domain_admin', 'support', 'security', 'auditor'];
        if (!in_array($defaultRole, $allowedRoles, true)) {
            $defaultRole = 'operator';
        }

        // 创建新用户
        $userId = $this->db->insert('admin_users', [
            'username'     => $finalUsername,
            'email'        => $email ?: '',
            'password_hash'=> password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), // 随机密码，无法通过密码登录
            'role'         => $defaultRole,
            'status'       => 1,
            'sso_id'       => $sub,
            'sso_provider' => 'logto',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        if ($userId) {
            return $this->db->queryOne(
                "SELECT * FROM admin_users WHERE id = ?",
                [$userId]
            );
        }

        return null;
    }

    /**
     * 生成唯一用户名
     */
    private function generateUniqueUsername(string $base): string
    {
        $username = $base;
        $counter = 1;

        while ($this->db->queryOne(
            "SELECT id FROM admin_users WHERE username = ?",
            [$username]
        )) {
            $username = $base . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * 创建本地会话
     */
    private function createLocalSession(array $admin): array
    {
        try {
            $sessionConfig = $this->config['session'] ?? [
                'lifetime' => 86400,
                'cookie_name' => 'lighthouse_session',
                'cookie_path' => '/',
                'cookie_domain' => '',
                'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ];

            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + ($sessionConfig['lifetime'] ?? 86400));

            // 保存会话到数据库
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $this->db->insert('sessions', [
                'user_id'       => $admin['id'],
                'session_token' => $token,
                'ip'            => $clientIp,
                'real_ip'       => $clientIp,
                'user_agent'    => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                'expires_at'    => $expiresAt,
                'sso_session'   => 1,  // 标记为 SSO 会话
            ]);

            // 更新最后登录信息
            $this->db->update(
                'admin_users',
                [
                    'last_login'    => date('Y-m-d H:i:s'),
                    'last_login_ip' => $clientIp,
                ],
                'id = ?',
                [$admin['id']]
            );

            // 设置 Cookie
            $cookieOptions = [
                'expires'  => time() + $sessionConfig['lifetime'],
                'path'     => $sessionConfig['cookie_path'],
                'domain'   => $sessionConfig['cookie_domain'],
                'secure'   => $sessionConfig['cookie_secure'],
                'httponly' => $sessionConfig['cookie_httponly'],
                'samesite' => $sessionConfig['cookie_samesite'],
            ];
            setcookie($sessionConfig['cookie_name'], $token, $cookieOptions);

            return [
                'success' => true,
                'token'   => $token,
            ];

        } catch (\Throwable $e) {
            error_log("[SSO会话创建错误] " . $e->getMessage());
            return [
                'success' => false,
                'message' => '会话创建失败',
            ];
        }
    }

    /**
     * 检查当前会话是否有效
     */
    public function check(): ?array
    {
        $sessionConfig = $this->config['session'] ?? [
            'cookie_name' => 'lighthouse_session',
        ];
        $token = $_COOKIE[$sessionConfig['cookie_name'] ?? 'lighthouse_session'] ?? null;

        if (!$token) {
            return null;
        }

        // 查询有效会话
        $session = $this->db->queryOne(
            "SELECT s.*, u.username, u.email, u.role, u.permissions
             FROM sessions s
             JOIN admin_users u ON s.user_id = u.id
             WHERE s.session_token = ? AND s.expires_at > NOW() AND u.status = 1",
            [$token]
        );

        if (!$session) {
            return null;
        }

        // 验证 User-Agent
        $currentUa = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 128);
        $sessionUa = mb_substr($session['user_agent'] ?? '', 0, 128);
        if ($currentUa !== $sessionUa) {
            $this->db->delete('sessions', 'session_token = ?', [$token]);
            return null;
        }

        return [
            'id'          => $session['user_id'],
            'username'    => $session['username'],
            'email'       => $session['email'],
            'role'        => $session['role'],
            'permissions' => $session['permissions'],
            'session_id'  => $session['id'],
            'sso_session' => $session['sso_session'] ?? 0,
        ];
    }

    /**
     * 退出登录
     */
    public function logout(): bool
    {
        $sessionConfig = $this->config['session'] ?? [
            'cookie_name' => 'lighthouse_session',
        ];
        $token = $_COOKIE[$sessionConfig['cookie_name'] ?? 'lighthouse_session'] ?? null;

        if ($token) {
            $this->db->delete('sessions', 'session_token = ?', [$token]);
        }

        // 清除 Cookie
        setcookie($sessionConfig['cookie_name'], '', [
            'expires'  => time() - 3600,
            'path'     => $sessionConfig['cookie_path'] ?? '/',
            'domain'   => $sessionConfig['cookie_domain'] ?? '',
            'secure'   => $sessionConfig['cookie_secure'] ?? false,
            'httponly' => $sessionConfig['cookie_httponly'] ?? true,
            'samesite' => $sessionConfig['cookie_samesite'] ?? 'Lax',
        ]);

        return true;
    }

    /**
     * 获取完整的回调 URI
     */
    private function getFullRedirectUri(): string
    {
        $redirectUri = $this->config['logto']['redirectUri'] ?? '/admin/callback.php';

        if (str_starts_with($redirectUri, 'http')) {
            return $redirectUri;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';

        return $scheme . '://' . $host . $redirectUri;
    }

    /**
     * 获取完整的登出后跳转 URI
     */
    private function getFullPostLogoutUri(): string
    {
        $postLogoutUri = $this->config['logto']['postLogoutUri'] ?? '/admin/login.php';

        if (str_starts_with($postLogoutUri, 'http')) {
            return $postLogoutUri;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';

        return $scheme . '://' . $host . $postLogoutUri;
    }

    /**
     * 要求登录（未登录则跳转到登录页）
     */
    public function requireAuth(): ?array
    {
        // 域名白名单检查
        $this->checkAdminDomain();

        $user = $this->check();
        if (!$user) {
            header('Location: /admin/login.php');
            exit;
        }
        return $user;
    }

    /**
     * 检查当前访问域名是否在后台白名单中
     *
     * 使用公共函数 getCurrentAdminHost() 获取域名，避免重复实现。
     */
    public function checkAdminDomain(): void
    {
        $allowedDomains = $this->config['site']['admin_allowed_domains'] ?? [];
        if (empty($allowedDomains) || !is_array($allowedDomains)) {
            return;
        }

        $currentHost = getCurrentAdminHost();

        foreach ($allowedDomains as $domain) {
            if (strtolower($domain) === $currentHost) {
                return;
            }
        }

        // 记录安全日志后跳转到无权访问页面（使用 getClientIp() 统一获取真实IP）
        $clientIp = getClientIp();
        error_log("[安全告警] 后台域名白名单拒绝访问(SSO): 域名={$currentHost}, IP={$clientIp}, URI=" . ($_SERVER['REQUEST_URI'] ?? ''));
        // 确保响应头未发送，防止重定向失败
        if (!headers_sent()) {
            header('Location: /admin/access_denied.php');
        }
        exit;
    }
}
