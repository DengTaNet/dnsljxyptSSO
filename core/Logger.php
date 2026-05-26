<?php
declare(strict_types=1);

namespace Core;

use PDOException;

/**
 * 访问日志记录类
 *
 * 记录每次访问的详细信息，包括真实 IP、浏览器信息、操作系统、
 * 请求 URL、来源页面、地理位置等。支持日志查询、筛选和分页。
 */
class Logger
{
    /** @var Database 数据库实例 */
    private Database $db;

    /** @var IpDetector IP 检测器实例 */
    private IpDetector $ipDetector;

    /** @var string 日志表名 */
    private string $logTable;

    /**
     * 构造函数
     *
     * @param Database   $db         数据库实例
     * @param IpDetector $ipDetector IP 检测器实例
     */
    public function __construct(Database $db, IpDetector $ipDetector)
    {
        $this->db = $db;
        $this->ipDetector = $ipDetector;
        $this->logTable = 'access_logs';
    }

    /**
     * 记录一次访问日志
     *
     * 自动收集客户端信息并写入数据库。包括真实 IP、浏览器、操作系统、
     * 地理位置、请求 URL、来源页面等详细信息。
     *
     * @param int|null $domainId    关联的域名 ID（可为 null）
     * @param string   $requestedUrl 请求的完整 URL
     * @return bool 记录成功返回 true
     */
    public function logAccess(?int $domainId, string $requestedUrl = ''): bool
    {
        try {
            // 获取客户端完整信息
            $clientInfo = $this->ipDetector->getClientInfo();
            $browserInfo = $this->ipDetector->getBrowserInfo();
            $osInfo = $this->ipDetector->getOsInfo();
            $ipInfo = $this->ipDetector->getIpInfo($clientInfo['ip']);

            // 检测真实IP和检测来源
            $directIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $realIp = $clientInfo['ip']; // getRealIp() 已在 getClientInfo() 中调用
            $detectionSource = 'remote_addr';

            if ($realIp !== $directIp) {
                // 真实IP与直连IP不同，判断检测来源
                $cdnIp = $this->ipDetector->detectCdnIp();
                if ($cdnIp !== null) {
                    $detectionSource = 'cdn_header';
                } elseif (($tencentConfig = \config('tencent_cloud')) !== null
                    && is_array($tencentConfig)
                    && !empty($tencentConfig['enabled'])) {
                    $tencentIp = $this->ipDetector->detectTencentCloudIp();
                    if ($tencentIp !== null) {
                        $detectionSource = 'tencent_cloud';
                    }
                }
                if ($detectionSource === 'remote_addr'
                    && ($aliyunConfig = \config('aliyun_esa')) !== null
                    && is_array($aliyunConfig)
                    && !empty($aliyunConfig['enabled'])) {
                    $aliyunIp = $this->ipDetector->detectAliyunIp();
                    if ($aliyunIp !== null) {
                        $detectionSource = 'aliyun_esa';
                    }
                }
            }

            // 如果未指定请求 URL，自动从服务器变量获取
            if (empty($requestedUrl)) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $uri = $_SERVER['REQUEST_URI'] ?? '/';
                $requestedUrl = $scheme . '://' . $host . $uri;
            }

            // 构建日志数据
            $data = [
                'domain_id'         => $domainId,
                'ip'                => $directIp,
                'real_ip'           => ($realIp !== $directIp) ? $realIp : null,
                'detection_source'  => ($realIp !== $directIp) ? $detectionSource : null,
                'mac'               => $clientInfo['mac'] ?? '',
                'browser'         => $browserInfo['name'] ?? '',
                'browser_version' => $browserInfo['version'] ?? '',
                'os'              => $osInfo['name'] ?? '',
                'os_version'      => $osInfo['version'] ?? '',
                'user_agent'      => mb_substr($clientInfo['user_agent'] ?? '', 0, 500),
                'referer'         => mb_substr($clientInfo['referer'] ?? '', 0, 500), // L-16: Referer截断为500字符
                'requested_url'   => mb_substr($requestedUrl, 0, 2000),
                'created_at'      => date('Y-m-d H:i:s'),
            ];

            // 插入日志记录
            $this->db->insert($this->logTable, $data);

            return true;
        } catch (PDOException $e) {
            // 日志记录失败不应影响主流程，仅记录错误
            $this->logError("日志记录失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 查询访问日志（支持筛选和分页）
     *
     * @param array $filters    筛选条件，支持以下键：
     *                          - ip: IP 地址
     *                          - domain_id: 域名 ID
     *                          - country: 国家
     *                          - province: 省份
     *                          - city: 城市
     *                          - browser: 浏览器名称
     *                          - os: 操作系统
     *                          - is_bot: 是否为爬虫 (0/1)
     *                          - start_date: 开始日期 (Y-m-d)
     *                          - end_date: 结束日期 (Y-m-d)
     *                          - keyword: 关键词搜索（URL、UA）
     * @param array $pagination 分页参数：
     *                          - page: 当前页码（默认1）
     *                          - per_page: 每页条数（默认20）
     *                          - sort_by: 排序字段（默认 created_at）
     *                          - sort_order: 排序方向（默认 DESC）
     * @return array 包含 logs, total, page, per_page, total_pages 的数组
     */
    public function getLogs(array $filters = [], array $pagination = []): array
    {
        // 初始化默认值（防止catch块中变量未定义）
        $page = 1;
        $perPage = 20;

        try {
            // 分页参数处理
            $page = max(1, (int) ($pagination['page'] ?? 1));
            $perPage = max(1, (int) ($pagination['per_page'] ?? (defined('DEFAULT_PAGE_SIZE') ? DEFAULT_PAGE_SIZE : 20)));
            $perPage = min($perPage, (int) (defined('MAX_PAGE_SIZE') ? MAX_PAGE_SIZE : 100));
            $sortBy = preg_replace('/[^a-zA-Z_]/', '', $pagination['sort_by'] ?? 'created_at');
            // 排序字段白名单验证
            $allowedSortFields = ['id', 'ip', 'real_ip', 'domain_id', 'browser', 'os', 'user_agent', 'requested_url', 'referer', 'created_at', 'detection_source', 'response_code'];
            $sortBy = in_array($sortBy, $allowedSortFields, true) ? $sortBy : 'created_at';
            $sortOrder = strtoupper($pagination['sort_order'] ?? 'DESC');
            $sortOrder = in_array($sortOrder, ['ASC', 'DESC'], true) ? $sortOrder : 'DESC';

            // 构建 WHERE 条件
            $where = [];
            $params = [];

            // IP 筛选
            if (!empty($filters['ip'])) {
                $where[] = "ip = :ip";
                $params[':ip'] = $filters['ip'];
            }

            // 域名 ID 筛选
            if (isset($filters['domain_id']) && $filters['domain_id'] !== '') {
                $where[] = "domain_id = :domain_id";
                $params[':domain_id'] = (int) $filters['domain_id'];
            }

            // 国家筛选（access_logs 表无此字段，已移除）

            // 省份筛选（access_logs 表无此字段，已移除）

            // 城市筛选（access_logs 表无此字段，已移除）

            // 浏览器筛选
            if (!empty($filters['browser'])) {
                $filters['browser'] = addcslashes($filters['browser'], '\\%_');
                $where[] = "browser LIKE :browser";
                $params[':browser'] = '%' . $filters['browser'] . '%';
            }

            // 操作系统筛选
            if (!empty($filters['os'])) {
                $filters['os'] = addcslashes($filters['os'], '\\%_');
                $where[] = "os LIKE :os";
                $params[':os'] = '%' . $filters['os'] . '%';
            }

            // 是否为爬虫（access_logs 表无此字段，已移除）

            // 日期范围筛选
            if (!empty($filters['start_date'])) {
                $where[] = "created_at >= :start_date";
                $params[':start_date'] = $filters['start_date'] . ' 00:00:00';
            }

            if (!empty($filters['end_date'])) {
                $where[] = "created_at <= :end_date";
                $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
            }

            // 关键词搜索（URL 或 User-Agent）
            if (!empty($filters['keyword'])) {
                $filters['keyword'] = addcslashes($filters['keyword'], '\\%_');
                $where[] = "(requested_url LIKE :keyword OR user_agent LIKE :keyword)";
                $params[':keyword'] = '%' . $filters['keyword'] . '%';
            }

            // 组装 SQL
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // 查询总数
            $totalSql = "SELECT COUNT(*) as total FROM {$this->logTable} {$whereClause}";
            $totalResult = $this->db->queryOne($totalSql, $params);
            $total = (int) ($totalResult['total'] ?? 0);

            // 查询日志列表
            $offset = ($page - 1) * $perPage;
            $logSql = "SELECT * FROM {$this->logTable} {$whereClause} ORDER BY {$sortBy} {$sortOrder} LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

            $logs = $this->db->query($logSql, $params);

            return [
                'logs'        => $logs,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => ($perPage > 0) ? (int) ceil($total / $perPage) : 0,
            ];
        } catch (PDOException $e) {
            $this->logError("日志查询失败: " . $e->getMessage());
            return [
                'logs'        => [],
                'total'       => 0,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => 0,
            ];
        }
    }

    /**
     * 获取最近的访问日志
     *
     * @param int $limit 返回条数（默认20，最大100）
     * @return array 日志记录数组
     */
    public function getRecentLogs(int $limit = 20): array
    {
        $limit = min(max(1, $limit), 100);

        try {
            return $this->db->query(
                "SELECT * FROM {$this->logTable} ORDER BY created_at DESC LIMIT ?",
                [$limit]
            );
        } catch (PDOException $e) {
            $this->logError("获取最近日志失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取指定 IP 的访问统计
     *
     * @param string $ip IP 地址
     * @param int    $days 统计天数（默认30天）
     * @return array 包含 total, today, unique_urls 等统计信息
     */
    public function getIpStatistics(string $ip, int $days = 30): array
    {
        try {
            $stats = [
                'ip'          => $ip,
                'total'       => 0,
                'today'       => 0,
                'week'        => 0,
                'month'       => 0,
                'unique_urls' => 0,
                'first_seen'  => null,
                'last_seen'   => null,
            ];

            // 总访问次数
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total, MIN(created_at) as first_seen, MAX(created_at) as last_seen FROM {$this->logTable} WHERE ip = :ip",
                [':ip' => $ip]
            );
            $stats['total'] = (int) ($result['total'] ?? 0);
            $stats['first_seen'] = $result['first_seen'] ?? null;
            $stats['last_seen'] = $result['last_seen'] ?? null;

            // 今日访问
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total FROM {$this->logTable} WHERE ip = :ip AND DATE(created_at) = CURDATE()",
                [':ip' => $ip]
            );
            $stats['today'] = (int) ($result['total'] ?? 0);

            // 本周访问
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total FROM {$this->logTable} WHERE ip = :ip AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)",
                [':ip' => $ip]
            );
            $stats['week'] = (int) ($result['total'] ?? 0);

            // 本月访问
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total FROM {$this->logTable} WHERE ip = :ip AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
                [':ip' => $ip]
            );
            $stats['month'] = (int) ($result['total'] ?? 0);

            // 独立 URL 数
            $result = $this->db->queryOne(
                "SELECT COUNT(DISTINCT requested_url) as total FROM {$this->logTable} WHERE ip = :ip",
                [':ip' => $ip]
            );
            $stats['unique_urls'] = (int) ($result['total'] ?? 0);

            return $stats;
        } catch (PDOException $e) {
            $this->logError("IP 统计查询失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取访问趋势数据（按天统计）
     *
     * @param int $days 统计天数（默认30天）
     * @return array 按天分组的访问数据
     */
    public function getAccessTrend(int $days = 30): array
    {
        $days = min(max(1, $days), 365);

        try {
            return $this->db->query(
                "SELECT
                    DATE(created_at) as date,
                    COUNT(*) as total_visits,
                    COUNT(DISTINCT ip) as unique_ips,
                    COUNT(DISTINCT requested_url) as unique_urls
                FROM {$this->logTable}
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC",
                [$days]
            );
        } catch (PDOException $e) {
            $this->logError("访问趋势查询失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取热门访问 URL 排行
     *
     * @param int $limit 返回条数（默认20）
     * @return array 按访问次数排序的 URL 列表
     */
    public function getTopUrls(int $limit = 20): array
    {
        $limit = min(max(1, $limit), 100);

        try {
            return $this->db->query(
                "SELECT
                    requested_url,
                    COUNT(*) as visit_count,
                    COUNT(DISTINCT ip) as unique_ips
                FROM {$this->logTable}
                GROUP BY requested_url
                ORDER BY visit_count DESC
                LIMIT ?",
                [$limit]
            );
        } catch (PDOException $e) {
            $this->logError("热门 URL 查询失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 清理过期日志
     *
     * 删除指定天数之前的日志记录，释放数据库空间。
     *
     * @param int $days 保留天数（默认90天）
     * @return int 删除的记录数
     */
    public function cleanOldLogs(int $days = 90): int
    {
        try {
            return $this->db->execute(
                "DELETE FROM {$this->logTable} WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );
        } catch (PDOException $e) {
            $this->logError("日志清理失败: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 记录内部错误日志
     *
     * @param string $message 错误消息
     */
    private function logError(string $message): void
    {
        $logFile = defined('APP_ROOT') ? APP_ROOT . '/logs/logger_error.log' : __DIR__ . '/../logs/logger_error.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        @file_put_contents(
            $logFile,
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
            FILE_APPEND
        );
    }

    /**
     * M-16: 日志完整性保护 - 生成日志记录的HMAC签名
     *
     * 使用服务器密钥对日志内容进行HMAC-SHA256签名，用于检测日志是否被篡改。
     * 密钥自动生成并存储在临时目录中，同一服务器实例共享同一密钥。
     *
     * @param string $logContent 日志内容
     * @return string HMAC-SHA256 签名（十六进制）
     */
    public static function generateLogHmac(string $logContent): string
    {
        $keyFile = sys_get_temp_dir() . '/lighthouse_log_hmac_key';
        $key = '';
        if (file_exists($keyFile)) {
            $key = @file_get_contents($keyFile);
        }
        if (empty($key)) {
            // 自动生成32字节密钥
            $key = bin2hex(random_bytes(32));
            @file_put_contents($keyFile, $key, LOCK_EX);
            @chmod($keyFile, 0600);
        }
        return hash_hmac('sha256', $logContent, $key);
    }

    /**
     * M-16: 验证日志记录的HMAC签名
     *
     * @param string $logContent 日志内容
     * @param string $hmac       待验证的HMAC签名
     * @return bool 签名有效返回true
     */
    public static function verifyLogHmac(string $logContent, string $hmac): bool
    {
        $expected = self::generateLogHmac($logContent);
        return hash_equals($expected, $hmac);
    }
}
