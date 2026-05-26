<?php
/**
 * 灯塔DNS拦截响应平台 - IP封禁管理
 * 搜索、筛选、添加封禁、解封、批量操作、导出
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

if (!$auth->hasPermission('ipban')) {
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
    if (!CsrfProtection::validateFromRequest()) die(json_encode(['success'=>false,'message'=>'CSRF验证失败']));

    $action = $_POST['action'] ?? '';

    // 添加封禁
    if ($action === 'add') {
        $ip = trim($_POST['ip'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $banType = $_POST['ban_type'] ?? 'permanent';
        $duration = (int)($_POST['duration'] ?? 0);

        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            die(json_encode(['success'=>false,'message'=>'请输入有效的IP地址']));
        }
        if (empty($reason)) {
            die(json_encode(['success'=>false,'message'=>'请输入封禁原因']));
        }

        // 检查是否已存在该IP的记录（含已解封/已过期），有则覆盖更新，无则新增
        $existingRecord = $db->queryOne("SELECT id FROM ip_bans WHERE ip = ?", [$ip]);

        // 验证 real_ip 格式
        $realIp = trim($_POST['real_ip'] ?? '');
        if (!empty($realIp) && !filter_var($realIp, FILTER_VALIDATE_IP)) {
            die(json_encode(['success' => false, 'message' => '真实IP格式无效']));
        }

        $data = [
            'ip'             => $ip,
            'real_ip'        => $realIp ?: null,
            'reason'         => $reason,
            'ban_type'       => $banType,
            'detection_source' => 'manual',
            'banned_by'      => $currentUser['id'],
            'status'         => 'active',
            'banned_at'      => date('Y-m-d H:i:s'),
            'lifted_at'      => null,
            'lifted_by'      => null,
            'lift_reason'    => null,
        ];

        if ($banType === 'temporary' && $duration > 0) {
            $data['expires_at'] = date('Y-m-d H:i:s', time() + $duration * 3600);
        }

        if ($existingRecord) {
            $db->update('ip_bans', $data, 'id = ?', [$existingRecord['id']]);
        } else {
            $db->insert('ip_bans', $data);
        }
        recordLog([
            'action'      => 'add',
            'module'      => 'ipban',
            'description' => '添加IP封禁: ' . $ip . ' (' . $banType . ')',
            'target_type' => 'ip_ban',
            'target_id'   => (string)($existingRecord['id'] ?? 0),
            'result'      => 'success',
        ]);
        die(json_encode(['success'=>true,'message'=>'IP封禁成功']));
    }

    // 编辑封禁
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $banType = $_POST['ban_type'] ?? 'permanent';
        $duration = (int)($_POST['duration'] ?? 0);

        if (!$id) die(json_encode(['success'=>false,'message'=>'缺少封禁ID']));
        if (empty($reason)) die(json_encode(['success'=>false,'message'=>'请输入封禁原因']));

        $ban = $db->queryOne("SELECT * FROM ip_bans WHERE id = ? AND status = 'active'", [$id]);
        if (!$ban) die(json_encode(['success'=>false,'message'=>'封禁记录不存在或已解封']));

        $updateData = [
            'reason'   => $reason,
            'ban_type' => $banType,
        ];

        if ($banType === 'temporary' && $duration > 0) {
            $updateData['expires_at'] = date('Y-m-d H:i:s', strtotime($ban['banned_at']) + $duration * 3600);
        } else {
            $updateData['expires_at'] = null;
        }

        $db->update('ip_bans', $updateData, 'id = ?', [$id]);
        recordLog([
            'action'      => 'update',
            'module'      => 'ipban',
            'description' => '编辑IP封禁: ID=' . $id,
            'target_type' => 'ip_ban',
            'target_id'   => (string)$id,
            'result'      => 'success',
        ]);
        die(json_encode(['success'=>true,'message'=>'封禁信息已更新']));
    }

    // 解封
    if ($action === 'unban') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                die(json_encode(['success'=>false,'message'=>'缺少封禁ID']));
            }
            $ban = $db->queryOne("SELECT id FROM ip_bans WHERE id = ? AND status = 'active'", [$id]);
            if (!$ban) {
                die(json_encode(['success'=>false,'message'=>'封禁记录不存在或已解除']));
            }
            $db->update('ip_bans', [
                'status'      => 'lifted',
                'lifted_at'   => date('Y-m-d H:i:s'),
                'lifted_by'   => $currentUser['id'],
                'lift_reason' => '管理员手动解封',
            ], 'id = ?', [$id]);
            recordLog([
                'action'      => 'unban',
                'module'      => 'ipban',
                'description' => '解除IP封禁: ID=' . $id,
                'target_type' => 'ip',
                'target_id'   => (string)$id,
                'result'      => 'success',
            ]);
            die(json_encode(['success'=>true,'message'=>'IP已解封']));
        } catch (\Throwable $e) {
            error_log('[IP封禁管理] 解封失败: ' . $e->getMessage());
            die(json_encode(['success'=>false,'message'=>'解封操作失败: ' . $e->getMessage()]));
        }
    }

    // 批量解封
    if ($action === 'batch_unban') {
        try {
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (empty($ids) || !is_array($ids)) {
                die(json_encode(['success'=>false,'message'=>'请选择要解封的IP']));
            }
            $ids = array_map('intval', $ids);
            $ids = array_filter($ids);
            if (empty($ids)) {
                die(json_encode(['success'=>false,'message'=>'无效的ID列表']));
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->execute(
                "UPDATE ip_bans SET status = 'lifted', lifted_at = NOW(), lifted_by = ? WHERE id IN ({$placeholders})",
                array_merge([$currentUser['id']], $ids)
            );
            recordLog([
                'action'      => 'batch_unban',
                'module'      => 'ipban',
                'description' => '批量解除IP封禁 (共' . count($ids) . '个)',
                'target_type' => 'ip',
                'target_id'   => implode(',', $ids),
                'result'      => 'success',
            ]);
            die(json_encode(['success'=>true,'message'=>'批量解封成功，共解封 ' . count($ids) . ' 个IP']));
        } catch (\Throwable $e) {
            error_log('[IP封禁管理] 批量解封失败: ' . $e->getMessage());
            die(json_encode(['success'=>false,'message'=>'批量解封失败: ' . $e->getMessage()]));
        }
    }

    die(json_encode(['success'=>false,'message'=>'未知操作']));
}

// ========== 辅助函数 ==========
if (!function_exists('buildBanWhere')) {
function buildBanWhere() {
    $where = ['1=1'];
    $search = $_GET['search'] ?? '';
    $search = addcslashes($search, '%_');
    $status = $_GET['status'] ?? '';
    $source = $_GET['source'] ?? '';
    if ($search) $where[] = "(ip LIKE ? OR real_ip LIKE ?)";
    if ($status) $where[] = "status = ?";
    if ($source) $where[] = "detection_source = ?";
    return implode(' AND ', $where);
}
}

if (!function_exists('buildBanParams')) {
function buildBanParams() {
    $params = [];
    $search = $_GET['search'] ?? '';
    $search = addcslashes($search, '%_');
    $status = $_GET['status'] ?? '';
    $source = $_GET['source'] ?? '';
    if ($search) { $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
    if ($status) $params[] = $status;
    if ($source) $params[] = $source;
    return $params;
}
}

// ========== 导出功能 ==========
// 注意：导出功能已有认证保护，上方已调用 $auth->requireAuth()，未登录用户无法访问此功能
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    if (!in_array($format, ['csv', 'json'], true)) {
        die(json_encode(['success' => false, 'message' => '不支持的导出格式']));
    }
    $where = buildBanWhere();
    $params = buildBanParams();
    $bans = $db->query("SELECT b.*, u.username AS banned_by_name FROM ip_bans b LEFT JOIN admin_users u ON b.banned_by = u.id WHERE {$where} ORDER BY b.created_at DESC LIMIT 10000", $params);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ip_bans_' . date('Ymd') . '.csv');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['IP地址', '真实IP', '封禁原因', '封禁类型', '封禁时间', '解封时间', '检测来源', '操作人', '状态']);
        foreach ($bans as $b) {
            $statusMap = ['active' => '已封禁', 'lifted' => '已解封', 'expired' => '已过期'];
            fputcsv($fp, [
                $b['ip'],
                $b['real_ip'],
                $b['reason'],
                $b['ban_type'],
                $b['created_at'],
                $b['lifted_at'],
                $b['detection_source'],
                $b['banned_by_name'] ?? $b['banned_by'],
                $statusMap[$b['status']] ?? $b['status'],
            ]);
        }
        fclose($fp);
        exit;
    }
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=ip_bans_' . date('Ymd') . '.json');
        echo json_encode($bans, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// ========== GET 查询 ==========
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$source = $_GET['source'] ?? '';

$where = buildBanWhere();
$params = buildBanParams();
$total = (int)$db->queryOne("SELECT COUNT(*) as cnt FROM ip_bans WHERE {$where}", $params)['cnt'];
$bans = $db->query(
    "SELECT b.*, u.username as banned_by_name FROM ip_bans b LEFT JOIN admin_users u ON b.banned_by = u.id WHERE {$where} ORDER BY b.created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, ($page - 1) * $perPage])
);
$totalPages = max(1, ceil($total / $perPage));
$csrfToken = CsrfProtection::getToken();
?>
<?php $pageTitle = 'IP封禁管理'; include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <div class="ml-64 p-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-dark-100">IP封禁管理</h1>
                <p class="text-dark-400 text-sm mt-1">管理IP封禁规则</p>
            </div>
            <div class="flex items-center space-x-3">
                <button onclick="showAddModal()" class="btn-primary text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                    <span>添加封禁</span>
                </button>
            </div>
        </div>

        <!-- 搜索过滤 -->
        <div class="stat-card mb-6">
            <form method="GET" class="flex items-center space-x-4 flex-wrap gap-y-2">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="搜索IP地址..."
                           class="search-input w-full">
                </div>
                <select name="status" class="search-input">
                    <option value="">全部状态</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>已封禁</option>
                    <option value="lifted" <?php echo $status === 'lifted' ? 'selected' : ''; ?>>已解封</option>
                </select>
                <select name="source" class="search-input">
                    <option value="">全部来源</option>
                    <option value="cdn_header" <?php echo $source === 'cdn_header' ? 'selected' : ''; ?>>CDN头</option>
                    <option value="tencent_cloud" <?php echo $source === 'tencent_cloud' ? 'selected' : ''; ?>>腾讯云</option>
                    <option value="aliyun_esa" <?php echo $source === 'aliyun_esa' ? 'selected' : ''; ?>>阿里云ESA</option>
                    <option value="manual" <?php echo $source === 'manual' ? 'selected' : ''; ?>>手动</option>
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
                <button onclick="batchUnban()" class="text-green-400 hover:text-green-300 text-sm flex items-center space-x-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>批量解封</span>
                </button>
            </div>
            <button onclick="clearSelection()" class="text-dark-400 hover:text-dark-200 text-sm">取消选择</button>
        </div>

        <!-- IP封禁列表 -->
        <div class="stat-card !p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-dark-400 border-b border-dark-800/50 bg-dark-900/30">
                            <th class="text-left py-3 px-4 w-8">
                                <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" class="rounded border-dark-700 bg-dark-800 text-lighthouse-500 cursor-pointer">
                            </th>
                            <th class="text-left py-3 px-4 font-medium">IP地址</th>
                            <th class="text-left py-3 px-4 font-medium">真实IP</th>
                            <th class="text-left py-3 px-4 font-medium">封禁原因</th>
                            <th class="text-left py-3 px-4 font-medium">封禁类型</th>
                            <th class="text-left py-3 px-4 font-medium">封禁时间</th>
                            <th class="text-left py-3 px-4 font-medium">过期时间</th>
                            <th class="text-left py-3 px-4 font-medium">来源</th>
                            <th class="text-left py-3 px-4 font-medium">操作人</th>
                            <th class="text-left py-3 px-4 font-medium">状态</th>
                            <th class="text-left py-3 px-4 font-medium">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bans as $b):
                            $sourceLabels = ['manual' => '手动', 'auto' => '自动', 'cdn_header' => 'CDN头', 'tencent_cloud' => '腾讯云', 'aliyun_esa' => '阿里云ESA'];
                            $sourceColors = ['manual' => 'badge-info', 'auto' => 'badge-warning', 'cdn_header' => 'badge-purple', 'tencent_cloud' => 'badge-success', 'aliyun_esa' => 'badge-danger'];
                            $isLifted = $b['status'] === 'lifted';
                        ?>
                        <tr class="table-row-hover">
                            <td class="py-3 px-4">
                                <?php if ($b['status'] === 'active'): ?>
                                <input type="checkbox" class="row-checkbox rounded border-dark-700 bg-dark-800 text-lighthouse-500 cursor-pointer" value="<?php echo $b['id']; ?>" onchange="updateSelection()">
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 font-mono text-red-400 text-xs"><?php echo htmlspecialchars($b['ip']); ?></td>
                            <td class="py-3 px-4 font-mono text-xs"><?php echo htmlspecialchars($b['real_ip'] ?? '-'); ?></td>
                            <td class="py-3 px-4 text-xs max-w-[200px] truncate" title="<?php echo htmlspecialchars($b['reason']); ?>"><?php echo htmlspecialchars($b['reason']); ?></td>
                            <td class="py-3 px-4">
                                <span class="badge <?php echo $b['ban_type'] === 'permanent' ? 'badge-danger' : 'badge-warning'; ?>">
                                    <?php echo $b['ban_type'] === 'permanent' ? '永久' : '临时'; ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-xs text-dark-400"><?php echo htmlspecialchars($b['created_at']); ?></td>
                            <td class="py-3 px-4 text-xs text-dark-400">
                                <?php if ($b['ban_type'] === 'permanent'): ?>
                                    <span class="text-dark-500">永久</span>
                                <?php elseif (!empty($b['expires_at'])): ?>
                                    <?php echo htmlspecialchars($b['expires_at']); ?>
                                <?php else: ?>
                                    <span class="text-dark-500">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4">
                                <span class="badge <?php echo $sourceColors[$b['detection_source']] ?? 'badge-info'; ?>">
                                    <?php echo $sourceLabels[$b['detection_source']] ?? $b['detection_source']; ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-xs"><?php echo htmlspecialchars($b['banned_by_name'] ?? '-'); ?></td>
                            <td class="py-3 px-4">
                                <?php if ($isLifted): ?>
                                <span class="badge badge-success">已解封</span>
                                <?php else: ?>
                                <span class="badge badge-danger">已封禁</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4">
                                <?php if ($b['status'] === 'active'): ?>
                                <button onclick="showEditModal(<?php echo $b['id']; ?>, <?php echo htmlspecialchars(json_encode($b['ip'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($b['reason'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>, '<?php echo $b['ban_type']; ?>', '<?php echo htmlspecialchars($b['expires_at'] ?? ''); ?>', '<?php echo htmlspecialchars($b['banned_at'] ?? ''); ?>')" class="text-blue-400 hover:text-blue-300 text-xs transition-colors">编辑</button>
                                <button onclick="unbanIp(<?php echo $b['id']; ?>)" class="text-green-400 hover:text-green-300 text-xs transition-colors ml-2">解封</button>
                                <?php else: ?>
                                <span class="text-dark-600 text-xs">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($isLifted): ?>
                        <tr class="bg-dark-900/20">
                            <td class="py-2 px-4"></td>
                            <td colspan="10" class="py-2 px-4 text-xs text-dark-400">
                                <span class="text-green-400 mr-2">解封信息:</span>
                                解封时间: <?php echo htmlspecialchars($b['lifted_at'] ?? '-'); ?>
                                <?php if (!empty($b['lift_reason'])): ?>
                                &nbsp;|&nbsp; 解封理由: <?php echo htmlspecialchars($b['lift_reason']); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (empty($bans)): ?>
                        <tr><td colspan="11" class="py-12 text-center text-dark-500">暂无IP封禁记录</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 分页 -->
        <?php if ($total > $perPage): ?>
        <div class="flex items-center justify-between mt-6">
            <span class="text-dark-400 text-sm">共 <?php echo $total; ?> 条记录</span>
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
        // 备用：如果 admin.js 加载失败，使用内联自定义弹窗（与暗色主题一致）
        if (typeof showConfirm !== 'function') {
            console.warn('[IP封禁管理] admin.js 加载失败，使用内联确认弹窗');
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
            console.warn('[IP封禁管理] admin.js 加载失败，使用内联toast');
            window.showToast = function(msg, type) {
                const colors = { success: 'bg-green-600', error: 'bg-red-600', warning: 'bg-yellow-600', info: 'bg-blue-600' };
                const t = document.createElement('div');
                t.className = `fixed top-4 right-4 z-50 ${colors[type] || colors.info} text-white px-6 py-3 rounded-lg shadow-lg`;
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

        // SEC-013: HTML转义函数
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        // 添加封禁模态框
        function showAddModal() {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay'; modal.id = 'add-modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <h2 class="text-xl font-semibold text-dark-100 mb-6">添加IP封禁</h2>
                    <form id="add-form" class="space-y-4">
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">IP地址 *</label>
                            <input type="text" name="ip" required placeholder="192.168.1.1" class="search-input w-full">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">真实IP</label>
                            <input type="text" name="real_ip" placeholder="真实客户端IP（可选）" class="search-input w-full">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">封禁原因 *</label>
                            <textarea name="reason" required rows="2" placeholder="请描述封禁原因" class="search-input w-full resize-none"></textarea>
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">封禁类型</label>
                            <select name="ban_type" class="search-input w-full" onchange="toggleDuration(this)">
                                <option value="permanent">永久封禁</option>
                                <option value="temporary">临时封禁</option>
                            </select>
                        </div>
                        <div id="duration-field" class="hidden">
                            <label class="block text-dark-300 text-sm font-medium mb-1">封禁时长（小时）</label>
                            <input type="number" name="duration" min="1" value="24" class="search-input w-full">
                        </div>
                        <div class="flex space-x-3 pt-2">
                            <button type="submit" class="btn-primary flex-1">确认封禁</button>
                            <button type="button" onclick="closeModal('add-modal')" class="btn-secondary flex-1">取消</button>
                        </div>
                    </form>
                </div>`;
            document.body.appendChild(modal);
            modal.querySelector('#add-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const fd = new FormData(e.target); fd.append('action','add'); fd.append('_token',csrfToken);
                fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
                    if(res.success){showToast(res.message,'success');closeModal('add-modal');setTimeout(()=>location.reload(),500);}
                    else showToast(res.message,'error');
                }).catch(err=>{console.error('[addBan]',err);showToast('网络请求失败，请重试','error');});
            });
        }

        function toggleDuration(sel) {
            document.getElementById('duration-field').classList.toggle('hidden', sel.value !== 'temporary');
        }

        // 编辑封禁模态框
        function showEditModal(id, ip, reason, banType, expiresAt, bannedAt) {
            const existingModal = document.getElementById('edit-modal');
            if (existingModal) existingModal.remove();

            // 计算剩余时长（小时）
            let durationHours = 24;
            if (banType === 'temporary' && expiresAt && bannedAt) {
                const diff = (new Date(expiresAt) - new Date(bannedAt)) / (1000 * 3600);
                if (diff > 0) durationHours = Math.round(diff);
            }

            const modal = document.createElement('div');
            modal.className = 'modal-overlay'; modal.id = 'edit-modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <h2 class="text-xl font-semibold text-dark-100 mb-6">编辑封禁信息</h2>
                    <form id="edit-form" class="space-y-4">
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">IP地址</label>
                            <input type="text" value="${escapeHtml(ip)}" readonly class="search-input w-full bg-dark-800 cursor-not-allowed" style="opacity:0.6">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">封禁时间</label>
                            <input type="text" value="${escapeHtml(bannedAt)}" readonly class="search-input w-full bg-dark-800 cursor-not-allowed" style="opacity:0.6">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">封禁原因 *</label>
                            <textarea name="reason" required rows="2" class="search-input w-full resize-none">${escapeHtml(reason)}</textarea>
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">封禁类型</label>
                            <select name="ban_type" class="search-input w-full" onchange="toggleEditDuration(this)">
                                <option value="permanent" ${banType === 'permanent' ? 'selected' : ''}>永久封禁</option>
                                <option value="temporary" ${banType === 'temporary' ? 'selected' : ''}>临时封禁</option>
                            </select>
                        </div>
                        <div id="edit-duration-field" class="${banType === 'temporary' ? '' : 'hidden'}">
                            <label class="block text-dark-300 text-sm font-medium mb-1">封禁时长（小时）</label>
                            <input type="number" name="duration" id="edit-duration" min="1" value="${durationHours}" class="search-input w-full" onchange="calcEditExpires()">
                        </div>
                        <div>
                            <label class="block text-dark-300 text-sm font-medium mb-1">过期时间</label>
                            <input type="text" id="edit-expires-display" value="${expiresAt || '永久'}" readonly class="search-input w-full bg-dark-800 cursor-not-allowed" style="opacity:0.6">
                        </div>
                        <div class="flex space-x-3 pt-2">
                            <button type="submit" class="btn-primary flex-1">保存修改</button>
                            <button type="button" onclick="closeModal('edit-modal')" class="btn-secondary flex-1">取消</button>
                        </div>
                    </form>
                </div>`;
            document.body.appendChild(modal);

            // 存储封禁时间用于计算过期时间
            modal.dataset.bannedAt = bannedAt;

            modal.querySelector('#edit-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const fd = new FormData(e.target);
                fd.append('action', 'update');
                fd.append('id', id);
                fd.append('_token', csrfToken);
                fetch(window.location.href, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) { showToast(res.message, 'success'); closeModal('edit-modal'); setTimeout(() => location.reload(), 500); }
                        else showToast(res.message, 'error');
                    }).catch(err=>{console.error('[editBan]',err);showToast('网络请求失败，请重试','error');});
            });
        }

        function toggleEditDuration(sel) {
            const field = document.getElementById('edit-duration-field');
            const display = document.getElementById('edit-expires-display');
            field.classList.toggle('hidden', sel.value !== 'temporary');
            if (sel.value === 'permanent') {
                display.value = '永久';
            } else {
                calcEditExpires();
            }
        }

        function calcEditExpires() {
            const bannedAt = document.getElementById('edit-modal')?.dataset.bannedAt;
            const duration = parseInt(document.getElementById('edit-duration')?.value) || 0;
            const display = document.getElementById('edit-expires-display');
            if (!display || !bannedAt || duration <= 0) return;
            const expires = new Date(new Date(bannedAt).getTime() + duration * 3600 * 1000);
            display.value = expires.getFullYear() + '-' +
                String(expires.getMonth() + 1).padStart(2, '0') + '-' +
                String(expires.getDate()).padStart(2, '0') + ' ' +
                String(expires.getHours()).padStart(2, '0') + ':' +
                String(expires.getMinutes()).padStart(2, '0') + ':' +
                String(expires.getSeconds()).padStart(2, '0');
        }

        // 解封
        async function unbanIp(id) {
            console.log('[unbanIp] 开始解封, id=', id);
            try {
                const confirmed = await showConfirm('确认解封该IP？解封后该IP将恢复正常访问。');
                if (!confirmed) { console.log('[unbanIp] 用户取消'); return; }
                console.log('[unbanIp] 用户确认，发送请求...');
                const fd = new FormData();
                fd.append('action', 'unban');
                fd.append('id', id);
                fd.append('_token', csrfToken);
                const resp = await fetch(window.location.href, { method: 'POST', body: fd });
                console.log('[unbanIp] 响应状态:', resp.status, resp.statusText);
                const text = await resp.text();
                console.log('[unbanIp] 响应内容:', text.substring(0, 200));
                let res;
                try { res = JSON.parse(text); } catch (e) {
                    console.error('[unbanIp] JSON解析失败:', e);
                    showToast('服务器响应异常，请查看控制台', 'error');
                    return;
                }
                if (res.success) {
                    showToast(res.message, 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast(res.message || '解封失败', 'error');
                }
            } catch (err) {
                console.error('[unbanIp] 请求异常:', err);
                showToast('网络请求失败: ' + err.message, 'error');
            }
        }

        // 批量选择
        function toggleSelectAll(el) { document.querySelectorAll('.row-checkbox').forEach(cb=>cb.checked=el.checked); updateSelection(); }
        function updateSelection() {
            const checked = document.querySelectorAll('.row-checkbox:checked');
            document.getElementById('selected-count').textContent = checked.length;
            document.getElementById('batch-bar').classList.toggle('hidden', checked.length === 0);
        }
        function clearSelection() {
            document.querySelectorAll('.row-checkbox').forEach(cb=>cb.checked=false);
            document.getElementById('select-all').checked=false; updateSelection();
        }
        async function batchUnban() {
            const ids = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb=>cb.value);
            if (!ids.length) { showToast('请先选择要解封的IP', 'warning'); return; }
            console.log('[batchUnban] 选中的ID:', ids);
            try {
                const confirmed = await showConfirm('确认解封选中的 ' + ids.length + ' 个IP？解封后这些IP将恢复正常访问。');
                if (!confirmed) { console.log('[batchUnban] 用户取消'); return; }
                console.log('[batchUnban] 用户确认，发送请求...');
                const fd = new FormData();
                fd.append('action', 'batch_unban');
                fd.append('ids', JSON.stringify(ids));
                fd.append('_token', csrfToken);
                const resp = await fetch(window.location.href, { method: 'POST', body: fd });
                console.log('[batchUnban] 响应状态:', resp.status, resp.statusText);
                const text = await resp.text();
                console.log('[batchUnban] 响应内容:', text.substring(0, 200));
                let res;
                try { res = JSON.parse(text); } catch (e) {
                    console.error('[batchUnban] JSON解析失败:', e);
                    showToast('服务器响应异常，请查看控制台', 'error');
                    return;
                }
                if (res.success) {
                    showToast(res.message, 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast(res.message || '批量解封失败', 'error');
                }
            } catch (err) {
                console.error('[batchUnban] 请求异常:', err);
                showToast('网络请求失败: ' + err.message, 'error');
            }
        }

    </script>
</body>
</html>
