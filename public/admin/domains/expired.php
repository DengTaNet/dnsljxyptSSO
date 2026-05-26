<?php
/**
 * 灯塔DNS拦截响应平台 - 到期域名管理
 * 搜索、筛选、CRUD、模态框、分页、批量操作、导出
 */

use Core\Database;
use Core\Auth;
use Core\CsrfProtection;

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

if (!$auth->hasPermission('domains_expired')) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    if (!CsrfProtection::validateFromRequest()) {
        die(json_encode(['success' => false, 'message' => 'CSRF验证失败']));
    }

    if ($action === 'add') {
        $domainName = sanitize(trim($_POST['domain_name'] ?? ''));
        $expireDate = $_POST['expire_date'] ?? '';
        $customerEmail = sanitize(trim($_POST['customer_email'] ?? ''));

        if (empty($domainName)) {
            die(json_encode(['success' => false, 'message' => '域名不能为空']));
        }

        // 验证域名格式
        if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domainName)) {
            die(json_encode(['success' => false, 'message' => '域名格式无效']));
        }

        // 检查是否已存在（不限type）
        $existing = $db->queryOne("SELECT id, domain_name, type, status, expire_date, customer_email, customer_name FROM domains WHERE domain_name = ?", [$domainName]);
        if ($existing) {
            die(json_encode([
                'success' => false,
                'code'    => 'DOMAIN_EXISTS',
                'message' => '该域名已存在',
                'data'    => ['existing' => $existing]
            ]));
        }

        $db->insert('domains', [
            'domain_name'    => $domainName,
            'type'           => 'expired',
            'status'         => 'active',
            'expire_date'    => $expireDate ?: null,
            'delete_date'    => $expireDate ? date('Y-m-d', strtotime($expireDate . ' + 7 days')) : null,
            'customer_email' => $customerEmail ?: null,
        ]);
        die(json_encode(['success' => true, 'message' => '域名添加成功']));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->delete('domains', 'id = ? AND type = ?', [$id, 'expired']);
            die(json_encode(['success' => true, 'message' => '域名已删除']));
        }
    }

    if ($action === 'batch_delete') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids)) {
            die(json_encode(['success' => false, 'message' => '无效的请求数据']));
        }
        if (!empty($ids)) {
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->execute("DELETE FROM domains WHERE id IN ({$placeholders}) AND type = 'expired'", $ids);
            die(json_encode(['success' => true, 'message' => '批量删除成功']));
        }
    }

    die(json_encode(['success' => false, 'message' => '未知操作']));
}

