<?php
/**
 * 灯塔DNS拦截响应平台 - 管理员登录页 (SSO 登录)
 * 深色科技风格 + 粒子动画 + 玻璃拟态效果
 * 使用 Logto SSO 进行身份验证
 */

use Core\Database;
use Core\SsoAuth;

define('BASEPATH', dirname(__DIR__, 2));

// 安装检测
if (!file_exists(BASEPATH . '/storage/install.lock')) {
    header('Location: /install.php');
    exit;
}

require_once BASEPATH . '/includes/functions.php';
initDebug();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = Database::getInstance();
$config = require BASEPATH . '/config/config.php';
$ssoAuth = new SsoAuth($db, $config);

// 域名白名单检查（复用 SsoAuth 统一逻辑，白名单为空时不限制）
$ssoAuth->checkAdminDomain();

// 如果已登录，跳转到仪表盘
if ($ssoAuth->check()) {
    header('Location: /admin/');
    exit;
}

$error = '';

// 检查 SSO 是否启用
$ssoEnabled = $ssoAuth->isEnabled();

// 处理 SSO 登录跳转
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['sso']) && $_GET['sso'] === '1') {
    if (!$ssoEnabled) {
        $error = 'SSO 登录未启用，请联系管理员。';
    } else {
        try {
            $signInUrl = $ssoAuth->getSignInUrl();
            header('Location: ' . $signInUrl);
            exit;
        } catch (\Throwable $e) {
            error_log("[SSO登录跳转错误] " . $e->getMessage());
            $error = 'SSO 登录服务暂时不可用，请稍后重试。';
        }
    }
}

