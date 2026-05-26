<?php
/**
 * 灯塔DNS拦截响应平台 - 数据库频率限制器
 *
 * 基于数据库的频率限制实现，解决 Session 频率限制可被绕过的问题。
 * 等保2.0三级要求：必须实现有效的访问频率限制。
 *
 * 使用示例：
 *   $limiter = new RateLimiter($db, 'appeal_verify', $clientIp, 10, 3600);
 *   if ($limiter->isExceeded()) {
 *       jsonError('请求过于频繁', 429);
 *   }
 *   $limiter->increment();
 */

declare(strict_types=1);

namespace Core;

use PDO;

/**
 * 数据库频率限制器类
 */
class RateLimiter
{
    /** @var Database 数据库实例 */
    private Database $db;

    /** @var string 限制器标识（如 appeal_verify, chat_send） */
    private string $key;

    /** @var string 客户端标识（如 IP 地址） */
    private string $identifier;

    /** @var int 时间窗口内最大请求数 */
    private int $maxRequests;

    /** @var int 时间窗口（秒） */
    private int $windowSeconds;

    /** @var string 表名 */
    private static string $tableName = 'rate_limits';

    /**
     * 构造函数
     *
     * @param Database $db           数据库实例
     * @param string   $key          限制器标识
     * @param string   $identifier   客户端标识（如 IP 地址）
     * @param int      $maxRequests  时间窗口内最大请求数
     * @param int      $windowSeconds 时间窗口（秒）
     */
    public function __construct(
        Database $db,
        string $key,
        string $identifier,
        int $maxRequests,
        int $windowSeconds
    ) {
        $this->db = $db;
        $this->key = $key;
        $this->identifier = $identifier;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;

        // 确保表存在
        $this->ensureTableExists();
    }

    /**
     * 检查是否已超过限制
     *
     * @return bool true 表示已超过限制，false 表示未超过
     */
    public function isExceeded(): bool
    {
        $record = $this->getRecord();
        if ($record === null) {
            return false;
        }

        // 检查时间窗口是否已过期
        if (time() - strtotime($record['window_start']) >= $this->windowSeconds) {
            return false;
        }

        return $record['count'] >= $this->maxRequests;
    }

    /**
     * 获取当前计数
     *
     * @return int 当前计数
     */
    public function getCount(): int
    {
        $record = $this->getRecord();
        if ($record === null) {
            return 0;
        }

        // 检查时间窗口是否已过期
        if (time() - strtotime($record['window_start']) >= $this->windowSeconds) {
            return 0;
        }

        return (int)$record['count'];
    }

    /**
     * 获取剩余请求数
     *
     * @return int 剩余请求数
     */
    public function getRemaining(): int
    {
        return max(0, $this->maxRequests - $this->getCount());
    }

    /**
     * 获取重置时间（秒）
     *
     * @return int 距离窗口重置的秒数
     */
    public function getResetTime(): int
    {
        $record = $this->getRecord();
        if ($record === null) {
            return $this->windowSeconds;
        }

        $elapsed = time() - strtotime($record['window_start']);
        return max(0, $this->windowSeconds - $elapsed);
    }

    /**
     * 增加计数
     *
     * @return bool 操作是否成功
     */
    public function increment(): bool
    {
        $record = $this->getRecord();
        $now = date('Y-m-d H:i:s');

        if ($record === null) {
            // 创建新记录
            return $this->createRecord($now);
        }

        // 检查时间窗口是否已过期
        if (time() - strtotime($record['window_start']) >= $this->windowSeconds) {
            // 重置窗口
            return $this->resetRecord($now);
        }

        // 增加计数
        return $this->incrementRecord();
    }

    /**
     * 重置计数
     *
     * @return bool 操作是否成功
     */
    public function reset(): bool
    {
        $now = date('Y-m-d H:i:s');
        return $this->resetRecord($now);
    }

    /**
     * 获取当前记录
     *
     * @return array|null 记录数组或 null
     */
    private function getRecord(): ?array
    {
        try {
            $record = $this->db->queryOne(
                "SELECT * FROM " . self::$tableName . " WHERE limiter_key = ? AND identifier = ?",
                [$this->key, $this->identifier]
            );
            return $record ?: null;
        } catch (\Throwable $e) {
            error_log('[RateLimiter] 查询失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 创建新记录
     *
     * @param string $windowStart 窗口开始时间
     * @return bool 操作是否成功
     */
    private function createRecord(string $windowStart): bool
    {
        try {
            $this->db->insert(self::$tableName, [
                'limiter_key'   => $this->key,
                'identifier'    => $this->identifier,
                'count'         => 1,
                'window_start'  => $windowStart,
                'updated_at'    => $windowStart,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[RateLimiter] 创建记录失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 重置记录
     *
     * @param string $windowStart 新窗口开始时间
     * @return bool 操作是否成功
     */
    private function resetRecord(string $windowStart): bool
    {
        try {
            $this->db->execute(
                "UPDATE " . self::$tableName . " SET count = 1, window_start = ?, updated_at = ? WHERE limiter_key = ? AND identifier = ?",
                [$windowStart, $windowStart, $this->key, $this->identifier]
            );
            return true;
        } catch (\Throwable $e) {
            error_log('[RateLimiter] 重置记录失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 增加记录计数
     *
     * @return bool 操作是否成功
     */
    private function incrementRecord(): bool
    {
        try {
            $this->db->execute(
                "UPDATE " . self::$tableName . " SET count = count + 1, updated_at = ? WHERE limiter_key = ? AND identifier = ?",
                [date('Y-m-d H:i:s'), $this->key, $this->identifier]
            );
            return true;
        } catch (\Throwable $e) {
            error_log('[RateLimiter] 增加计数失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 确保频率限制表存在
     */
    private function ensureTableExists(): void
    {
        try {
            // 检查表是否存在
            $exists = $this->db->queryOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                [self::$tableName]
            );

            if (!$exists) {
                // 创建表
                $this->db->execute("
                    CREATE TABLE " . self::$tableName . " (
                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        limiter_key VARCHAR(100) NOT NULL COMMENT '限制器标识',
                        identifier VARCHAR(100) NOT NULL COMMENT '客户端标识（如IP）',
                        count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当前计数',
                        window_start DATETIME NOT NULL COMMENT '窗口开始时间',
                        updated_at DATETIME NOT NULL COMMENT '最后更新时间',
                        UNIQUE KEY uk_key_identifier (limiter_key, identifier),
                        KEY idx_window_start (window_start),
                        KEY idx_updated_at (updated_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='频率限制记录表'
                ");
            }
        } catch (\Throwable $e) {
            error_log('[RateLimiter] 确保表存在失败: ' . $e->getMessage());
        }
    }

    /**
     * 清理过期记录（可由定时任务调用）
     *
     * @param Database $db          数据库实例
     * @param int      $expireHours 过期小时数（默认24小时）
     * @return int 删除的记录数
     */
    public static function cleanup(Database $db, int $expireHours = 24): int
    {
        try {
            $expireTime = date('Y-m-d H:i:s', time() - $expireHours * 3600);
            $deleted = $db->execute(
                "DELETE FROM " . self::$tableName . " WHERE updated_at < ?",
                [$expireTime]
            );
            return (int)$deleted;
        } catch (\Throwable $e) {
            error_log('[RateLimiter] 清理过期记录失败: ' . $e->getMessage());
            return 0;
        }
    }
}
