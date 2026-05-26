<?php
/**
 * 灯塔DNS拦截响应平台 - 公共头部模板
 * 深色科技风格
 */
if (!isset($pageTitle)) $pageTitle = '灯塔DNS拦截响应平台';
if (!isset($siteName)) $siteName = '灯塔DNS拦截响应平台';

// 动态Logo路径
$siteLogo = getSiteLogo();

// 动态Favicon
$faviconPath = getFavicon();

// M-12: 安全响应头（等保2.0三级要求）
if (!headers_sent()) {
    // 从配置读取安全响应头
    $securityConfig = [];
    try {
        $configPath = defined('BASEPATH') ? BASEPATH . '/config/config.php' : dirname(__DIR__) . '/config/config.php';
        if (file_exists($configPath)) {
            $cfg = require $configPath;
            $securityConfig = $cfg['security']['headers'] ?? [];
        }
    } catch (\Throwable $e) {
        // 配置读取失败时使用默认值
    }

    // Content-Security-Policy: 防止XSS攻击
    $csp = $securityConfig['content_security_policy'] ?? "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net data:; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none';";
    header('Content-Security-Policy: ' . $csp);

    // X-Content-Type-Options: 防止MIME类型嗅探
    header('X-Content-Type-Options: ' . ($securityConfig['x_content_type_options'] ?? 'nosniff'));

    // X-Frame-Options: 防止点击劫持
    header('X-Frame-Options: ' . ($securityConfig['x_frame_options'] ?? 'DENY'));

    // X-XSS-Protection: XSS过滤（旧浏览器兼容）
    header('X-XSS-Protection: ' . ($securityConfig['x_xss_protection'] ?? '1; mode=block'));

    // Referrer-Policy: 控制Referer信息泄露
    header('Referrer-Policy: ' . ($securityConfig['referrer_policy'] ?? 'strict-origin-when-cross-origin'));

    // Permissions-Policy: 限制浏览器功能
    header('Permissions-Policy: ' . ($securityConfig['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=()'));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($siteDescription ?? '域名过期与违规拦截服务'); ?>">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken()); ?>">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($faviconPath); ?>">
    <!-- Tailwind CSS CDN -->
    <!-- M-11: 生产环境建议将Tailwind CSS下载到本地，避免依赖外部CDN。
         可通过配置 site.cdn_local = true 切换为本地资源路径。
         本地化步骤：1) 下载 tailwindcss CDN 版本保存为 /assets/js/tailwindcss.js
                    2) 在下方将 CDN src 替换为本地路径 -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'lighthouse': {
                            50:  '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                            950: '#082f49',
                        },
                        'dark': {
                            50:  '#f8fafc',
                            100: '#f1f5f9',
                            200: '#e2e8f0',
                            300: '#cbd5e1',
                            400: '#94a3b8',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155',
                            800: '#1e293b',
                            900: '#0f172a',
                            950: '#020617',
                        }
                    },
                    animation: {
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'glow': 'glow 2s ease-in-out infinite alternate',
                        'float': 'float 6s ease-in-out infinite',
                        'scan': 'scan 3s linear infinite',
                    },
                    keyframes: {
                        glow: {
                            '0%': { boxShadow: '0 0 5px rgba(14, 165, 233, 0.5), 0 0 10px rgba(14, 165, 233, 0.3)' },
                            '100%': { boxShadow: '0 0 20px rgba(14, 165, 233, 0.8), 0 0 40px rgba(14, 165, 233, 0.4)' },
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' },
                        },
                        scan: {
                            '0%': { transform: 'translateY(-100%)' },
                            '100%': { transform: 'translateY(100%)' },
                        },
                    },
                },
            },
        }
    </script>
    <!-- 自定义样式 -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- 操作日志记录器 -->
    <script src="/assets/js/operation-logger.js"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
    </style>
</head>
<body class="bg-dark-950 text-dark-100 min-h-screen antialiased">
    <!-- 背景粒子效果 -->
    <div id="particles-bg" class="fixed inset-0 pointer-events-none z-0 overflow-hidden"></div>

    <!-- 导航栏 -->
    <nav class="relative z-10 border-b border-dark-800/50 bg-dark-950/80 backdrop-blur-xl">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <div class="flex items-center space-x-3">
                    <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="Logo" class="h-8 w-8">
                    <span class="text-lg font-semibold text-lighthouse-400"><?php echo htmlspecialchars($siteName); ?></span>
                </div>
                <!-- 导航链接 -->
                <div class="hidden md:flex items-center space-x-6">
                </div>
                <!-- 移动端菜单按钮 -->
                <button id="mobile-menu-btn" class="md:hidden text-dark-400 hover:text-lighthouse-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
        <!-- 移动端菜单 -->
        <div id="mobile-menu" class="hidden md:hidden border-t border-dark-800/50 bg-dark-950/95 backdrop-blur-xl">
            <div class="px-4 py-3 space-y-2">
            </div>
        </div>
    </nav>

    <!-- 主内容区域 -->
    <main class="relative z-10">
