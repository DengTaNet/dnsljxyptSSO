<?php
/**
 * 灯塔DNS拦截响应平台 - 后台侧边栏
 * 深色科技风格，包含所有管理模块入口
 *
 * 需要变量: $currentUser (当前管理员信息数组)
 */

$siteName = '灯塔DNS拦截响应平台';
$currentPath = $_SERVER['REQUEST_URI'] ?? '/admin/';

// 动态Logo
$siteLogo = getSiteLogo();

// 构建导航菜单（带权限控制）
$navItems = [
    ['url' => '/admin/',                       'icon' => 'dashboard', 'label' => '仪表盘',     'permission' => 'dashboard'],
    ['url' => '/admin/domains/expired.php',    'icon' => 'clock',     'label' => '到期域名',   'permission' => 'domains_expired'],
    ['url' => '/admin/domains/violation.php',  'icon' => 'shield',    'label' => '审查域名',   'permission' => 'domains_violation'],
    ['url' => '/admin/chat/list.php',          'icon' => 'chat',      'label' => '客服中心',   'permission' => 'chat'],
    ['url' => '/admin/logs/access.php',        'icon' => 'document',  'label' => '访问记录',   'permission' => 'logs'],
    ['url' => '/admin/logs/operation.php',     'icon' => 'log',      'label' => '日志中心',   'permission' => 'log_center'],
    ['url' => '/admin/ipban/manage.php',       'icon' => 'ban',       'label' => 'IP封禁',     'permission' => 'ipban'],
];

// 人事管理菜单（仅超级管理员可见）
if (isset($currentUser['role']) && $currentUser['role'] === 'super_admin') {
    $navItems[] = ['url' => '/admin/staff/index.php', 'icon' => 'users', 'label' => '组织管理', 'permission' => 'staff'];
}

// 根据权限过滤菜单项（super_admin跳过检查）
if (isset($currentUser['role']) && $currentUser['role'] !== 'super_admin') {
    // 需要Auth实例来检查权限
    $navItems = array_filter($navItems, function($item) use ($currentUser) {
        // 如果没有自定义permissions，使用预设角色权限
        if (!empty($currentUser['permissions'])) {
            $perms = json_decode($currentUser['permissions'], true);
            if (is_array($perms) && (in_array('*', $perms) || in_array($item['permission'], $perms))) {
                return true;
            }
            return false;
        }
        // 预设角色权限映射
        $rolePermissions = [
            'admin'         => ['*'],
            'operator'      => ['dashboard', 'domains_expired', 'domains_violation', 'chat', 'logs', 'log_center'],
            'domain_admin'  => ['dashboard', 'domains_expired', 'domains_violation'],
            'support'       => ['dashboard', 'chat'],
            'security'      => ['dashboard', 'ipban', 'logs', 'log_center'],
            'auditor'       => ['dashboard', 'logs', 'log_center'],
        ];
        $perms = $rolePermissions[$currentUser['role']] ?? [];
        if (in_array('*', $perms) || in_array($item['permission'], $perms)) {
            return true;
        }
        return false;
    });
}

$icons = [
    'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>',
    'clock'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    'shield'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
    'chat'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>',
    'document'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
    'ban'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>',
    'users'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
    'log'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>',
];
?>

<!-- 侧边栏 -->
<aside class="fixed left-0 top-0 bottom-0 w-64 bg-dark-900/80 border-r border-dark-800/50 backdrop-blur-xl z-20 flex flex-col">
    <!-- Logo -->
    <div class="flex items-center space-x-3 px-6 h-16 border-b border-dark-800/50 flex-shrink-0">
        <div class="w-8 h-8 bg-lighthouse-500/10 rounded-lg flex items-center justify-center border border-lighthouse-500/20">
            <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="Logo" class="w-5 h-5">
        </div>
        <span class="text-sm font-semibold text-lighthouse-400 truncate"><?php echo htmlspecialchars($siteName); ?></span>
    </div>

    <!-- 导航菜单 -->
    <nav class="mt-4 px-3 space-y-1 flex-1 overflow-y-auto">
        <?php foreach ($navItems as $item): ?>
        <?php $isActive = (strpos($currentPath, $item['url']) === 0) && ($item['url'] !== '/admin/' || $currentPath === '/admin/' || $currentPath === '/admin/index.php'); ?>
        <a href="<?php echo $item['url']; ?>"
           class="sidebar-link <?php echo $isActive ? 'active' : ''; ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <?php echo $icons[$item['icon']]; ?>
            </svg>
            <span><?php echo $item['label']; ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <!-- 底部用户信息 -->
    <div class="p-4 border-t border-dark-800/50 flex-shrink-0">
        <div class="flex items-center space-x-3">
            <div class="w-9 h-9 bg-lighthouse-500/10 rounded-lg flex items-center justify-center border border-lighthouse-500/20">
                <svg class="w-4 h-4 text-lighthouse-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm text-dark-200 truncate"><?php echo htmlspecialchars($currentUser['username'] ?? 'Admin'); ?></p>
                <p class="text-xs text-dark-500 truncate"><?php echo htmlspecialchars($currentUser['role'] ?? 'admin'); ?></p>
            </div>
            <button onclick="showLogoutModal()" class="text-dark-500 hover:text-red-400 transition-all duration-200 p-1.5 rounded-lg hover:bg-red-500/10" title="退出登录">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </button>
        </div>
    </div>
</aside>

<!-- 退出登录确认弹窗 -->
<div id="logoutModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <!-- 遮罩层 -->
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="hideLogoutModal()"></div>
    <!-- 弹窗内容 -->
    <div class="relative bg-dark-900 border border-dark-700/50 rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl shadow-black/50 transform scale-95 opacity-0 transition-all duration-300" id="logoutModalContent">
        <!-- 顶部图标 -->
        <div class="flex justify-center mb-5">
            <div class="w-14 h-14 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-center justify-center">
                <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </div>
        </div>
        <!-- 文字 -->
        <h3 class="text-lg font-semibold text-dark-100 text-center mb-2">确认退出登录</h3>
        <p class="text-sm text-dark-400 text-center mb-6">退出后需要重新输入账号密码才能访问管理后台</p>
        <!-- 按钮 -->
        <div class="flex space-x-3">
            <button onclick="hideLogoutModal()" class="btn-secondary flex-1 !py-2.5">取消</button>
            <form method="POST" action="/admin/logout.php" class="flex-1">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars(\Core\CsrfProtection::getToken()); ?>">
                <button type="submit" class="w-full bg-gradient-to-r from-red-500/80 to-red-600/80 hover:from-red-500 hover:to-red-600 text-white rounded-xl py-2.5 font-medium transition-all duration-200 hover:shadow-lg hover:shadow-red-500/20">确认退出</button>
            </form>
        </div>
    </div>
</div>

<script>
function showLogoutModal() {
    const modal = document.getElementById('logoutModal');
    const content = document.getElementById('logoutModalContent');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    requestAnimationFrame(() => {
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
    });
}

function hideLogoutModal() {
    const modal = document.getElementById('logoutModal');
    const content = document.getElementById('logoutModalContent');
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 300);
}

// ESC 关闭弹窗
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideLogoutModal();
});
</script>
