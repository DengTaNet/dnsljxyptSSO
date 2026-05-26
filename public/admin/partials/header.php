<?php
/**
 * 灯塔DNS拦截响应平台 - 后台管理共享头部模板
 * 包含 Tailwind CSS 配置、CSRF meta 标签
 *
 * 使用方式: 在页面顶部 include 此文件
 * 需要变量: $pageTitle (页面标题)
 */

if (!isset($pageTitle)) {
    $pageTitle = '管理后台';
}
$siteName = '灯塔DNS拦截响应平台';

// 动态Logo和Favicon
$siteLogo = getSiteLogo();
$faviconPath = getFavicon();

// M-12: 安全响应头
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($faviconPath); ?>">
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
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
    <script src="/assets/js/main.js"></script>
    <script src="/assets/js/animations.js"></script>
    <script src="/assets/js/operation-logger.js"></script>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(\Core\CsrfProtection::getToken()); ?>">
</head>
