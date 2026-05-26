<?php
/**
 * 灯塔DNS拦截响应平台 - 安装向导
 * 自包含单文件安装程序，不依赖项目其他PHP文件
 */

// ============================================================
// 安装锁检查
// ============================================================
define('BASEPATH', dirname(__DIR__));
$lockFile = BASEPATH . '/storage/install.lock';

// SEC-015: 安装向导CSRF保护 - 启动session并生成token
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['install_csrf_token'])) {
    $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));
}
$installCsrfToken = $_SESSION['install_csrf_token'];

if (file_exists($lockFile)) {
    $lockInfo = json_decode(file_get_contents($lockFile), true) ?: [];
    $installTime = $lockInfo['install_time'] ?? '未知';
    $phpVersion  = $lockInfo['php_version'] ?? '未知';
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>系统已安装 - 灯塔DNS拦截响应平台</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">';
    echo '<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:"Inter",sans-serif;background:linear-gradient(135deg,#0a0f1a,#050810);min-height:100vh;display:flex;align-items:center;justify-content:center;color:#e2e8f0}.container{text-align:center;max-width:500px;padding:40px;background:rgba(15,23,42,0.8);border:1px solid rgba(0,212,170,0.2);border-radius:16px;backdrop-filter:blur(20px);box-shadow:0 0 40px rgba(0,212,170,0.1)}h1{font-size:1.5rem;margin-bottom:16px;color:#00d4aa}.info{margin:20px 0;padding:16px;background:rgba(0,212,170,0.1);border-radius:8px;font-size:0.9rem;line-height:1.8;color:#94a3b8}.info span{color:#e2e8f0}a{display:inline-block;margin-top:20px;padding:12px 32px;background:linear-gradient(135deg,#00d4aa,#00a8e8);color:#0a0f1a;font-weight:600;border-radius:8px;text-decoration:none;transition:all .3s}a:hover{box-shadow:0 0 20px rgba(0,212,170,0.4);transform:translateY(-2px)}</style></head>';
    echo '<body><div class="container"><h1>系统已安装</h1><p style="color:#94a3b8;margin-bottom:20px">灯塔DNS拦截响应平台已经成功安装，请勿重复执行安装程序。</p>';
    echo '<div class="info">安装时间：<span>' . htmlspecialchars($installTime) . '</span><br>PHP 版本：<span>' . htmlspecialchars($phpVersion) . '</span></div>';
    echo '<a href="/admin/">进入后台管理</a></div></body></html>';
    exit;
}

