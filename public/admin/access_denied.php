<?php
/**
 * 灯塔DNS拦截响应平台 - 无权访问页面
 *
 * 非白名单域名访问 /admin/ 后台时展示此页面，
 * 避免直接返回 404，给用户明确的拒绝提示。
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>无权访问 - 灯塔DNS拦截响应平台</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        dark: {
                            950: '#020617',
                            900: '#0f172a',
                            800: '#1e293b',
                            700: '#334155',
                            600: '#475569',
                            500: '#64748b',
                            400: '#94a3b8',
                            300: '#cbd5e1',
                        },
                        lighthouse: {
                            500: '#0ea5e9',
                            600: '#0284c7',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #020617;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            overflow: hidden;
        }
        .grid-bg {
            background-image:
                linear-gradient(rgba(14, 165, 233, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(14, 165, 233, 0.03) 1px, transparent 1px);
            background-size: 60px 60px;
        }

        /* ===== 粒子飘散动画 ===== */
        .particle {
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
            animation: particleDrift linear infinite;
        }
        @keyframes particleDrift {
            0% {
                opacity: 0;
                transform: translate(0, 0) scale(0);
            }
            10% {
                opacity: 0.8;
            }
            90% {
                opacity: 0.1;
            }
            100% {
                opacity: 0;
                transform: translate(var(--dx), var(--dy)) scale(1.2);
            }
        }

        /* ===== 扫描线 ===== */
        .scan-line {
            position: fixed;
            left: 0;
            width: 100%;
            height: 2px;
            pointer-events: none;
            background: linear-gradient(90deg,
                transparent 0%,
                rgba(239, 68, 68, 0.15) 20%,
                rgba(239, 68, 68, 0.4) 50%,
                rgba(239, 68, 68, 0.15) 80%,
                transparent 100%);
            box-shadow: 0 0 8px rgba(239, 68, 68, 0.3);
            animation: scanDown 4s linear infinite;
        }
        @keyframes scanDown {
            0% { top: -2px; }
            100% { top: 100%; }
        }

        /* ===== 卡片毛玻璃浮动 ===== */
        .card {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            border: 1px solid rgba(51, 65, 85, 0.45);
            box-shadow: 0 0 40px rgba(239, 68, 68, 0.08),
                        0 0 80px rgba(14, 165, 233, 0.04),
                        0 25px 50px rgba(0, 0, 0, 0.4);
            position: relative;
            overflow: hidden;
        }
        .card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                105deg,
                transparent 40%,
                rgba(239, 68, 68, 0.04) 45%,
                rgba(239, 68, 68, 0.08) 50%,
                rgba(239, 68, 68, 0.04) 55%,
                transparent 60%
            );
            animation: lightSweep 5s ease-in-out infinite;
        }
        @keyframes lightSweep {
            0% { transform: translateX(-100%) rotate(105deg); }
            100% { transform: translateX(100%) rotate(105deg); }
        }
        .card-content {
            position: relative;
            z-index: 1;
        }

        .fade-in-up {
            animation: fadeInUp 0.8s ease-out;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .float-card {
            animation: floatCard 6s ease-in-out infinite;
        }
        @keyframes floatCard {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }

        /* ===== 图标动画 ===== */
        .icon-ring {
            background: rgba(239, 68, 68, 0.08);
            border: 2px solid rgba(239, 68, 68, 0.25);
            box-shadow:
                0 0 20px rgba(239, 68, 68, 0.15),
                0 0 40px rgba(239, 68, 68, 0.06),
                inset 0 0 20px rgba(239, 68, 68, 0.05);
            animation: iconBreath 2.5s ease-in-out infinite;
        }
        @keyframes iconBreath {
            0%, 100% {
                box-shadow:
                    0 0 20px rgba(239, 68, 68, 0.15),
                    0 0 40px rgba(239, 68, 68, 0.06),
                    inset 0 0 20px rgba(239, 68, 68, 0.05);
            }
            50% {
                box-shadow:
                    0 0 35px rgba(239, 68, 68, 0.3),
                    0 0 70px rgba(239, 68, 68, 0.12),
                    inset 0 0 30px rgba(239, 68, 68, 0.1);
            }
        }
        .icon-ring:hover {
            animation: iconGlow 1s ease-in-out infinite;
            border-color: rgba(239, 68, 68, 0.5);
            transform: scale(1.05);
            transition: transform 0.3s ease;
        }
        @keyframes iconGlow {
            0%, 100% {
                box-shadow:
                    0 0 25px rgba(239, 68, 68, 0.25),
                    0 0 60px rgba(239, 68, 68, 0.1),
                    inset 0 0 20px rgba(239, 68, 68, 0.08);
            }
            50% {
                box-shadow:
                    0 0 45px rgba(239, 68, 68, 0.45),
                    0 0 90px rgba(239, 68, 68, 0.2),
                    inset 0 0 35px rgba(239, 68, 68, 0.15);
            }
        }
        .icon-inner {
            animation: iconPulse 3s ease-in-out infinite;
        }
        @keyframes iconPulse {
            0%, 100% { opacity: 0.85; }
            50% { opacity: 1; }
        }

        /* ===== 标题发光 ===== */
        .title-glow {
            text-shadow: 0 0 40px rgba(239, 68, 68, 0.4),
                         0 0 80px rgba(239, 68, 68, 0.15);
            letter-spacing: 0.3em;
        }

        /* ===== 光晕球 ===== */
        .orb {
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
            animation: orbFloat ease-in-out infinite;
        }
        @keyframes orbFloat {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.4; }
            25% { transform: translate(30px, -40px) scale(1.15); opacity: 0.6; }
            50% { transform: translate(-20px, -10px) scale(0.9); opacity: 0.35; }
            75% { transform: translate(-35px, 25px) scale(1.1); opacity: 0.55; }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center antialiased">
    <!-- 网格背景 -->
    <div class="fixed inset-0 grid-bg pointer-events-none"></div>

    <!-- 光晕球 -->
    <div class="orb w-[600px] h-[600px] bg-red-500/8 -top-40 -left-40" style="animation-delay: 0s; animation-duration: 12s;"></div>
    <div class="orb w-[500px] h-[500px] bg-red-600/6 top-1/3 -right-32" style="animation-delay: -4s; animation-duration: 15s;"></div>
    <div class="orb w-[400px] h-[400px] bg-lighthouse-500/5 bottom-0 left-1/4" style="animation-delay: -8s; animation-duration: 10s;"></div>
    <div class="orb w-[350px] h-[350px] bg-red-700/5 bottom-10 right-10" style="animation-delay: -2s; animation-duration: 14s;"></div>

    <!-- 扫描线 -->
    <div class="scan-line" style="animation-delay: 0s;"></div>
    <div class="scan-line" style="animation-delay: 2s;"></div>

    <!-- 粒子容器 -->
    <div id="particles-container"></div>

    <div class="relative z-10 w-full max-w-lg px-4">
        <!-- 图标 -->
        <div class="text-center mb-6 fade-in-up">
            <div class="inline-flex items-center justify-center w-24 h-24 icon-ring rounded-full">
                <div class="icon-inner">
                    <svg class="w-12 h-12 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="filter: drop-shadow(0 0 8px rgba(245, 31, 31, 0.5));">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M12 9v2m0 4h.01"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- 卡片 -->
        <div class="float-card fade-in-up" style="animation-delay: 0.15s;">
            <div class="card rounded-2xl p-8 text-center">
                <div class="card-content">
                    <h1 class="text-2xl font-bold text-white mb-3 title-glow">无 权 访 问</h1>
                    <p class="text-white/80 text-sm mb-6 leading-relaxed">
                        访问域名未授权
                    </p>
                    <div class="inline-flex items-center space-x-2 text-white/50 text-xs bg-dark-800/50 rounded-lg px-4 py-2 mb-6">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>若有疑问 请联系系统管理员</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 底部信息 -->
        <div class="text-center mt-6 fade-in-up" style="animation-delay: 0.3s;">
            <p class="text-white/40 text-xs">
                &copy; <?php echo date('Y'); ?> 灯塔网络科技（甘肃）有限公司
            </p>
        </div>
    </div>
<script>
(function() {
    var container = document.getElementById('particles-container');
    var count = 35;
    for (var i = 0; i < count; i++) {
        var size = Math.floor(Math.random() * 5) + 2;
        var left = Math.random() * 100;
        var top = Math.random() * 100;
        var duration = Math.floor(Math.random() * 11) + 6;
        var delay = Math.random() * 10;
        var dx = Math.floor(Math.random() * 401) - 200;
        var dy = Math.floor(Math.random() * 401) - 300;
        var r = Math.floor(Math.random() * 76) + 180;
        var g = Math.floor(Math.random() * 61) + 40;
        var b = Math.floor(Math.random() * 41) + 40;
        var div = document.createElement('div');
        div.className = 'particle';
        div.style.cssText =
            'width:' + size + 'px;' +
            'height:' + size + 'px;' +
            'left:' + left + '%;' +
            'top:' + top + '%;' +
            'background:radial-gradient(circle, rgba(' + r + ',' + g + ',' + b + ',0.45), transparent);' +
            'box-shadow:0 0 ' + (size * 2) + 'px rgba(239,68,68,0.4);' +
            '--dx:' + dx + 'px;' +
            '--dy:' + dy + 'px;' +
            'animation-duration:' + duration + 's;' +
            'animation-delay:' + delay + 's';
        container.appendChild(div);
    }
})();
</script>
</body>
</html>
