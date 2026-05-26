<?php
/**
 * 灯塔DNS拦截响应平台 - 审查域名管理
 * 搜索、筛选、CRUD、模态框、分页、通过/拒绝操作
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

if (!$auth->hasPermission('domains_violation')) {
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

// ========== POST 操作处理 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    if (!CsrfProtection::validateFromRequest()) {
        die(json_encode(['success' => false, 'message' => 'CSRF验证失败']));
    }

    if ($action === 'add') {
        $domainName = sanitize(trim($_POST['domain_name'] ?? ''));
        $reason = sanitize(trim($_POST['violation_reason'] ?? ''));
        $email = sanitize(trim($_POST['customer_email'] ?? ''));
        if (empty($domainName) || empty($reason)) {
            die(json_encode(['success' => false, 'message' => '域名和审查原因不能为空']));
        }
        // 验证域名格式
        if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domainName)) {
            die(json_encode(['success' => false, 'message' => '域名格式无效']));
        }
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
            'domain_name'      => $domainName,
            'type'             => 'violation',
            'status'           => 'active',
            'violation_reason' => $reason,
            'review_date'      => date('Y-m-d H:i:s'),
            'customer_email'   => $email ?: null,
        ]);
        recordLog([
            'action'      => 'add',
            'module'      => 'domain',
            'description' => '添加审查域名: ' . $domainName,
            'target_type' => 'domain',
            'target_id'   => $domainName,
            'result'      => 'success',
        ]);
        die(json_encode(['success' => true, 'message' => '审查域名添加成功']));
    }

    if ($action === 'approve') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                die(json_encode(['success' => false, 'message' => '缺少域名ID']));
            }
            // 验证域名存在且为违规类型
            $domain = $db->queryOne("SELECT id FROM domains WHERE id = ? AND type = 'violation'", [$id]);
            if (!$domain) {
                die(json_encode(['success' => false, 'message' => '域名不存在']));
            }
            // 通过域名审核 + 同时通过该域名下的申诉（如果有）
            $db->update('domains', ['status' => 'released', 'review_status' => 'approved', 'release_date' => date('Y-m-d H:i:s'), 'review_note' => '管理员审核通过'], 'id = ? AND type = ?', [$id, 'violation']);
            // 同时通过该域名下所有pending/processing的申诉
            $db->execute(
                "UPDATE appeals SET status = 'approved', review_note = '管理员审核通过', reviewed_at = NOW() WHERE domain_id = ? AND status IN ('pending', 'processing')",
                [$id]
            );
            recordLog([
                'action'      => 'approve',
                'module'      => 'domain',
                'description' => '通过域名审核: ID=' . $id,
                'target_type' => 'domain',
                'target_id'   => (string)$id,
                'result'      => 'success',
            ]);
            die(json_encode(['success' => true, 'message' => '域名已通过审核，关联申诉已同步通过']));
        } catch (\Throwable $e) {
            error_log('[域名审核] 通过审核失败: ' . $e->getMessage());
            die(json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]));
        }
    }

    if ($action === 'reject') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                die(json_encode(['success' => false, 'message' => '缺少域名ID']));
            }
            $note = sanitize(trim($_POST['review_note'] ?? '审核未通过'));
            // 拒绝域名审核 + 同时拒绝该域名下的申诉（如果有）
            $db->update('domains', ['review_status' => 'rejected', 'review_note' => $note], 'id = ? AND type = ?', [$id, 'violation']);
            // 同时拒绝该域名下所有pending/processing的申诉
            $db->execute(
                "UPDATE appeals SET status = 'rejected', review_note = ?, reviewed_at = NOW() WHERE domain_id = ? AND status IN ('pending', 'processing')",
                [$note, $id]
            );
            recordLog([
                'action'      => 'reject',
                'module'      => 'domain',
                'description' => '拒绝域名审核: ID=' . $id,
                'target_type' => 'domain',
                'target_id'   => (string)$id,
                'result'      => 'success',
            ]);
            die(json_encode(['success' => true, 'message' => '已拒绝，关联申诉已同步拒绝']));
        } catch (\Throwable $e) {
            error_log('[域名审核] 拒绝审核失败: ' . $e->getMessage());
            die(json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]));
        }
    }

    die(json_encode(['success' => false, 'message' => '未知操作']));
}

// ========== GET 查询 ==========
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$search = $_GET['search'] ?? '';
$search = addcslashes($search, '%_');
$status = $_GET['status'] ?? '';

$where = ["type = 'violation'"];
$params = [];

if ($search) {
    $where[] = "(domain_name LIKE ? OR customer_email LIKE ? OR violation_reason LIKE ?)";
    $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
}
if ($status) {
    $where[] = "status = ?";
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);
$total = (int)$db->queryOne("SELECT COUNT(*) as cnt FROM domains WHERE {$whereClause}", $params)['cnt'];
$domains = $db->query(
    "SELECT d.*, 
            (SELECT COUNT(*) FROM appeals a WHERE a.domain_id = d.id AND a.status = 'pending') as appeal_count
     FROM domains d WHERE {$whereClause} ORDER BY d.created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, ($page - 1) * $perPage])
);
$totalPages = max(1, ceil($total / $perPage));
$csrfToken = CsrfProtection::getToken();
?>
<?php $pageTitle = '审查域名管理'; include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <div class="ml-64 p-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-dark-100">审查域名管理</h1>
                <p class="text-dark-400 text-sm mt-1">管理审查域名的审查与处理</p>
            </div>
            <div class="flex items-center space-x-3">
                <button onclick="showAddModal()" class="btn-primary text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m0-6H6"/>
                    </svg>
                    <span>添加审查域名</span>
                </button>
            </div>
        </div>

        <!-- 搜索过滤 -->
        <div class="stat-card mb-6">
            <form method="GET" class="flex items-center space-x-4 flex-wrap gap-y-2">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="搜索域名、邮箱或审查原因..."
                           class="search-input w-full">
                </div>
                <select name="status" class="search-input">
                    <option value="">全部状态</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>审查中</option>
                    <option value="released" <?php echo $status === 'released' ? 'selected' : ''; ?>>已通过</option>
                    <option value="pending_review" <?php echo $status === 'pending_review' ? 'selected' : ''; ?>>待审核</option>
                </select>
                <button type="submit" class="btn-primary text-sm">搜索</button>
                <a href="?" class="btn-secondary text-sm">重置</a>
            </form>
        </div>

        <!-- 域名列表 -->
        <div class="stat-card !p-0 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-dark-400 border-b border-dark-800/50 bg-dark-900/30">
                        <th class="text-left py-3 px-4 font-medium">域名</th>
                        <th class="text-left py-3 px-4 font-medium">审查原因</th>
                        <th class="text-left py-3 px-4 font-medium">审查时间</th>
                        <th class="text-left py-3 px-4 font-medium">客户邮箱</th>
                        <th class="text-left py-3 px-4 font-medium">申辩</th>
                        <th class="text-left py-3 px-4 font-medium">状态</th>
                        <th class="text-left py-3 px-4 font-medium">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($domains as $d):
                        $statusClass = match($d['status']) {
                            'active' => 'badge-danger',
                            'released' => 'badge-success',
                            'pending_review' => 'badge-purple',
                            default => 'badge-info'
                        };
                        $statusLabel = match($d['status']) {
                            'active' => '审查中',
                            'released' => '已通过',
                            'pending_review' => '待审核',
                            default => $d['status']
                        };
                        $reviewStatus = $d['review_status'] ?? 'pending';
                        $reviewClass = match($reviewStatus) {
                            'approved' => 'badge-success',
                            'rejected' => 'badge-danger',
                            default => 'badge-warning'
                        };
                        $reviewLabel = match($reviewStatus) {
                            'approved' => '审核通过',
                            'rejected' => '审核拒绝',
                            default => '待审核'
                        };
                    ?>
                    <tr class="table-row-hover">
                        <td class="py-3 px-4 font-mono text-red-400 text-xs"><?php echo htmlspecialchars($d['domain_name']); ?></td>
                        <td class="py-3 px-4 text-xs max-w-xs truncate" title="<?php echo htmlspecialchars($d['violation_reason'] ?? ''); ?>">
                            <?php echo htmlspecialchars(mb_substr($d['violation_reason'] ?? '-', 0, 50)); ?>
                        </td>
                        <td class="py-3 px-4 text-xs text-dark-400"><?php echo htmlspecialchars($d['review_date'] ?? $d['created_at']); ?></td>
                        <td class="py-3 px-4 text-xs"><?php echo htmlspecialchars($d['customer_email'] ?? '-'); ?></td>
                        <td class="py-3 px-4">
                            <?php if ($d['appeal_count'] > 0): ?>
                            <span class="badge badge-warning"><?php echo $d['appeal_count']; ?> 条申诉</span>
                            <?php else: ?>
                            <span class="text-dark-500 text-xs">无</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4">
                            <span class="badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                            <?php if ($reviewStatus !== 'pending'): ?>
                            <span class="badge <?php echo $reviewClass; ?> ml-1"><?php echo $reviewLabel; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4">
                            <div class="flex items-center space-x-2">
                                <button onclick="viewAppeal(<?php echo $d['id']; ?>, <?php echo htmlspecialchars(json_encode($d['domain_name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)" class="text-blue-400 hover:text-blue-300 text-xs transition-colors">查看</button>
                                <?php if ($d['id'] ?? false): ?>
                                <a href="/admin/chat/list.php?domain_id=<?php echo $d['id']; ?>" class="text-lighthouse-400 hover:text-lighthouse-300 text-xs transition-colors">会话</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($domains)): ?>
                    <tr><td colspan="7" class="py-12 text-center text-dark-500">暂无审查域名数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($total > $perPage): ?>
        <div class="flex items-center justify-between mt-6">
            <span class="text-dark-400 text-sm">共 <?php echo $total; ?> 条记录</span>
            <div class="flex items-center space-x-1">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>"
                   class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="/admin/assets/js/admin.js"></script>
    <script>
        // 备用：如果 admin.js 加载失败，使用内联自定义弹窗
        if (typeof showConfirm !== 'function') {
            console.warn('[审查域名] admin.js 加载失败，使用内联确认弹窗');
            window.showConfirm = function(message, options = {}) {
                const opts = Object.assign({ title: '操作确认', confirmText: '确认', cancelText: '取消' }, options);
                return new Promise((resolve) => {
                    const overlay = document.createElement('div');
                    overlay.className = 'fixed inset-0 z-[100] flex items-center justify-center';
                    overlay.style.cssText = 'background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);';
                    overlay.innerHTML = `
                        <div class="bg-dark-800 border border-dark-700 rounded-xl shadow-2xl p-6 mx-4 max-w-md w-full">
                            <h3 class="text-lg font-semibold text-dark-100 mb-3">${opts.title}</h3>
                            <p class="text-dark-300 text-sm mb-6 leading-relaxed">${message}</p>
                            <div class="flex space-x-3 justify-end">
                                <button class="js-cancel px-4 py-2 text-sm rounded-lg bg-dark-700 text-dark-300 hover:bg-dark-600 border border-dark-600">${opts.cancelText}</button>
                                <button class="js-ok px-4 py-2 text-sm rounded-lg text-white bg-red-600 hover:bg-red-700">${opts.confirmText}</button>
                            </div>
                        </div>`;
                    document.body.appendChild(overlay);
                    overlay.querySelector('.js-cancel').onclick = () => { overlay.remove(); resolve(false); };
                    overlay.querySelector('.js-ok').onclick = () => { overlay.remove(); resolve(true); };
                    overlay.addEventListener('click', (e) => { if (e.target === overlay) { overlay.remove(); resolve(false); } });
                });
            };
        }
        if (typeof showToast !== 'function') {
            window.showToast = function(msg, type) {
                const colors = { success: 'bg-green-600', error: 'bg-red-600', warning: 'bg-yellow-600' };
                const t = document.createElement('div');
                t.className = `fixed top-4 right-4 z-50 ${colors[type] || 'bg-blue-600'} text-white px-6 py-3 rounded-lg shadow-lg`;
                t.textContent = msg;
                document.body.appendChild(t);
                setTimeout(() => t.remove(), 3000);
            };
        }
        if (typeof closeModal !== 'function') {
            window.closeModal = function(id) {
                const el = document.getElementById(id);
                if (el) el.remove();
            };
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        function showAddModal() {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay'; modal.id = 'add-modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <h2 class="text-xl font-semibold text-dark-100 mb-6">添加审查域名</h2>
                    <form id="add-form" class="space-y-4">
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">域名 *</label>
                            <input type="text" name="domain_name" required placeholder="example.com" class="search-input w-full">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">审查原因 *</label>
                            <textarea name="violation_reason" required rows="3" placeholder="请描述违规原因" class="search-input w-full resize-none"></textarea>
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
                </div>`;
            document.body.appendChild(modal);
            modal.querySelector('#add-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const form = e.target;
                const fd = new FormData(form); fd.append('action','add'); fd.append('_token',csrfToken);
                fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
                    if(res.success){showToast(res.message,'success');closeModal('add-modal');setTimeout(()=>location.reload(),500);}
                    else if(res.code === 'DOMAIN_EXISTS'){ showDomainExistsModal(res.data.existing, form); }
                    else showToast(res.message,'error');
                }).catch(err=>{console.error('[add]',err);showToast('网络请求失败，请重试','error');});
            });
        }

        // 显示域名已存在提示模态框
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

        // 查看申诉材料
        function viewAppeal(domainId, domainName) {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay'; modal.id = 'appeal-view-modal';
            modal.innerHTML = `
                <div class="modal-content" style="max-width:640px;">
                    <h2 class="text-xl font-semibold text-dark-100 mb-4">审查详情 - ${escapeHtml(domainName)}</h2>
                    <div id="appeal-view-loading" class="text-center py-8 text-dark-400">加载中...</div>
                    <div id="appeal-view-content" class="hidden space-y-4 max-h-[60vh] overflow-y-auto"></div>
                    <div id="appeal-view-actions" class="hidden pt-4 border-t border-dark-800/50 mt-4 space-y-3">
                        <div id="unified-review-actions" class="flex items-center justify-end space-x-3">
                            <button id="unified-reject-btn" class="text-sm px-4 py-2 bg-orange-600 hover:bg-orange-500 text-white rounded-lg transition-colors">拒绝</button>
                            <button id="unified-approve-btn" class="text-sm px-4 py-2 bg-green-600 hover:bg-green-500 text-white rounded-lg transition-colors">通过</button>
                        </div>
                        <div id="reject-note-area" class="hidden">
                            <textarea id="reject-note-input" rows="3" placeholder="请输入拒绝理由..." class="search-input w-full text-sm resize-none mb-2"></textarea>
                            <div class="flex items-center justify-end space-x-3">
                                <button id="cancel-reject-btn" class="text-sm px-4 py-2 bg-dark-700 hover:bg-dark-600 text-dark-300 rounded-lg transition-colors">取消</button>
                                <button id="confirm-reject-btn" class="text-sm px-4 py-2 bg-orange-600 hover:bg-orange-500 text-white rounded-lg transition-colors">确认拒绝</button>
                            </div>
                        </div>
                        <div class="flex items-center justify-end space-x-3 pt-2">
                            <button onclick="closeModal('appeal-view-modal')" class="btn-secondary text-sm">关闭</button>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(modal);

            // 通过按钮：同时释放域名 + 通过申诉
            document.getElementById('unified-approve-btn').onclick = async function() {
                const confirmed = await showConfirm('确认通过审核？\n将通过域名审核并释放域名，同时通过关联的申诉（如有）。');
                if (!confirmed) return;
                const fd=new FormData(); fd.append('action','approve'); fd.append('id',domainId); fd.append('_token',csrfToken);
                fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
                    if(res.success){showToast(res.message,'success');closeModal('appeal-view-modal');setTimeout(()=>location.reload(),500);}else showToast(res.message,'error');
                }).catch(err=>{console.error('[approve]',err);showToast('网络请求失败，请重试','error');});
            };

            // 拒绝按钮：显示内联输入理由区域
            document.getElementById('unified-reject-btn').onclick = function() {
                document.getElementById('unified-review-actions').classList.add('hidden');
                document.getElementById('reject-note-area').classList.remove('hidden');
                document.getElementById('reject-note-input').focus();
            };

            // 取消拒绝
            document.getElementById('cancel-reject-btn').onclick = function() {
                document.getElementById('reject-note-area').classList.add('hidden');
                document.getElementById('unified-review-actions').classList.remove('hidden');
                document.getElementById('reject-note-input').value = '';
            };

            // 确认拒绝：同时设置review_status=rejected + 拒绝申诉
            document.getElementById('confirm-reject-btn').onclick = function() {
                const note = document.getElementById('reject-note-input').value.trim();
                if(!note) {
                    showToast('请输入拒绝理由', 'warning');
                    document.getElementById('reject-note-input').focus();
                    return;
                }
                const fd=new FormData(); fd.append('action','reject'); fd.append('id',domainId); fd.append('review_note',note); fd.append('_token',csrfToken);
                fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
                    if(res.success){showToast(res.message,'success');closeModal('appeal-view-modal');setTimeout(()=>location.reload(),500);}else showToast(res.message,'error');
                }).catch(err=>{console.error('[reject]',err);showToast('网络请求失败，请重试','error');});
            };

            // 加载申诉数据
            fetch('/api/appeal.php?action=list&domain_name=' + encodeURIComponent(domainName))
                .then(r => r.json())
                .then(res => {
                    document.getElementById('appeal-view-loading').classList.add('hidden');
                    const contentEl = document.getElementById('appeal-view-content');
                    const actionsEl = document.getElementById('appeal-view-actions');
                    if (res.success && res.data && res.data.appeals && res.data.appeals.length > 0) {
                        const appeal = res.data.appeals[0];
                        const statusMap = {pending:'待处理',processing:'处理中',approved:'已通过',rejected:'已拒绝'};
                        const statusClassMap = {pending:'badge-warning',processing:'badge-info',approved:'badge-success',rejected:'badge-danger'};
                        let evidenceHtml = '<span class="text-dark-500 text-xs">无</span>';
                        if (appeal.evidence_files) {
                            try {
                                const files = JSON.parse(appeal.evidence_files);
                                if (files.length > 0) {
                                    evidenceHtml = files.map(f => `<a href="${escapeHtml(f.startsWith('/') ? f : '/' + f)}" target="_blank" class="text-blue-400 hover:text-blue-300 text-xs underline break-all">${escapeHtml(f.split('/').pop())}</a>`).join('<br>');
                                }
                            } catch(e) {}
                        }
                        contentEl.innerHTML = `
                            <div class="grid grid-cols-2 gap-4">
                                <div><span class="text-dark-500 text-xs block mb-1">联系人</span><span class="text-dark-200 text-sm">${escapeHtml(appeal.customer_name || '-')}</span></div>
                                <div><span class="text-dark-500 text-xs block mb-1">联系邮箱</span><span class="text-dark-200 text-sm">${escapeHtml(appeal.customer_email || '-')}</span></div>
                                <div><span class="text-dark-500 text-xs block mb-1">联系电话</span><span class="text-dark-200 text-sm">${escapeHtml(appeal.customer_phone || '-')}</span></div>
                                <div><span class="text-dark-500 text-xs block mb-1">申诉时间</span><span class="text-dark-200 text-sm">${escapeHtml(appeal.created_at || '-')}</span></div>
                                <div><span class="text-dark-500 text-xs block mb-1">当前状态</span><span class="badge ${statusClassMap[appeal.status] || 'badge-info'}">${statusMap[appeal.status] || appeal.status}</span></div>
                            </div>
                            <div><span class="text-dark-500 text-xs block mb-1"></span><p class="text-dark-200 text-sm bg-dark-800/50 rounded-lg p-3 whitespace-pre-wrap">${escapeHtml(appeal.appeal_content || '-')}</p></div>
                            <div><span class="text-dark-500 text-xs block mb-1">证据文件</span><div class="mt-1">${evidenceHtml}</div></div>
                            ${appeal.review_note ? `<div><span class="text-dark-500 text-xs block mb-1">审核备注</span><p class="text-dark-300 text-sm">${escapeHtml(appeal.review_note)}</p></div>` : ''}
                        `;
                        contentEl.classList.remove('hidden');
                    } else {
                        contentEl.innerHTML = '<div class="text-center py-8 text-dark-500">暂无申诉记录</div>';
                        contentEl.classList.remove('hidden');
                    }
                    actionsEl.classList.remove('hidden');
                })
                .catch(err => {
                    document.getElementById('appeal-view-loading').classList.add('hidden');
                    document.getElementById('appeal-view-content').innerHTML = '<div class="text-center py-8 text-red-400">加载失败</div>';
                    document.getElementById('appeal-view-content').classList.remove('hidden');
                    document.getElementById('appeal-view-actions').classList.remove('hidden');
                });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

    </script>
</body>
</html>