// ============================================================
// AJAX 请求处理
// ============================================================
$action = $_POST['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // SEC-015: CSRF令牌验证
    $submittedToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($submittedToken) || !hash_equals($installCsrfToken, $submittedToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败，请刷新页面重试']);
        exit;
    }

    switch ($action) {
        // 测试数据库连接
        case 'test_db':
            $host   = $_POST['db_host'] ?? '127.0.0.1';
            // SEC: 数据库主机白名单验证，防止DSN注入
            if (!preg_match('/^[a-zA-Z0-9._\-]{1,253}$/', $host)) {
                echo json_encode(['success' => false, 'message' => '数据库主机地址格式无效']);
                exit;
            }
            $port   = intval($_POST['db_port'] ?? 3306);
            $dbname = $_POST['db_name'] ?? '';
            $user   = $_POST['db_user'] ?? '';
            $pass   = $_POST['db_pass'] ?? '';

            // SEC-001/SEC-002: 数据库名白名单验证，防止SQL注入
            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbname)) {
                echo json_encode(['success' => false, 'message' => '数据库名仅允许字母、数字和下划线']);
                exit;
            }

            try {
                // 尝试直接连接到指定数据库
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                echo json_encode(['success' => true, 'message' => '数据库连接成功，可以自动创建表结构。']);
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                $errorCode = $e->getCode();
                // 根据错误类型给出具体提示
                if (strpos($errorMsg, 'Unknown database') !== false
                    || strpos($errorMsg, '1049') !== false
                    || (int)$errorCode === 1049) {
                    echo json_encode(['success' => false, 'message' => '数据库 "' . htmlspecialchars($dbname) . '" 不存在，请先创建数据库后再安装。']);
                } elseif (strpos($errorMsg, 'Access denied') !== false
                    || strpos($errorMsg, '1044') !== false
                    || strpos($errorMsg, '1045') !== false
                    || (int)$errorCode === 1044
                    || (int)$errorCode === 1045) {
                    echo json_encode(['success' => false, 'message' => '数据库用户 "' . htmlspecialchars($user) . '" 认证失败或权限不足，请检查用户名和密码。']);
                } elseif (strpos($errorMsg, 'Connection refused') !== false
                    || strpos($errorMsg, '2002') !== false
                    || (int)$errorCode === 2002) {
                    echo json_encode(['success' => false, 'message' => '无法连接到 MySQL 服务器（' . htmlspecialchars($host) . ':' . $port . '），请确认 MySQL 服务已启动。']);
                } else {
                    echo json_encode(['success' => false, 'message' => '数据库连接失败，请检查数据库配置信息。']);
                }
            }
            exit;

        // 执行安装
        case 'install':
            $steps = [];
            $dbHost     = $_POST['db_host'] ?? '127.0.0.1';
            // SEC: 数据库主机白名单验证，防止DSN注入
            if (!preg_match('/^[a-zA-Z0-9._\-]{1,253}$/', $dbHost)) {
                echo json_encode(['success' => false, 'message' => '数据库主机地址格式无效']);
                exit;
            }
            $dbPort     = intval($_POST['db_port'] ?? 3306);
            $dbName     = $_POST['db_name'] ?? 'dnslj';
            $dbUser     = $_POST['db_user'] ?? 'root';
            $dbPass     = $_POST['db_pass'] ?? '';
            $dbPrefix   = $_POST['db_prefix'] ?? '';

            // 表前缀白名单验证
            if (!empty($dbPrefix) && !preg_match('/^[a-zA-Z0-9_]{1,10}$/', $dbPrefix)) {
                $steps[] = ['name' => '参数验证', 'status' => 'fail', 'msg' => '表前缀仅允许字母、数字和下划线，最长10个字符'];
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            $logtoEndpoint      = $_POST['logto_endpoint'] ?? '';
            $logtoAppId         = $_POST['logto_app_id'] ?? '';
            $logtoAppSecret     = $_POST['logto_app_secret'] ?? '';
            $logtoRedirectUri   = $_POST['logto_redirect_uri'] ?? '/admin/callback.php';
            $logtoPostLogoutUri = $_POST['logto_post_logout_uri'] ?? '/admin/login.php';
            $logtoAutoCreate    = ($_POST['logto_auto_create_user'] ?? '1') === '1';
            $logtoDefaultRole   = $_POST['logto_default_role'] ?? 'operator';

            // SSO 配置验证
            if (empty($logtoEndpoint) || !filter_var($logtoEndpoint, FILTER_VALIDATE_URL)) {
                $steps[] = ['name' => '参数验证', 'status' => 'fail', 'msg' => 'Logto 服务端点必须为有效的 URL 地址'];
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            if (empty($logtoAppId)) {
                $steps[] = ['name' => '参数验证', 'status' => 'fail', 'msg' => 'Logto 应用 ID 不能为空'];
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            if (empty($logtoAppSecret)) {
                $steps[] = ['name' => '参数验证', 'status' => 'fail', 'msg' => 'Logto 应用密钥不能为空'];
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            $siteName      = $_POST['site_name'] ?? '灯塔DNS拦截响应平台';
            $siteDesc      = $_POST['site_desc'] ?? '';
            $siteEmail     = $_POST['site_email'] ?? '';
            $logoPath      = '/assets/images/logo.png';

            // 接收后台域名白名单
            $adminDomains  = [];
            if (isset($_POST['admin_domains']) && is_array($_POST['admin_domains'])) {
                foreach ($_POST['admin_domains'] as $d) {
                    $d = trim($d);
                    if ($d !== '') $adminDomains[] = $d;
                }
            }

            // SEC-001/SEC-002: 数据库名白名单验证，防止SQL注入
            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
                echo json_encode(['success' => false, 'message' => '数据库名仅允许字母、数字和下划线']);
                exit;
            }

            $steps = [];

            // Step 1: 创建 storage 目录
            try {
                $dirs = [
                    dirname(__DIR__) . '/storage',
                    dirname(__DIR__) . '/storage/logs',
                    __DIR__ . '/uploads',
                    __DIR__ . '/uploads/chat',
                    __DIR__ . '/uploads/evidence',
                    __DIR__ . '/assets/images',
                ];
                foreach ($dirs as $dir) {
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                }
                $steps[] = ['name' => '创建目录结构', 'status' => 'ok'];
            } catch (Exception $e) {
                $steps[] = ['name' => '创建目录结构', 'status' => 'fail', 'msg' => '目录创建失败，请检查权限配置。'];
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            // Step 2: 连接数据库（直接使用指定数据库）
            try {
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                $steps[] = ['name' => '连接数据库', 'status' => 'ok'];
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Unknown database') !== false || strpos($e->getMessage(), '1049') !== false) {
                    $steps[] = ['name' => '连接数据库', 'status' => 'fail', 'msg' => '数据库不存在，请让管理员先创建数据库。'];
                } else {
                    $steps[] = ['name' => '连接数据库', 'status' => 'fail', 'msg' => '数据库连接失败，请检查配置信息。'];
                }
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            // Step 4: 导入 SQL
            try {
                $sqlFile = dirname(__DIR__) . '/sql/init.sql';
                if (!file_exists($sqlFile)) {
                    throw new Exception('SQL 文件不存在：sql/init.sql');
                }
                $sqlContent = file_get_contents($sqlFile);

                // 替换默认管理员为 SSO 占位管理员（必须在表前缀替换之前执行）
                $ssoPlaceholderPass = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);

                // SEC-003: 使用PDO参数化查询插入SSO占位管理员，避免SQL注入
                $pattern = "/INSERT\s+INTO\s+`?admin_users`?\s*\([^)]+\)\s+VALUES\s*\([^)]+\)/is";
                $replacement = "INSERT INTO `admin_users` (`username`, `email`, `password_hash`, `role`, `status`, `sso_id`, `sso_provider`) VALUES (:admin_user, :admin_email, :password_hash, 'super_admin', 1, :sso_id, :sso_provider)";
                $sqlContent = preg_replace($pattern, $replacement, $sqlContent);

                // 替换表前缀（在INSERT替换之后执行，避免破坏参数化占位符）
                if (!empty($dbPrefix)) {
                    $sqlContent = preg_replace('/`(\w+)`/i', '`' . $dbPrefix . '$1`', $sqlContent);
                    // 修正 SET 和 USE 语句中的反引号
                    $sqlContent = preg_replace('/`SET /', 'SET ', $sqlContent);
                    $sqlContent = preg_replace('/`FOREIGN_KEY_CHECKS`/i', 'FOREIGN_KEY_CHECKS', $sqlContent);
                    $sqlContent = preg_replace('/`NAMES`/i', 'NAMES', $sqlContent);
                    $sqlContent = preg_replace('/`utf8mb4`/i', 'utf8mb4', $sqlContent);
                }

                // 分割并执行 SQL
                $pdo->exec("SET NAMES utf8mb4");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                // 按分号分割SQL语句
                $statements = array_filter(array_map('trim', explode(';', $sqlContent)), function($s) {
                    // 过滤空语句
                    if ($s === '') return false;
                    // 过滤纯注释语句（去掉前导空白和注释行后检查是否为空）
                    $cleaned = preg_replace('/^\s*--.*$/m', '', $s);
                    $cleaned = preg_replace('/^\s*\/\*.*?\*\//s', '', $cleaned);
                    return trim($cleaned) !== '';
                });

                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt === '') continue;
                    // 跳过 SET 语句（已手动执行）
                    if (preg_match('/^\s*SET\s+/i', $stmt)) continue;
                    // 跳过纯注释语句
                    $cleaned = preg_replace('/^\s*--.*$/m', '', $stmt);
                    $cleaned = preg_replace('/^\s*\/\*.*?\*\//s', '', $cleaned);
                    if (trim($cleaned) === '') continue;
                    try {
                        // SEC-003: 对管理员INSERT语句使用参数化查询
                        if (strpos($stmt, ':admin_user') !== false) {
                            $pdoStmt = $pdo->prepare($stmt);
                            $pdoStmt->execute([
                                ':admin_user'     => 'sso_admin',
                                ':admin_email'    => '',
                                ':password_hash'  => $ssoPlaceholderPass,
                                ':sso_id'         => 'install_placeholder',
                                ':sso_provider'   => 'logto',
                            ]);
                        } else {
                            // CREATE TABLE 前先确保表不存在
                            if (preg_match('/^\s*CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $stmt, $m)) {
                                $tableName = $m[2];
                                try { $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`"); } catch (PDOException $e) {}
                            }
                            $pdo->exec($stmt);
                        }
                    } catch (PDOException $e) {
                        // 跳过 "already exists" 错误（表已存在则跳过）
                        if (strpos($e->getMessage(), 'already exists') !== false) continue;
                        // 跳过 "doesn't exist" 错误（DROP TABLE 的表不存在）
                        if (strpos($e->getMessage(), "doesn't exist") !== false) continue;
                        throw $e;
                    }
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $steps[] = ['name' => '导入数据库结构', 'status' => 'ok'];
            } catch (Exception $e) {
                $steps[] = ['name' => '导入数据库结构', 'status' => 'fail', 'msg' => '数据库结构导入失败，请检查SQL文件。'];
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            // Step 5: 处理文件上传（Logo & Favicon）
            try {
                $imagesDir = __DIR__ . '/assets/images/';

                // Logo 上传
                if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $logoFile = $_FILES['logo_file'];
                    $ext = strtolower(pathinfo($logoFile['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['svg', 'png', 'jpg', 'jpeg'])) {
                        // SEC-019: MIME类型验证
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($logoFile['tmp_name']);
                        $allowedMimes = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
                        if (isset($allowedMimes[$ext]) && $mime !== $allowedMimes[$ext]) {
                            $steps[] = ['name' => '保存上传文件', 'status' => 'fail', 'msg' => 'Logo文件MIME类型不匹配'];
                            echo json_encode(['success' => false, 'steps' => $steps]);
                            exit;
                        }
                        // SEC-020: SVG上传XSS防护
                        if ($ext === 'svg') {
                            $svgContent = file_get_contents($logoFile['tmp_name']);
                            $svgContent = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $svgContent);
                            $svgContent = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $svgContent);
                            $svgContent = preg_replace('/<iframe[^>]*>.*?<\/iframe>/si', '', $svgContent);
                            $svgContent = preg_replace('/<embed[^>]*>/i', '', $svgContent);
                            $svgContent = preg_replace('/<object[^>]*>.*?<\/object>/si', '', $svgContent);
                            file_put_contents($logoFile['tmp_name'], $svgContent);
                        }
                        $targetName = 'logo.' . $ext;
                        move_uploaded_file($logoFile['tmp_name'], $imagesDir . $targetName);
                        $logoPath = '/assets/images/' . $targetName;
                    }
                }

                // Favicon 上传
                if (isset($_FILES['favicon_file']) && $_FILES['favicon_file']['error'] === UPLOAD_ERR_OK) {
                    $favFile = $_FILES['favicon_file'];
                    $ext = strtolower(pathinfo($favFile['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['ico', 'png'])) {
                        // SEC-019: MIME类型验证
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($favFile['tmp_name']);
                        $allowedMimes = ['ico' => 'image/x-icon', 'image/vnd.microsoft.icon', 'png' => 'image/png'];
                        $expectedMimes = ($ext === 'ico') ? ['image/x-icon', 'image/vnd.microsoft.icon'] : ['image/png'];
                        if (!in_array($mime, $expectedMimes)) {
                            $steps[] = ['name' => '保存上传文件', 'status' => 'fail', 'msg' => 'Favicon文件MIME类型不匹配'];
                            echo json_encode(['success' => false, 'steps' => $steps]);
                            exit;
                        }
                        $targetName = 'favicon.' . $ext;
                        move_uploaded_file($favFile['tmp_name'], $imagesDir . $targetName);
                    }
                }

                $steps[] = ['name' => '保存上传文件', 'status' => 'ok'];
            } catch (Exception $e) {
                $steps[] = ['name' => '保存上传文件', 'status' => 'fail', 'msg' => '文件保存失败，请检查上传目录权限。'];
                // 非致命错误，继续
            }

            // Step 6: 生成配置文件
            try {
                $logtoConfig = [
                    'endpoint'        => $logtoEndpoint,
                    'app_id'          => $logtoAppId,
                    'app_secret'      => $logtoAppSecret,
                    'redirect_uri'    => $logtoRedirectUri,
                    'post_logout_uri' => $logtoPostLogoutUri,
                    'auto_create'     => $logtoAutoCreate,
                    'default_role'    => $logtoDefaultRole,
                ];
                $configContent = generateConfig($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $dbPrefix, $siteName, $siteDesc, $siteEmail, $logoPath, $adminDomains, $logtoConfig);
                $configDir = dirname(__DIR__) . '/config';
                if (!is_dir($configDir)) {
                    mkdir($configDir, 0755, true);
                }
                $configFile = $configDir . '/config.php';
                file_put_contents($configFile, $configContent);
                chmod($configFile, 0640);
                $steps[] = ['name' => '生成配置文件', 'status' => 'ok'];
            } catch (Exception $e) {
                $steps[] = ['name' => '生成配置文件', 'status' => 'fail', 'msg' => '配置文件生成失败，请检查目录权限。'];
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            // Step 7: 创建安装锁
            try {
                // SEC-031: install.lock不存储敏感信息（移除db_host、db_name）
                $lockData = json_encode([
                    'install_time' => date('Y-m-d H:i:s'),
                    'php_version'  => PHP_VERSION,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents($lockFile, $lockData);
                $steps[] = ['name' => '创建安装锁', 'status' => 'ok'];

                // 安全措施：尝试将安装文件重命名，防止二次安装
                $selfFile = __FILE__;
                $disabledFile = dirname(__FILE__) . '/install.php.disabled';
                if (!@rename($selfFile, $disabledFile)) {
                    $steps[] = ['name' => '安全提示', 'status' => 'warn', 'msg' => '安装脚本无法自动禁用，请手动删除 public/install.php 文件。'];
                }
            } catch (Exception $e) {
                $steps[] = ['name' => '创建安装锁', 'status' => 'fail', 'msg' => '安装锁创建失败，请检查目录权限。'];
                echo json_encode(['success' => false, 'steps' => $steps]);
                exit;
            }

            echo json_encode(['success' => true, 'steps' => $steps]);
            exit;
    }
    exit;
}

// ============================================================
// 生成配置文件内容
// ============================================================
function generateConfig($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $dbPrefix, $siteName, $siteDesc, $siteEmail, $logoPath, $adminDomains = [], $logtoConfig = []) {
    $dbPort = (int)$dbPort;

    // 验证域名格式
    $validDomains = [];
    if (is_array($adminDomains)) {
        foreach ($adminDomains as $domain) {
            $domain = trim($domain);
            if ($domain && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$/', $domain)) {
                $validDomains[] = strtolower($domain);
            }
        }
    }

    $config = [
        'database' => [
            'host'      => $dbHost,
            'port'      => $dbPort,
            'dbname'    => $dbName,
            'user'      => $dbUser,
            'password'  => $dbPass,
            'prefix'    => $dbPrefix,
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options'   => [
                2     => 2,     // PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                19    => 2,     // PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                20    => false, // PDO::ATTR_EMULATE_PREPARES => false
                1002  => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci", // PDO::MYSQL_ATTR_INIT_COMMAND
            ],
        ],
        'site' => [
            'name'                  => $siteName,
            'description'           => $siteDesc,
            'logo_path'             => $logoPath,
            'contact_email'         => $siteEmail,
            'admin_url'             => '/admin/',
            'timezone'              => 'Asia/Shanghai',
            'admin_allowed_domains' => $validDomains,
        ],
        'tencent_cloud' => [
            'enabled'    => false,
            'secret_id'  => '',
            'secret_key' => '',
            'endpoint'   => 'cdn.tencentcloudapi.com',
            'region'     => '',
        ],
        'aliyun_esa' => [
            'enabled'          => false,
            'access_key_id'     => '',
            'access_key_secret' => '',
            'endpoint'          => 'esa.cn-hangzhou.aliyuncs.com',
        ],
        'ip_detection' => [
            'trusted_proxies' => [
                '173.245.48.0/20',
                '103.21.244.0/22',
                '103.22.200.0/22',
                '103.31.4.0/22',
                '141.101.64.0/18',
                '108.162.192.0/18',
                '190.93.240.0/20',
                '188.114.96.0/20',
                '197.234.240.0/22',
                '198.41.128.0/17',
                '162.158.0.0/15',
                '104.16.0.0/13',
                '104.24.0.0/14',
                '172.64.0.0/13',
                '131.0.72.0/22',
            ],
            'detection_order' => ['cdn_header', 'tencent_cloud', 'aliyun_esa', 'remote_addr'],
            'cdn_headers' => [
                'X-Forwarded-For',
                'X-Real-IP',
                'CF-Connecting-IP',
                'True-Client-IP',
                'Ali-CDN-Real-IP',
            ],
        ],
        'upload' => [
            'max_size'          => 10 * 1024 * 1024,
            'max_image_size'    => 5 * 1024 * 1024,
            'max_video_size'    => 50 * 1024 * 1024,
            'allowed_images'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
            'allowed_videos'    => ['mp4', 'avi', 'mov', 'mkv', 'webm'],
            'allowed_files'     => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', '7z', 'txt'],
            'chat_upload_dir'   => BASEPATH . '/public/uploads/chat/',
            'evidence_upload_dir' => BASEPATH . '/public/uploads/evidence/',
        ],
        'session' => [
            'lifetime'      => 7200,
            'cookie_name'   => 'lighthouse_session',
            'cookie_path'   => '/',
            'cookie_domain' => '',
            'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? true : false,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ],
        'security' => [
            'csrf_enabled'      => true,
            'csrf_token_name'   => '_token',
            'csrf_token_expire' => 3600,
        ],
        'logto' => [
            'enabled'         => true,
            'endpoint'        => $logtoConfig['endpoint'] ?? '',
            'appId'           => $logtoConfig['app_id'] ?? '',
            'appSecret'       => $logtoConfig['app_secret'] ?? '',
            'redirectUri'     => $logtoConfig['redirect_uri'] ?? '/admin/callback.php',
            'postLogoutUri'   => $logtoConfig['post_logout_uri'] ?? '/admin/login.php',
            'scopes'          => ['openid', 'profile', 'email'],
            'autoCreateUser'  => $logtoConfig['auto_create'] ?? true,
            'defaultRole'     => $logtoConfig['default_role'] ?? 'operator',
        ],
        'logging' => [
            'enabled'      => true,
            'log_dir'      => BASEPATH . '/storage/logs/',
            'log_level'    => 'info',
            'max_log_days' => 90,
        ],
        'pagination' => [
            'per_page'      => 20,
            'max_per_page'  => 100,
            'visible_pages' => 7,
        ],
        'debug' => [
            'enabled'       => false,
            'log_dir'       => BASEPATH . '/storage/logs/debug/',
            'log_queries'   => true,
            'log_auth'      => true,
            'log_api'       => true,
            'log_errors'    => true,
            'max_file_size' => 52428800,
            'max_files'     => 30,
        ],
    ];

    // 用 var_export 安全生成 PHP 代码，正确处理所有特殊字符
    $configCode = var_export($config, true);

    // 生成完整配置文件内容
    $output = "<?php\n";
    $output .= "/**\n";
    $output .= " * 灯塔DNS拦截响应平台 - 配置文件\n";
    $output .= " * 此文件由安装向导自动生成\n";
    $output .= " * 生成时间：" . date('Y-m-d H:i:s') . "\n";
    $output .= " */\n\n";
    $output .= "// 防止直接访问\n";
    $output .= "if (!defined('BASEPATH')) {\n";
    $output .= "    define('BASEPATH', dirname(__DIR__));\n";
    $output .= "}\n\n";
    $output .= "return {$configCode};\n";

    return $output;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 - 灯塔DNS拦截响应平台</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
         *  全局样式
         * ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0a0f1a 0%, #050810 100%);
            min-height: 100vh;
            color: #e2e8f0;
            overflow-x: hidden;
        }

        /* ============================================================
         *  粒子背景
         * ============================================================ */
        #particles-canvas {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        /* ============================================================
         *  主容器
         * ============================================================ */
        .install-container {
            position: relative;
            z-index: 1;
            max-width: 780px;
            margin: 0 auto;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* ============================================================
         *  灯塔 Logo 动画
         * ============================================================ */
        .lighthouse-logo {
            width: 80px;
            height: 80px;
            margin-bottom: 12px;
            animation: logoPulse 3s ease-in-out infinite;
            filter: drop-shadow(0 0 20px rgba(0, 212, 170, 0.5));
        }
        @keyframes logoPulse {
            0%, 100% { filter: drop-shadow(0 0 20px rgba(0, 212, 170, 0.4)); transform: scale(1); }
            50% { filter: drop-shadow(0 0 35px rgba(0, 212, 170, 0.7)); transform: scale(1.05); }
        }

        .site-title {
            font-size: 1.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, #00d4aa, #00a8e8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 6px;
        }
        .site-subtitle {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 32px;
        }

        /* ============================================================
         *  进度条（步骤指示器）
         * ============================================================ */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 36px;
            width: 100%;
            max-width: 600px;
        }
        .step-dot {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            border: 2px solid #334155;
            background: #0f172a;
            color: #64748b;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            z-index: 2;
            flex-shrink: 0;
        }
        .step-dot.active {
            border-color: #00d4aa;
            background: linear-gradient(135deg, #00d4aa, #00a8e8);
            color: #0a0f1a;
            box-shadow: 0 0 20px rgba(0, 212, 170, 0.4);
            animation: stepPulse 2s ease-in-out infinite;
        }
        .step-dot.done {
            border-color: #00d4aa;
            background: #00d4aa;
            color: #0a0f1a;
        }
        @keyframes stepPulse {
            0%, 100% { box-shadow: 0 0 20px rgba(0, 212, 170, 0.3); }
            50% { box-shadow: 0 0 30px rgba(0, 212, 170, 0.6); }
        }
        .step-line {
            width: 60px; height: 2px;
            background: #1e293b;
            transition: background 0.5s;
            flex-shrink: 0;
        }
        .step-line.done {
            background: linear-gradient(90deg, #00d4aa, #00a8e8);
        }
        .step-labels {
            display: flex;
            justify-content: space-between;
            width: 100%;
            max-width: 600px;
            margin-bottom: 28px;
        }
        .step-label {
            font-size: 0.7rem;
            color: #475569;
            text-align: center;
            width: 60px;
            transition: color 0.3s;
        }
        .step-label.active { color: #00d4aa; }
        .step-label.done { color: #00d4aa; }

        /* ============================================================
         *  步骤面板
         * ============================================================ */
        .steps-wrapper {
            width: 100%;
            overflow: hidden;
            position: relative;
        }
        .steps-track {
            display: flex;
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .step-panel {
            min-width: 100%;
            padding: 0 4px;
        }
        .panel-card {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(51, 65, 85, 0.5);
            border-radius: 16px;
            padding: 32px;
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        .panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: #f1f5f9;
        }
        .panel-desc {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 24px;
        }

        /* ============================================================
         *  表单元素
         * ============================================================ */
        .form-group {
            margin-bottom: 18px;
        }
        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 500;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .form-input {
            width: 100%;
            padding: 10px 14px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid #1e293b;
            border-radius: 10px;
            color: #e2e8f0;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            outline: none;
        }
        .form-input:focus {
            border-color: #00d4aa;
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.15);
        }
        .form-input::placeholder {
            color: #475569;
        }
        .form-input.error {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
        }
        .form-hint {
            font-size: 0.75rem;
            margin-top: 4px;
            color: #64748b;
        }
        .form-hint.error { color: #ef4444; }
        .form-hint.success { color: #00d4aa; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 640px) {
            .form-row { grid-template-columns: 1fr; }
        }

        /* ============================================================
         *  按钮
         * ============================================================ */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 24px;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #00d4aa, #00a8e8);
            color: #0a0f1a;
        }
        .btn-primary:hover {
            box-shadow: 0 0 24px rgba(0, 212, 170, 0.5);
            transform: translateY(-2px);
        }
        .btn-primary:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .btn-secondary {
            background: rgba(30, 41, 59, 0.8);
            color: #94a3b8;
            border: 1px solid #334155;
        }
        .btn-secondary:hover {
            background: rgba(51, 65, 85, 0.6);
            color: #e2e8f0;
        }
        .btn-sm {
            padding: 7px 16px;
            font-size: 0.8rem;
        }
        .btn-group {
            display: flex;
            justify-content: space-between;
            margin-top: 28px;
            gap: 12px;
        }

        /* ============================================================
         *  环境检测项
         * ============================================================ */
        .check-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid #1e293b;
            border-radius: 10px;
            margin-bottom: 8px;
            transition: all 0.3s;
        }
        .check-item.pass {
            border-color: rgba(0, 212, 170, 0.3);
            background: rgba(0, 212, 170, 0.05);
        }
        .check-item.fail {
            border-color: rgba(239, 68, 68, 0.3);
            background: rgba(239, 68, 68, 0.05);
        }
        .check-name {
            font-size: 0.85rem;
            color: #cbd5e1;
        }
        .check-status {
            font-size: 0.85rem;
            font-weight: 600;
        }
        .check-status.pass { color: #00d4aa; }
        .check-status.fail { color: #ef4444; }

        /* ============================================================
         *  文件上传区域
         * ============================================================ */
        .upload-zone {
            border: 2px dashed #334155;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: rgba(15, 23, 42, 0.4);
            position: relative;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #00d4aa;
            background: rgba(0, 212, 170, 0.05);
        }
        .upload-zone input[type="file"] {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .upload-icon {
            font-size: 2rem;
            margin-bottom: 8px;
            color: #475569;
        }
        .upload-text {
            font-size: 0.85rem;
            color: #64748b;
        }
        .upload-text span {
            color: #00d4aa;
            font-weight: 500;
        }
        .upload-hint {
            font-size: 0.75rem;
            color: #475569;
            margin-top: 4px;
        }
        .upload-preview {
            margin-top: 12px;
            display: none;
        }
        .upload-preview img {
            max-width: 120px;
            max-height: 120px;
            border-radius: 8px;
            border: 1px solid #334155;
        }
        .upload-preview .remove-btn {
            display: inline-block;
            margin-top: 8px;
            font-size: 0.75rem;
            color: #ef4444;
            cursor: pointer;
            background: none;
            border: none;
            font-family: 'Inter', sans-serif;
        }

        /* ============================================================
         *  安装进度
         * ============================================================ */
        .install-progress {
            margin: 20px 0;
        }
        .progress-bar-track {
            width: 100%;
            height: 8px;
            background: #1e293b;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #00d4aa, #00a8e8);
            border-radius: 4px;
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            width: 0%;
            box-shadow: 0 0 10px rgba(0, 212, 170, 0.5);
        }
        .install-step-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            font-size: 0.85rem;
            color: #64748b;
            transition: color 0.3s;
        }
        .install-step-item.ok { color: #00d4aa; }
        .install-step-item.fail { color: #ef4444; }
        .install-step-item.running { color: #f59e0b; }
        .install-step-icon {
            width: 20px;
            text-align: center;
            font-size: 0.9rem;
        }

        /* ============================================================
         *  配置摘要
         * ============================================================ */
        .summary-section {
            margin-bottom: 20px;
        }
        .summary-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #00d4aa;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #1e293b;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 0.8rem;
        }
        .summary-label { color: #64748b; }
        .summary-value { color: #cbd5e1; font-weight: 500; }

        /* ============================================================
         *  提示消息
         * ============================================================ */
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 16px;
            display: none;
            animation: alertSlide 0.3s ease;
        }
        @keyframes alertSlide {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-success {
            background: rgba(0, 212, 170, 0.1);
            border: 1px solid rgba(0, 212, 170, 0.3);
            color: #00d4aa;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        /* ============================================================
         *  复选框
         * ============================================================ */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: #00d4aa;
            cursor: pointer;
        }
        .checkbox-group label {
            font-size: 0.8rem;
            color: #94a3b8;
            cursor: pointer;
        }

        /* ============================================================
         *  安装成功页面
         * ============================================================ */
        .success-container {
            text-align: center;
            padding: 40px 20px;
        }
        .success-icon {
            font-size: 4rem;
            margin-bottom: 16px;
            animation: successBounce 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes successBounce {
            0% { transform: scale(0); opacity: 0; }
            60% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }
        .success-title {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #00d4aa, #00a8e8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 12px;
        }
        .success-desc {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 32px;
            line-height: 1.6;
        }

        /* ============================================================
         *  加载动画
         * ============================================================ */
        .spinner {
            display: inline-block;
            width: 16px; height: 16px;
            border: 2px solid rgba(10, 15, 26, 0.3);
            border-top-color: #0a0f1a;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ============================================================
         *  滑入动画
         * ============================================================ */
        .fade-in {
            animation: fadeIn 0.5s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ============================================================
         *  脉冲动画
         * ============================================================ */
        @keyframes pulseSuccess {
            0% { box-shadow: 0 0 0 0 rgba(0, 212, 170, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(0, 212, 170, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 212, 170, 0); }
        }
        @keyframes pulseFail {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .pulse-success { animation: pulseSuccess 1s ease; }
        .pulse-fail { animation: pulseFail 1s ease; }
    </style>
</head>
<body>
    <!-- 粒子背景 -->
    <canvas id="particles-canvas"></canvas>

    <div class="install-container">
        <!-- SEC-015: CSRF隐藏字段 -->
        <input type="hidden" id="install_csrf_token" value="<?php echo htmlspecialchars($installCsrfToken ?? ''); ?>">
        <!-- 灯塔 Logo -->
        <svg class="lighthouse-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="80" height="80">
            <defs>
                <linearGradient id="towerGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                    <stop offset="0%" style="stop-color:#00d4aa;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#00a8e8;stop-opacity:1" />
                </linearGradient>
                <radialGradient id="lightGrad" cx="50%" cy="30%" r="50%">
                    <stop offset="0%" style="stop-color:#00d4aa;stop-opacity:0.8" />
                    <stop offset="100%" style="stop-color:#00d4aa;stop-opacity:0" />
                </radialGradient>
                <filter id="glow">
                    <feGaussianBlur stdDeviation="2" result="coloredBlur"/>
                    <feMerge>
                        <feMergeNode in="coloredBlur"/>
                        <feMergeNode in="SourceGraphic"/>
                    </feMerge>
                </filter>
            </defs>
            <rect x="30" y="75" width="40" height="8" rx="2" fill="#1e293b" stroke="#334155" stroke-width="1"/>
            <rect x="25" y="82" width="50" height="5" rx="2" fill="#0f172a" stroke="#334155" stroke-width="1"/>
            <path d="M38 75 L42 35 L58 35 L62 75 Z" fill="url(#towerGrad)" opacity="0.9"/>
            <path d="M39.5 65 L60.5 65 L61.5 70 L38.5 70 Z" fill="#0f172a" opacity="0.3"/>
            <path d="M41 55 L59 55 L60 60 L40 60 Z" fill="#0f172a" opacity="0.3"/>
            <path d="M42.5 45 L57.5 45 L58 50 L42 50 Z" fill="#0f172a" opacity="0.3"/>
            <rect x="38" y="28" width="24" height="10" rx="2" fill="#1e293b" stroke="#00d4aa" stroke-width="1"/>
            <rect x="40" y="30" width="20" height="6" rx="1" fill="#00d4aa" opacity="0.3"/>
            <circle cx="50" cy="33" r="4" fill="#00d4aa" filter="url(#glow)">
                <animate attributeName="opacity" values="0.6;1;0.6" dur="2s" repeatCount="indefinite"/>
            </circle>
            <circle cx="50" cy="33" r="2" fill="#ffffff" opacity="0.9">
                <animate attributeName="opacity" values="0.8;1;0.8" dur="2s" repeatCount="indefinite"/>
            </circle>
            <path d="M38 28 L50 18 L62 28 Z" fill="#00a8e8" stroke="#00d4aa" stroke-width="0.5"/>
            <circle cx="50" cy="18" r="2" fill="#00d4aa" filter="url(#glow)"/>
            <path d="M50 33 L15 10 L20 8 L50 30 Z" fill="#00d4aa" opacity="0.15">
                <animate attributeName="opacity" values="0.1;0.2;0.1" dur="3s" repeatCount="indefinite"/>
            </path>
            <path d="M50 33 L85 10 L80 8 L50 30 Z" fill="#00d4aa" opacity="0.15">
                <animate attributeName="opacity" values="0.1;0.2;0.1" dur="3s" repeatCount="indefinite" begin="1.5s"/>
            </path>
            <circle cx="50" cy="33" r="20" fill="url(#lightGrad)" opacity="0.3">
                <animate attributeName="r" values="18;22;18" dur="3s" repeatCount="indefinite"/>
                <animate attributeName="opacity" values="0.2;0.4;0.2" dur="3s" repeatCount="indefinite"/>
            </circle>
            <rect x="46" y="50" width="8" height="6" rx="1" fill="#00d4aa" opacity="0.2" stroke="#00d4aa" stroke-width="0.5"/>
            <rect x="46" y="62" width="8" height="6" rx="1" fill="#00d4aa" opacity="0.2" stroke="#00d4aa" stroke-width="0.5"/>
        </svg>

        <div class="site-title">灯塔DNS拦截响应平台</div>
        <div class="site-subtitle">安装向导</div>

        <!-- 步骤指示器 -->
        <div class="step-indicator">
            <div class="step-dot active" data-step="0">1</div>
            <div class="step-line"></div>
            <div class="step-dot" data-step="1">2</div>
            <div class="step-line"></div>
            <div class="step-dot" data-step="2">3</div>
            <div class="step-line"></div>
            <div class="step-dot" data-step="3">4</div>
            <div class="step-line"></div>
            <div class="step-dot" data-step="4">5</div>
        </div>
        <div class="step-labels">
            <div class="step-label active" data-step="0">环境检测</div>
            <div class="step-label" data-step="1">数据库</div>
            <div class="step-label" data-step="2">SSO 配置</div>
            <div class="step-label" data-step="3">站点设置</div>
            <div class="step-label" data-step="4">安装</div>
        </div>

        <!-- 步骤面板 -->
        <div class="steps-wrapper">
            <div class="steps-track" id="stepsTrack">

                <!-- ==================== 步骤1：环境检测 ==================== -->
                <div class="step-panel" data-panel="0">
                    <div class="panel-card fade-in">
                        <div class="panel-title">环境检测</div>
                        <div class="panel-desc">系统正在检测您的服务器环境是否满足安装要求</div>
                        <div id="checkResults"></div>
                        <div class="btn-group">
                            <div></div>
                            <button class="btn btn-primary" id="btnStep1Next" disabled onclick="goToStep(1)">
                                下一步
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ==================== 步骤2：数据库配置 ==================== -->
                <div class="step-panel" data-panel="1">
                    <div class="panel-card">
                        <div class="panel-title">数据库配置</div>
                        <div class="panel-desc">请填写您的 MySQL 数据库连接信息</div>

                        <div id="dbAlert" class="alert"></div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">数据库主机</label>
                                <input type="text" class="form-input" id="db_host" value="127.0.0.1" placeholder="127.0.0.1">
                            </div>
                            <div class="form-group">
                                <label class="form-label">数据库端口</label>
                                <input type="number" class="form-input" id="db_port" value="3306" placeholder="3306">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">数据库名称</label>
                            <input type="text" class="form-input" id="db_name" value="dnslj" placeholder="dnslj">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">数据库用户名</label>
                                <input type="text" class="form-input" id="db_user" value="root" placeholder="root">
                            </div>
                            <div class="form-group">
                                <label class="form-label">数据库密码</label>
                                <input type="password" class="form-input" id="db_pass" placeholder="请输入数据库密码">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">表前缀（可选）</label>
                            <input type="text" class="form-input" id="db_prefix" value="" placeholder="例如: lh_">
                            <div class="form-hint">留空则不使用表前缀</div>
                        </div>

                        <div style="margin-top: 16px;">
                            <button class="btn btn-secondary btn-sm" onclick="testDbConnection()">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                测试连接
                            </button>
                        </div>

                        <div class="btn-group">
                            <button class="btn btn-secondary" onclick="goToStep(0)">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                上一步
                            </button>
                            <button class="btn btn-primary" id="btnStep2Next" onclick="validateStep2()">
                                下一步
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ==================== 步骤3：SSO 配置 ==================== -->
                <div class="step-panel" data-panel="2">
                    <div class="panel-card">
                        <div class="panel-title">SSO 配置</div>
                        <div class="panel-desc">配置 Logto 统一身份认证服务，用于后台管理系统的单点登录</div>

                        <div class="form-group">
                            <label class="form-label">Logto 服务端点（Endpoint） <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-input" id="logto_endpoint" placeholder="https://your-logto-endpoint.app" autocomplete="off">
                            <div class="form-hint" id="logto_endpoint_hint"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">应用 ID（App ID） <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-input" id="logto_app_id" placeholder="在 Logto Console 中获取" autocomplete="off">
                            <div class="form-hint" id="logto_app_id_hint"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">应用密钥（App Secret） <span style="color:#ef4444">*</span></label>
                            <input type="password" class="form-input" id="logto_app_secret" placeholder="在 Logto Console 中获取" autocomplete="off">
                            <div class="form-hint" id="logto_app_secret_hint"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">登录回调地址（Redirect URI） <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-input" id="logto_redirect_uri_display" readonly style="background-color: #1e293b; color: #94a3b8; cursor: not-allowed;">
                            <input type="hidden" id="logto_redirect_uri" name="logto_redirect_uri">
                            <div class="form-hint" id="logto_redirect_uri_hint">系统自动生成，请确保在 Logto Console 中配置此回调 URL</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">登出后跳转地址（Post Logout URI）</label>
                            <input type="text" class="form-input" id="logto_post_logout_uri_display" readonly style="background-color: #1e293b; color: #94a3b8; cursor: not-allowed;">
                            <input type="hidden" id="logto_post_logout_uri" name="logto_post_logout_uri">
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="logto_auto_create_user" value="1" checked>
                            <label for="logto_auto_create_user">自动创建用户（首次 SSO 登录时自动在系统中创建对应账户）</label>
                        </div>
                        <div class="form-group">
                            <label class="form-label">默认用户角色</label>
                            <select class="form-input" id="logto_default_role">
                                <option value="operator" selected>运维人员（operator）</option>
                                <option value="admin">管理员（admin）</option>
                            </select>
                            <div class="form-hint">自动创建用户时分配的默认角色</div>
                        </div>

                        <div class="btn-group">
                            <button class="btn btn-secondary" onclick="goToStep(1)">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                上一步
                            </button>
                            <button class="btn btn-primary" onclick="validateStep3()">
                                下一步
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ==================== 步骤4：站点设置 ==================== -->
                <div class="step-panel" data-panel="3">
                    <div class="panel-card">
                        <div class="panel-title">站点设置</div>
                        <div class="panel-desc">配置站点基本信息和上传 Logo / Favicon</div>

                        <div class="form-group">
                            <label class="form-label">站点名称</label>
                            <input type="text" class="form-input" id="site_name" value="灯塔DNS拦截响应平台" placeholder="灯塔DNS拦截响应平台">
                        </div>
                        <div class="form-group">
                            <label class="form-label">站点描述</label>
                            <input type="text" class="form-input" id="site_desc" value="域名过期与违规拦截服务" placeholder="站点描述">
                        </div>
                        <div class="form-group">
                            <label class="form-label">联系邮箱</label>
                            <input type="email" class="form-input" id="site_email" value="support@dengtanet.com" placeholder="admin@example.com">
                        </div>

                        <!-- 后台访问域名白名单 -->
                        <div class="form-group">
                            <label class="form-label">后台访问域名 <span style="color:#64748b;font-weight:400;font-size:13px;">（白名单，可选）</span></label>
                            <div class="form-hint" style="margin-bottom:10px;">限制只能通过指定域名访问后台管理面板，留空则不限制。例如设置 dnslj.dengtanet.com 后，通过 icp.dtltd.cn 将无法访问后台。</div>
                            <div id="domainList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;"></div>
                            <div style="display:flex;gap:8px;">
                                <input type="text" class="form-input" id="domain_input" placeholder="例如: dnslj.dengtanet.com" style="flex:1;" onkeydown="if(event.key==='Enter'){event.preventDefault();addDomain();}">
                                <button type="button" class="btn btn-secondary" onclick="addDomain()" style="white-space:nowrap;padding:8px 16px;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                    添加
                                </button>
                            </div>
                        </div>

                        <!-- Logo 上传 -->
                        <div class="form-group">
                            <label class="form-label">站点 Logo</label>
                            <div class="upload-zone" id="logoZone">
                                <input type="file" id="logo_file" name="logo_file" accept=".svg,.png,.jpg,.jpeg">
                                <div class="upload-icon">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                </div>
                                <div class="upload-text">拖拽文件到此处或 <span>点击上传</span></div>
                                <div class="upload-hint">支持 SVG, PNG, JPG 格式，最大 2MB</div>
                                <div class="upload-preview" id="logoPreview">
                                    <img id="logoPreviewImg" src="" alt="Logo 预览">
                                    <br>
                                    <button class="remove-btn" type="button" onclick="removeUpload('logo')">移除</button>
                                </div>
                            </div>
                        </div>

                        <!-- Favicon 上传 -->
                        <div class="form-group">
                            <label class="form-label">浏览器 Favicon</label>
                            <div class="upload-zone" id="faviconZone">
                                <input type="file" id="favicon_file" name="favicon_file" accept=".ico,.png">
                                <div class="upload-icon">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                                </div>
                                <div class="upload-text">拖拽文件到此处或 <span>点击上传</span></div>
                                <div class="upload-hint">支持 ICO, PNG 格式，最大 1MB</div>
                                <div class="upload-preview" id="faviconPreview">
                                    <img id="faviconPreviewImg" src="" alt="Favicon 预览">
                                    <br>
                                    <button class="remove-btn" type="button" onclick="removeUpload('favicon')">移除</button>
                                </div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button class="btn btn-secondary" onclick="goToStep(2)">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                上一步
                            </button>
                            <button class="btn btn-primary" onclick="goToStep(4)">
                                下一步
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ==================== 步骤5：安装执行 ==================== -->
                <div class="step-panel" data-panel="4">
                    <div class="panel-card" id="installPanel">
                        <div class="panel-title">安装执行</div>
                        <div class="panel-desc">请确认以下配置信息，然后点击"开始安装"</div>

                        <!-- 配置摘要 -->
                        <div id="installSummary"></div>

                        <!-- 安装进度 -->
                        <div class="install-progress" id="installProgress" style="display:none;">
                            <div class="progress-bar-track">
                                <div class="progress-bar-fill" id="progressFill"></div>
                            </div>
                            <div id="installSteps"></div>
                        </div>

                        <!-- 安装成功 -->
                        <div class="success-container" id="installSuccess" style="display:none;">
                            <div class="success-icon">&#10003;</div>
                            <div class="success-title">安装成功</div>
                            <div class="success-desc">
                                灯塔DNS拦截响应平台已成功安装！<br>
                                您可以通过 SSO 统一身份认证登录后台管理系统。
                            </div>
                            <a href="/admin/" class="btn btn-primary">
                                进入后台管理
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </a>
                        </div>

                        <div class="btn-group" id="installBtnGroup">
                            <button class="btn btn-secondary" onclick="goToStep(3)">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                上一步
                            </button>
                            <button class="btn btn-primary" id="btnInstall" onclick="startInstall()">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                开始安装
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ============================================================
         JavaScript
         ============================================================ -->
    <script>
    (function() {
        'use strict';

        // ============================================================
        //  全局状态
        // ============================================================
        let currentStep = 0;
        const totalSteps = 5;
        let envCheckPassed = false;
        let dbTested = false;

        // ============================================================
        //  粒子背景动画
        // ============================================================
        function initParticles() {
            const canvas = document.getElementById('particles-canvas');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            let particles = [];
            const particleCount = 60;

            function resize() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
            }
            resize();
            window.addEventListener('resize', resize);

            for (let i = 0; i < particleCount; i++) {
                particles.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.3,
                    vy: (Math.random() - 0.5) * 0.3,
                    r: Math.random() * 2 + 0.5,
                    alpha: Math.random() * 0.5 + 0.1,
                });
            }

            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                particles.forEach((p, i) => {
                    p.x += p.vx;
                    p.y += p.vy;
                    if (p.x < 0) p.x = canvas.width;
                    if (p.x > canvas.width) p.x = 0;
                    if (p.y < 0) p.y = canvas.height;
                    if (p.y > canvas.height) p.y = 0;

                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(0, 212, 170, ${p.alpha})`;
                    ctx.fill();

                    // 连线
                    for (let j = i + 1; j < particles.length; j++) {
                        const p2 = particles[j];
                        const dx = p.x - p2.x;
                        const dy = p.y - p2.y;
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 120) {
                            ctx.beginPath();
                            ctx.moveTo(p.x, p.y);
                            ctx.lineTo(p2.x, p2.y);
                            ctx.strokeStyle = `rgba(0, 212, 170, ${0.08 * (1 - dist / 120)})`;
                            ctx.lineWidth = 0.5;
                            ctx.stroke();
                        }
                    }
                });
                requestAnimationFrame(animate);
            }
            animate();
        }

        // ============================================================
        //  步骤切换
        // ============================================================
        window.goToStep = function(step) {
            if (step < 0 || step >= totalSteps) return;
            if (step === 1 && !envCheckPassed) return;

            currentStep = step;
            const track = document.getElementById('stepsTrack');
            track.style.transform = `translateX(-${step * 100}%)`;

            // 更新步骤指示器
            document.querySelectorAll('.step-dot').forEach((dot, i) => {
                dot.classList.remove('active', 'done');
                if (i < step) dot.classList.add('done');
                else if (i === step) dot.classList.add('active');
            });
            document.querySelectorAll('.step-line').forEach((line, i) => {
                line.classList.toggle('done', i < step);
            });
            document.querySelectorAll('.step-label').forEach((label, i) => {
                label.classList.remove('active', 'done');
                if (i < step) label.classList.add('done');
                else if (i === step) label.classList.add('active');
            });

            // 步骤5：显示配置摘要
            if (step === 4) {
                renderSummary();
            }
        };

        // ============================================================
        //  环境检测
        // ============================================================
        function runEnvCheck() {
            const checks = <?php echo json_encode(getEnvChecks()); ?>;
            const container = document.getElementById('checkResults');
            let html = '';
            let allPassed = true;

            checks.forEach((check, idx) => {
                const passed = check.pass;
                if (!passed) allPassed = false;
                const cls = passed ? 'pass' : 'fail';
                const icon = passed ? '&#10003;' : '&#10007;';
                const statusText = passed ? '通过' : '未通过';
                html += `<div class="check-item ${cls}" style="animation: fadeIn 0.3s ease ${idx * 0.05}s both;">
                    <span class="check-name">${check.name}</span>
                    <span class="check-status ${cls}">${icon} ${statusText}</span>
                </div>`;
            });

            container.innerHTML = html;
            envCheckPassed = allPassed;

            const btn = document.getElementById('btnStep1Next');
            if (allPassed) {
                btn.disabled = false;
            } else {
                btn.disabled = true;
            }
        }

        // ============================================================
        //  数据库测试连接
        // ============================================================
        window.testDbConnection = function() {
            const alert = document.getElementById('dbAlert');
            const host = document.getElementById('db_host').value.trim();
            const port = document.getElementById('db_port').value.trim();
            const name = document.getElementById('db_name').value.trim();
            const user = document.getElementById('db_user').value.trim();
            const pass = document.getElementById('db_pass').value;

            if (!host || !name || !user) {
                alert.className = 'alert alert-error';
                alert.style.display = 'block';
                alert.textContent = '请填写数据库主机、名称和用户名';
                return;
            }

            alert.className = 'alert alert-success';
            alert.style.display = 'block';
            alert.innerHTML = '<span class="spinner"></span> 正在测试连接...';

            const formData = new FormData();
            formData.append('action', 'test_db');
            formData.append('csrf_token', document.getElementById('install_csrf_token').value);
            formData.append('db_host', host);
            formData.append('db_port', port);
            formData.append('db_name', name);
            formData.append('db_user', user);
            formData.append('db_pass', pass);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(async r => {
                    console.log('[安装向导] 服务器响应状态:', r.status, r.statusText);
                    if (!r.ok) {
                        let text = await r.text().catch(() => '');
                        throw new Error('服务器返回 ' + r.status + (text ? '：' + text.substring(0, 200) : ''));
                    }
                    const data = await r.json();
                    console.log('[安装向导] 服务器响应数据:', data);
                    return data;
                })
                .then(data => {
                    if (data.success) {
                        alert.className = 'alert alert-success';
                        alert.style.display = 'block';
                        alert.textContent = data.message;
                        dbTested = true;
                    } else {
                        alert.className = 'alert alert-error';
                        alert.style.display = 'block';
                        alert.textContent = data.message;
                        dbTested = false;
                    }
                })
                .catch(err => {
                    alert.className = 'alert alert-error';
                    alert.style.display = 'block';
                    alert.textContent = '请求失败：' + err.message;
                    dbTested = false;
                });
        };

        // ============================================================
        //  步骤2验证
        // ============================================================
        window.validateStep2 = function() {
            const host = document.getElementById('db_host').value.trim();
            const port = document.getElementById('db_port').value.trim();
            const name = document.getElementById('db_name').value.trim();
            const user = document.getElementById('db_user').value.trim();

            if (!host || !port || !name || !user) {
                const alert = document.getElementById('dbAlert');
                alert.className = 'alert alert-error';
                alert.style.display = 'block';
                alert.textContent = '请填写所有必填字段';
                return;
            }

            if (!dbTested) {
                const alert = document.getElementById('dbAlert');
                alert.className = 'alert alert-error';
                alert.style.display = 'block';
                alert.textContent = '请先测试数据库连接';
                return;
            }

            goToStep(2);
        };

        document.addEventListener('DOMContentLoaded', function() {

            // 文件上传处理
            setupUploadZone('logoZone', 'logo_file', 'logoPreview', 'logoPreviewImg', 2 * 1024 * 1024, ['svg', 'png', 'jpg', 'jpeg']);
            setupUploadZone('faviconZone', 'favicon_file', 'faviconPreview', 'faviconPreviewImg', 1 * 1024 * 1024, ['ico', 'png']);

            // 自动生成 SSO 回调地址
            generateSSOUrls();

            // 初始化
            initParticles();
            runEnvCheck();
        });

        // ============================================================
        //  自动生成 SSO 回调 URL
        // ============================================================
        function generateSSOUrls() {
            const origin = window.location.origin;
            const redirectUri = origin + '/admin/callback.php';
            const postLogoutUri = origin + '/admin/login.php';

            // 设置显示值（只读输入框）
            const redirectDisplay = document.getElementById('logto_redirect_uri_display');
            const postLogoutDisplay = document.getElementById('logto_post_logout_uri_display');
            const redirectHidden = document.getElementById('logto_redirect_uri');
            const postLogoutHidden = document.getElementById('logto_post_logout_uri');

            if (redirectDisplay) redirectDisplay.value = redirectUri;
            if (postLogoutDisplay) postLogoutDisplay.value = postLogoutUri;
            if (redirectHidden) redirectHidden.value = redirectUri;
            if (postLogoutHidden) postLogoutHidden.value = postLogoutUri;
        }

        // ============================================================
        //  步骤3验证
        // ============================================================
        window.validateStep3 = function() {
            const endpoint = document.getElementById('logto_endpoint').value.trim();
            const appId = document.getElementById('logto_app_id').value.trim();
            const appSecret = document.getElementById('logto_app_secret').value.trim();
            let valid = true;

            // Logto 服务端点
            const endpointHint = document.getElementById('logto_endpoint_hint');
            const urlRegex = /^https?:\/\/.+/i;
            if (!endpoint) {
                endpointHint.textContent = '请输入 Logto 服务端点';
                endpointHint.className = 'form-hint error';
                document.getElementById('logto_endpoint').classList.add('error');
                valid = false;
            } else if (!urlRegex.test(endpoint)) {
                endpointHint.textContent = '端点地址格式不正确，必须以 http:// 或 https:// 开头';
                endpointHint.className = 'form-hint error';
                document.getElementById('logto_endpoint').classList.add('error');
                valid = false;
            } else {
                endpointHint.textContent = '';
                endpointHint.className = 'form-hint';
                document.getElementById('logto_endpoint').classList.remove('error');
            }

            // 应用 ID
            const appIdHint = document.getElementById('logto_app_id_hint');
            if (!appId) {
                appIdHint.textContent = '请输入应用 ID';
                appIdHint.className = 'form-hint error';
                document.getElementById('logto_app_id').classList.add('error');
                valid = false;
            } else {
                appIdHint.textContent = '';
                appIdHint.className = 'form-hint';
                document.getElementById('logto_app_id').classList.remove('error');
            }

            // 应用密钥
            const appSecretHint = document.getElementById('logto_app_secret_hint');
            if (!appSecret) {
                appSecretHint.textContent = '请输入应用密钥';
                appSecretHint.className = 'form-hint error';
                document.getElementById('logto_app_secret').classList.add('error');
                valid = false;
            } else {
                appSecretHint.textContent = '';
                appSecretHint.className = 'form-hint';
                document.getElementById('logto_app_secret').classList.remove('error');
            }

            // 回调地址（自动生成，无需验证，但确保已生成）
            const redirectUri = document.getElementById('logto_redirect_uri').value.trim();
            const redirectUriHint = document.getElementById('logto_redirect_uri_hint');
            if (!redirectUri) {
                // 如果未生成，重新生成
                generateSSOUrls();
            }
            redirectUriHint.textContent = '系统自动生成，请确保在 Logto Console 中配置此回调 URL';
            redirectUriHint.className = 'form-hint';

            if (valid) goToStep(3);
        };

        // ============================================================
        //  文件上传区域
        // ============================================================
        function setupUploadZone(zoneId, inputId, previewId, imgId, maxSize, allowedExts) {
            const zone = document.getElementById(zoneId);
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);
            const img = document.getElementById(imgId);

            if (!zone || !input) return;

            // 拖拽事件
            zone.addEventListener('dragover', function(e) {
                e.preventDefault();
                zone.classList.add('dragover');
            });
            zone.addEventListener('dragleave', function() {
                zone.classList.remove('dragover');
            });
            zone.addEventListener('drop', function(e) {
                e.preventDefault();
                zone.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    input.files = e.dataTransfer.files;
                    handleFileSelect(input.files[0], maxSize, allowedExts, preview, img, zone);
                }
            });

            // 点击选择
            input.addEventListener('change', function() {
                if (this.files.length > 0) {
                    handleFileSelect(this.files[0], maxSize, allowedExts, preview, img, zone);
                }
            });
        }

        function handleFileSelect(file, maxSize, allowedExts, preview, img, zone) {
            const ext = file.name.split('.').pop().toLowerCase();

            if (!allowedExts.includes(ext)) {
                alert('不支持的文件格式，请上传 ' + allowedExts.join(', ') + ' 格式的文件');
                return;
            }
            if (file.size > maxSize) {
                alert('文件大小超出限制（最大 ' + (maxSize / 1024 / 1024) + 'MB）');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                preview.style.display = 'block';
                zone.style.borderColor = '#00d4aa';
            };
            reader.readAsDataURL(file);
        }

        window.removeUpload = function(type) {
            if (type === 'logo') {
                document.getElementById('logo_file').value = '';
                document.getElementById('logoPreview').style.display = 'none';
                document.getElementById('logoPreviewImg').src = '';
                document.getElementById('logoZone').style.borderColor = '';
            } else if (type === 'favicon') {
                document.getElementById('favicon_file').value = '';
                document.getElementById('faviconPreview').style.display = 'none';
                document.getElementById('faviconPreviewImg').src = '';
                document.getElementById('faviconZone').style.borderColor = '';
            }
        };

        // ============================================================
        //  配置摘要
        // ============================================================
        function renderSummary() {
            const dbHost = document.getElementById('db_host').value;
            const dbPort = document.getElementById('db_port').value;
            const dbName = document.getElementById('db_name').value;
            const dbUser = document.getElementById('db_user').value;
            const dbPrefix = document.getElementById('db_prefix').value || '（无）';
            const logtoEndpoint = document.getElementById('logto_endpoint').value;
            const logtoAppId = document.getElementById('logto_app_id').value;
            const logtoRedirectUri = document.getElementById('logto_redirect_uri').value;
            const siteName = document.getElementById('site_name').value;
            const siteDesc = document.getElementById('site_desc').value;
            const siteEmail = document.getElementById('site_email').value;

            const html = `
                <div class="summary-section">
                    <div class="summary-title">数据库配置</div>
                    <div class="summary-row"><span class="summary-label">主机</span><span class="summary-value">${escHtml(dbHost)}:${escHtml(dbPort)}</span></div>
                    <div class="summary-row"><span class="summary-label">数据库</span><span class="summary-value">${escHtml(dbName)}</span></div>
                    <div class="summary-row"><span class="summary-label">表前缀</span><span class="summary-value">${escHtml(dbPrefix)}</span></div>
                </div>
                <div class="summary-section">
                    <div class="summary-title">SSO 配置</div>
                    <div class="summary-row"><span class="summary-label">Logto 端点</span><span class="summary-value">${escHtml(logtoEndpoint)}</span></div>
                    <div class="summary-row"><span class="summary-label">应用 ID</span><span class="summary-value">${escHtml(logtoAppId)}</span></div>
                    <div class="summary-row"><span class="summary-label">回调地址</span><span class="summary-value">${escHtml(logtoRedirectUri)}</span></div>
                </div>
                <div class="summary-section">
                    <div class="summary-title">站点信息</div>
                    <div class="summary-row"><span class="summary-label">站点名称</span><span class="summary-value">${escHtml(siteName)}</span></div>
                    <div class="summary-row"><span class="summary-label">站点描述</span><span class="summary-value">${escHtml(siteDesc)}</span></div>
                    <div class="summary-row"><span class="summary-label">联系邮箱</span><span class="summary-value">${escHtml(siteEmail)}</span></div>
                    <div class="summary-row"><span class="summary-label">后台域名</span><span class="summary-value">${getAdminDomains().length > 0 ? escHtml(getAdminDomains().join(', ')) : '不限制'}</span></div>
                </div>
            `;
            document.getElementById('installSummary').innerHTML = html;
        }

        // ============================================================
        //  域名白名单管理
        // ============================================================
        const adminDomains = [];

        window.addDomain = function() {
            const input = document.getElementById('domain_input');
            const domain = input.value.trim().toLowerCase();
            if (!domain) return;
            // 基本域名格式验证
            if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/.test(domain)) {
                input.style.borderColor = '#ef4444';
                setTimeout(() => input.style.borderColor = '', 1500);
                return;
            }
            if (adminDomains.includes(domain)) {
                input.value = '';
                return;
            }
            adminDomains.push(domain);
            input.value = '';
            renderDomainList();
        };

        window.removeDomain = function(idx) {
            adminDomains.splice(idx, 1);
            renderDomainList();
        };

        function renderDomainList() {
            const container = document.getElementById('domainList');
            if (adminDomains.length === 0) {
                container.innerHTML = '';
                return;
            }
            container.innerHTML = adminDomains.map((d, i) =>
                `<div style="display:flex;align-items:center;gap:8px;background:rgba(14,165,233,0.06);border:1px solid rgba(14,165,233,0.15);border-radius:8px;padding:6px 12px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
                    <span style="flex:1;font-size:13px;color:#cbd5e1;">${escHtml(d)}</span>
                    <button type="button" onclick="removeDomain(${i})" style="background:none;border:none;color:#64748b;cursor:pointer;padding:2px 4px;font-size:16px;line-height:1;" title="移除">&times;</button>
                </div>`
            ).join('');
        }

        function getAdminDomains() {
            return adminDomains.slice();
        }

        function escHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // ============================================================
        //  执行安装
        // ============================================================
        window.startInstall = function() {
            const btn = document.getElementById('btnInstall');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> 安装中...';

            // 前端CSRF token检查
            const csrfToken = document.getElementById('install_csrf_token');
            if (!csrfToken || !csrfToken.value) {
                btn.disabled = false;
                btn.innerHTML = '开始安装';
                alert('CSRF令牌丢失，请刷新页面后重试');
                return;
            }

            // 检查必填字段
            const requiredFields = ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'logto_endpoint', 'logto_app_id', 'logto_app_secret', 'site_name'];
            for (const fieldId of requiredFields) {
                const el = document.getElementById(fieldId);
                if (!el || !el.value.trim()) {
                    btn.disabled = false;
                    btn.innerHTML = '开始安装';
                    alert('请填写所有必填字段');
                    return;
                }
            }

            document.getElementById('installSummary').style.display = 'none';
            document.getElementById('installProgress').style.display = 'block';

            // 先显示加载占位，等后端返回后再动态渲染步骤
            const stepsContainer = document.getElementById('installSteps');
            stepsContainer.innerHTML = '<div style="text-align:center;padding:20px;color:#94a3b8;">正在执行安装，请稍候...</div>';

            const formData = new FormData();
            formData.append('action', 'install');
            formData.append('csrf_token', document.getElementById('install_csrf_token').value);
            formData.append('db_host', document.getElementById('db_host').value);
            formData.append('db_port', document.getElementById('db_port').value);
            formData.append('db_name', document.getElementById('db_name').value);
            formData.append('db_user', document.getElementById('db_user').value);
            formData.append('db_pass', document.getElementById('db_pass').value);
            formData.append('db_prefix', document.getElementById('db_prefix').value);
            formData.append('logto_endpoint', document.getElementById('logto_endpoint').value);
            formData.append('logto_app_id', document.getElementById('logto_app_id').value);
            formData.append('logto_app_secret', document.getElementById('logto_app_secret').value);
            formData.append('logto_redirect_uri', document.getElementById('logto_redirect_uri').value);
            formData.append('logto_post_logout_uri', document.getElementById('logto_post_logout_uri').value);
            formData.append('logto_auto_create_user', document.getElementById('logto_auto_create_user').checked ? '1' : '0');
            formData.append('logto_default_role', document.getElementById('logto_default_role').value);
            formData.append('site_name', document.getElementById('site_name').value);
            formData.append('site_desc', document.getElementById('site_desc').value);
            formData.append('site_email', document.getElementById('site_email').value);

            // 后台域名白名单
            getAdminDomains().forEach(d => formData.append('admin_domains[]', d));

            // 附加文件
            const logoFile = document.getElementById('logo_file').files[0];
            if (logoFile) formData.append('logo_file', logoFile);
            const faviconFile = document.getElementById('favicon_file').files[0];
            if (faviconFile) formData.append('favicon_file', faviconFile);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(async r => {
                    if (!r.ok) {
                        let text = await r.text().catch(() => '');
                        throw new Error('服务器返回 ' + r.status + (text ? '：' + text.substring(0, 200) : ''));
                    }
                    return r.json();
                })
                .then(data => {
                    // 验证响应格式
                    if (!data || typeof data !== 'object') {
                        throw new Error('服务器返回了无效的响应格式');
                    }

                    // 用后端返回的步骤动态渲染步骤列表
                    const steps = Array.isArray(data.steps) ? data.steps : [];
                    if (steps.length === 0) {
                        throw new Error('安装步骤返回为空，请检查服务器日志');
                    }

                    stepsContainer.innerHTML = steps.map(step =>
                        `<div class="install-step-item" id="istep_${escHtml(step.name)}">
                            <span class="install-step-icon">&#9679;</span>
                            <span>${escHtml(step.name)}</span>
                        </div>`
                    ).join('');

                    if (data.success) {

                        // 逐步显示成功
                        steps.forEach((step, i) => {
                            setTimeout(() => {
                                const el = document.getElementById('istep_' + step.name);
                                if (el) {
                                    el.classList.add(step.status);
                                    el.querySelector('.install-step-icon').innerHTML = step.status === 'ok' ? '&#10003;' : '&#10007;';
                                }
                                const progress = ((i + 1) / steps.length) * 100;
                                document.getElementById('progressFill').style.width = progress + '%';
                            }, i * 400);
                        });

                        // 安装完成
                        setTimeout(() => {
                            document.getElementById('installProgress').style.display = 'none';
                            document.getElementById('installBtnGroup').style.display = 'none';
                            document.getElementById('installSuccess').style.display = 'block';
                        }, steps.length * 400 + 500);
                    } else {
                        // 安装失败 - 步骤列表已在上方渲染
                        const failMsg = data.message || '未知错误';

                        steps.forEach((step, i) => {
                                setTimeout(() => {
                                    const el = document.getElementById('istep_' + step.name);
                                    if (el) {
                                        el.classList.add(step.status);
                                        el.querySelector('.install-step-icon').innerHTML = step.status === 'ok' ? '&#10003;' : '&#10007;';
                                        if (step.status === 'fail' && step.msg) {
                                            el.innerHTML += `<span style="font-size:0.75rem;color:#ef4444;margin-left:8px;">${escHtml(step.msg)}</span>`;
                                        }
                                    }
                                    const progress = ((i + 1) / steps.length) * 100;
                                    document.getElementById('progressFill').style.width = progress + '%';
                                }, i * 300);
                            });

                            setTimeout(() => {
                                btn.disabled = false;
                                btn.innerHTML = '重新安装';
                            }, steps.length * 300 + 500);
                    }
                })
                .catch(err => {
                    stepsContainer.innerHTML = `<div style="text-align:center;padding:20px;color:#ef4444;">安装失败：${escHtml(err.message)}</div>`;
                    btn.disabled = false;
                    btn.innerHTML = '重新安装';
                });
        };

    })();
    </script>
