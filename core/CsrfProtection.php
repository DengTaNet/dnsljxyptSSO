<?php
declare(strict_types=1);

/**
 * CSRF 保护类
 *
 * 提供基于 Session 的 CSRF（跨站请求伪造）令牌生成和验证功能。
 * 所有表单提交和状态变更请求都应携带有效的 CSRF 令牌。
 *
 * 使用方式：
 * 1. 在表单中嵌入令牌：<?php echo csrfField(); ?>
 * 2. 在处理请求时验证：CsrfProtection::validate();
 */

namespace Core;

class CsrfProtection
{
    /** @var string Session 中存储令牌的键名 */
    private const TOKEN_KEY = 'csrf_token';

    /** @var string Session 中存储令牌过期时间的键名 */
    private const EXPIRES_KEY = 'csrf_token_expires';

    /** @var string Session 中存储令牌列表的键名（支持多令牌） */
    private const TOKENS_KEY = 'csrf_tokens';

    /** @var int 默认令牌有效期（秒） */
    private const DEFAULT_LIFETIME = 3600;

    /**
     * 生成新的 CSRF 令牌
     *
     * 使用 random_bytes() 生成加密安全的随机令牌。
     * 令牌和过期时间存储在 Session 中。
     *
     * @param int|null $lifetime 令牌有效期（秒），null 使用配置值
     * @return string 生成的 CSRF 令牌（64位十六进制字符串）
     */
    public static function generateToken(?int $lifetime = null): string
    {
        self::ensureSession();

        // 生成 32 字节随机数据（64位十六进制字符串）
        $token = bin2hex(random_bytes(32));

        // 计算过期时间
        $lifetime = $lifetime ?? (defined('CSRF_TOKEN_LIFETIME') ? CSRF_TOKEN_LIFETIME : self::DEFAULT_LIFETIME);
        $expires = time() + $lifetime;

        // 存储到 Session（支持多令牌，最多保留 5 个）
        if (!isset($_SESSION[self::TOKENS_KEY]) || !is_array($_SESSION[self::TOKENS_KEY])) {
            $_SESSION[self::TOKENS_KEY] = [];
        }

        $_SESSION[self::TOKENS_KEY][$token] = $expires;

        // 限制令牌数量，清理过期的令牌
        self::cleanupTokens();

        // 同时存储最新令牌（向后兼容）
        $_SESSION[self::TOKEN_KEY] = $token;
        $_SESSION[self::EXPIRES_KEY] = $expires;

        return $token;
    }

    /**
     * 验证 CSRF 令牌
     *
     * 检查提交的令牌是否存在于 Session 中且未过期。
     * 验证成功后默认清除该令牌（防止重放攻击）。
     *
     * @param string      $token        待验证的令牌
     * @param bool        $consumeAfter 验证后是否清除令牌（默认 true）
     * @return bool 验证通过返回 true
     */
    public static function validate(string $token, bool $consumeAfter = false): bool
    {
        self::ensureSession();

        if (empty($token)) {
            return false;
        }

        // 检查多令牌列表（使用hash_equals防止时序攻击）
        if (isset($_SESSION[self::TOKENS_KEY]) && is_array($_SESSION[self::TOKENS_KEY])) {
            foreach ($_SESSION[self::TOKENS_KEY] as $existingToken => $expires) {
                if (hash_equals($existingToken, $token)) {
                    // 检查是否过期
                    if (time() > $expires) {
                        unset($_SESSION[self::TOKENS_KEY][$existingToken]);
                        return false;
                    }
                    // 验证成功
                    if ($consumeAfter) {
                        unset($_SESSION[self::TOKENS_KEY][$existingToken]);
                    }
                    return true;
                }
            }
        }

        // 向后兼容：检查单个令牌
        if (isset($_SESSION[self::TOKEN_KEY], $_SESSION[self::EXPIRES_KEY])) {
            // 检查是否过期
            if (time() > $_SESSION[self::EXPIRES_KEY]) {
                unset($_SESSION[self::TOKEN_KEY], $_SESSION[self::EXPIRES_KEY]);
                return false;
            }

            // 使用时间安全的比较函数
            $valid = hash_equals($_SESSION[self::TOKEN_KEY], $token);

            if ($valid && $consumeAfter) {
                unset($_SESSION[self::TOKEN_KEY], $_SESSION[self::EXPIRES_KEY]);
            }

            return $valid;
        }

        return false;
    }

