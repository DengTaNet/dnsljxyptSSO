<?php
/**
 * 灯塔DNS拦截响应平台 - 域名过期页面
 * 深色科技风格 + 丰富动画效果
 */

$siteName = config('site.name', '灯塔DNS拦截响应平台');
$contactEmail = config('site.contact_email', 'support@dengtanet.com');

$domain = $domain ?? null;

// 安全获取域名参数（防止XSS和注入）
$rawDomain = $_GET['domain'] ?? '';
$safeDomain = preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $rawDomain) ? $rawDomain : '';

// 获取域名信息（从 index.php 传入或 URL 参数获取）
$domainName = $domain['domain_name'] ?? $safeDomain ?? $_SERVER['HTTP_HOST'] ?? '';
$expireDate = $domain['expire_date'] ?? null;
$deleteDate = $domain['delete_date'] ?? null;
$customerEmail = $domain['customer_email'] ?? '';

// 如果有 URL 参数域名，尝试从数据库查询
if (empty($domain) && !empty($safeDomain)) {
    try {
        $domainData = db()->queryOne(
            "SELECT * FROM domains WHERE domain_name = ? AND status = 'active' AND type = 'expired'",
            [$safeDomain]
        );
        if ($domainData) {
            $domainName = $domainData['domain_name'];
            $expireDate = $domainData['expire_date'];
            $deleteDate = $domainData['delete_date'];
            $customerEmail = $domainData['customer_email'];
        }
    } catch (\Throwable $e) {
        error_log('[expired_template] 数据库查询失败: ' . $e->getMessage());
    }
}

// 计算删除倒计时目标时间
$deleteTimestamp = null;
if ($deleteDate) {
    $deleteTimestamp = strtotime($deleteDate . ' 23:59:59');
} elseif ($expireDate) {
    // 默认过期后7天删除
    $deleteTimestamp = strtotime($expireDate . ' 23:59:59') + (7 * 86400);
}

// 计算已过期天数
$expiredDays = 0;
if ($expireDate) {
    $expiredDays = (int) ((time() - strtotime($expireDate)) / 86400);
}

// 判断是否已超过删除期限
$isPastDelete = $deleteTimestamp && $deleteTimestamp <= time();

$pageTitle = '域名已过期';
include BASEPATH . '/includes/header.php';
?>

<!-- 灯塔光束扫描背景 -->
<div id="lighthouse-beam" class="fixed inset-0 pointer-events-none z-0 overflow-hidden"></div>

<!-- 粒子背景增强层 -->
<div id="particles-enhanced" class="fixed inset-0 pointer-events-none z-0 overflow-hidden"></div>

<!-- 数据流动画装饰 -->
<div id="data-flow-bg" class="fixed inset-0 pointer-events-none z-0 overflow-hidden opacity-20"></div>

