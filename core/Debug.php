<?php
declare(strict_types=1);

namespace Core;

/**
 * 调试日志类
 *
 * 在调试模式下记录所有代码操作：数据库连接、SQL查询、事务、
 * 认证操作、API请求、错误异常等。日志按日期分文件存储。
 *
 * 使用方式：
 *   \Core\Debug::log('sql', 'SELECT * FROM users WHERE id = 1', ['params' => [1], 'time' => '0.002s']);
 *   \Core\Debug::log('auth', '用户登录', ['username' => 'admin', 'result' => 'success']);
 *   \Core\Debug::log('api', 'POST /api/chat.php?action=send', ['status' => 'success']);
 */
class Debug
{
    /** @var bool 是否启用 */
    private static bool $enabled = false;

    /** @var string 日志目录 */
    private static string $logDir = '';

    /** @var array 配置 */
    private static array $config = [];

    /** @var float 请求开始时间 */
    private static float $startTime = 0;

    /** @var int 当前请求的SQL查询计数 */
    private static int $queryCount = 0;

    /** @var float 当前请求的SQL总耗时 */
    private static float $queryTime = 0;

    /**
     * 初始化调试模式
     *
     * @param array $appConfig 应用配置数组
     */
    public static function init(array $appConfig): void
    {
        $debugConfig = $appConfig['debug'] ?? [];
        self::$enabled = !empty($debugConfig['enabled']);
        self::$logDir = $debugConfig['log_dir'] ?? '';
        self::$config = $debugConfig;
        self::$startTime = microtime(true);
        self::$queryCount = 0;
        self::$queryTime = 0.0;

        // SEC-H01: IP白名单限制 - 仅允许白名单中的IP开启调试模式
        if (self::$enabled) {
            $allowedIps = $debugConfig['allowed_ips'] ?? [];
            if (empty($allowedIps)) {
                // allowed_ips为空数组，不允许任何人开启调试
                self::$enabled = false;
            } elseif (in_array('*', $allowedIps, true)) {
                // allowed_ips包含 '*'，允许所有IP开启调试
                // 保持 enabled = true
            } else {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                if (!in_array($clientIp, $allowedIps, true)) {
                    self::$enabled = false;
                }
            }
        }

        if (self::$enabled && !empty(self::$logDir)) {
            // 确保日志目录存在
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0750, true);
            }
        }
    }

    /**
     * 检查调试模式是否启用
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * 记录调试日志
     *
     * @param string $category 分类：sql/auth/api/error/system/request
     * @param string $action   操作描述
     * @param array  $context  上下文数据
     */
    public static function log(string $category, string $action, array $context = []): void
    {
        if (!self::$enabled) {
            return;
        }

        // 按分类检查是否启用
        $categoryKey = 'log_' . $category;
        if (isset(self::$config[$categoryKey]) && !self::$config[$categoryKey]) {
            return;
        }

        // 敏感信息脱敏
        $context = self::sanitizeContext($context);

        // 构建日志行
        $timestamp = date('Y-m-d H:i:s');
        $memory = round(memory_get_usage(true) / 1024, 1);
        $elapsed = round(microtime(true) - self::$startTime, 4);

        $logLine = sprintf(
            "[%s] [%s] [%s] %s",
            $timestamp,
            $category,
            self::getRequestId(),
            $action
        );

        if (!empty($context)) {
            $logLine .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $logLine .= sprintf(" | mem=%sKB elapsed=%ss", $memory, $elapsed) . "\n";

        // 写入日志文件
        self::writeLog($logLine, $category);
    }

    /**
     * 记录SQL查询
     *
     * @param string $sql    SQL语句
     * @param array  $params 绑定参数
     * @param float  $time   执行耗时（秒）
     * @param string $method 查询方法（query/queryOne/execute/insert/update/delete）
     */
    public static function logQuery(string $sql, array $params = [], float $time = 0, string $method = ''): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$queryCount++;
        self::$queryTime += $time;

        // 格式化SQL（去掉多余空白）
        $formattedSql = preg_replace('/\s+/', ' ', trim($sql));

        self::log('sql', sprintf('SQL [%s] %s', $method, $formattedSql), [
            'params' => $params,
            'time'   => round($time * 1000, 2) . 'ms',
            'count'  => self::$queryCount,
        ]);
    }

    /**
     * 记录数据库连接
     */
    public static function logConnect(string $host, string $dbname): void
    {
        self::log('sql', sprintf('数据库连接 %s/%s', $host, $dbname));
    }

    /**
     * 记录事务操作
     */
    public static function logTransaction(string $type): void
    {
        self::log('sql', sprintf('事务 %s', strtoupper($type)));
    }

    /**
     * 记录认证操作
     */
    public static function logAuth(string $action, array $context = []): void
    {
        self::log('auth', $action, $context);
    }

    /**
     * 记录API请求
     */
    public static function logApi(string $action, array $context = []): void
    {
        self::log('api', $action, $context);
    }

    /**
     * 记录错误/异常
     */
    public static function logError(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * 记录请求开始
     */
    public static function logRequestStart(): void
    {
        if (!self::$enabled) return;

        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        self::log('request', sprintf('%s %s from %s', $method, $uri, $ip), [
            'get'  => $_GET,
            'post' => array_map(function($v) { return is_string($v) ? '[len=' . strlen($v) . ']' : $v; }, $_POST),
            'cookie' => array_keys($_COOKIE),
        ]);
    }

    /**
     * 记录请求结束（汇总信息）
     */
    public static function logRequestEnd(): void
    {
        if (!self::$enabled) return;

        $totalTime = round(microtime(true) - self::$startTime, 4);
        $memory = round(memory_get_peak_usage(true) / 1024, 1);

        self::log('system', sprintf('请求结束 | 总耗时=%ss | 峰值内存=%sKB | SQL查询=%d次 | SQL总耗时=%.2fms',
            $totalTime, $memory, self::$queryCount, self::$queryTime * 1000));
    }

    /**
     * 获取当前请求的调试统计
     */
    public static function getStats(): array
    {
        return [
            'enabled'    => self::$enabled,
            'query_count' => self::$queryCount,
            'query_time' => round(self::$queryTime * 1000, 2),
            'elapsed'    => round(microtime(true) - self::$startTime, 4),
            'memory'     => round(memory_get_usage(true) / 1024, 1),
        ];
    }

    /**
     * 写入日志文件
     */
    private static function writeLog(string $logLine, string $category): void
    {
        if (empty(self::$logDir)) return;

        // 验证 category 防止路径遍历
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $category)) return;

        // 按日期+分类分文件
        $date = date('Y-m-d');
        $file = self::$logDir . "{$date}_{$category}.log";

        // 检查文件大小限制
        if (file_exists($file)) {
            $size = filesize($file);
            $maxSize = self::$config['max_file_size'] ?? 50 * 1024 * 1024;
            if ($size > $maxSize) {
                // 轮转：重命名为 .1
                @rename($file, $file . '.1');
            }
        }

        // 写入日志（使用 FILE_APPEND | LOCK_EX 保证原子性）
        @file_put_contents($file, $logLine, FILE_APPEND | LOCK_EX);

        // 清理旧日志文件
        self::cleanOldLogs();
    }

    /**
     * 清理过期的日志文件
     */
    private static function cleanOldLogs(): void
    {
        // 每次请求只清理一次（概率触发，避免性能影响）
        if (mt_rand(1, 100) !== 1) {
            return;
        }

        if (empty(self::$logDir)) return;

        $maxFiles = (int)(self::$config['max_files'] ?? 30);
        $files = glob(self::$logDir . '*.log');

        if ($files === false || count($files) <= $maxFiles) {
            return;
        }

        // 按修改时间排序，删除最旧的
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        $toDelete = array_slice($files, 0, count($files) - $maxFiles);
        foreach ($toDelete as $f) {
            @unlink($f);
            // 同时删除轮转文件
            @unlink($f . '.1');
        }
    }

    /**
     * 生成请求ID（用于追踪同一请求的所有日志）
     */
    private static function getRequestId(): string
    {
        static $requestId = null;
        if ($requestId === null) {
            $requestId = substr(bin2hex(random_bytes(4)), 0, 8);
        }
        return $requestId;
    }

    /**
     * 敏感信息脱敏
     */
    private static function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'password_hash', 'token', 'session_token', 'csrf_token', '_token', 'secret', 'api_key', 'access_token', 'refresh_token', 'code', 'app_secret'];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::sanitizeContext($value);
            } elseif (is_string($value) && in_array(strtolower((string)$key), $sensitiveKeys, true)) {
                $context[$key] = '[REDACTED]';
            }
        }

        return $context;
    }
}
