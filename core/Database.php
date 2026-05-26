<?php
/**
 * 灯塔DNS拦截响应平台 - PDO 数据库连接类
 * 
 * 单例模式，提供数据库连接和常用查询方法。
 */

namespace Core;

use PDO;
use PDOException;

class Database
{
    /** @var Database|null 单例实例 */
    private static ?Database $instance = null;

    /** @var PDO 数据库连接 */
    private PDO $pdo;

    /** @var array 配置 */
    private array $config;

    /**
     * 私有构造函数，防止外部实例化
     */
    private function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * 获取单例实例
     */
    public static function getInstance(?array $config = null): self
    {
        if (self::$instance === null) {
            if ($config === null) {
                $basePath = defined('BASEPATH') ? BASEPATH : dirname(__DIR__, 2);
                $config = require $basePath . '/config/config.php';
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * 建立数据库连接
     *
     * @throws PDOException
     */
    private function connect(): void
    {
        $db = $this->config['database'] ?? null;
        if (!is_array($db)) {
            throw new \InvalidArgumentException('数据库配置缺失或格式不正确');
        }

        // SEC-H03: 检查数据库凭据是否已配置，防止使用空凭据连接
        if (empty($db['user']) || empty($db['password'])) {
            throw new \RuntimeException(
                '数据库用户名或密码未配置。请通过环境变量（DB_USER、DB_PASSWORD）或安装向导设置数据库凭据。'
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['dbname'],
            $db['charset']
        );

        // 将配置中的 options 转换为 PDO 常量关联数组
        // 配置文件使用整数值避免依赖 PDO 常量（防止 PDO 扩展未加载时解析失败）
        $pdoOptions = [];
        if (isset($db['options']) && is_array($db['options'])) {
            // 如果是关联数组（键为 PDO 常量值），直接使用
            if ($this->isAssoc($db['options'])) {
                $pdoOptions = $db['options'];
            } else {
                // 索引数组：按顺序映射到 PDO 属性
                $pdoOptions = [
                    PDO::ATTR_ERRMODE            => $db['options'][0] ?? PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => $db['options'][1] ?? PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => $db['options'][2] ?? false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => $db['options'][3] ?? "SET NAMES utf8mb4",
                ];
            }
        }

        // SSL/TLS 加密连接（M-10）
        $sslConfig = $db['ssl'] ?? [];
        if (!empty($sslConfig['enabled'])) {
            $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $sslConfig['ca_file'] ?? '';
            if (!empty($sslConfig['verify_server_cert']) && !empty($sslConfig['ca_file'])) {
                $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            } elseif (isset($sslConfig['verify_server_cert']) && !$sslConfig['verify_server_cert']) {
                $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

        try {
            $startConnect = microtime(true);
            $this->pdo = new PDO($dsn, $db['user'], $db['password'], $pdoOptions);
            $connectTime = microtime(true) - $startConnect;

            // 调试日志：记录数据库连接
            if (class_exists(\Core\Debug::class) && \Core\Debug::isEnabled()) {
                \Core\Debug::logConnect($db['host'] . ':' . $db['port'], $db['dbname']);
                \Core\Debug::log('sql', '数据库连接成功', ['time' => round($connectTime * 1000, 2) . 'ms']);
            }
        } catch (PDOException $e) {
            // 调试日志：记录连接失败
            if (class_exists(\Core\Debug::class) && \Core\Debug::isEnabled()) {
                \Core\Debug::logError('数据库连接失败: ' . $e->getMessage(), ['host' => $db['host'], 'dbname' => $db['dbname']]);
            }
            error_log('数据库连接失败: ' . $e->getMessage());
            throw new PDOException('数据库连接失败，请检查配置。');
        }
    }

    /**
     * 检查数组是否为关联数组
     */
    private function isAssoc(array $arr): bool
    {
        if (empty($arr)) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * 获取 PDO 连接
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * 执行查询并返回所有结果
     */
    public function query(string $sql, array $params = []): array
    {
        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $result = $stmt->fetchAll();
        $this->debugLog($sql, $params, $start, 'query', count($result));
        return $result;
    }

    /**
     * 执行查询并返回单行结果
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $result = $stmt->fetch();
        $this->debugLog($sql, $params, $start, 'queryOne', $result !== false ? 1 : 0);
        return $result !== false ? $result : null;
    }

    /**
     * 执行 INSERT/UPDATE/DELETE 并返回受影响行数
     */
    public function execute(string $sql, array $params = []): int
    {
        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $rows = $stmt->rowCount();
        $this->debugLog($sql, $params, $start, 'execute', $rows);
        return $rows;
    }

    /**
     * 绑定参数（自动检测整数类型）
     *
     * 对命名参数中值为整数的自动绑定 PDO::PARAM_INT，
     * 解决 LIMIT/OFFSET 等子句中参数被当作字符串的问题。
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            // 位置参数（?占位符）从0开始，但PDO要求从1开始
            if (is_int($key)) {
                $key = $key + 1;
            }
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
            } elseif (is_null($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }
    }

    /**
     * 插入数据并返回最后插入的ID
     */
    public function insert(string $table, array $data): int
    {
        $this->validateIdentifier($table);
        foreach (array_keys($data) as $col) {
            $this->validateIdentifier($col);
        }
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        $id = (int) $this->pdo->lastInsertId();
        $this->debugLog($sql, array_values($data), $start, 'insert', 1, ['table' => $table, 'lastInsertId' => $id]);
        return $id;
    }

    /**
     * 更新数据
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $this->validateIdentifier($table);
        foreach (array_keys($data) as $col) {
            $this->validateIdentifier($col);
        }
        // 防止$where参数中的SQL注入
        // 仅允许包含参数占位符（?或:named）和合法SQL运算符的WHERE条件
        if (preg_match('/[;\'"\\\\()*]/', $where)) {
            throw new \InvalidArgumentException('不安全的WHERE条件：包含非法字符');
        }

        // 将WHERE条件中的命名参数(:name)转换为位置参数(?)
        $normalizedWhere = $where;
        $normalizedWhereParams = [];
        if (!empty($whereParams)) {
            // 检查是否使用命名参数
            $firstKey = array_key_first($whereParams);
            if (str_starts_with((string)$firstKey, ':')) {
                // 命名参数 → 转为位置参数
                $normalizedWhereParams = array_values($whereParams);
                $normalizedWhere = preg_replace_callback('/:[a-zA-Z_]\w*/', function () use (&$normalizedWhereParams) {
                    // 已经通过array_values转换，直接替换为?
                    static $idx = 0;
                    $idx++;
                    return '?';
                }, $where);
            } else {
                $normalizedWhereParams = array_values($whereParams);
            }
        }

        $set = implode(', ', array_map(fn($col) => "`{$col}` = ?", array_keys($data)));
        $sql = "UPDATE `{$table}` SET {$set} WHERE {$normalizedWhere}";

        $params = array_merge(array_values($data), $normalizedWhereParams);
        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->rowCount();
        $this->debugLog($sql, $params, $start, 'update', $rows, ['table' => $table]);
        return $rows;
    }

    /**
     * 删除数据
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $this->validateIdentifier($table);
        // 防止$where参数中的SQL注入
        if (preg_match('/[;\'"\\\\()*]/', $where)) {
            throw new \InvalidArgumentException('不安全的WHERE条件：包含非法字符');
        }

        // 将命名参数(:name)转换为位置参数(?)
        $normalizedWhere = $where;
        $normalizedParams = [];
        if (!empty($params)) {
            $firstKey = array_key_first($params);
            if (str_starts_with((string)$firstKey, ':')) {
                $normalizedParams = array_values($params);
                $normalizedWhere = preg_replace_callback('/:[a-zA-Z_]\w*/', function () {
                    return '?';
                }, $where);
            } else {
                $normalizedParams = array_values($params);
            }
        }

        $sql = "DELETE FROM `{$table}` WHERE {$normalizedWhere}";
        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($normalizedParams);
        $rows = $stmt->rowCount();
        $this->debugLog($sql, $normalizedParams, $start, 'delete', $rows, ['table' => $table]);
        return $rows;
    }

    /**
     * 开始事务
     */
    public function beginTransaction(): bool
    {
        $result = $this->pdo->beginTransaction();
        if (class_exists(\Core\Debug::class) && \Core\Debug::isEnabled()) {
            \Core\Debug::logTransaction('begin');
        }
        return $result;
    }

    /**
     * 提交事务
     */
    public function commit(): bool
    {
        $result = $this->pdo->commit();
        if (class_exists(\Core\Debug::class) && \Core\Debug::isEnabled()) {
            \Core\Debug::logTransaction('commit');
        }
        return $result;
    }

    /**
     * 回滚事务
     */
    public function rollback(): bool
    {
        $result = $this->pdo->rollBack();
        if (class_exists(\Core\Debug::class) && \Core\Debug::isEnabled()) {
            \Core\Debug::logTransaction('rollback');
        }
        return $result;
    }

    /**
     * 获取最后插入的ID
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * 防止克隆
     */
    private function __clone() {}

    /**
     * SEC-005/006/007: 验证标识符（表名、列名）防止SQL注入
     *
     * 标识符仅允许字母、数字和下划线，且必须以字母或下划线开头。
     *
     * @param string $identifier 待验证的标识符
     * @return bool 有效返回 true
     * @throws \InvalidArgumentException 标识符无效时抛出异常
     */
    public function validateIdentifier(string $identifier): bool
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $identifier)) {
            throw new \InvalidArgumentException("无效的数据库标识符");
        }
        return true;
    }

    /**
     * 记录调试日志（内部方法）
     */
    private function debugLog(string $sql, array $params, float $start, string $method, int $rows, array $extra = []): void
    {
        if (!class_exists(\Core\Debug::class) || !\Core\Debug::isEnabled()) {
            return;
        }
        $time = microtime(true) - $start;
        $context = array_merge($extra, [
            'rows' => $rows,
        ]);
        \Core\Debug::logQuery($sql, $params, $time, $method);
    }

    /**
     * 防止反序列化
     */
    public function __wakeup()
    {
        throw new \Exception('不允许反序列化单例');
    }
}
