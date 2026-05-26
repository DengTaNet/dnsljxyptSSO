<?php
/**
 * 灯塔DNS拦截响应平台 - 管理员认证类
 *
 * 处理管理员认证、权限验证。
 * 仅支持 SSO 登录。
 */

namespace Core;

class Auth
{
    private Database $db;
    private array $config;
    private ?SsoAuth $ssoAuth = null;

    public function __construct(Database $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;

        // 初始化 SSO 认证（如果启用）
        // 使用 try-catch 防止 SSO SDK 不可用时导致整个系统不可用
        if ($this->isSsoEnabled()) {
            try {
                $this->ssoAuth = new SsoAuth($db, $config);
            } catch (\Throwable $e) {
                error_log("[Auth] SSO 初始化失败: " . $e->getMessage());
                $this->ssoAuth = null;
            }
        }
    }

    /**
     * 检查 SSO 是否启用
     */
    public function isSsoEnabled(): bool
    {
        return ($this->config['logto']['enabled'] ?? false) === true;
    }

    /**
     * 获取 SSO 认证实例
     */
    public function getSsoAuth(): ?SsoAuth
    {
        return $this->ssoAuth;
    }

    /**
     * 验证当前会话（仅支持 SSO 认证）
     *
     * @return array|null 管理员信息，未登录返回null
     */
    public function check(): ?array
    {
        if ($this->isSsoEnabled() && $this->ssoAuth) {
            return $this->ssoAuth->check();
        }

        return null;
    }

    /**
     * 退出登录（仅支持 SSO 登出）
     */
    public function logout(): bool
    {
        if ($this->isSsoEnabled() && $this->ssoAuth) {
            return $this->ssoAuth->logout();
        }

        return true;
    }

    /**
     * 要求登录（未登录则跳转到登录页）
     */
    public function requireAuth(): ?array
    {
        // 域名白名单检查（在登录验证之前，确保未登录时也无法通过非白名单域名访问后台）
        if ($this->ssoAuth) {
            $this->ssoAuth->checkAdminDomain();
        } else {
            $this->checkAdminDomain();
        }

        $user = $this->check();
        if (!$user) {
            header('Location: /admin/login.php');
            exit;
        }
        return $user;
    }

    /**
     * 检查当前访问域名是否在后台白名单中
     * 白名单为空时不限制访问
     * 注意：SSO 启用时优先使用 SsoAuth::checkAdminDomain()，此方法作为 SSO 未启用时的回退
     *
     * 使用公共函数 getCurrentAdminHost() 获取域名，避免重复实现。
     */
    private function checkAdminDomain(): void
    {
        $allowedDomains = $this->config['site']['admin_allowed_domains'] ?? [];

        // 白名单为空，不限制
        if (empty($allowedDomains) || !is_array($allowedDomains)) {
            return;
        }

        $currentHost = getCurrentAdminHost();

        foreach ($allowedDomains as $domain) {
            if (strtolower($domain) === $currentHost) {
                return;
            }
        }

        // 域名不在白名单中，记录安全日志并跳转到无权访问页面
        $clientIp = getClientIp();
        error_log("[安全告警] 后台域名白名单拒绝访问: 域名={$currentHost}, IP={$clientIp}, URI=" . ($_SERVER['REQUEST_URI'] ?? ''));
        if (class_exists('\Core\Debug')) {
            \Core\Debug::logAuth("域名白名单拒绝: {$currentHost}", ['ip' => $clientIp, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        }
        // 确保响应头未发送，防止重定向失败
        if (!headers_sent()) {
            header('Location: /admin/access_denied.php');
        }
        exit;
    }

    /**
     * 要求特定角色
     *
     * @param array $roles 允许的角色列表
     */
    public function requireRole(array $roles): void
    {
        $user = $this->requireAuth();
        if (!in_array($user['role'], $roles)) {
            http_response_code(403);
            die('权限不足。');
        }
    }

    /**
     * 检查当前用户是否拥有指定模块的权限
     *
     * @param string $module 权限模块名
     * @return bool
     */
    public function hasPermission(string $module): bool
    {
        $user = $this->check();
        if (!$user) {
            return false;
        }

        // super_admin 拥有所有权限
        if ($user['role'] === 'super_admin') {
            return true;
        }

        // 如果有自定义permissions字段，以自定义为准
        if (!empty($user['permissions'])) {
            $customPerms = json_decode($user['permissions'], true);
            if (is_array($customPerms) && in_array('*', $customPerms)) {
                return true;
            }
            if (is_array($customPerms) && in_array($module, $customPerms)) {
                return true;
            }
            // 有自定义权限但不在其中，返回false
            return false;
        }

        // 根据预设角色模板检查
        $rolePermissions = [
            'super_admin'   => ['*'],
            'admin'         => ['*'],
            'operator'      => ['dashboard', 'domains_expired', 'domains_violation', 'chat', 'logs', 'log_center'],
            'domain_admin'  => ['dashboard', 'domains_expired', 'domains_violation'],
            'support'       => ['dashboard', 'chat'],
            'security'      => ['dashboard', 'ipban', 'logs', 'log_center'],
            'auditor'       => ['dashboard', 'logs', 'log_center'],
        ];

        $perms = $rolePermissions[$user['role']] ?? [];
        if (in_array('*', $perms)) {
            return true;
        }

        return in_array($module, $perms);
    }

    /**
     * 获取当前用户的所有权限模块列表
     *
     * @return array
     */
    public function getPermissions(): array
    {
        $user = $this->check();
        if (!$user) {
            return [];
        }

        if ($user['role'] === 'super_admin' || $user['role'] === 'admin') {
            return ['*'];
        }

        // 如果有自定义permissions字段
        if (!empty($user['permissions'])) {
            $customPerms = json_decode($user['permissions'], true);
            if (is_array($customPerms)) {
                return $customPerms;
            }
        }

        // 根据预设角色模板返回
        $rolePermissions = [
            'super_admin'   => ['*'],
            'admin'         => ['*'],
            'operator'      => ['dashboard', 'domains_expired', 'domains_violation', 'chat', 'logs', 'log_center'],
            'domain_admin'  => ['dashboard', 'domains_expired', 'domains_violation'],
            'support'       => ['dashboard', 'chat'],
            'security'      => ['dashboard', 'ipban', 'logs', 'log_center'],
            'auditor'       => ['dashboard', 'logs', 'log_center'],
        ];

        return $rolePermissions[$user['role']] ?? [];
    }

    /**
     * 获取所有可用的权限模块列表
     *
     * @return array
     */
    public static function getAllModules(): array
    {
        return [
            'dashboard'        => '仪表盘',
            'domains_expired'  => '到期域名',
            'domains_violation'=> '审查域名',
            'chat'             => '客服中心',
            'logs'             => '访问记录',
            'log_center'       => '日志中心',
            'ipban'            => 'IP封禁',
            'staff'            => '组织管理',
        ];
    }

    /**
     * 获取所有可用的角色列表
     *
     * @return array
     */
    public static function getAllRoles(): array
    {
        return [
            'super_admin'  => '超级管理员',
            'admin'        => '管理员',
            'operator'     => '运维人员',
            'domain_admin' => '域名管理员',
            'support'      => '客服专员',
            'security'     => '安全专员',
            'auditor'      => '审计员',
        ];
    }
}
