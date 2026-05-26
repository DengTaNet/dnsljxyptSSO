<?php
/**
 * 灯塔DNS拦截响应平台 - 组织管理
 * 管理员列表、添加/编辑组织成员信息、角色与权限分配
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

// 仅超级管理员可访问
if ($currentUser['role'] !== 'super_admin') {
    http_response_code(403);
    echo '<div class="flex items-center justify-center h-screen"><div class="text-center"><h2 class="text-2xl font-bold text-red-400 mb-2">访问被拒绝</h2><p class="text-gray-400">您没有权限访问此页面</p><a href="/admin/" class="text-blue-400 hover:underline mt-4 inline-block">返回首页</a></div></div>';
    exit;
}

$csrfToken = CsrfProtection::getToken();
$roles = Auth::getAllRoles();
$modules = Auth::getAllModules();
?>
<?php $pageTitle = '组织管理'; include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <div class="ml-64 p-8">
        <!-- 顶部栏 -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-dark-100">组织管理</h1>
                <p class="text-dark-400 text-sm mt-1">管理平台组织账户与权限分配</p>
            </div>
            <div class="flex items-center space-x-3">
                <button onclick="showStaffModal()" class="btn-primary text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    <span>添加组织用户</span>
                </button>
            </div>
        </div>

        <!-- 权限模块说明 -->
        <div class="stat-card mb-6">
            <h3 class="text-sm font-semibold text-dark-200 mb-3">预设角色权限说明</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                <?php
                $roleDesc = [
                    'super_admin'  => ['label' => '超级管理员', 'perms' => '全部权限', 'color' => 'text-red-400'],
                    'admin'        => ['label' => '管理员', 'perms' => '全部权限', 'color' => 'text-orange-400'],
                    'operator'     => ['label' => '运维人员', 'perms' => '仪表盘、到期域名、审查域名、客服中心、访问记录、日志中心', 'color' => 'text-blue-400'],
                    'domain_admin' => ['label' => '域名管理员', 'perms' => '仪表盘、到期域名、审查域名', 'color' => 'text-purple-400'],
                    'support'      => ['label' => '客服专员', 'perms' => '仪表盘、客服中心', 'color' => 'text-green-400'],
                    'security'     => ['label' => '安全专员', 'perms' => '仪表盘、IP封禁、访问记录、日志中心', 'color' => 'text-yellow-400'],
                    'auditor'      => ['label' => '审计员', 'perms' => '仪表盘、访问记录、日志中心', 'color' => 'text-cyan-400'],
                ];
                foreach ($roleDesc as $key => $desc):
                ?>
                <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                    <span class="font-semibold <?php echo $desc['color']; ?>"><?php echo $desc['label']; ?></span>
                    <p class="text-dark-400 mt-1"><?php echo $desc['perms']; ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 管理员列表 -->
        <div class="stat-card !p-0" style="overflow: visible;">
            <table class="w-full text-sm" style="overflow: visible;">
                <thead>
                    <tr class="text-dark-400 border-b border-dark-800/50 bg-dark-900/30">
                        <th class="text-left py-3 px-4 font-medium">ID</th>
                        <th class="text-left py-3 px-4 font-medium">用户名</th>
                        <th class="text-left py-3 px-4 font-medium">邮箱</th>
                        <th class="text-left py-3 px-4 font-medium">角色</th>
                        <th class="text-left py-3 px-4 font-medium">权限</th>
                        <th class="text-left py-3 px-4 font-medium">状态</th>
                        <th class="text-left py-3 px-4 font-medium">最后登录</th>
                        <th class="text-left py-3 px-4 font-medium">操作</th>
                    </tr>
                </thead>
                <tbody id="staff-table-body">
                    <tr><td colspan="8" class="py-12 text-center text-dark-500">加载中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script src="/admin/assets/js/admin.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let staffList = [];
        const roles = <?php echo json_encode($roles, JSON_UNESCAPED_UNICODE); ?>;
        const modules = <?php echo json_encode($modules, JSON_UNESCAPED_UNICODE); ?>;

        // 预设角色权限映射
        const rolePermissions = {
            'super_admin':   ['*'],
            'admin':         ['*'],
            'operator':      ['dashboard', 'domains_expired', 'domains_violation', 'chat', 'logs', 'log_center'],
            'domain_admin':  ['dashboard', 'domains_expired', 'domains_violation'],
            'support':       ['dashboard', 'chat'],
            'security':      ['dashboard', 'ipban', 'logs', 'log_center'],
            'auditor':       ['dashboard', 'logs', 'log_center'],
        };

        // 加载管理员列表
        async function loadStaffList() {
            try {
                const resp = await fetch('/api/staff.php?action=list');
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status);
                }
                const result = await resp.json();
                if (result.success && result.data) {
                    staffList = result.data.list || [];
                    renderTable();
                } else {
                    document.getElementById('staff-table-body').innerHTML = '<tr><td colspan="8" class="py-12 text-center text-red-400">加载失败: ' + escapeHtml(result.message || '未知错误') + '</td></tr>';
                }
            } catch (e) {
                console.error('[组织管理] 加载失败:', e);
                const tbody = document.getElementById('staff-table-body');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="8" class="py-12 text-center text-red-400">加载失败: ' + escapeHtml(e.message) + '</td></tr>';
                }
            }
        }

        // 渲染表格
        function renderTable() {
            const tbody = document.getElementById('staff-table-body');
            if (!staffList.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="py-12 text-center text-dark-500">暂无管理员数据</td></tr>';
                return;
            }

            tbody.innerHTML = staffList.map(s => {
                const roleLabel = roles[s.role] || s.role;
                const isActive = s.status == 1;
                const statusBadge = isActive
                    ? '<span class="badge badge-success">启用</span>'
                    : '<span class="badge badge-danger">禁用</span>';

                // 显示权限信息
                let permText = '';
                if (s.permissions) {
                    try {
                        const perms = JSON.parse(s.permissions);
                        if (perms.includes('*')) {
                            permText = '<span class="text-xs text-dark-400">自定义(全部)</span>';
                        } else {
                            permText = '<span class="text-xs text-lighthouse-400">自定义</span>';
                        }
                    } catch(e) {
                        permText = '<span class="text-xs text-dark-500">-</span>';
                    }
                } else {
                    permText = '<span class="text-xs text-dark-400">预设模板</span>';
                }

                const isSuperAdmin = s.role === 'super_admin';
                const isSelf = s.id === <?php echo $currentUser['id']; ?>;

                let actions = '';
                // 查看按钮 - 所有管理员都可以查看
                actions += `<button onclick="showViewModal(${s.id})" class="text-blue-400 hover:text-blue-300 text-xs transition-colors">查看</button>`;
                // 编辑按钮 - super_admin不能被其他人编辑
                if (!isSuperAdmin || isSelf) {
                    actions += `<button onclick="showEditModal(${s.id})" class="text-lighthouse-400 hover:text-lighthouse-300 text-xs transition-colors ml-2">编辑</button>`;
                }

                // 状态管理下拉菜单 - super_admin不能被禁用或删除
                if (!isSuperAdmin) {
                    // 计算24小时删除限制
                    const now = Date.now();
                    const lastLoginTime = s.last_login ? new Date(s.last_login).getTime() : 0;
                    const elapsed24h = lastLoginTime > 0 ? (now - lastLoginTime) / (1000 * 60 * 60) : -1;
                    const canDelete = elapsed24h >= 24;
                    const remainingMs = canDelete ? 0 : (24 * 60 * 60 * 1000 - (now - lastLoginTime));
                    const remainingHours = Math.floor(remainingMs / (1000 * 60 * 60));
                    const remainingMinutes = Math.floor((remainingMs % (1000 * 60 * 60)) / (1000 * 60));
                    let deleteLabel = '删除';
                    if (!canDelete) {
                        if (lastLoginTime > 0) {
                            deleteLabel = '删除（还需等待' + remainingHours + '小时' + remainingMinutes + '分）';
                        } else {
                            deleteLabel = '删除（从未登录，无法删除）';
                        }
                    }

                    let menuButtons = '';
                    // 启用按钮：仅在禁用状态(status=0)时显示
                    if (!isActive) {
                        menuButtons += '<button onclick="setStatus(' + s.id + ', 1)" class="w-full text-left px-3 py-2 text-xs text-green-400 hover:bg-dark-700 transition-colors">启用</button>';
                    }
                    // 禁用按钮：仅在启用状态(status=1)时显示
                    if (isActive) {
                        menuButtons += '<button onclick="setStatus(' + s.id + ', 0)" class="w-full text-left px-3 py-2 text-xs text-yellow-400 hover:bg-dark-700 transition-colors">禁用</button>';
                    }
                    menuButtons += '<div class="border-t border-dark-700"></div>';
                    if (canDelete) {
                        menuButtons += '<button onclick="deleteStaff(' + s.id + ')" class="w-full text-left px-3 py-2 text-xs text-red-400 hover:bg-dark-700 transition-colors">' + deleteLabel + '</button>';
                    } else {
                        menuButtons += '<button disabled class="w-full text-left px-3 py-2 text-xs text-red-400/50 cursor-not-allowed" title="' + (lastLoginTime > 0 ? '距离最后登录不足24小时，无法删除' : '该账户从未登录，无法删除') + '">' + deleteLabel + '</button>';
                    }

                    actions += `
                    <div class="relative inline-block ml-2">
                        <button onclick="toggleStatusMenu(event, ${s.id})" class="text-dark-400 hover:text-dark-200 text-xs transition-colors px-1" title="状态管理">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                            </svg>
                        </button>
                        <div id="status-menu-${s.id}" class="hidden absolute right-0 bottom-full mb-1 w-48 bg-dark-800 border border-dark-700 rounded-lg shadow-xl z-50 overflow-visible">
                            ${menuButtons}
                        </div>
                    </div>`;
                }

                return `<tr class="table-row-hover">
                    <td class="py-3 px-4 text-xs text-dark-500">${s.id}</td>
                    <td class="py-3 px-4 text-sm font-medium">${escapeHtml(s.username)}</td>
                    <td class="py-3 px-4 text-xs text-dark-300">${escapeHtml(s.email)}</td>
                    <td class="py-3 px-4"><span class="badge ${isSuperAdmin ? 'badge-danger' : 'badge-info'}">${escapeHtml(roleLabel)}</span></td>
                    <td class="py-3 px-4">${permText}</td>
                    <td class="py-3 px-4">${statusBadge}</td>
                    <td class="py-3 px-4 text-xs text-dark-400">${s.last_login ? escapeHtml(s.last_login) : '从未登录'}</td>
                    <td class="py-3 px-4"><div class="flex items-center space-x-1">${actions || '<span class="text-dark-600 text-xs">-</span>'}</div></td>
                </tr>`;
            }).join('');
        }

        // HTML转义
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // 添加成员模态框
        let permissionsManuallyChanged = false;

        function showStaffModal() {
            permissionsManuallyChanged = false;
            let roleOptions = '';
            for (const [key, label] of Object.entries(roles)) {
                if (key === 'super_admin') continue; // 不允许添加超级管理员
                roleOptions += `<option value="${key}">${label}</option>`;
            }

            // 生成权限复选框
            let permCheckboxes = '';
            for (const [modKey, modLabel] of Object.entries(modules)) {
                permCheckboxes += `
                    <label class="flex items-center space-x-2 cursor-pointer hover:bg-dark-700/50 rounded px-2 py-1.5 transition-colors">
                        <input type="checkbox" class="perm-checkbox rounded border-gray-600 bg-gray-700 text-blue-500 focus:ring-blue-500 focus:ring-offset-0" value="${modKey}" onchange="permissionsManuallyChanged = true">
                        <span class="text-xs text-gray-300">${modLabel}</span>
                    </label>`;
            }

            const html = `
            <div id="staff-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onclick="if(event.target===this)this.remove()">
                <div class="bg-gray-800 rounded-xl shadow-2xl w-full max-w-md mx-4 p-6 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-semibold text-white mb-4">添加组织用户</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">用户名 <span class="text-red-400">*</span></label>
                            <input type="text" id="new-username" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white" required>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">邮箱 <span class="text-red-400">*</span></label>
                            <input type="email" id="new-email" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white" required>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">角色</label>
                            <select id="new-role" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white">
                                ${roleOptions}
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-2">权限模块</label>
                            <div class="bg-gray-900/50 rounded-lg p-3 border border-gray-700/50">
                                <p class="text-xs text-gray-500 mb-2">选择角色后自动勾选预设权限，可手动调整</p>
                                <div class="grid grid-cols-2 gap-1" id="perm-checkboxes">
                                    ${permCheckboxes}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end space-x-3 mt-6">
                        <button onclick="document.getElementById('staff-modal').remove()" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">取消</button>
                        <button onclick="createStaff()" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">确认添加</button>
                    </div>
                </div>
            </div>`;
            document.body.insertAdjacentHTML('beforeend', html);

            // 监听角色选择变化，自动更新权限复选框
            document.getElementById('new-role').addEventListener('change', function() {
                updatePermCheckboxes(this.value);
            });

            // 初始化权限复选框
            updatePermCheckboxes(document.getElementById('new-role').value);
        }

        // 根据角色更新权限复选框
        function updatePermCheckboxes(role) {
            const perms = rolePermissions[role] || [];
            const checkboxes = document.querySelectorAll('.perm-checkbox');
            checkboxes.forEach(cb => {
                if (perms.includes('*')) {
                    cb.checked = true;
                } else {
                    cb.checked = perms.includes(cb.value);
                }
            });
            permissionsManuallyChanged = false;
        }

        // 创建管理员
        async function createStaff() {
            const username = document.getElementById('new-username').value.trim();
            const email = document.getElementById('new-email').value.trim();
            const role = document.getElementById('new-role').value;

            if (!username || !email) {
                showToast('请填写所有必填项', 'error');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('username', username);
                formData.append('email', email);
                formData.append('role', role);
                formData.append('_token', csrfToken);

                // 如果手动修改了权限，收集权限数组
                if (permissionsManuallyChanged) {
                    const checkedPerms = [];
                    document.querySelectorAll('.perm-checkbox:checked').forEach(cb => {
                        checkedPerms.push(cb.value);
                    });
                    formData.append('permissions', JSON.stringify(checkedPerms));
                }

                const res = await fetch('/api/staff.php?action=create', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showToast('管理员添加成功', 'success');
                    document.getElementById('staff-modal').remove();
                    loadStaffList();
                } else {
                    showToast(data.message || '添加失败', 'error');
                }
            } catch (e) {
                showToast('操作失败', 'error');
            }
        }

        // 查看管理员信息（只读模态框）
        function showViewModal(id) {
            const staff = staffList.find(s => s.id === id);
            if (!staff) return;

            const roleLabel = roles[staff.role] || staff.role;
            const isActive = staff.status == 1;
            const statusText = isActive ? '启用' : '禁用';
            const statusColor = isActive ? 'badge-success' : 'badge-danger';

            // 解析权限 - 生成复选框列表
            let permCheckboxesHtml = '';
            let effectivePerms = [];

            if (staff.permissions) {
                try {
                    effectivePerms = JSON.parse(staff.permissions);
                } catch(e) {
                    effectivePerms = [];
                }
            } else {
                effectivePerms = rolePermissions[staff.role] || [];
            }

            const isAllPerms = effectivePerms.includes('*');

            for (const [modKey, modLabel] of Object.entries(modules)) {
                const checked = isAllPerms ? 'checked' : (effectivePerms.includes(modKey) ? 'checked' : '');
                permCheckboxesHtml += `
                    <label class="flex items-center space-x-2 cursor-default">
                        <input type="checkbox" ${checked} disabled class="rounded border-gray-600 bg-gray-700 text-blue-500">
                        <span class="text-xs ${checked ? 'text-gray-200' : 'text-gray-500'}">${modLabel}</span>
                    </label>`;
            }

            const permSource = staff.permissions ? '自定义权限' : '预设权限';

            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'view-modal';
            modal.innerHTML = `
                <div class="modal-content" style="max-width:36rem">
                    <h2 class="text-xl font-semibold text-dark-100 mb-6">管理员信息</h2>
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                                <span class="text-dark-500 text-xs block mb-1">用户名</span>
                                <span class="text-dark-100 text-sm font-medium">${escapeHtml(staff.username)}</span>
                            </div>
                            <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                                <span class="text-dark-500 text-xs block mb-1">邮箱</span>
                                <span class="text-dark-100 text-sm">${escapeHtml(staff.email)}</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                                <span class="text-dark-500 text-xs block mb-1">角色</span>
                                <span class="badge ${staff.role === 'super_admin' ? 'badge-danger' : 'badge-info'}">${escapeHtml(roleLabel)}</span>
                            </div>
                            <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                                <span class="text-dark-500 text-xs block mb-1">状态</span>
                                <span class="badge ${statusColor}">${statusText}</span>
                            </div>
                        </div>
                        <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-dark-500 text-xs">权限模块</span>
                                <span class="text-xs text-lighthouse-400">${permSource}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-1">
                                ${permCheckboxesHtml}
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                                <span class="text-dark-500 text-xs block mb-1">创建时间</span>
                                <span class="text-dark-100 text-sm">${escapeHtml(staff.created_at || '-')}</span>
                            </div>
                            <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                                <span class="text-dark-500 text-xs block mb-1">最后登录时间</span>
                                <span class="text-dark-100 text-sm">${escapeHtml(staff.last_login || '从未登录')}</span>
                            </div>
                        </div>
                        <div class="bg-dark-800/50 rounded-lg p-3 border border-dark-700/50">
                            <span class="text-dark-500 text-xs block mb-1">最后登录IP</span>
                            <span class="text-dark-100 text-sm font-mono">${escapeHtml(staff.last_login_ip || '-')}</span>
                        </div>
                    </div>
                    <div class="mt-6 pt-4 border-t border-dark-800">
                        <button type="button" onclick="closeModal('view-modal')" class="btn-secondary w-full">关闭</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        // 编辑组织成员信息模态框
        function showEditModal(id) {
            const staff = staffList.find(s => s.id === id);
            if (!staff) return;

            permissionsManuallyChanged = false;

            let roleOptions = '';
            for (const [key, label] of Object.entries(roles)) {
                if (key === 'super_admin' && staff.role !== 'super_admin') continue; // 非super_admin不能设为super_admin
                roleOptions += `<option value="${key}" ${key === staff.role ? 'selected' : ''}>${label}</option>`;
            }

            // 生成权限复选框
            let permCheckboxes = '';
            for (const [modKey, modLabel] of Object.entries(modules)) {
                permCheckboxes += `
                    <label class="flex items-center space-x-2 cursor-pointer hover:bg-dark-700/50 rounded px-2 py-1.5 transition-colors">
                        <input type="checkbox" class="perm-checkbox rounded border-gray-600 bg-gray-700 text-blue-500 focus:ring-blue-500 focus:ring-offset-0" value="${modKey}" onchange="permissionsManuallyChanged = true">
                        <span class="text-xs text-gray-300">${modLabel}</span>
                    </label>`;
            }

            const html = `
            <div id="edit-staff-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onclick="if(event.target===this)this.remove()">
                <div class="bg-gray-800 rounded-xl shadow-2xl w-full max-w-md mx-4 p-6 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-semibold text-white mb-4">编辑组织成员信息</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">用户名 <span class="text-red-400">*</span></label>
                            <input type="text" id="edit-username" value="${escapeHtml(staff.username)}" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white" required>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">邮箱 <span class="text-red-400">*</span></label>
                            <input type="email" id="edit-email" value="${escapeHtml(staff.email)}" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white" required>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">角色</label>
                            <select id="edit-role" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white" ${staff.role === 'super_admin' ? 'disabled' : ''}>
                                ${roleOptions}
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-2">权限模块</label>
                            <div class="bg-gray-900/50 rounded-lg p-3 border border-gray-700/50">
                                <p class="text-xs text-gray-500 mb-2">选择角色后自动勾选预设权限，可手动调整</p>
                                <div class="grid grid-cols-2 gap-1" id="edit-perm-checkboxes">
                                    ${permCheckboxes}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end space-x-3 mt-6">
                        <button onclick="document.getElementById('edit-staff-modal').remove()" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition">取消</button>
                        <button onclick="updateStaff(${staff.id})" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">保存修改</button>
                    </div>
                </div>
            </div>`;
            document.body.insertAdjacentHTML('beforeend', html);

            // 监听角色选择变化
            document.getElementById('edit-role').addEventListener('change', function() {
                updateEditPermCheckboxes(this.value);
            });

            // 初始化权限复选框 - 根据当前权限设置
            initEditPermCheckboxes(staff);
        }

        function updateEditPermCheckboxes(role) {
            const perms = rolePermissions[role] || [];
            const checkboxes = document.querySelectorAll('#edit-perm-checkboxes .perm-checkbox');
            checkboxes.forEach(cb => {
                if (perms.includes('*')) {
                    cb.checked = true;
                } else {
                    cb.checked = perms.includes(cb.value);
                }
            });
            permissionsManuallyChanged = false;
        }

        function initEditPermCheckboxes(staff) {
            const checkboxes = document.querySelectorAll('#edit-perm-checkboxes .perm-checkbox');

            // 确定有效权限
            let effectivePerms = [];
            if (staff.permissions) {
                try {
                    effectivePerms = JSON.parse(staff.permissions);
                } catch(e) {}
            }

            if (effectivePerms.length === 0 || (effectivePerms.length === 1 && effectivePerms[0] === '*')) {
                // 使用预设角色权限
                updateEditPermCheckboxes(staff.role);
            } else {
                // 使用自定义权限
                checkboxes.forEach(cb => {
                    cb.checked = effectivePerms.includes(cb.value);
                });
            }
            permissionsManuallyChanged = false;
        }

        async function updateStaff(id) {
            const username = document.getElementById('edit-username').value.trim();
            const email = document.getElementById('edit-email').value.trim();
            const role = document.getElementById('edit-role').value;

            if (!username || !email) {
                showToast('请填写用户名和邮箱', 'error');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('id', id);
                formData.append('username', username);
                formData.append('email', email);
                formData.append('role', role);
                formData.append('_token', csrfToken);

                // 如果手动修改了权限，收集权限数组
                if (permissionsManuallyChanged) {
                    const checkedPerms = [];
                    document.querySelectorAll('#edit-perm-checkboxes .perm-checkbox:checked').forEach(cb => {
                        checkedPerms.push(cb.value);
                    });
                    formData.append('permissions', JSON.stringify(checkedPerms));
                }

                const res = await fetch('/api/staff.php?action=update', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showToast('管理员信息已更新', 'success');
                    document.getElementById('edit-staff-modal').remove();
                    loadStaffList();
                } else {
                    showToast(data.message || '更新失败', 'error');
                }
            } catch (e) {
                showToast('操作失败', 'error');
            }
        }

        // 状态管理下拉菜单切换
        function toggleStatusMenu(event, id) {
            event.stopPropagation();
            // 先关闭所有已打开的菜单
            document.querySelectorAll('[id^="status-menu-"]').forEach(el => {
                if (el.id !== 'status-menu-' + id) el.classList.add('hidden');
            });
            const menu = document.getElementById('status-menu-' + id);
            if (menu) menu.classList.toggle('hidden');
        }

        // 点击页面其他地方关闭下拉菜单
        document.addEventListener('click', () => {
            document.querySelectorAll('[id^="status-menu-"]').forEach(el => el.classList.add('hidden'));
        });

        // 设置管理员状态（启用/禁用）
        function setStatus(id, status) {
            const staff = staffList.find(s => s.id === id);
            if (!staff) return;
            if (staff.role === 'super_admin') {
                showToast('不能修改超级管理员的状态', 'error');
                return;
            }
            // 关闭下拉菜单
            document.querySelectorAll('[id^="status-menu-"]').forEach(el => el.classList.add('hidden'));

            const action = status == 1 ? '启用' : '禁用';
            const actionColor = status == 1 ? 'text-green-400' : 'text-yellow-400';
            const btnColor = status == 1 ? 'bg-green-600 hover:bg-green-700' : 'bg-yellow-600 hover:bg-yellow-700';

            const modal = document.createElement('div');
            modal.id = 'status-confirm-modal';
            modal.className = 'fixed inset-0 z-[60] flex items-center justify-center bg-black/50';
            modal.onclick = function(e) { if (e.target === modal) modal.remove(); };
            modal.innerHTML = `
                <div class="bg-gray-800 rounded-xl shadow-2xl w-full max-w-sm mx-4 p-6">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="w-10 h-10 rounded-full bg-dark-700 flex items-center justify-center">
                            <svg class="w-5 h-5 ${actionColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white">确认${action}</h3>
                    </div>
                    <p class="text-gray-300 text-sm mb-6">确定要${action}管理员 <span class="text-white font-medium">"${escapeHtml(staff.username)}"</span> 吗？</p>
                    <div class="flex justify-end space-x-3">
                        <button onclick="document.getElementById('status-confirm-modal').remove()" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition rounded-lg border border-gray-600 hover:border-gray-500">取消</button>
                        <button id="status-confirm-btn" class="px-4 py-2 text-sm text-white rounded-lg transition ${btnColor}">${action}</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            document.getElementById('status-confirm-btn').onclick = function() {
                modal.remove();
                const fd = new FormData();
                fd.append('action', 'toggle_status');
                fd.append('id', id);
                fd.append('status', status);
                fd.append('_token', csrfToken);

                fetch('/api/staff.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            showToast(res.message, 'success');
                            setTimeout(() => loadStaffList(), 300);
                        } else {
                            showToast(res.message || '操作失败', 'error');
                        }
                    })
                    .catch(() => showToast('操作失败', 'error'));
            };
        }

        // 删除管理员
        function deleteStaff(id) {
            const staff = staffList.find(s => s.id === id);
            if (!staff) return;
            if (staff.role === 'super_admin') {
                showToast('不能删除超级管理员', 'error');
                return;
            }

            // 检查24小时限制
            const now = Date.now();
            const lastLoginTime = staff.last_login ? new Date(staff.last_login).getTime() : 0;
            const elapsed24h = lastLoginTime > 0 ? (now - lastLoginTime) / (1000 * 60 * 60) : -1;
            if (elapsed24h < 24) {
                if (lastLoginTime > 0) {
                    const remainingMs = 24 * 60 * 60 * 1000 - (now - lastLoginTime);
                    const remainingHours = Math.floor(remainingMs / (1000 * 60 * 60));
                    const remainingMinutes = Math.floor((remainingMs % (1000 * 60 * 60)) / (1000 * 60));
                    showToast(`距离最后登录不足24小时，还需等待${remainingHours}小时${remainingMinutes}分`, 'error');
                } else {
                    showToast('该账户从未登录，无法删除', 'error');
                }
                return;
            }

            // 关闭下拉菜单
            document.querySelectorAll('[id^="status-menu-"]').forEach(el => el.classList.add('hidden'));

            const modal = document.createElement('div');
            modal.id = 'delete-confirm-modal';
            modal.className = 'fixed inset-0 z-[60] flex items-center justify-center bg-black/50';
            modal.onclick = function(e) { if (e.target === modal) modal.remove(); };
            modal.innerHTML = `
                <div class="bg-gray-800 rounded-xl shadow-2xl w-full max-w-sm mx-4 p-6">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="w-10 h-10 rounded-full bg-red-900/50 flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white">确认删除</h3>
                    </div>
                    <p class="text-gray-300 text-sm mb-2">确定要删除管理员 <span class="text-white font-medium">"${escapeHtml(staff.username)}"</span> 吗？</p>
                    <p class="text-red-400 text-xs mb-6">此操作不可恢复，删除后该管理员的所有数据将被永久移除。</p>
                    <div class="flex justify-end space-x-3">
                        <button onclick="document.getElementById('delete-confirm-modal').remove()" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition rounded-lg border border-gray-600 hover:border-gray-500">取消</button>
                        <button id="delete-confirm-btn" class="px-4 py-2 text-sm text-white rounded-lg transition bg-red-600 hover:bg-red-700">删除</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            document.getElementById('delete-confirm-btn').onclick = function() {
                modal.remove();
                executeDelete(id);
            };
        }

        // 执行删除操作
        function executeDelete(id) {
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            fd.append('_token', csrfToken);

            fetch('/api/staff.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast(res.message, 'success');
                        setTimeout(() => loadStaffList(), 300);
                    } else {
                        showToast(res.message || '操作失败', 'error');
                    }
                })
                .catch(() => showToast('操作失败', 'error'));
        }

        // 关闭模态框
        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.remove();
        }

        // 页面加载时获取数据
        loadStaffList();
    </script>
</body>
</html>