    /**
     * 从请求中获取并验证 CSRF 令牌
     *
     * 依次从以下位置获取令牌：
     * 1. HTTP 头 X-CSRF-Token
     * 2. HTTP 头 X-XSRF-Token
     * 3. POST 数据 _token
     * 4. GET/POST 数据 csrf_token
     *
     * @param bool $consumeAfter 验证后是否清除令牌（默认 false，令牌可重用）
     *
     * M-09 安全提示：对于状态变更操作（如删除数据、修改密码、封禁IP、清理日志等），
     * 调用方应显式传入 consumeAfter=true，以防止令牌被重放攻击。
     * 例如：CsrfProtection::validateFromRequest(true)
     *
     * 对于查询类操作（如导出、列表查询等）可以保持默认 false，允许AJAX重试。
     *
     * @return bool 验证通过返回 true
     */
    public static function validateFromRequest(bool $consumeAfter = false): bool
    {
        // 从 HTTP 头获取
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? '';

        // 从 POST 数据获取
        if (empty($token)) {
            $token = $_POST['_token'] ?? '';
            if (empty($token)) {
                $token = $_POST['csrf_token'] ?? '';
            }
        }

        // SEC-016: 移除从GET参数获取CSRF令牌的逻辑，防止令牌泄露
        return self::validate((string) $token, $consumeAfter);
    }

    /**
     * 获取当前有效的 CSRF 令牌
     *
     * 如果没有有效令牌，自动生成新的。
     *
     * @return string CSRF 令牌
     */
    public static function getToken(): string
    {
        self::ensureSession();

        // 检查是否有有效的令牌
        if (isset($_SESSION[self::TOKENS_KEY]) && is_array($_SESSION[self::TOKENS_KEY])) {
            // 清理过期令牌并返回第一个有效令牌
            foreach ($_SESSION[self::TOKENS_KEY] as $existingToken => $expires) {
                if (time() > $expires) {
                    unset($_SESSION[self::TOKENS_KEY][$existingToken]);
                    continue;
                }
                return $existingToken;
            }
        }

        // 检查向后兼容的单令牌
        if (isset($_SESSION[self::TOKEN_KEY], $_SESSION[self::EXPIRES_KEY])) {
            if (time() <= $_SESSION[self::EXPIRES_KEY]) {
                return $_SESSION[self::TOKEN_KEY];
            }
        }

        // 生成新令牌
        return self::generateToken();
    }

    /**
     * 生成 CSRF 隐藏输入字段 HTML
     *
     * 在 HTML 表单中嵌入此字段即可自动包含 CSRF 令牌。
     *
     * @return string HTML 隐藏输入字段
     */
    public static function field(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * 生成 CSRF meta 标签 HTML
     *
     * 用于在页面 head 中嵌入令牌，供 JavaScript/AJAX 请求使用。
     *
     * @return string HTML meta 标签
     */
    public static function metaTag(): string
    {
        $token = self::getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }

    /**
     * 获取用于 AJAX 请求的 CSRF 令牌头
     *
     * @return array ['X-CSRF-Token' => 'token_value']
     */
    public static function ajaxHeader(): array
    {
        return [
            'X-CSRF-Token' => self::getToken(),
        ];
    }

    /**
     * 清除所有 CSRF 令牌
     *
     * 通常在用户登出时调用。
     */
    public static function clearAll(): void
    {
        self::ensureSession();
        unset($_SESSION[self::TOKEN_KEY], $_SESSION[self::EXPIRES_KEY], $_SESSION[self::TOKENS_KEY]);
    }

    /**
     * 确保令牌数量不超过限制，并清理过期令牌
     *
     * 最多保留 5 个有效令牌。
     */
    private static function cleanupTokens(): void
    {
        if (!isset($_SESSION[self::TOKENS_KEY]) || !is_array($_SESSION[self::TOKENS_KEY])) {
            return;
        }

        $now = time();
        $maxTokens = 5;

        // 移除过期令牌
        foreach ($_SESSION[self::TOKENS_KEY] as $token => $expires) {
            if ($now > $expires) {
                unset($_SESSION[self::TOKENS_KEY][$token]);
            }
        }

        // 如果令牌数量超过限制，移除最早的
        if (count($_SESSION[self::TOKENS_KEY]) > $maxTokens) {
            asort($_SESSION[self::TOKENS_KEY]); // 按过期时间升序排列
            $_SESSION[self::TOKENS_KEY] = array_slice(
                $_SESSION[self::TOKENS_KEY],
                -$maxTokens,
                $maxTokens,
                true
            );
        }
    }

    /**
     * 确保 Session 已启动
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 7200;
            $cookieParams = [
                'lifetime' => $lifetime,
                'path'     => '/',
                'secure'   => (!empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? 80) === 443),
                'httponly'  => true,
                'samesite'  => 'Lax',
            ];
            session_set_cookie_params($cookieParams);
            session_start();
        }
    }
}