<!-- 主内容区域 -->
<div class="flex flex-col items-center justify-center min-h-[calc(100vh-12rem)] px-4 py-12 relative">

    <!-- 警告脉冲动画图标 -->
    <div class="relative mb-8 fade-in-up" style="animation-delay: 0.1s;">
        <!-- 外层脉冲环 -->
        <div class="absolute inset-[-20px] rounded-full border-2 border-amber-400/30 animate-ping" style="animation-duration: 2s;"></div>
        <div class="absolute inset-[-40px] rounded-full border border-amber-400/10 animate-ping" style="animation-duration: 3s; animation-delay: 0.5s;"></div>
        <div class="absolute inset-[-60px] rounded-full border border-amber-400/5 animate-ping" style="animation-duration: 4s; animation-delay: 1s;"></div>

        <!-- 图标主体 -->
        <div class="relative w-28 h-28 bg-gradient-to-br from-amber-500/20 to-orange-500/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-amber-500/30">
            <svg class="w-14 h-14 text-amber-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <!-- 发光效果 -->
            <div class="absolute inset-0 bg-amber-400/20 rounded-full blur-xl animate-pulse-slow"></div>
        </div>
    </div>

    <!-- 标题区域 -->
    <div class="text-center mb-8 fade-in-up" style="animation-delay: 0.2s;">
        <h1 class="text-3xl md:text-5xl font-bold mb-3">
            <span class="bg-gradient-to-r from-amber-400 via-yellow-300 to-orange-400 bg-clip-text text-transparent" id="warning-title">
                域 名 待 续 期
            </span>
        </h1>
        <p class="text-dark-400 text-base md:text-lg max-w-xl mx-auto">
           该域名未及时续期 已被暂时劫持至此页面停靠
        </p>
    </div>

    <!-- 域名信息卡片 -->
    <div class="glass rounded-2xl p-8 max-w-xl w-full mt-4 fade-in-up glow-border" style="animation-delay: 0.3s;">
        <!-- 状态标签 -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-2">
                <span class="relative flex h-3 w-3">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500"></span>
                </span>
                <span class="<?php echo $isPastDelete ? 'text-red-400' : 'text-amber-400'; ?> text-sm font-medium"><?php echo $isPastDelete ? '正在删除队列' : '已过期'; ?></span>
            </div>
            <span class="text-dark-500 text-xs font-mono" id="current-time"></span>
        </div>

        <!-- 域名信息 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-dark-500 group-hover:text-lighthouse-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                    </svg>
                    <span>当前域名</span>
                </span>
                <span class="text-white font-mono text-sm font-medium glow-text"><?php echo e($domainName); ?></span>
            </div>

            <?php if ($expireDate): ?>
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-dark-500 group-hover:text-amber-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>到期时间</span>
                </span>
                <span class="text-amber-400 font-semibold text-sm"><?php echo e($expireDate); ?> 23:59:59</span>
            </div>

            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-dark-500 group-hover:text-amber-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>已过期</span>
                </span>
                <span class="text-amber-400 font-semibold text-sm"><?php echo $expiredDays; ?> 天</span>
            </div>
            <?php endif; ?>

            <?php if ($deleteDate): ?>
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-dark-500 group-hover:text-red-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span>预计删除日期</span>
                </span>
                <span class="text-red-400 font-semibold text-sm"><?php echo e($deleteDate); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- 翻转倒计时组件 -->
        <?php if ($deleteTimestamp && $deleteTimestamp > time()): ?>
        <div class="mt-8">
            <div class="text-center mb-4">
                <span class="text-dark-300 text-sm font-medium">域名将在到期后 7 天自动删除</span>
            </div>

            <div class="flex items-center justify-center space-x-3 md:space-x-4" id="countdown-container">
                <!-- 天 -->
                <div class="flip-card-wrapper">
                    <div class="flip-card" id="flip-days">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <span class="flip-value">00</span>
                            </div>
                            <div class="flip-card-back">
                                <span class="flip-value">00</span>
                            </div>
                        </div>
                        <div class="flip-label">天</div>
                    </div>
                </div>

                <span class="text-amber-400/60 text-2xl font-bold animate-pulse">:</span>

                <!-- 时 -->
                <div class="flip-card-wrapper">
                    <div class="flip-card" id="flip-hours">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <span class="flip-value">00</span>
                            </div>
                            <div class="flip-card-back">
                                <span class="flip-value">00</span>
                            </div>
                        </div>
                        <div class="flip-label">时</div>
                    </div>
                </div>

                <span class="text-amber-400/60 text-2xl font-bold animate-pulse">:</span>

                <!-- 分 -->
                <div class="flip-card-wrapper">
                    <div class="flip-card" id="flip-minutes">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <span class="flip-value">00</span>
                            </div>
                            <div class="flip-card-back">
                                <span class="flip-value">00</span>
                            </div>
                        </div>
                        <div class="flip-label">分</div>
                    </div>
                </div>

                <span class="text-amber-400/60 text-2xl font-bold animate-pulse">:</span>

                <!-- 秒 -->
                <div class="flip-card-wrapper">
                    <div class="flip-card" id="flip-seconds">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <span class="flip-value">00</span>
                            </div>
                            <div class="flip-card-back">
                                <span class="flip-value">00</span>
                            </div>
                        </div>
                        <div class="flip-label">秒</div>
                    </div>
                </div>
            </div>

            <!-- 进度条 -->
            <div class="mt-6">
                <div class="flex justify-between text-xs text-dark-500 mb-1">
                    <span>删除进度</span>
                    <span id="progress-percent">0%</span>
                </div>
                <div class="w-full h-1.5 bg-dark-800 rounded-full overflow-hidden">
                    <div id="progress-bar" class="h-full bg-gradient-to-r from-amber-500 to-red-500 rounded-full transition-all duration-1000" style="width: 0%;"></div>
                </div>
            </div>
        </div>
        <?php elseif ($deleteTimestamp && $deleteTimestamp <= time()): ?>
        <div class="mt-8 text-center">
            <div class="inline-flex items-center space-x-2 bg-red-500/10 border border-red-500/30 rounded-xl px-6 py-3">
                <svg class="w-5 h-5 text-red-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
                <span class="text-red-400 font-medium">域名正在删除队列中 即将被释放</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- 操作按钮 -->
        <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <a href="mailto:<?php echo e($contactEmail); ?>?subject=<?php echo urlencode('域名续费咨询 - ' . $domainName); ?>"
               class="btn-primary flex items-center justify-center space-x-2 py-3 px-6 text-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <span>联系我们</span>
            </a>
            <a href="https://dns.dengtanet.com/" target="_blank"
                    class="btn-secondary flex items-center justify-center space-x-2 py-3 px-6">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>前往续费</span>
            </a>
        </div>
    </div>

    <!-- 提示信息 -->
    <div class="mt-8 max-w-xl w-full fade-in-up" style="animation-delay: 0.5s;">
        <div class="glass-light rounded-xl p-5">
            <div class="flex items-start space-x-3">
                <svg class="w-5 h-5 text-amber-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="text-dark-300 text-sm leading-relaxed">
                        如果您是该二级域名的所有者，请尽快前往灯塔DNS操作。域名到期后<?php echo $deleteDate ? '' : '7'; ?>天内续期可恢复，超过期限后域名将被释放并可能被他人抢注。
                    </p>
                    <p class="text-dark-500 text-xs mt-2">
                        联系邮箱：<?php echo e($contactEmail); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- 装饰性数据流动画 -->
    <div class="fixed bottom-0 left-0 right-0 h-32 pointer-events-none overflow-hidden opacity-30">
        <div class="data-flow-line" style="animation-delay: 0s;"></div>
        <div class="data-flow-line" style="animation-delay: 1s;"></div>
        <div class="data-flow-line" style="animation-delay: 2s;"></div>
        <div class="data-flow-line" style="animation-delay: 3s;"></div>
        <div class="data-flow-line" style="animation-delay: 4s;"></div>
    </div>
