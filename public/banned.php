<?php
defined('BASEPATH') || define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/includes/functions.php';
initDebug();

// 安装检测
$installLock = BASEPATH . '/storage/install.lock';
if (!file_exists($installLock)) {
    header('Location: /install.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 灯塔DNS拦截响应平台 - IP封禁页面
 * 红色警告主题 + 丰富动画效果
 */

$siteName = config('site.name', '灯塔DNS拦截响应平台');
$contactEmail = config('site.contact_email', 'support@dengtanet.com');

// 获取客户端IP信息
$accessIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$realIp = '';
$detectionSource = '';

// 尝试获取真实IP
$headers = [
    'HTTP_EO_CONNECTING_IP' => '腾讯云 Edge ONE',
    'HTTP_CF_CONNECTING_IP' => 'Cloudflare',
    'HTTP_TRUE_CLIENT_IP' => 'Akamai',
    'HTTP_X_REAL_IP' => 'Nginx Proxy',
    'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For',
    'HTTP_ALI_CDN_REAL_IP' => 'Aliyun CDN',
];

foreach ($headers as $header => $source) {
    if (!empty($_SERVER[$header])) {
        $realIp = explode(',', $_SERVER[$header])[0];
        $realIp = trim($realIp);
        $detectionSource = $source;
        break;
    }
}

// 从数据库查询封禁信息
$banInfo = null;
try {
    $banInfo = db()->queryOne(
        "SELECT * FROM ip_bans WHERE (ip = ? OR real_ip = ?) AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY banned_at DESC LIMIT 1",
        [$accessIp, $accessIp]
    );

    // 如果没找到，尝试用真实IP查询
    if (!$banInfo && $realIp && $realIp !== $accessIp) {
        $banInfo = db()->queryOne(
            "SELECT * FROM ip_bans WHERE (ip = ? OR real_ip = ?) AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY banned_at DESC LIMIT 1",
            [$realIp, $realIp]
        );
    }
} catch (\Throwable $e) {
    // 数据库查询失败
}

// 封禁信息
$banIp = $banInfo['ip'] ?? $accessIp;
$banRealIp = $banInfo['real_ip'] ?? $realIp;
$banReason = $banInfo['reason'] ?? '您的访问行为触发了安全策略，IP地址已被自动封禁。';
$banType = $banInfo['ban_type'] ?? 'auto';
$banTypeText = $banType === 'auto' ? '自动封禁' : '手动封禁';
$bannedAt = $banInfo['banned_at'] ?? date('Y-m-d H:i:s');
$expiresAt = $banInfo['expires_at'] ?? null;
$banDetectionSource = $banInfo['detection_source'] ?? $detectionSource;
$isPermanent = empty($expiresAt);

// 计算剩余封禁时间
$remainingTime = '';
if (!$isPermanent && $expiresAt) {
    $diff = strtotime($expiresAt) - time();
    if ($diff > 0) {
        $days = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        $minutes = floor(($diff % 3600) / 60);
        if ($days > 0) $remainingTime = "{$days}天{$hours}小时{$minutes}分钟";
        elseif ($hours > 0) $remainingTime = "{$hours}小时{$minutes}分钟";
        else $remainingTime = "{$minutes}分钟";
    }
}

$pageTitle = '访问被拒绝';
include BASEPATH . '/includes/header.php';
?>

<!-- 红色扫描线效果背景 -->
<div id="red-scanline-bg" class="fixed inset-0 pointer-events-none z-0 overflow-hidden"></div>

<!-- 粒子背景增强层 -->
<div id="particles-enhanced" class="fixed inset-0 pointer-events-none z-0 overflow-hidden"></div>

<!-- 红色脉冲边框装饰 -->
<div class="fixed inset-0 pointer-events-none z-0">
    <div class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-red-500/30 to-transparent animate-pulse"></div>
    <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-red-500/30 to-transparent animate-pulse" style="animation-delay: 1s;"></div>
    <div class="absolute top-0 bottom-0 left-0 w-px bg-gradient-to-b from-transparent via-red-500/20 to-transparent animate-pulse" style="animation-delay: 0.5s;"></div>
    <div class="absolute top-0 bottom-0 right-0 w-px bg-gradient-to-b from-transparent via-red-500/20 to-transparent animate-pulse" style="animation-delay: 1.5s;"></div>
</div>

<!-- 主内容区域 -->
<div class="flex flex-col items-center justify-center min-h-[calc(100vh-12rem)] px-4 py-12 relative">

    <!-- 封禁警告图标 -->
    <div class="relative mb-8 ban-fade-in-up" style="animation-delay: 0.1s;">
        <!-- 外层脉冲环 -->
        <div class="absolute inset-[-20px] rounded-full border-2 border-red-500/40 animate-ping" style="animation-duration: 1.5s;"></div>
        <div class="absolute inset-[-40px] rounded-full border border-red-500/20 animate-ping" style="animation-duration: 2s; animation-delay: 0.3s;"></div>
        <div class="absolute inset-[-60px] rounded-full border border-red-500/10 animate-ping" style="animation-duration: 3s; animation-delay: 0.6s;"></div>

        <!-- 图标主体 -->
        <div class="relative w-28 h-28 bg-gradient-to-br from-red-600/30 to-red-900/20 rounded-full flex items-center justify-center backdrop-blur-sm border-2 border-red-500/40" id="ban-icon">
            <svg class="w-14 h-14 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
            </svg>
            <div class="absolute inset-0 bg-red-500/30 rounded-full blur-xl animate-pulse"></div>
        </div>
    </div>

    <!-- 标题区域 -->
    <div class="text-center mb-8 ban-fade-in-up" style="animation-delay: 0.2s;">
        <h1 class="text-3xl md:text-5xl font-bold mb-3">
            <span class="gradient-text-danger" id="ban-title">
                访问被拒绝
            </span>
        </h1>
        <p class="text-dark-400 text-base md:text-lg max-w-xl mx-auto">
            您的环境可能存在风险，出于安全原因您已被限制访问
        </p>
    </div>

    <!-- 封禁信息卡片 -->
    <div class="rounded-2xl p-8 max-w-xl w-full mt-4 ban-fade-in-up" style="animation-delay: 0.3s; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(20px); border: 1px solid rgba(239, 68, 68, 0.3);" id="ban-card">
        <!-- 状态标签 -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-2">
                <span class="relative flex h-3 w-3">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-600"></span>
                </span>
                <span class="text-red-400 text-sm font-medium"><?php echo $isPermanent ? '永久封禁' : '临时封禁'; ?></span>
            </div>
            <span class="text-dark-500 text-xs font-mono" id="current-time"></span>
        </div>

        <!-- 封禁详情 -->
        <div class="space-y-4">
            <!-- IP地址 -->
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-red-500/60 group-hover:text-red-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                    </svg>
                    <span>IP地址</span>
                </span>
                <span class="text-red-400 font-mono text-sm font-medium"><?php echo e($banIp); ?></span>
            </div>

            <!-- 真实IP -->
            <?php if ($banRealIp && $banRealIp !== $banIp): ?>
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-red-500/60 group-hover:text-red-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <span>真实IP</span>
                </span>
                <span class="text-orange-400 font-mono text-sm font-medium"><?php echo e($banRealIp); ?></span>
            </div>
            <?php endif; ?>

            <!-- 封禁原因 -->
            <div class="py-3 border-b border-dark-800/50">
                <span class="text-dark-400 text-sm flex items-center space-x-2 mb-2">
                    <svg class="w-4 h-4 text-red-500/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <span>封禁原因</span>
                </span>
                <span class="text-red-300 text-sm leading-relaxed block pl-6"><?php echo e($banReason); ?></span>
            </div>

            <!-- 封禁时间 -->
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-red-500/60 group-hover:text-red-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>封禁时间</span>
                </span>
                <span class="text-dark-300 text-sm"><?php echo e($bannedAt); ?></span>
            </div>

            <!-- 封禁类型 -->
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-red-500/60 group-hover:text-red-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    <span>封禁类型</span>
                </span>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?php echo $isPermanent ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20'; ?>">
                    <?php echo $isPermanent ? '永久封禁' : '临时封禁'; ?>
                    <span class="ml-1.5 text-dark-500">(<?php echo e($banTypeText); ?>)</span>
                </span>
            </div>

            <!-- 解封时间（临时封禁） -->
            <?php if (!$isPermanent && $expiresAt): ?>
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-amber-500/60 group-hover:text-amber-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>解封时间</span>
                </span>
                <span class="text-amber-400 text-sm font-medium"><?php echo e($expiresAt); ?></span>
            </div>
            <?php if ($remainingTime): ?>
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50">
                <span class="text-dark-400 text-sm">剩余时间</span>
                <span class="text-amber-400 text-sm font-medium animate-pulse" id="remaining-time"><?php echo e($remainingTime); ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- 检测来源 -->
            <?php if ($banDetectionSource): ?>
            <div class="flex items-center justify-between py-3 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-red-500/60 group-hover:text-red-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                    </svg>
                    <span>检测来源</span>
                </span>
                <span class="text-dark-300 text-sm"><?php echo e($banDetectionSource); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 联系信息 -->
    <div class="mt-8 max-w-xl w-full ban-fade-in-up" style="animation-delay: 0.5s;">
        <div class="rounded-xl p-5" style="background: rgba(30, 41, 59, 0.4); backdrop-filter: blur(12px); border: 1px solid rgba(51, 65, 85, 0.3);">
            <div class="flex items-start space-x-3">
                <svg class="w-5 h-5 text-red-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="text-dark-300 text-sm leading-relaxed mb-3">
                        如有疑问，请联系管理员进行申辩。请在邮件中附上您的IP地址和相关信息。
                    </p>
                    <div class="space-y-2">
                        <div class="flex items-center space-x-2 text-sm">
                            <svg class="w-4 h-4 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <a href="mailto:<?php echo e($contactEmail); ?>?subject=<?php echo urlencode('IP封禁申辩 - ' . $banIp); ?>"
                               class="text-red-400 hover:text-red-300 transition-colors">
                                <?php echo e($contactEmail); ?>
                            </a>
                        </div>
                        <div class="flex items-center space-x-2 text-sm">
                            <svg class="w-4 h-4 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                            </svg>
                            <span class="text-dark-400">IP: <?php echo e($banIp); ?><?php echo $banRealIp && $banRealIp !== $banIp ? ' / ' . e($banRealIp) : ''; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 联系按钮 -->
            <div class="mt-4">
                <a href="mailto:<?php echo e($contactEmail); ?>?subject=<?php echo urlencode('IP封禁申辩 - ' . $banIp); ?>&body=<?php echo urlencode("IP地址: {$banIp}\n真实IP: {$banRealIp}\n封禁时间: {$bannedAt}\n\n请说明申辩理由:"); ?>"
                   class="appeal-contact-btn inline-flex items-center justify-center space-x-2 w-full py-3 px-6 rounded-xl font-medium text-sm transition-all duration-200"
                   style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1)); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5;">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <span>联系管理员申辩</span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php include BASEPATH . '/includes/footer.php'; ?>

