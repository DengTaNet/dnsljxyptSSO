<?php
/**
 * 灯塔DNS拦截响应平台 - 入口分发文件
 * 
 * 根据请求的域名查询数据库，判断域名类型并分发到对应页面。
 * 同时检测访问者IP是否被封禁。
 */

define('BASEPATH', dirname(__DIR__));

// ==================== 安装检测 ====================
$installLock = BASEPATH . '/storage/install.lock';
if (!file_exists($installLock)) {
    // 未安装，跳转到安装向导
    header('Location: /install.php');
    exit;
}

// 加载依赖
require_once BASEPATH . '/includes/functions.php';
initDebug();

// 启动会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 设置时区
date_default_timezone_set(config('site.timezone', 'Asia/Shanghai'));

// 获取当前请求的域名和URI（M-06: 使用validateHost进行格式验证）
$host = validateHost();
if (empty($host)) {
    http_response_code(400);
    die('Bad Request');
}
$requestedUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $host . $requestedUri;

// 初始化IP检测
$clientIp = getClientIp();

// ==================== 1. 检查IP是否被封禁 ====================
try {
    $ipBan = db()->queryOne(
        "SELECT * FROM ip_bans WHERE (ip = ? OR real_ip = ?) AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY banned_at DESC LIMIT 1",
        [$clientIp, $clientIp]
    );

    // 如果通过CDN头检测到真实IP，也检查真实IP
    if (!$ipBan) {
        $cdnHeaders = ['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'];
        foreach ($cdnHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $forwardedIp = explode(',', $_SERVER[$header])[0];
                $forwardedIp = trim($forwardedIp);
                if ($forwardedIp !== $clientIp && filter_var($forwardedIp, FILTER_VALIDATE_IP)) {
                    $ipBan = db()->queryOne(
                        "SELECT * FROM ip_bans WHERE (ip = ? OR real_ip = ?) AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY banned_at DESC LIMIT 1",
                        [$forwardedIp, $forwardedIp]
                    );
                    if ($ipBan) break;
                }
            }
        }
    }

    // 如果IP被封禁，跳转到封禁页面
    if ($ipBan) {
        // 记录访问日志
        try {
            // 解析User-Agent（L-11: 截断为500字符）
            $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $uaInfo = parseUserAgent($ua);

            $logData = [
                'domain_id'      => null,
                'ip'             => $clientIp,
                'real_ip'        => $ipBan['real_ip'] ?? $clientIp,
                'mac'            => '',
                'browser'        => $uaInfo['browser'],
                'browser_version'=> $uaInfo['browser_version'],
                'os'             => $uaInfo['os'],
                'os_version'     => $uaInfo['os_version'],
                'user_agent'     => $ua,
                'referer'        => $_SERVER['HTTP_REFERER'] ?? '',
                'requested_url'  => $requestedUrl,
                'detection_source' => 'remote_addr',
                'response_code'  => 403,
                'created_at'     => date('Y-m-d H:i:s'),
            ];
            $columns = implode(', ', array_keys($logData));
            $placeholders = implode(', ', array_fill(0, count($logData), '?'));
            db()->execute("INSERT INTO access_logs ({$columns}) VALUES ({$placeholders})", array_values($logData));
        } catch (\Throwable $e) {
            // 日志记录失败不影响主流程
        }

        // 返回403状态码并显示封禁页面
        http_response_code(403);
        include BASEPATH . '/public/banned.php';
        exit;
    }
} catch (\Throwable $e) {
    // IP封禁检查失败，继续执行
}

// ==================== 2. 查询域名信息 ====================
$domain = null;
try {
    $domain = db()->queryOne(
        "SELECT * FROM domains WHERE domain_name = ? AND status = 'active'",
        [$host]
    );
} catch (\Throwable $e) {
    // 数据库查询失败，显示默认页面
}

