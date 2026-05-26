<?php
/**
 * 灯塔DNS拦截响应平台 - 操作日志中心
 * 深色科技风格，包含统计卡片、筛选栏、日志列表表格和分页
 */

use Core\Database;
use Core\Auth;

define('BASEPATH', dirname(__DIR__, 3));
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

if (!$auth->hasPermission('log_center')) {
    http_response_code(403);
    $pageTitle = '访问被拒绝';
    include BASEPATH . '/public/admin/partials/header.php';
    echo '<body class="bg-dark-950 text-dark-100 min-h-screen">';
    include BASEPATH . '/public/admin/partials/sidebar.php';
    echo '<div class="ml-64 p-8">
        <div class="flex flex-col items-center justify-center" style="min-height: calc(100vh - 8rem);">
            <div class="text-center">
                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-red-500/10 border border-red-500/30 flex items-center justify-center">
                    <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-red-400 mb-2">访问被拒绝</h2>
                <p class="text-dark-400 mb-6">您没有权限访问此页面</p>
                <a href="/admin/" class="inline-flex items-center space-x-2 px-4 py-2 bg-dark-800 hover:bg-dark-700 border border-dark-700 rounded-lg text-sm text-dark-300 hover:text-white transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    <span>返回首页</span>
                </a>
            </div>
        </div>
    </div>
    <script src="/admin/assets/js/admin.js"></script>
    </body></html>';
    exit;
}

// ========== 获取统计数据 ==========
$today = date('Y-m-d');
$stats = [
    'today_total'    => 0,
    'today_admin'    => 0,
    'today_customer' => 0,
    'today_failure'  => 0,
];

try {
    $stats['today_total'] = (int)($db->queryOne("SELECT COUNT(*) as cnt FROM logzx WHERE created_at >= ?", [$today . ' 00:00:00'])['cnt'] ?? 0);
    $stats['today_admin'] = (int)($db->queryOne("SELECT COUNT(*) as cnt FROM logzx WHERE created_at >= ? AND user_type = 'admin'", [$today . ' 00:00:00'])['cnt'] ?? 0);
    $stats['today_customer'] = (int)($db->queryOne("SELECT COUNT(*) as cnt FROM logzx WHERE created_at >= ? AND user_type = 'customer'", [$today . ' 00:00:00'])['cnt'] ?? 0);
    $stats['today_failure'] = (int)($db->queryOne("SELECT COUNT(*) as cnt FROM logzx WHERE created_at >= ? AND result = 'failure'", [$today . ' 00:00:00'])['cnt'] ?? 0);
} catch (\Throwable $e) {
    // 静默处理
}

// ========== 构建筛选条件 ==========
$where = ['1=1'];
$params = [];

$dateFrom  = $_GET['date_from'] ?? '';
$dateTo    = $_GET['date_to'] ?? '';
$userType  = $_GET['user_type'] ?? '';
$logAction = $_GET['action'] ?? '';
$module    = $_GET['module'] ?? '';
$userName  = $_GET['user_name'] ?? '';
$ip        = $_GET['ip'] ?? '';
$targetType= $_GET['target_type'] ?? '';
$result    = $_GET['result'] ?? '';
$keyword   = $_GET['keyword'] ?? '';