// 显示来自回调的错误信息（仅从 session 读取，避免 URL 泄露）
if (isset($_SESSION['sso_error'])) {
    $error = htmlspecialchars($_SESSION['sso_error']);
    unset($_SESSION['sso_error']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 灯塔DNS拦截响应平台</title>
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
                            200: '#e2e8f0',
                            100: '#f1f5f9',
                        },
                        lighthouse: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        /* 登录页粒子背景 */
        #login-particles {
            position: fixed;
            inset: 0;
            overflow: hidden;
            z-index: 0;
            background: radial-gradient(ellipse at 20% 50%, rgba(14, 165, 233, 0.04) 0%, transparent 50%),
                        radial-gradient(ellipse at 80% 20%, rgba(14, 165, 233, 0.03) 0%, transparent 50%),
                        radial-gradient(ellipse at 50% 80%, rgba(14, 165, 233, 0.02) 0%, transparent 50%);
        }

        .login-particle {
            position: absolute;
            width: 2px;
            height: 2px;
            background: rgba(14, 165, 233, 0.4);
            border-radius: 50%;
            animation: login-particle-float linear infinite;
        }

        @keyframes login-particle-float {
            0% { transform: translateY(100vh) scale(0); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-10vh) scale(1); opacity: 0; }
        }

        /* 网格背景 */
        .grid-bg {
            background-image:
                linear-gradient(rgba(14, 165, 233, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(14, 165, 233, 0.03) 1px, transparent 1px);
            background-size: 60px 60px;
        }

        /* 登录卡片发光效果 */
        .login-card {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(51, 65, 85, 0.5);
            box-shadow: 0 0 40px rgba(14, 165, 233, 0.05),
                        0 25px 50px rgba(0, 0, 0, 0.3);
            transition: box-shadow 0.3s ease;
        }

        .login-card:hover {
            box-shadow: 0 0 60px rgba(14, 165, 233, 0.08),
                        0 25px 50px rgba(0, 0, 0, 0.4);
        }

        /* SSO 登录按钮渐变 + 发光 */
        .sso-login-btn {
            background: linear-gradient(135deg, #0284c7, #0ea5e9, #38bdf8);
            background-size: 200% 200%;
            animation: btn-gradient 3s ease infinite;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .sso-login-btn:hover {
            box-shadow: 0 0 20px rgba(14, 165, 233, 0.4),
                        0 0 40px rgba(14, 165, 233, 0.2),
                        0 8px 25px rgba(14, 165, 233, 0.3);
            transform: translateY(-1px);
        }

        .sso-login-btn:active {
            transform: translateY(0);
        }

        @keyframes btn-gradient {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        /* Logo 发光脉冲 */
        .logo-glow {
            animation: logo-pulse 3s ease-in-out infinite;
        }

        @keyframes logo-pulse {
            0%, 100% { box-shadow: 0 0 15px rgba(14, 165, 233, 0.15); }
            50% { box-shadow: 0 0 30px rgba(14, 165, 233, 0.3), 0 0 60px rgba(14, 165, 233, 0.1); }
        }

        /* 错误提示动画 */
        .error-shake {
            animation: error-shake 0.5s ease-in-out;
        }

        @keyframes error-shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-4px); }
            20%, 40%, 60%, 80% { transform: translateX(4px); }
        }

        /* 淡入动画 */
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* SSO 图标动画 */
        .sso-icon {
            transition: transform 0.3s ease;
        }

        .sso-login-btn:hover .sso-icon {
            transform: translateX(4px);
        }
    </style>
</head>
<body class="bg-dark-950 min-h-screen flex items-center justify-center antialiased">
    <!-- 粒子背景 -->
    <div id="login-particles"></div>
    <div class="fixed inset-0 grid-bg pointer-events-none z-0"></div>

    <!-- 背景光晕 -->
    <div class="fixed inset-0 pointer-events-none overflow-hidden z-0">
        <div class="absolute top-1/4 left-1/4 w-[500px] h-[500px] bg-lighthouse-500/[0.03] rounded-full blur-[100px]"></div>
        <div class="absolute bottom-1/4 right-1/4 w-[400px] h-[400px] bg-lighthouse-500/[0.02] rounded-full blur-[80px]"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-lighthouse-500/[0.01] rounded-full blur-[120px]"></div>
    </div>

    <div class="relative z-10 w-full max-w-md px-4 fade-in-up">
        <!-- Logo -->
        <div class="text-center mb-8 fade-in">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-lighthouse-500/10 rounded-2xl mb-5 logo-glow border border-lighthouse-500/20">
                <img src="/assets/images/logo.png" alt="灯塔" class="w-12 h-12">
            </div>
            <h1 class="text-2xl font-bold text-dark-100 tracking-wide">灯塔DNS拦截响应平台</h1>
            <p class="text-dark-500 text-sm mt-2">管理后台安全登录</p>
        </div>

        <!-- 登录卡片 -->
        <div class="login-card rounded-2xl p-8">
            <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 mb-6 error-shake">
                <div class="flex items-center space-x-3">
                    <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-red-400 text-sm"><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$ssoEnabled): ?>
            <!-- SSO 未启用提示 -->
            <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 mb-6">
                <div class="flex items-center space-x-3">
                    <svg class="w-5 h-5 text-yellow-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <p class="text-yellow-400 text-sm">SSO 登录服务未启用，请联系管理员配置。</p>
                </div>
            </div>
            <?php else: ?>
            <!-- SSO 登录按钮 -->
            <a href="?sso=1" class="sso-login-btn w-full flex items-center justify-center space-x-3 text-white font-semibold py-4 rounded-xl focus:outline-none focus:ring-2 focus:ring-lighthouse-500/50 transition-all duration-200">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                </svg>
                <span>使用 灯塔统一身份认证 登录</span>
                <svg class="w-5 h-5 sso-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>

            <div class="mt-6 text-center">
                <p class="text-dark-500 text-xs">
                    点击上方按钮将跳转到统一身份认证平台登录
                </p>
            </div>

            <div class="mt-6 pt-6 border-t border-dark-700/50">
                <div class="flex items-center justify-center space-x-2 text-dark-500 text-xs">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <span>安全的企业级单点登录</span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 底部信息 -->
        <div class="text-center mt-6 fade-in">
            <p class="text-dark-600 text-xs">&copy; <?php echo date('Y'); ?> <a href="https://www.dengtanet.com" target="_blank" class="text-dark-500 hover:text-dark-400 transition-colors">灯塔网络科技（甘肃）有限公司</a></p>
            <p class="text-dark-700 text-xs mt-1"><a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener" class="hover:text-dark-500 transition-colors">陇ICP备2025025190号</a></p>
        </div>
    </div>

    <script>
        // 粒子动画初始化
        (function initParticles() {
            const container = document.getElementById('login-particles');
            if (!container) return;
            for (let i = 0; i < 40; i++) {
                const particle = document.createElement('div');
                particle.className = 'login-particle';
                const size = Math.random() * 3 + 1;
                const left = Math.random() * 100;
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 20;
                const opacity = Math.random() * 0.4 + 0.1;
                particle.style.cssText = `width:${size}px;height:${size}px;left:${left}%;animation-duration:${duration}s;animation-delay:${delay}s;opacity:${opacity};`;
                container.appendChild(particle);
            }
        })();
    </script>
</body>
</html>