<!-- IP封禁页面专用样式 -->
<style>
    /* 红色扫描线背景 */
    #red-scanline-bg::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, rgba(239, 68, 68, 0.3), transparent);
        animation: redScanline 3s linear infinite;
    }

    #red-scanline-bg::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: repeating-linear-gradient(
            0deg,
            transparent,
            transparent 2px,
            rgba(239, 68, 68, 0.02) 2px,
            rgba(239, 68, 68, 0.02) 4px
        );
        pointer-events: none;
    }

    @keyframes redScanline {
        0% { top: 0; }
        100% { top: 100%; }
    }

    /* 危险渐变文字 */
    .gradient-text-danger {
        background: linear-gradient(90deg, #ef4444, #f97316, #ef4444, #dc2626);
        background-size: 300% auto;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        animation: dangerGradient 4s linear infinite;
    }

    @keyframes dangerGradient {
        0% { background-position: 0% center; }
        100% { background-position: 300% center; }
    }

    /* 呼吸灯边框 */
    @keyframes borderBreathing {
        0%, 100% {
            border-color: rgba(239, 68, 68, 0.2);
            box-shadow: 0 0 15px rgba(239, 68, 68, 0.1), 0 0 30px rgba(239, 68, 68, 0.05);
        }
        50% {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 25px rgba(239, 68, 68, 0.2), 0 0 50px rgba(239, 68, 68, 0.1);
        }
    }

    /* 渐入动画 */
    .ban-fade-in-up {
        opacity: 0;
        animation: banFadeInUp 0.8s ease-out forwards;
    }

    @keyframes banFadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* 警告闪烁动画 */
    @keyframes warningFlash {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.6; }
    }

    #ban-icon {
        animation: warningFlash 2s ease-in-out infinite;
    }

    /* 红色脉冲边框 */
    @keyframes redPulseBorder {
        0%, 100% {
            box-shadow: 0 0 5px rgba(239, 68, 68, 0.2), inset 0 0 5px rgba(239, 68, 68, 0.05);
        }
        50% {
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.4), inset 0 0 10px rgba(239, 68, 68, 0.1);
        }
    }

    /* 全局红色扫描线覆盖 */
    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, rgba(239, 68, 68, 0.15), transparent);
        animation: redScanline 5s linear infinite;
        pointer-events: none;
        z-index: 100;
    }

    .appeal-contact-btn:hover {
        background: linear-gradient(135deg, rgba(239, 68, 68, 0.3), rgba(239, 68, 68, 0.15)) !important;
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.2);
    }

    /* 红色粒子颜色覆盖 */
    #particles-enhanced .particle {
        background: rgba(239, 68, 68, 0.3) !important;
    }
