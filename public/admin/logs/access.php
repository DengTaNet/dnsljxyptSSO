<?php
/**
 * 灯塔DNS拦截响应平台 - 访问记录
 * 日期范围筛选、IP/域名/浏览器筛选、分页、CSV/JSON导出
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

if (!$auth->hasPermission('logs')) {
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

// ========== 辅助函数 ==========
if (!function_exists('buildWhereClause')) {
function buildWhereClause() {
    $where = ['1=1'];
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $ip = $_GET['ip'] ?? '';
    $ip = addcslashes($ip, '%_');
    $domain = $_GET['domain'] ?? '';
    $domain = addcslashes($domain, '%_');
    $browser = $_GET['browser'] ?? '';
    $browser = addcslashes($browser, '%_');
    if ($dateFrom) $where[] = "al.created_at >= ?";
    if ($dateTo) $where[] = "al.created_at <= ?";
    if ($ip) $where[] = "(al.ip LIKE ? OR al.real_ip LIKE ?)";
    if ($domain) $where[] = "d.domain_name LIKE ?";
    if ($browser) $where[] = "al.browser LIKE ?";
    return implode(' AND ', $where);
}
}

if (!function_exists('buildWhereParams')) {
function buildWhereParams() {
    $params = [];
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $ip = $_GET['ip'] ?? '';
    $ip = addcslashes($ip, '%_');
    $domain = $_GET['domain'] ?? '';
    $domain = addcslashes($domain, '%_');
    $browser = $_GET['browser'] ?? '';
    $browser = addcslashes($browser, '%_');
    if ($dateFrom) $params[] = $dateFrom . ' 00:00:00';
    if ($dateTo) $params[] = $dateTo . ' 23:59:59';
    if ($ip) { $params[] = "%{$ip}%"; $params[] = "%{$ip}%"; }
    if ($domain) $params[] = "%{$domain}%";
    if ($browser) $params[] = "%{$browser}%";
    return $params;
}
}

// ========== 导出功能 ==========
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    if (!in_array($format, ['csv', 'json'], true)) {
        die(json_encode(['success' => false, 'message' => '不支持的导出格式']));
    }
    $where = buildWhereClause();
    $params = buildWhereParams();
    $logs = $db->query("SELECT al.*, d.domain_name FROM access_logs al LEFT JOIN domains d ON al.domain_id = d.id WHERE {$where} ORDER BY al.created_at DESC LIMIT 10000", $params);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=access_logs_' . date('Ymd') . '.csv');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['时间', '域名', '访问IP', '真实IP', 'MAC地址', '浏览器', '操作系统', '来源页面', '请求URL', '检测来源']);
        foreach ($logs as $l) {
            fputcsv($fp, [
                $l['created_at'],
                $l['domain_name'],
                $l['ip'],
                $l['real_ip'],
                $l['mac'],
                $l['browser'] . ' ' . $l['browser_version'],
                $l['os'],
                $l['referer'],
                $l['requested_url'],
                $l['detection_source'],
            ]);
        }
        fclose($fp);
        exit;
    }
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=access_logs_' . date('Ymd') . '.json');
        echo json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// ========== GET 查询 ==========
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$where = buildWhereClause();
$params = buildWhereParams();

$total = (int)$db->queryOne(
    "SELECT COUNT(*) as cnt FROM access_logs al LEFT JOIN domains d ON al.domain_id = d.id WHERE {$where}",
    $params
)['cnt'];

$logs = $db->query(
    "SELECT al.*, d.domain_name FROM access_logs al LEFT JOIN domains d ON al.domain_id = d.id WHERE {$where} ORDER BY al.created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, ($page - 1) * $perPage])
);
$totalPages = max(1, ceil($total / $perPage));
?>
<?php $pageTitle = '访问记录'; include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <div class="ml-64 p-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-dark-100">访问记录</h1>
                <p class="text-dark-400 text-sm mt-1">查看所有域名访问日志</p>
            </div>
            <div class="flex items-center space-x-2">
                <span class="text-dark-500 text-xs">导出:</span>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="text-lighthouse-400 hover:text-lighthouse-300 text-sm">CSV</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'json'])); ?>" class="text-lighthouse-400 hover:text-lighthouse-300 text-sm">JSON</a>
            </div>
        </div>

        <!-- 筛选栏 -->
        <div class="stat-card mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <div>
                    <label class="block text-dark-400 text-xs mb-1">开始日期</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>" class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">结束日期</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>" class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">IP地址</label>
                    <input type="text" name="ip" value="<?php echo htmlspecialchars($_GET['ip'] ?? ''); ?>" placeholder="搜索IP..." class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">域名</label>
                    <input type="text" name="domain" value="<?php echo htmlspecialchars($_GET['domain'] ?? ''); ?>" placeholder="搜索域名..." class="search-input w-full">
                </div>
                <div>
                    <label class="block text-dark-400 text-xs mb-1">浏览器</label>
                    <input type="text" name="browser" value="<?php echo htmlspecialchars($_GET['browser'] ?? ''); ?>" placeholder="搜索浏览器..." class="search-input w-full">
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="btn-primary text-sm flex-1">搜索</button>
                    <a href="?" class="btn-secondary text-sm">重置</a>
                </div>
            </form>
        </div>

        <!-- 访问记录表格 -->
        <div class="stat-card !p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-dark-400 border-b border-dark-800/50 bg-dark-900/30">
                            <th class="text-left py-3 px-4 font-medium">时间</th>
                            <th class="text-left py-3 px-4 font-medium">域名</th>
                            <th class="text-left py-3 px-4 font-medium">访问IP</th>
                            <th class="text-left py-3 px-4 font-medium">真实IP</th>
                            <th class="text-left py-3 px-4 font-medium">MAC地址</th>
                            <th class="text-left py-3 px-4 font-medium">浏览器</th>
                            <th class="text-left py-3 px-4 font-medium">操作系统</th>
                            <th class="text-left py-3 px-4 font-medium">来源页面</th>
                            <th class="text-left py-3 px-4 font-medium">请求URL</th>
                            <th class="text-left py-3 px-4 font-medium">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                        <tr class="table-row-hover">
                            <td class="py-3 px-4 text-xs text-dark-400 whitespace-nowrap"><?php echo htmlspecialchars($l['created_at']); ?></td>
                            <td class="py-3 px-4 font-mono text-lighthouse-400 text-xs"><?php echo htmlspecialchars($l['domain_name'] ?? '-'); ?></td>
                            <td class="py-3 px-4 font-mono text-xs"><?php echo htmlspecialchars($l['ip']); ?></td>
                            <td class="py-3 px-4 font-mono text-xs"><?php echo htmlspecialchars($l['real_ip'] ?? '-'); ?></td>
                            <td class="py-3 px-4 font-mono text-xs text-dark-500"><?php echo htmlspecialchars($l['mac'] ?? '-'); ?></td>
                            <td class="py-3 px-4 text-xs"><?php echo htmlspecialchars(($l['browser'] ?? '') . ' ' . ($l['browser_version'] ?? '')); ?></td>
                            <td class="py-3 px-4 text-xs"><?php echo htmlspecialchars($l['os'] ?? '-'); ?></td>
                            <td class="py-3 px-4 text-xs max-w-[150px] truncate" title="<?php echo htmlspecialchars($l['referer'] ?? ''); ?>"><?php echo htmlspecialchars($l['referer'] ?? '-'); ?></td>
                            <td class="py-3 px-4 text-xs max-w-[200px] truncate" title="<?php echo htmlspecialchars($l['requested_url'] ?? ''); ?>"><?php echo htmlspecialchars($l['requested_url'] ?? '-'); ?></td>
                            <td class="py-3 px-4">
                                <button onclick="showBanModal('<?php echo htmlspecialchars($l['ip']); ?>')" class="text-red-400 hover:text-red-300 text-xs transition-colors flex items-center space-x-1" title="封禁IP">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <span>封禁</span>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="10" class="py-12 text-center text-dark-500">暂无访问记录</td></tr>
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

        // 显示封禁模态框
        function showBanModal(ip) {
            // 移除已有的封禁模态框
            const existingModal = document.getElementById('ban-modal');
            if (existingModal) existingModal.remove();

            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'ban-modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <h2 class="text-xl font-semibold text-dark-100 mb-6">封禁IP地址</h2>
                    <form id="ban-form" class="space-y-4">
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">IP地址</label>
                            <input type="text" name="ip" value="${ip}" readonly class="search-input w-full bg-dark-800 cursor-not-allowed" style="opacity:0.6">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-2">封禁类型</label>
                            <div class="space-y-2">
                                <label class="flex items-center space-x-2 cursor-pointer">
                                    <input type="radio" name="ban_type" value="temporary" checked class="rounded border-dark-700 bg-dark-800 text-lighthouse-500">
                                    <span class="text-sm text-dark-200">临时封禁（24小时）</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer">
                                    <input type="radio" name="ban_type" value="permanent" class="rounded border-dark-700 bg-dark-800 text-lighthouse-500">
                                    <span class="text-sm text-dark-200">永久封禁</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">封禁理由 *</label>
                            <textarea name="reason" rows="3" required placeholder="请输入封禁理由（必填）" class="search-input w-full"></textarea>
                        </div>
                        <div class="flex space-x-3 pt-2">
                            <button type="submit" class="btn-primary flex-1">确认封禁</button>
                            <button type="button" onclick="closeModal('ban-modal')" class="btn-secondary flex-1">取消</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(modal);
            modal.querySelector('#ban-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const form = e.target;
                const reason = form.querySelector('[name="reason"]').value.trim();
                if (!reason) {
                    showToast('请输入封禁理由', 'error');
                    return;
                }
                const data = new FormData(form);
                data.append('_token', csrfToken);
                fetch('/api/ipban.php?action=add', { method: 'POST', body: data })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            showToast(res.message || '封禁成功', 'success');
                            closeModal('ban-modal');
                            setTimeout(() => location.reload(), 500);
                        } else {
                            showToast(res.message || '封禁失败', 'error');
                        }
                    })
                    .catch(() => showToast('操作失败', 'error'));
            });
        }

        // 关闭模态框
        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.remove();
        }
    </script>
</body>
</html>