// ========== 导出功能 ==========
// 注意：导出功能已有认证保护，上方已调用 $auth->requireAuth()，未登录用户无法访问此功能
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    if (!in_array($format, ['csv', 'json'], true)) {
        die(json_encode(['success' => false, 'message' => '不支持的导出格式']));
    }
    $where = ["type = 'expired'"];
    $params = [];
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    if ($search) {
        $search = addcslashes($search, '%_');
        $where[] = "(domain_name LIKE ? OR customer_email LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    if ($status) {
        if ($status === 'deleting') {
            $where[] = "delete_date IS NOT NULL AND delete_date <= CURDATE()";
        } else {
            $where[] = "status = ?";
            $params[] = $status;
        }
    }
    $whereClause = implode(' AND ', $where);
    $domains = $db->query("SELECT * FROM domains WHERE {$whereClause} ORDER BY created_at DESC LIMIT 10000", $params);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=expired_domains_' . date('Ymd') . '.csv');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['ID', '域名', '过期日期', '删除日期', '状态', '客户邮箱', '创建时间']);
        foreach ($domains as $d) {
            $statusMap = ['active' => '正常', 'inactive' => '停用'];
            fputcsv($fp, [
                $d['id'],
                $d['domain_name'],
                $d['expire_date'],
                $d['delete_date'],
                $statusMap[$d['status']] ?? $d['status'],
                $d['customer_email'],
                $d['created_at'],
            ]);
        }
        fclose($fp);
        exit;
    }
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=expired_domains_' . date('Ymd') . '.json');
        echo json_encode($domains, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// ========== GET 查询 ==========
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$search = $_GET['search'] ?? '';
$search = addcslashes($search, '%_');
$status = $_GET['status'] ?? '';

$where = ["type = 'expired'"];
$params = [];

if ($search) {
    $where[] = "(domain_name LIKE ? OR customer_email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($status) {
    if ($status === 'deleting') {
        $where[] = "delete_date IS NOT NULL AND delete_date <= CURDATE()";
    } else {
        $where[] = "status = ?";
        $params[] = $status;
    }
}

$whereClause = implode(' AND ', $where);
$total = (int)$db->queryOne("SELECT COUNT(*) as cnt FROM domains WHERE {$whereClause}", $params)['cnt'];
$domains = $db->query(
    "SELECT * FROM domains WHERE {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, ($page - 1) * $perPage])
);
$totalPages = max(1, ceil($total / $perPage));

$csrfToken = CsrfProtection::getToken();
?>
<?php $pageTitle = '到期域名管理'; include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <div class="ml-64 p-8">
        <!-- 顶部栏 -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-dark-100">到期域名管理</h1>
                <p class="text-dark-400 text-sm mt-1">管理到期域名的拦截与释放</p>
            </div>
            <div class="flex items-center space-x-3">
                <button onclick="showAddModal()" class="btn-primary text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    <span>添加域名</span>
                </button>
            </div>
        </div>

        <!-- 搜索过滤栏 -->
        <div class="stat-card mb-6">
            <form method="GET" class="flex items-center space-x-4 flex-wrap gap-y-2">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="搜索域名或邮箱..."
                           class="search-input w-full">
                </div>
                <select name="status" class="search-input">
                    <option value="">全部状态</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>正常</option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>停用</option>
                    <option value="deleting" <?php echo $status === 'deleting' ? 'selected' : ''; ?>>删除队列</option>
                </select>
                <button type="submit" class="btn-primary text-sm">搜索</button>
                <a href="?" class="btn-secondary text-sm">重置</a>
                <div class="border-l border-dark-700 pl-4 flex items-center space-x-2">
                    <span class="text-dark-500 text-xs">导出:</span>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="text-lighthouse-400 hover:text-lighthouse-300 text-xs">CSV</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'json'])); ?>" class="text-lighthouse-400 hover:text-lighthouse-300 text-xs">JSON</a>
                </div>
            </form>
        </div>

        <!-- 批量操作栏 -->
        <div id="batch-bar" class="hidden stat-card mb-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <span class="text-sm text-dark-300">已选择 <span id="selected-count" class="text-lighthouse-400 font-bold">0</span> 项</span>
                <button onclick="batchDelete()" class="text-red-400 hover:text-red-300 text-sm flex items-center space-x-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span>批量删除</span>
                </button>
            </div>
            <button onclick="clearSelection()" class="text-dark-400 hover:text-dark-200 text-sm">取消选择</button>
        </div>

        <!-- 域名列表 -->
        <div class="stat-card !p-0 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-dark-400 border-b border-dark-800/50 bg-dark-900/30">
                        <th class="text-left py-3 px-4 w-8">
                            <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" class="rounded border-dark-700 bg-dark-800 text-lighthouse-500 cursor-pointer">
                        </th>
                        <th class="text-left py-3 px-4 font-medium">域名</th>
                        <th class="text-left py-3 px-4 font-medium">到期时间</th>
                        <th class="text-left py-3 px-4 font-medium">删除时间</th>
                        <th class="text-left py-3 px-4 font-medium">状态</th>
                        <th class="text-left py-3 px-4 font-medium">客户邮箱</th>
                        <th class="text-left py-3 px-4 font-medium">创建时间</th>
                        <th class="text-left py-3 px-4 font-medium">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($domains as $d):
                        // 判断是否超过删除期限
                        $isPastDelete = !empty($d['delete_date']) && strtotime($d['delete_date'] . ' 23:59:59') <= time();
                        $statusClass = $isPastDelete ? 'badge-danger' : match($d['status']) {
                            'active' => 'badge-warning',
                            'inactive' => 'badge-danger',
                            default => 'badge-info'
                        };
                        $statusLabel = $isPastDelete ? '删除队列' : match($d['status']) {
                            'active' => '正常',
                            'inactive' => '停用',
                            default => $d['status']
                        };
                        $isExpiring = $d['expire_date'] && strtotime($d['expire_date']) < strtotime('+7 days') && strtotime($d['expire_date']) >= time();
                    ?>
                    <tr class="table-row-hover">
                        <td class="py-3 px-4">
                            <input type="checkbox" class="row-checkbox rounded border-dark-700 bg-dark-800 text-lighthouse-500 cursor-pointer" value="<?php echo $d['id']; ?>" onchange="updateSelection()">
                        </td>
                        <td class="py-3 px-4 font-mono text-lighthouse-400 text-xs"><?php echo htmlspecialchars($d['domain_name']); ?></td>
                        <td class="py-3 px-4 text-xs <?php echo $isExpiring ? 'text-orange-400 font-semibold' : 'text-dark-300'; ?>">
                            <?php echo htmlspecialchars($d['expire_date'] ?? '-'); ?>
                            <?php if ($isExpiring): ?><span class="ml-1 badge badge-danger text-[10px]">即将到期</span><?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-xs text-dark-400"><?php echo htmlspecialchars($d['delete_date'] ?? '-'); ?></td>
                        <td class="py-3 px-4">
                            <span class="badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                        </td>
                        <td class="py-3 px-4 text-xs"><?php echo htmlspecialchars($d['customer_email'] ?? '-'); ?></td>
                        <td class="py-3 px-4 text-xs text-dark-400"><?php echo htmlspecialchars($d['created_at']); ?></td>
                        <td class="py-3 px-4" id="action-cell-<?php echo $d['id']; ?>">
                            <div class="flex items-center space-x-2">
                                <button onclick="editDomain(<?php echo $d['id']; ?>)" class="text-blue-400 hover:text-blue-300 text-xs transition-colors" title="编辑">编辑</button>
                                <button onclick="deleteDomain(<?php echo $d['id']; ?>)" class="text-red-400 hover:text-red-300 text-xs transition-colors" title="删除">删除</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($domains)): ?>
                    <tr><td colspan="8" class="py-12 text-center text-dark-500">暂无到期域名数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($total > $perPage): ?>
        <div class="flex items-center justify-between mt-6">
            <span class="text-dark-400 text-sm">共 <?php echo $total; ?> 条记录，第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
            <div class="flex items-center space-x-1">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>" class="page-link">&laquo;</a>
                <?php endif; ?>
                <?php
                $start = max(1, $page - 3);
                $end = min($totalPages, $page + 3);
                for ($i = $start; $i <= $end; $i++):
                ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>"
                   class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>" class="page-link">&raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="/admin/assets/js/admin.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        // 根据到期时间自动计算删除时间（+7天）
        function autoCalcDeleteDate(expireId, deleteId) {
            const expireVal = document.getElementById(expireId)?.value;
            const deleteInput = document.getElementById(deleteId);
            if (!deleteInput) return;
            if (expireVal) {
                const d = new Date(expireVal);
                d.setDate(d.getDate() + 7);
                deleteInput.value = d.toISOString().split('T')[0];
            } else {
                deleteInput.value = '';
            }
        }

        // 显示添加模态框
        function showAddModal() {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'add-modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <h2 class="text-xl font-semibold text-dark-100 mb-6">添加到期域名</h2>
                    <form id="add-form" class="space-y-4">
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">域名 *</label>
                            <input type="text" name="domain_name" required placeholder="example.com" class="search-input w-full">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">到期时间</label>
                            <input type="date" name="expire_date" id="add-expire-date" class="search-input w-full" onchange="autoCalcDeleteDate('add-expire-date','add-delete-date')">
                            <p class="text-dark-500 text-xs mt-1">到期后7天自动标记删除</p>
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">删除时间</label>
                            <input type="date" name="delete_date" id="add-delete-date" readonly class="search-input w-full bg-dark-800 cursor-not-allowed" style="opacity:0.6">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">客户邮箱</label>
                            <input type="email" name="customer_email" placeholder="customer@example.com" class="search-input w-full">
                        </div>
                        <div class="flex space-x-3 pt-2">
                            <button type="submit" class="btn-primary flex-1">确认添加</button>
                            <button type="button" onclick="closeModal('add-modal')" class="btn-secondary flex-1">取消</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(modal);
            modal.querySelector('#add-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const form = e.target;
                const data = new FormData(form);
                data.append('action', 'add');
                data.append('_token', csrfToken);
                fetch(window.location.href, { method: 'POST', body: data })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) { showToast(res.message, 'success'); closeModal('add-modal'); setTimeout(() => location.reload(), 500); }
                        else if (res.code === 'DOMAIN_EXISTS') {
                            showDomainExistsModal(res.data.existing, form);
                        }
                        else showToast(res.message, 'error');
                    })
                    .catch(() => showToast('操作失败', 'error'));
            });
        }

        // 显示域名已存在提示模态框
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        function showDomainExistsModal(existing, addForm) {
            const typeMap = { expired: '到期域名', violation: '审查域名' };
            const statusMap = { active: '正常', inactive: '停用', released: '已释放', pending_review: '待审核' };
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'domain-exists-modal';
            modal.innerHTML = `
                <div class="modal-content" style="max-width:480px;">
                    <h2 class="text-xl font-semibold text-dark-100 mb-4">域名已存在</h2>
                    <p class="text-dark-300 text-sm mb-4">该域名已在系统中存在，详细信息如下：</p>
                    <div class="bg-dark-800 rounded-lg p-4 mb-6 space-y-2 text-sm">
                        <div class="flex"><span class="text-dark-400 w-24">域名：</span><span class="text-lighthouse-400 font-mono">${escapeHtml(existing.domain_name)}</span></div>
                        <div class="flex"><span class="text-dark-400 w-24">类型：</span><span>${escapeHtml(typeMap[existing.type] || existing.type)}</span></div>
                        <div class="flex"><span class="text-dark-400 w-24">状态：</span><span>${escapeHtml(statusMap[existing.status] || existing.status)}</span></div>
                        <div class="flex"><span class="text-dark-400 w-24">过期日期：</span><span>${escapeHtml(existing.expire_date || '-')}</span></div>
                        <div class="flex"><span class="text-dark-400 w-24">客户邮箱：</span><span>${escapeHtml(existing.customer_email || '-')}</span></div>
                        <div class="flex"><span class="text-dark-400 w-24">客户名称：</span><span>${escapeHtml(existing.customer_name || '-')}</span></div>
                    </div>
                    <div class="flex space-x-3">
                        <button id="btn-release-existing" class="flex-1 text-sm px-4 py-2 bg-red-600 hover:bg-red-500 text-white rounded-lg transition-colors">释放已有域名</button>
                        <button onclick="closeModal('domain-exists-modal')" class="btn-secondary flex-1 text-sm">取消</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            // 释放已有域名（删除）
            modal.querySelector('#btn-release-existing').addEventListener('click', () => {
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', existing.id);
                fd.append('_token', csrfToken);
                fetch('/api/domain.php?action=delete', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            showToast('已有域名已删除，正在重新添加...', 'success');
                            closeModal('domain-exists-modal');
                            // 重新提交添加表单
                            const data = new FormData(addForm);
                            data.append('action', 'add');
                            data.append('_token', csrfToken);
                            fetch(window.location.href, { method: 'POST', body: data })
                                .then(r => r.json())
                                .then(res2 => {
                                    if (res2.success) { showToast(res2.message, 'success'); closeModal('add-modal'); setTimeout(() => location.reload(), 500); }
                                    else showToast(res2.message, 'error');
                                })
                                .catch(() => showToast('操作失败', 'error'));
                        } else {
                            showToast(res.message || '删除失败', 'error');
                        }
                    })
                    .catch(() => showToast('操作失败', 'error'));
            });
        }

        // 倒计时定时器存储
        // 删除域名（二次确认后立即永久删除）
        function deleteDomain(id) {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'delete-confirm-modal';
            modal.innerHTML = `
                <div class="modal-content" style="max-width:420px;">
                    <h2 class="text-xl font-semibold text-dark-100 mb-4">删除确认</h2>
                    <p class="text-dark-300 text-sm mb-2">确定要永久删除该域名吗？</p>
                    <p class="text-red-400 text-xs mb-6">此操作不可恢复，删除后该域名的所有数据将被永久移除。</p>
                    <div class="flex space-x-3">
                        <button onclick="closeModal('delete-confirm-modal')" class="btn-secondary flex-1 text-sm">取消</button>
                        <button id="btn-confirm-delete" class="flex-1 text-sm px-4 py-2 bg-red-600 hover:bg-red-500 text-white rounded-lg transition-colors">确认删除</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            modal.querySelector('#btn-confirm-delete').addEventListener('click', () => {
                closeModal('delete-confirm-modal');
                executePermanentDelete(id);
            });
        }

        // 执行永久删除
        function executePermanentDelete(id) {
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            fd.append('_token', csrfToken);
            fetch('/api/domain.php?action=delete', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast(res.message || '域名已永久删除', 'success');
                        setTimeout(() => location.reload(), 500);
                    } else {
                        showToast(res.message || '删除失败', 'error');
                    }
                })
                .catch(() => showToast('操作失败', 'error'));
        }

        // 批量选择
        function toggleSelectAll(el) {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = el.checked);
            updateSelection();
        }
        function updateSelection() {
            const checked = document.querySelectorAll('.row-checkbox:checked');
            const bar = document.getElementById('batch-bar');
            document.getElementById('selected-count').textContent = checked.length;
            bar.classList.toggle('hidden', checked.length === 0);
        }
        function clearSelection() {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('select-all').checked = false;
            updateSelection();
        }
        async function batchDelete() {
            const ids = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
            if (!ids.length) return;
            const confirmed = await showConfirm(`确认删除选中的 ${ids.length} 个域名？此操作不可撤销。`);
            if (!confirmed) return;
            const fd = new FormData();
            fd.append('action', 'batch_delete');
            fd.append('ids', JSON.stringify(ids));
            fd.append('_token', csrfToken);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => { if (res.success) { showToast(res.message, 'success'); setTimeout(() => location.reload(), 500); } else showToast(res.message, 'error'); });
        }

        // 编辑域名
        function editDomain(id) {
            // 先通过API获取域名详情
            fetch('/api/domain.php?action=list&type=expired&page=1&per_page=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(() => {
                    // 直接从当前页面行数据获取，避免额外API调用
                    // 使用list API获取单条记录
                    fetch('/api/domain.php?action=list&type=expired', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(r => r.json())
                        .then(res => {
                            if (!res.success && !res.data) {
                                // 兼容不同响应格式
                                showToast('获取域名详情失败', 'error');
                                return;
                            }
                            const domains = res.data?.domains || res.domains || [];
                            const domain = domains.find(d => d.id == id);
                            if (!domain) {
                                showToast('未找到该域名', 'error');
                                return;
                            }
                            showEditModal(domain);
                        })
                        .catch(() => showToast('获取域名详情失败', 'error'));
                });
        }

        // 显示编辑模态框
        function showEditModal(domain) {
            // 移除已有的编辑模态框
            const existingModal = document.getElementById('edit-modal');
            if (existingModal) existingModal.remove();

            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'edit-modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <h2 class="text-xl font-semibold text-dark-100 mb-6">编辑到期域名</h2>
                    <form id="edit-form" class="space-y-4">
                        <input type="hidden" name="id" value="${domain.id}">
                        <input type="hidden" name="expired_edit" value="1">
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">域名</label>
                            <input type="text" name="domain_name" value="${domain.domain_name}" readonly class="search-input w-full bg-dark-800 cursor-not-allowed" style="opacity:0.6">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">过期日期</label>
                            <input type="date" name="expire_date" id="edit-expire-date" value="${domain.expire_date || ''}" class="search-input w-full" onchange="autoCalcDeleteDate('edit-expire-date','edit-delete-date')">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">删除时间</label>
                            <input type="date" name="delete_date" id="edit-delete-date" value="${domain.delete_date || ''}" readonly class="search-input w-full bg-dark-800 cursor-not-allowed" style="opacity:0.6">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">客户邮箱</label>
                            <input type="email" name="customer_email" value="${domain.customer_email || ''}" placeholder="customer@example.com" class="search-input w-full">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">客户名称</label>
                            <input type="text" name="customer_name" value="${domain.customer_name || ''}" placeholder="客户名称（可选）" class="search-input w-full">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">备注</label>
                            <textarea name="notes" rows="3" placeholder="备注信息（可选）" class="search-input w-full">${domain.notes || ''}</textarea>
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">状态</label>
                            <select name="status" class="search-input w-full">
                                <option value="active" ${domain.status === 'active' ? 'selected' : ''}>正常</option>
                                <option value="inactive" ${domain.status === 'inactive' ? 'selected' : ''}>停用</option>
                            </select>
                        </div>
                        <div class="flex space-x-3 pt-2">
                            <button type="submit" class="btn-primary flex-1">保存修改</button>
                            <button type="button" onclick="closeModal('edit-modal')" class="btn-secondary flex-1">取消</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(modal);
            // 初始化编辑模态框的删除时间
            autoCalcDeleteDate('edit-expire-date', 'edit-delete-date');
            modal.querySelector('#edit-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const form = e.target;
                const data = new FormData(form);
                data.append('action', 'update');
                data.append('_token', csrfToken);
                fetch('/api/domain.php?action=update', { method: 'POST', body: data })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) { showToast(res.message || '更新成功', 'success'); closeModal('edit-modal'); setTimeout(() => location.reload(), 500); }
                        else showToast(res.message || '更新失败', 'error');
                    })
                    .catch(() => showToast('操作失败', 'error'));
            });
        }

    </script>
</body>
</html>