if ($dateFrom) { $where[] = 'created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo)   { $where[] = 'created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
if ($userType && in_array($userType, ['admin', 'customer', 'system'], true)) { $where[] = 'user_type = ?'; $params[] = $userType; }
if ($logAction) { $where[] = 'action = ?'; $params[] = $logAction; }
if ($module)    { $where[] = 'module = ?'; $params[] = $module; }
if ($userName)  { $userName = addcslashes($userName, '%_'); $where[] = 'user_name LIKE ?'; $params[] = '%' . $userName . '%'; }
if ($ip)        { $ip = addcslashes($ip, '%_'); $where[] = '(ip LIKE ? OR real_ip LIKE ?)'; $params[] = '%' . $ip . '%'; $params[] = '%' . $ip . '%'; }
if ($targetType){ $where[] = 'target_type = ?'; $params[] = $targetType; }
if ($result && in_array($result, ['success', 'failure', 'pending'], true)) { $where[] = 'result = ?'; $params[] = $result; }
if ($keyword)   { $keyword = addcslashes($keyword, '%_'); $where[] = '(description LIKE ? OR target_name LIKE ? OR user_name LIKE ?)'; $params[] = '%' . $keyword . '%'; $params[] = '%' . $keyword . '%'; $params[] = '%' . $keyword . '%'; }

$whereClause = implode(' AND ', $where);

// ========== 分页查询 ==========
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$total = 0;
$logs = [];
try {
    $total = (int)($db->queryOne("SELECT COUNT(*) as cnt FROM logzx WHERE {$whereClause}", $params)['cnt'] ?? 0);
    $logs = $db->query(
        "SELECT * FROM logzx WHERE {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );
} catch (\Throwable $e) {
    // 静默处理
}

$totalPages = max(1, ceil($total / $perPage));

// ========== 操作类型颜色映射 ==========
$actionColors = [
    'login'     => 'bg-green-500/10 text-green-400 border-green-500/30',
    'logout'    => 'bg-gray-500/10 text-gray-400 border-gray-500/30',
    'create'    => 'bg-blue-500/10 text-blue-400 border-blue-500/30',
    'update'    => 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30',
    'delete'    => 'bg-red-500/10 text-red-400 border-red-500/30',
    'page_view' => 'bg-purple-500/10 text-purple-400 border-purple-500/30',
    'click'     => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30',
    'api_call'  => 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30',
    'submit'    => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    'review'    => 'bg-orange-500/10 text-orange-400 border-orange-500/30',
    'ban'       => 'bg-red-500/10 text-red-400 border-red-500/30',
    'unban'     => 'bg-green-500/10 text-green-400 border-green-500/30',
    'close'     => 'bg-gray-500/10 text-gray-400 border-gray-500/30',
    'send'      => 'bg-sky-500/10 text-sky-400 border-sky-500/30',
];

function getActionColor(string $action, array $colorMap): string {
    // 尝试精确匹配
    if (isset($colorMap[$action])) return $colorMap[$action];
    // 尝试前缀匹配
    foreach ($colorMap as $key => $color) {
        if (strpos($action, $key) === 0) return $color;
    }
    return 'bg-dark-700/50 text-dark-300 border-dark-600/50';
}
?>
<?php $pageTitle = '日志中心'; include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <div class="ml-64 p-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-dark-100">日志中心</h1>
                <p class="text-dark-400 text-sm mt-1">查看所有操作日志，追踪系统活动</p>
            </div>
            <div class="flex items-center space-x-2">
                <button onclick="refreshStats()" class="btn-secondary text-sm flex items-center space-x-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    <span>刷新</span>
                </button>
                <?php if ($currentUser['role'] === 'super_admin'): ?>
                <button onclick="showClearModal()" class="text-red-400 hover:text-red-300 text-sm flex items-center space-x-1 transition-colors px-3 py-2 rounded-lg hover:bg-red-500/10">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span>清空日志</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-dark-400 text-xs font-medium uppercase tracking-wider">今日操作总数</p>
                        <p class="text-2xl font-bold text-dark-100 mt-1" id="stat-total"><?php echo number_format($stats['today_total']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-lighthouse-500/10 rounded-xl flex items-center justify-center border border-lighthouse-500/20">
                        <svg class="w-6 h-6 text-lighthouse-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-dark-400 text-xs font-medium uppercase tracking-wider">管理员操作</p>
                        <p class="text-2xl font-bold text-blue-400 mt-1" id="stat-admin"><?php echo number_format($stats['today_admin']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-500/10 rounded-xl flex items-center justify-center border border-blue-500/20">
                        <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-dark-400 text-xs font-medium uppercase tracking-wider">客户操作</p>
                        <p class="text-2xl font-bold text-gray-300 mt-1" id="stat-customer"><?php echo number_format($stats['today_customer']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-gray-500/10 rounded-xl flex items-center justify-center border border-gray-500/20">
                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-dark-400 text-xs font-medium uppercase tracking-wider">失败操作</p>
                        <p class="text-2xl font-bold text-red-400 mt-1" id="stat-failure"><?php echo number_format($stats['today_failure']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-red-500/10 rounded-xl flex items-center justify-center border border-red-500/20">
                        <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- 筛选栏 -->
        <div class="stat-card mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-3">
                <div>
                    <label class="block text-dark-400 text-xs mb-1">开始日期</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">结束日期</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">用户类型</label>
                    <select name="user_type" class="search-input w-full">
                        <option value="">全部</option>
                        <option value="admin" <?php echo $userType === 'admin' ? 'selected' : ''; ?>>管理员</option>
                        <option value="customer" <?php echo $userType === 'customer' ? 'selected' : ''; ?>>客户</option>
                        <option value="system" <?php echo $userType === 'system' ? 'selected' : ''; ?>>系统</option>
                    </select>
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">操作类型</label>
                    <input type="text" name="action" value="<?php echo htmlspecialchars($logAction); ?>" placeholder="如: login, create..." class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">模块</label>
                    <input type="text" name="module" value="<?php echo htmlspecialchars($module); ?>" placeholder="如: domains, chat..." class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">用户名</label>
                    <input type="text" name="user_name" value="<?php echo htmlspecialchars($_GET['user_name'] ?? ''); ?>" placeholder="搜索用户名..." class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">IP地址</label>
                    <input type="text" name="ip" value="<?php echo htmlspecialchars($_GET['ip'] ?? ''); ?>" placeholder="搜索IP..." class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">结果</label>
                    <select name="result" class="search-input w-full">
                        <option value="">全部</option>
                        <option value="success" <?php echo $result === 'success' ? 'selected' : ''; ?>>成功</option>
                        <option value="failure" <?php echo $result === 'failure' ? 'selected' : ''; ?>>失败</option>
                        <option value="pending" <?php echo $result === 'pending' ? 'selected' : ''; ?>>进行中</option>
                    </select>
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">关键词搜索</label>
                    <input type="text" name="keyword" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="描述/对象名/用户名..." class="search-input w-full">
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="btn-primary text-sm flex-1">搜索</button>
                    <a href="?" class="btn-secondary text-sm">重置</a>
                </div>
            </form>
        </div>

        <!-- 日志列表表格 -->
        <div class="stat-card !p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-dark-400 border-b border-dark-800/50 bg-dark-900/30">
                            <th class="text-left py-3 px-4 font-medium">时间</th>
                            <th class="text-left py-3 px-4 font-medium">用户</th>
                            <th class="text-left py-3 px-4 font-medium">类型</th>
                            <th class="text-left py-3 px-4 font-medium">操作</th>
                            <th class="text-left py-3 px-4 font-medium">模块</th>
                            <th class="text-left py-3 px-4 font-medium">描述</th>
                            <th class="text-left py-3 px-4 font-medium">对象</th>
                            <th class="text-left py-3 px-4 font-medium">IP</th>
                            <th class="text-left py-3 px-4 font-medium">耗时</th>
                            <th class="text-left py-3 px-4 font-medium">结果</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                        <tr class="table-row-hover">
                            <td class="py-3 px-4 text-xs text-dark-400 whitespace-nowrap"><?php echo htmlspecialchars($l['created_at']); ?></td>
                            <td class="py-3 px-4">
                                <?php if (!empty($l['user_name'])): ?>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-dark-200 font-medium"><?php echo htmlspecialchars($l['user_name']); ?></span>
                                    <?php if (!empty($l['user_role'])): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-dark-700/50 text-dark-400 border border-dark-600/50"><?php echo htmlspecialchars($l['user_role']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <span class="text-xs text-dark-500">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4">
                                <?php
                                $typeClass = match($l['user_type']) {
                                    'admin'    => 'bg-blue-500/10 text-blue-400 border border-blue-500/30',
                                    'customer' => 'bg-gray-500/10 text-gray-400 border border-gray-500/30',
                                    'system'   => 'bg-purple-500/10 text-purple-400 border border-purple-500/30',
                                    default    => 'bg-dark-700/50 text-dark-400 border border-dark-600/50',
                                };
                                $typeLabel = match($l['user_type']) {
                                    'admin'    => '管理员',
                                    'customer' => '客户',
                                    'system'   => '系统',
                                    default    => $l['user_type'],
                                };
                                ?>
                                <span class="text-xs px-2 py-0.5 rounded-full <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-xs px-2 py-0.5 rounded border <?php echo getActionColor($l['action'], $actionColors); ?>"><?php echo htmlspecialchars($l['action']); ?></span>
                            </td>
                            <td class="py-3 px-4 text-xs text-dark-300"><?php echo htmlspecialchars($l['module'] ?? '-'); ?></td>
                            <td class="py-3 px-4 text-xs text-dark-300 max-w-[200px] truncate" title="<?php echo htmlspecialchars($l['description'] ?? ''); ?>"><?php echo htmlspecialchars($l['description'] ?? '-'); ?></td>
                            <td class="py-3 px-4 text-xs text-dark-400 max-w-[150px] truncate" title="<?php echo htmlspecialchars(($l['target_type'] ?? '') . ': ' . ($l['target_name'] ?? '') . ' (' . ($l['target_id'] ?? '') . ')'); ?>">
                                <?php if (!empty($l['target_name'])): ?>
                                    <?php echo htmlspecialchars($l['target_name']); ?>
                                    <?php if (!empty($l['target_id'])): ?>
                                        <span class="text-dark-600 ml-1">#<?php echo htmlspecialchars($l['target_id']); ?></span>
                                    <?php endif; ?>
                                <?php elseif (!empty($l['target_type'])): ?>
                                    <?php echo htmlspecialchars($l['target_type']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 font-mono text-xs text-dark-400" title="<?php echo htmlspecialchars($l['real_ip'] ?? ''); ?>"><?php echo htmlspecialchars($l['ip'] ?? '-'); ?></td>
                            <td class="py-3 px-4 text-xs text-dark-400">
                                <?php if (!empty($l['duration'])): ?>
                                    <?php echo $l['duration'] > 1000 ? round($l['duration'] / 1000, 1) . 's' : $l['duration'] . 'ms'; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4">
                                <?php if ($l['result'] === 'success'): ?>
                                <span class="inline-flex items-center space-x-1 text-xs text-green-400">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>成功</span>
                                </span>
                                <?php elseif ($l['result'] === 'failure'): ?>
                                <span class="inline-flex items-center space-x-1 text-xs text-red-400">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    <span>失败</span>
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center space-x-1 text-xs text-yellow-400">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span>进行中</span>
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="10" class="py-12 text-center text-dark-500">暂无操作日志</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 分页 -->
        <?php if ($total > $perPage): ?>
        <div class="flex items-center justify-between mt-6">
            <span class="text-dark-400 text-sm">共 <?php echo number_format($total); ?> 条记录，第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
            <div class="flex items-center space-x-1">
                <?php
                $queryParams = http_build_query(array_diff_key($_GET, ['page' => '']));
                if ($page > 1): ?>
                <a href="?<?php echo $queryParams; ?>&page=<?php echo $page - 1; ?>" class="page-link">&laquo;</a>
                <?php endif; ?>
                <?php
                $start = max(1, $page - 3);
                $end = min($totalPages, $page + 3);
                for ($i = $start; $i <= $end; $i++): ?>
                <a href="?<?php echo $queryParams; ?>&page=<?php echo $i; ?>"
                   class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?<?php echo $queryParams; ?>&page=<?php echo $page + 1; ?>" class="page-link">&raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="/admin/assets/js/admin.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        // 刷新统计数据
        function refreshStats() {
            fetch('/api/log.php?action=stats')
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data) {
                        const d = res.data;
                        document.getElementById('stat-total').textContent = d.today_total.toLocaleString();
                        document.getElementById('stat-admin').textContent = d.today_admin.toLocaleString();
                        document.getElementById('stat-customer').textContent = d.today_customer.toLocaleString();
                        document.getElementById('stat-failure').textContent = d.today_failure.toLocaleString();
                        showToast('统计数据已刷新', 'success');
                    }
                })
                .catch(() => showToast('刷新失败', 'error'));
        }

        // 显示清空日志模态框
        function showClearModal() {
            const existingModal = document.getElementById('clear-modal');
            if (existingModal) existingModal.remove();

            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'clear-modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <h2 class="text-xl font-semibold text-dark-100 mb-4">清空操作日志</h2>
                    <div class="space-y-4">
                        <div class="p-3 rounded-lg bg-red-500/10 border border-red-500/20">
                            <p class="text-red-400 text-sm">此操作不可恢复，请谨慎操作。</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-dark-300 text-sm font-medium mb-1">开始日期（可选）</label>
                                <input type="date" name="clear_date_from" class="search-input w-full">
                            </div>
                            <div>
                                <label class="block text-dark-300 text-sm font-medium mb-1">结束日期（可选）</label>
                                <input type="date" name="clear_date_to" class="search-input w-full">
                            </div>
                        </div>
                        <p class="text-dark-500 text-xs">不填日期则清空全部日志。</p>
                        <div class="flex space-x-3 pt-2">
                            <button type="button" onclick="doClearLog()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm transition-colors flex-1">确认清空</button>
                            <button type="button" onclick="closeModal('clear-modal')" class="btn-secondary flex-1">取消</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        // 执行清空日志
        function doClearLog() {
            const modal = document.getElementById('clear-modal');
            const dateFrom = modal.querySelector('[name="clear_date_from"]').value;
            const dateTo = modal.querySelector('[name="clear_date_to"]').value;

            const formData = new FormData();
            formData.append('_token', csrfToken);
            if (dateFrom) formData.append('date_from', dateFrom);
            if (dateTo) formData.append('date_to', dateTo);

            fetch('/api/log.php?action=clear', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast(res.message || '日志已清空', 'success');
                        closeModal('clear-modal');
                        setTimeout(() => location.reload(), 500);
                    } else {
                        showToast(res.message || '清空失败', 'error');
                    }
                })
                .catch(() => showToast('操作失败', 'error'));
        }

        // 关闭模态框
        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.remove();
        }
    </script>
</body>
</html>