</div>

<?php include BASEPATH . '/includes/footer.php'; ?>

<!-- 翻转倒计时专用样式 -->
<style>
    /* 翻转卡片样式 */
    .flip-card-wrapper {
        perspective: 300px;
    }

    .flip-card {
        width: 72px;
        height: 88px;
        position: relative;
    }

    @media (min-width: 768px) {
        .flip-card {
            width: 88px;
            height: 100px;
        }
    }

    .flip-card-inner {
        width: 100%;
        height: 100%;
        position: relative;
    }

    .flip-card-front,
    .flip-card-back {
        position: absolute;
        width: 100%;
        height: 100%;
        background: linear-gradient(180deg, #1e293b 0%, #0f172a 49.9%, #0a0f1a 50.1%, #050810 100%);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(245, 158, 11, 0.2);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.05);
        overflow: hidden;
    }

    .flip-card-front::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        top: 50%;
        height: 1px;
        background: rgba(0, 0, 0, 0.4);
        box-shadow: 0 1px 0 rgba(255, 255, 255, 0.03);
    }

    .flip-value {
        font-family: 'JetBrains Mono', monospace;
        font-size: 2rem;
        font-weight: 700;
        color: #fbbf24;
        text-shadow: 0 0 20px rgba(251, 191, 36, 0.3);
    }

    @media (min-width: 768px) {
        .flip-value {
            font-size: 2.5rem;
        }
    }

    .flip-label {
        text-align: center;
        margin-top: 8px;
        font-size: 0.75rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* 翻转动画 */
    .flip-card.flipping .flip-card-front {
        animation: flipTop 0.6s ease-in forwards;
        transform-origin: bottom;
    }

    .flip-card.flipping .flip-card-back {
        animation: flipBottom 0.6s ease-out forwards;
        transform-origin: top;
        transform: rotateX(90deg);
    }

    @keyframes flipTop {
        0% { transform: rotateX(0deg); }
        100% { transform: rotateX(-90deg); }
    }

    @keyframes flipBottom {
        0% { transform: rotateX(90deg); }
        100% { transform: rotateX(0deg); }
    }

    /* 灯塔光束扫描 */
    #lighthouse-beam {
        background: conic-gradient(
            from 0deg at 50% 0%,
            transparent 0deg,
            rgba(0, 212, 170, 0.03) 10deg,
            transparent 20deg,
            transparent 180deg,
            rgba(0, 168, 232, 0.03) 190deg,
            transparent 200deg
        );
        animation: beamRotate 15s linear infinite;
    }

    @keyframes beamRotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    /* 数据流动画线条 */
    .data-flow-line {
        position: absolute;
        height: 1px;
        width: 100%;
        background: linear-gradient(90deg,
            transparent 0%,
            rgba(0, 212, 170, 0.3) 20%,
            rgba(0, 168, 232, 0.5) 50%,
            rgba(0, 212, 170, 0.3) 80%,
            transparent 100%
        );
        animation: dataFlowMove 6s linear infinite;
    }

    @keyframes dataFlowMove {
        0% { transform: translateY(100%); opacity: 0; }
        10% { opacity: 1; }
        90% { opacity: 1; }
        100% { transform: translateY(-200%); opacity: 0; }
    }

    /* 呼吸灯效果 */
    @keyframes breathingGlow {
        0%, 100% { box-shadow: 0 0 10px rgba(245, 158, 11, 0.2), 0 0 20px rgba(245, 158, 11, 0.1); }
        50% { box-shadow: 0 0 20px rgba(245, 158, 11, 0.4), 0 0 40px rgba(245, 158, 11, 0.2); }
    }

    .glass {
        animation: breathingGlow 4s ease-in-out infinite;
    }

    /* 渐入动画 */
    .fade-in-up {
        opacity: 0;
        animation: fadeInUp 0.8s ease-out forwards;
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* 扫描线效果 */
    .scanline-overlay::after {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, rgba(245, 158, 11, 0.15), transparent);
        animation: scanlineMove 4s linear infinite;
        pointer-events: none;
        z-index: 100;
    }

    @keyframes scanlineMove {
        0% { top: 0; }
        100% { top: 100%; }
    }

    body {
        position: relative;
    }

    body::after {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, rgba(245, 158, 11, 0.1), transparent);
        animation: scanlineMove 5s linear infinite;
        pointer-events: none;
        z-index: 100;
    }