</style>

<!-- IP封禁页面脚本 -->
<script>
(function() {
    // 当前时间显示
    function updateCurrentTime() {
        const el = document.getElementById('current-time');
        if (el) {
            const now = new Date();
            el.textContent = now.toLocaleString('zh-CN', {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
        }
    }
    setInterval(updateCurrentTime, 1000);
    updateCurrentTime();

    // 打字机效果 - 标题
    const titleEl = document.getElementById('ban-title');
    if (titleEl && typeof LighthouseAnimations !== 'undefined') {
        const originalText = titleEl.textContent.trim();
        titleEl.textContent = '';
        titleEl.style.webkitTextFillColor = 'transparent';
        setTimeout(() => {
            LighthouseAnimations.typeWriter(titleEl, originalText, 100);
        }, 500);
    }

    // 增强粒子效果（红色主题）
    if (typeof LighthouseAnimations !== 'undefined') {
        LighthouseAnimations.initParticles('particles-enhanced', 40);
    }

    // 按钮波纹效果
    document.querySelectorAll('a, button').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (typeof LighthouseAnimations !== 'undefined') {
                LighthouseAnimations.ripple(e, this);
            }
        });
    });

    // 封禁卡片鼠标跟踪发光效果
    const banCard = document.getElementById('ban-card');
    if (banCard) {
        banCard.addEventListener('mousemove', function(e) {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            this.style.background = `radial-gradient(circle at ${x}px ${y}px, rgba(239, 68, 68, 0.08), rgba(15, 23, 42, 0.6) 50%)`;
        });
        banCard.addEventListener('mouseleave', function() {
            this.style.background = 'rgba(15, 23, 42, 0.6)';
        });
    }

    // 震动效果（页面加载时）
    setTimeout(() => {
        const icon = document.getElementById('ban-icon');
        if (icon && typeof LighthouseAnimations !== 'undefined') {
            LighthouseAnimations.shake(icon);
        }
    }, 1500);
})();
</script>