// ==================== 记录所有访问日志 ====================
try {
    // 尝试从CDN头检测真实IP
    $realIp = null;
    $cdnHeaders = ['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'];
    foreach ($cdnHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $forwardedIp = trim(explode(',', $_SERVER[$header])[0]);
            if ($forwardedIp !== $clientIp && filter_var($forwardedIp, FILTER_VALIDATE_IP)) {
                $realIp = $forwardedIp;
                break;
            }
        }
    }

    // 解析User-Agent
    // L-11: 截断User-Agent为500字符，防止超长UA导致存储或日志问题
    $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $uaInfo = parseUserAgent($ua);

    $logData = [
        'domain_id'      => $domain ? $domain['id'] : null,
        'ip'             => $clientIp,
        'real_ip'        => $realIp,
        'response_code'  => 200,
        'mac'            => '',
        'browser'        => $uaInfo['browser'],
        'browser_version'=> $uaInfo['browser_version'],
        'os'             => $uaInfo['os'],
        'os_version'     => $uaInfo['os_version'],
        'user_agent'     => $ua,
        'referer'        => $_SERVER['HTTP_REFERER'] ?? '',
        'requested_url'  => $requestedUrl,
        'detection_source' => 'remote_addr',
        'created_at'     => date('Y-m-d H:i:s'),
    ];
    $columns = implode(', ', array_keys($logData));
    $placeholders = implode(', ', array_fill(0, count($logData), '?'));
    db()->execute("INSERT INTO access_logs ({$columns}) VALUES ({$placeholders})", array_values($logData));
} catch (\Throwable $e) {
    // 日志记录失败不影响主流程
}

if ($domain) {
    // 根据域名类型分发
    if ($domain['type'] === 'expired') {
        http_response_code(200);
        include BASEPATH . '/public/expired_template.php';
        exit;
    } elseif ($domain['type'] === 'violation') {
        http_response_code(200);
        include BASEPATH . '/public/violation_template.php';
        exit;
    }
}

// ==================== 3. 域名未在系统中注册，显示默认首页 ====================
$siteName = config('site.name', '灯塔DNS拦截响应平台');
$siteDescription = config('site.description', '域名过期与违规拦截服务');
$contactEmail = config('site.contact_email', 'support@dengtanet.com');

$pageTitle = '灯塔DNS拦截响应平台';
include BASEPATH . '/includes/header.php';
?>

<!-- 默认首页内容 -->
<div class="flex flex-col items-center justify-center min-h-[calc(100vh-12rem)] px-4 py-12">

    <!-- 灯塔图片 -->
    <div class="relative mb-10 fade-in-up" style="animation-delay: 0.1s;">
        <div class="w-48 h-48 md:w-64 md:h-64 relative">
            <div class="absolute inset-0 bg-lighthouse-500/10 rounded-full blur-3xl animate-pulse-slow"></div>
            <img src="/assets/images/lighthouse-hero.png" alt="灯塔" class="w-full h-full relative z-10 object-contain drop-shadow-[0_0_40px_rgba(14,165,233,0.4)]">
        </div>
        <!-- 光束效果 -->
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-80 h-80">
            <div class="absolute inset-0 bg-gradient-to-r from-lighthouse-500/0 via-lighthouse-500/5 to-lighthouse-500/0 rounded-full animate-spin" style="animation-duration: 10s;"></div>
        </div>
        <!-- 脉冲环 -->
        <div class="absolute inset-[-10px] rounded-full border border-lighthouse-500/10 animate-ping" style="animation-duration: 4s;"></div>
    </div>

    <!-- 标题 -->
    <h1 class="text-4xl md:text-5xl font-bold text-center mb-4 fade-in-up" style="animation-delay: 0.2s;">
        <span class="bg-gradient-to-r from-[#00d4aa] via-[#00a8e8] to-[#00d4aa] bg-clip-text text-transparent" id="home-title">
            <?php echo e($siteName); ?>
        </span>
    </h1>
    <p class="text-dark-400 text-lg text-center max-w-2xl mb-8 fade-in-up" style="animation-delay: 0.3s;">
        <?php echo e($siteDescription); ?>
    </p>
</div>

<?php include BASEPATH . '/includes/footer.php'; ?>

<!-- 首页专用脚本 -->
<script>
(function() {
    // 打字机效果 - 标题
    const titleEl = document.getElementById('home-title');
    if (titleEl && typeof LighthouseAnimations !== 'undefined') {
        const originalText = titleEl.textContent.trim();
        titleEl.textContent = '';
        setTimeout(() => {
            LighthouseAnimations.typeWriter(titleEl, originalText, 100);
        }, 300);
    }
})();
</script>