</body>
</html>
<?php
// ============================================================
// PHP 环境检测函数
// ============================================================
function getEnvChecks() {
    $checks = [];

    // 检查是否已安装
    $lockFile = dirname(__DIR__) . '/storage/install.lock';
    if (file_exists($lockFile)) {
        $checks[] = ['name' => '安装锁检测（系统未安装）', 'pass' => false];
    } else {
        $checks[] = ['name' => '安装锁检测（系统未安装）', 'pass' => true];
    }

    // PHP 版本
    $checks[] = [
        'name' => 'PHP 版本 >= 8.0（当前 ' . PHP_VERSION . '）',
        'pass' => version_compare(PHP_VERSION, '8.0.0', '>='),
    ];

    // PDO 扩展
    $checks[] = [
        'name' => 'PDO 扩展',
        'pass' => extension_loaded('pdo'),
    ];

    // PDO MySQL 扩展
    $checks[] = [
        'name' => 'PDO MySQL 扩展',
        'pass' => extension_loaded('pdo_mysql'),
    ];

    // JSON 扩展
    $checks[] = [
        'name' => 'JSON 扩展',
        'pass' => extension_loaded('json'),
    ];

    // MBString 扩展
    $checks[] = [
        'name' => 'MBString 扩展',
        'pass' => extension_loaded('mbstring'),
    ];

    // GD 扩展
    $checks[] = [
        'name' => 'GD 扩展（图片处理）',
        'pass' => extension_loaded('gd'),
    ];

    // 文件上传
    $checks[] = [
        'name' => '文件上传功能',
        'pass' => ini_get('file_uploads') === '1' || ini_get('file_uploads') === 'On',
    ];

    // Session 功能
    $checks[] = [
        'name' => 'Session 功能',
        'pass' => extension_loaded('session'),
    ];

    // 目录写权限
    $dirs = [
        'storage/'         => dirname(__DIR__) . '/storage',
        'public/uploads/'  => __DIR__ . '/uploads',
    ];
    foreach ($dirs as $label => $path) {
        $writable = false;
        if (is_dir($path)) {
            $writable = is_writable($path);
        } else {
            $writable = is_writable(dirname($path));
        }
        $checks[] = [
            'name' => '目录写权限：' . $label,
            'pass' => $writable,
        ];
    }

    return $checks;
}
?>