</style>

<!-- 倒计时脚本 -->
<script>
(function() {
    // 删除目标时间戳（来自PHP）
    const deleteTimestamp = <?php echo (int)$deleteTimestamp; ?>;
    const expireTimestamp = <?php echo $expireDate ? (int)strtotime($expireDate . ' 23:59:59') : 0; ?>;

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

    // 翻转倒计时
    class FlipCountdown {
        constructor() {
            this.prevValues = { days: '', hours: '', minutes: '', seconds: '' };
        }

        update(targetTimestamp) {
            const now = Math.floor(Date.now() / 1000);
            let diff = targetTimestamp - now;

            if (diff <= 0) {
                this.setValues('00', '00', '00', '00');
                return false;
            }

            const days = Math.floor(diff / 86400);
            diff %= 86400;
            const hours = Math.floor(diff / 3600);
            diff %= 3600;
            const minutes = Math.floor(diff / 60);
            const seconds = diff % 60;

            const d = String(days).padStart(2, '0');
            const h = String(hours).padStart(2, '0');
            const m = String(minutes).padStart(2, '0');
            const s = String(seconds).padStart(2, '0');

            this.setValues(d, h, m, s);

            // 更新进度条
            if (expireTimestamp && targetTimestamp > expireTimestamp) {
                const totalDuration = targetTimestamp - expireTimestamp;
                const elapsed = now - expireTimestamp;
                const percent = Math.min(100, Math.max(0, (elapsed / totalDuration) * 100));
                const progressBar = document.getElementById('progress-bar');
                const progressPercent = document.getElementById('progress-percent');
                if (progressBar) progressBar.style.width = percent.toFixed(1) + '%';
                if (progressPercent) progressPercent.textContent = percent.toFixed(1) + '%';
            }

            return true;
        }

        setValues(d, h, m, s) {
            this.flipUnit('flip-days', d, this.prevValues.days);
            this.flipUnit('flip-hours', h, this.prevValues.hours);
            this.flipUnit('flip-minutes', m, this.prevValues.minutes);
            this.flipUnit('flip-seconds', s, this.prevValues.seconds);
            this.prevValues = { days: d, hours: h, minutes: m, seconds: s };
        }

        flipUnit(id, newValue, oldValue) {
            const el = document.getElementById(id);
            if (!el) return;
            if (newValue === oldValue) return;

            const fronts = el.querySelectorAll('.flip-card-front .flip-value');
            const backs = el.querySelectorAll('.flip-card-back .flip-value');

            // 更新背面为新值
            backs.forEach(b => b.textContent = newValue);

            // 触发翻转动画
            el.classList.remove('flipping');
            void el.offsetWidth; // 强制重绘
            el.classList.add('flipping');

            // 动画结束后更新正面
            setTimeout(() => {
                fronts.forEach(f => f.textContent = newValue);
                el.classList.remove('flipping');
            }, 600);
        }
    }

    // 初始化倒计时
    if (deleteTimestamp > 0) {
        const countdown = new FlipCountdown();
        countdown.update(deleteTimestamp);
        setInterval(() => countdown.update(deleteTimestamp), 1000);
    }

    // 打字机效果 - 标题
    const titleEl = document.getElementById('warning-title');
    if (titleEl && typeof LighthouseAnimations !== 'undefined') {
        const originalText = titleEl.textContent.trim();
        titleEl.textContent = '';
        setTimeout(() => {
            LighthouseAnimations.typeWriter(titleEl, originalText, 80);
        }, 500);
    }

    // 增强粒子效果
    if (typeof LighthouseAnimations !== 'undefined') {
        LighthouseAnimations.initParticles('particles-enhanced', 50);
    }

    // 按钮波纹效果
    document.querySelectorAll('.btn-primary, .btn-secondary').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (typeof LighthouseAnimations !== 'undefined') {
                LighthouseAnimations.ripple(e, this);
            }
        });
    });
})();
</script>
