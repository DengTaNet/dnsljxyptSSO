<?php
/**
 * 灯塔DNS拦截响应平台 - 后台仪表盘
 * 深色科技风格，包含统计卡片、最近访问、域名分布、快捷操作
 */

use Core\Database;
use Core\Auth;

define('BASEPATH', dirname(__DIR__, 2));
require_once BASEPATH . '/includes/functions.php';
initDebug();

// 安装锁检查
if (!file_exists(BASEPATH . '/storage/install.lock')) {
    header('Location: /install.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Shanghai');

$db = Database::getInstance();
$config = require BASEPATH . '/config/config.php';
$auth = new Auth($db, $config);
$currentUser = $auth->requireAuth();

// ========== 统计数据 ==========
$expiredCount = (int)$db->queryOne(
    "SELECT COUNT(*) as cnt FROM domains WHERE type = 'expired' AND status = 'active'"
)['cnt'];

$reviewCount = (int)$db->queryOne(
    "SELECT COUNT(*) as cnt FROM domains WHERE type = 'violation' AND status = 'active'"
)['cnt'];

$todayVisits = (int)$db->queryOne(
    "SELECT COUNT(*) as cnt FROM access_logs WHERE DATE(created_at) = CURDATE()"
)['cnt'];

$bannedIpCount = (int)$db->queryOne(
    "SELECT COUNT(*) as cnt FROM ip_bans WHERE status = 'active'"
)['cnt'];

// 域名状态分布
$domainStats = $db->query(
    "SELECT type, status, COUNT(*) as cnt FROM domains GROUP BY type, status"
);
$domainDistribution = [
    'expired_active' => 0,
    'expired_released' => 0,
    'violation_active' => 0,
    'violation_released' => 0,
    'violation_pending' => 0,
];
foreach ($domainStats as $row) {
    $key = $row['type'] . '_' . $row['status'];
    if (isset($domainDistribution[$key])) {
        $domainDistribution[$key] = (int)$row['cnt'];
    }
}

// 最近访问记录（最新10条）
$recentLogs = $db->query(
    "SELECT al.*, d.domain_name
     FROM access_logs al
     LEFT JOIN domains d ON al.domain_id = d.id
     ORDER BY al.created_at DESC
     LIMIT 10"
);

// 最近7天访问趋势
$visitTrend = $db->query(
    "SELECT DATE(created_at) as date, COUNT(*) as visits
     FROM access_logs
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY DATE(created_at)
     ORDER BY date ASC"
);

// 活跃会话数
$activeConversations = (int)$db->queryOne(
    "SELECT COUNT(*) as cnt FROM chat_conversations WHERE status = 'active'"
)['cnt'];

// 待处理申辩
$pendingAppeals = (int)$db->queryOne(
    "SELECT COUNT(*) as cnt FROM appeals WHERE status = 'pending'"
)['cnt'];
?>
<?php $pageTitle = '仪表盘'; include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <!-- 侧边栏 -->
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <!-- 主内容 -->
    <div class="ml-64 p-8">
        <!-- 顶部栏 -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-dark-100">仪表盘</h1>
                <p class="text-dark-400 text-sm mt-1">欢迎回来，<?php echo htmlspecialchars($currentUser['username']); ?></p>
            </div>
            <div class="flex items-center space-x-4">
                <div class="flex items-center space-x-2 text-dark-400 text-sm bg-dark-900/50 border border-dark-800/50 rounded-lg px-3 py-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span><?php echo date('Y-m-d H:i'); ?></span>
                </div>
            </div>
        </div>

        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- 到期域名 -->
            <div class="stat-card">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-dark-400 text-sm">到期域名</span>
                    <div class="w-10 h-10 bg-orange-500/10 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-3xl font-bold text-dark-100" data-count="<?php echo $expiredCount; ?>"><?php echo number_format($expiredCount); ?></p>
                <p class="text-dark-500 text-xs mt-1">拦截中的到期域名</p>
            </div>

            <!-- 审查中域名 -->
            <div class="stat-card">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-dark-400 text-sm">审查中域名</span>
                    <div class="w-10 h-10 bg-yellow-500/10 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-3xl font-bold text-dark-100" data-count="<?php echo $reviewCount; ?>"><?php echo number_format($reviewCount); ?></p>
                <p class="text-dark-500 text-xs mt-1">拦截中的审查域名</p>
            </div>

            <!-- 今日访问量 -->
            <div class="stat-card">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-dark-400 text-sm">今日访问量</span>
                    <div class="w-10 h-10 bg-cyan-500/10 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-3xl font-bold text-dark-100" data-count="<?php echo $todayVisits; ?>"><?php echo number_format($todayVisits); ?></p>
                <p class="text-dark-500 text-xs mt-1">今日总访问次数</p>
            </div>

            <!-- 已封禁IP -->
            <div class="stat-card">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-dark-400 text-sm">已封禁IP</span>
                    <div class="w-10 h-10 bg-red-500/10 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        </svg>
                    </div>
                </div>
                <p class="text-3xl font-bold text-dark-100" data-count="<?php echo $bannedIpCount; ?>"><?php echo number_format($bannedIpCount); ?></p>
                <p class="text-dark-500 text-xs mt-1">当前生效中的封禁</p>
            </div>
        </div>

        <!-- 快捷操作 + 域名状态分布 -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- 快捷操作 -->
            <div class="stat-card">
                <h2 class="text-lg font-semibold text-dark-100 mb-4">快捷操作</h2>
                <div class="space-y-3">
                    <a href="/admin/domains/expired.php" class="flex items-center space-x-3 p-3 rounded-lg bg-dark-800/30 hover:bg-dark-800/50 transition-colors group">
                        <div class="w-8 h-8 bg-orange-500/10 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-dark-200 group-hover:text-dark-100">添加到期域名</p>
                            <p class="text-xs text-dark-500">添加需要拦截的到期域名</p>
                        </div>
                        <svg class="w-4 h-4 text-dark-600 group-hover:text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                    <a href="/admin/domains/violation.php" class="flex items-center space-x-3 p-3 rounded-lg bg-dark-800/30 hover:bg-dark-800/50 transition-colors group">
                        <div class="w-8 h-8 bg-yellow-500/10 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-dark-200 group-hover:text-dark-100">添加审查域名</p>
                            <p class="text-xs text-dark-500">添加需要审查的域名</p>
                        </div>
                        <svg class="w-4 h-4 text-dark-600 group-hover:text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                    <a href="/admin/ipban/manage.php" class="flex items-center space-x-3 p-3 rounded-lg bg-dark-800/30 hover:bg-dark-800/50 transition-colors group">
                        <div class="w-8 h-8 bg-red-500/10 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-dark-200 group-hover:text-dark-100">IP封禁管理</p>
                            <p class="text-xs text-dark-500">管理IP封禁规则</p>
                        </div>
                        <svg class="w-4 h-4 text-dark-600 group-hover:text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                    <a href="/admin/chat/list.php" class="flex items-center space-x-3 p-3 rounded-lg bg-dark-800/30 hover:bg-dark-800/50 transition-colors group">
                        <div class="w-8 h-8 bg-lighthouse-500/10 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 text-lighthouse-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-dark-200 group-hover:text-dark-100">客服中心</p>
                            <p class="text-xs text-dark-500">查看客户会话 <?php if ($activeConversations > 0): ?><span class="text-lighthouse-400">(<?php echo $activeConversations; ?>)</span><?php endif; ?></p>
                        </div>
                        <svg class="w-4 h-4 text-dark-600 group-hover:text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- 域名状态分布 -->
            <div class="stat-card lg:col-span-2">
                <h2 class="text-lg font-semibold text-dark-100 mb-4">域名状态分布</h2>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div class="text-center p-4 rounded-lg bg-orange-500/5 border border-orange-500/10">
                        <p class="text-2xl font-bold text-orange-400"><?php echo $domainDistribution['expired_active']; ?></p>
                        <p class="text-xs text-dark-400 mt-1">到期拦截中</p>
                    </div>
                    <div class="text-center p-4 rounded-lg bg-orange-500/5 border border-orange-500/10">
                        <p class="text-2xl font-bold text-orange-300"><?php echo $domainDistribution['expired_released']; ?></p>
                        <p class="text-xs text-dark-400 mt-1">到期已释放</p>
                    </div>
                    <div class="text-center p-4 rounded-lg bg-red-500/5 border border-red-500/10">
                        <p class="text-2xl font-bold text-red-400"><?php echo $domainDistribution['violation_active']; ?></p>
                        <p class="text-xs text-dark-400 mt-1">审查拦截中</p>
                    </div>
                    <div class="text-center p-4 rounded-lg bg-yellow-500/5 border border-yellow-500/10">
                        <p class="text-2xl font-bold text-yellow-400"><?php echo $domainDistribution['violation_pending']; ?></p>
                        <p class="text-xs text-dark-400 mt-1">待审核</p>
                    </div>
                    <div class="text-center p-4 rounded-lg bg-green-500/5 border border-green-500/10">
                        <p class="text-2xl font-bold text-green-400"><?php echo $domainDistribution['violation_released']; ?></p>
                        <p class="text-xs text-dark-400 mt-1">审查已释放</p>
                    </div>
                </div>

                <!-- 简易访问趋势图 -->
                <div class="mt-6">
                    <h3 class="text-sm text-dark-400 mb-3">最近7天访问趋势</h3>
                    <div class="flex items-end space-x-2 h-32">
                        <?php
                        $maxVisits = !empty($visitTrend) ? max(array_column($visitTrend, 'visits')) : 1;
                        // 填充7天数据
                        $trendData = [];
                        for ($i = 6; $i >= 0; $i--) {
                            $date = date('Y-m-d', strtotime("-{$i} days"));
                            $found = false;
                            foreach ($visitTrend as $row) {
                                if ($row['date'] === $date) {
                                    $trendData[] = ['date' => $date, 'visits' => (int)$row['visits']];
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $trendData[] = ['date' => $date, 'visits' => 0];
                            }
                        }
                        foreach ($trendData as $item):
                            $height = $maxVisits > 0 ? max(4, ($item['visits'] / $maxVisits) * 100) : 4;
                            $isToday = $item['date'] === date('Y-m-d');
                        ?>
                        <div class="flex-1 flex flex-col items-center justify-end h-full">
                            <span class="text-xs text-dark-400 mb-1"><?php echo $item['visits']; ?></span>
                            <div class="w-full rounded-t-sm <?php echo $isToday ? 'bg-lighthouse-500' : 'bg-lighthouse-500/30'; ?> transition-all duration-300 hover:bg-lighthouse-500/60"
                                 style="height: <?php echo $height; ?>%; min-height: 4px;"
                                 title="<?php echo $item['date']; ?>: <?php echo $item['visits']; ?>次访问"></div>
                            <span class="text-xs text-dark-500 mt-1"><?php echo date('m/d', strtotime($item['date'])); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 最近访问记录 -->
        <div class="stat-card">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-dark-100">最近访问记录</h2>
                <a href="/admin/logs/access.php" class="text-lighthouse-400 hover:text-lighthouse-300 text-sm transition-colors flex items-center space-x-1">
                    <span>查看全部</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-dark-400 border-b border-dark-800/50">
                            <th class="text-left py-3 px-4 font-medium">域名</th>
                            <th class="text-left py-3 px-4 font-medium">访问IP</th>
                            <th class="text-left py-3 px-4 font-medium">真实IP</th>
                            <th class="text-left py-3 px-4 font-medium">浏览器</th>
                            <th class="text-left py-3 px-4 font-medium">来源</th>
                            <th class="text-left py-3 px-4 font-medium">时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr class="table-row-hover">
                            <td class="py-3 px-4 font-mono text-lighthouse-400 text-xs"><?php echo htmlspecialchars($log['domain_name'] ?? '-'); ?></td>
                            <td class="py-3 px-4 font-mono text-xs"><?php echo htmlspecialchars($log['ip']); ?></td>
                            <td class="py-3 px-4 font-mono text-xs"><?php echo htmlspecialchars($log['real_ip'] ?? '-'); ?></td>
                            <td class="py-3 px-4 text-xs"><?php echo htmlspecialchars(($log['browser'] ?? '') . ' ' . ($log['browser_version'] ?? '')); ?></td>
                            <td class="py-3 px-4">
                                <span class="badge badge-info text-xs"><?php echo htmlspecialchars($log['detection_source'] ?? '-'); ?></span>
                            </td>
                            <td class="py-3 px-4 text-xs text-dark-400"><?php echo htmlspecialchars($log['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentLogs)): ?>
                        <tr><td colspan="6" class="py-12 text-center text-dark-500">
                            <svg class="w-12 h-12 mx-auto mb-3 text-dark-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                            </svg>
                            暂无访问记录
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script src="/admin/assets/js/chart.js"></script>
    <script src="/admin/assets/js/admin.js"></script>
    <script>
        // 数字递增动画
        document.querySelectorAll('[data-count]').forEach(el => {
            const target = parseInt(el.dataset.count);
            const duration = 800;
            const start = performance.now();
            function update(now) {
                const progress = Math.min((now - start) / duration, 1);
                const ease = 1 - Math.pow(1 - progress, 3);
                el.textContent = Math.round(target * ease).toLocaleString();
                if (progress < 1) requestAnimationFrame(update);
            }
            requestAnimationFrame(update);
        });
    </script>
</body>
</html>
