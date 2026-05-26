<?php
/**
 * 灯塔DNS拦截响应平台 - 会话列表
 * 搜索、状态筛选、未读消息、进入会话、关闭会话
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

if (!$auth->hasPermission('chat')) {
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

// POST 关闭会话
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!CsrfProtection::validateFromRequest()) die(json_encode(['success'=>false,'message'=>'CSRF验证失败']));
    $action = $_POST['action'] ?? '';
    if ($action === 'close') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->update('chat_conversations', ['status' => 'closed'], 'id = ?', [$id]);
            $db->insert('chat_messages', [
                'conversation_id' => $id,
                'sender_type' => 'admin',
                'sender_id' => null,
                'sender_name' => '系统',
                'message' => '会话已被管理员关闭，感谢您的咨询。',
                'message_type' => 'system',
            ]);
            die(json_encode(['success'=>true,'message'=>'会话已关闭']));
        }
    }
    die(json_encode(['success'=>false,'message'=>'未知操作']));
}

// GET 查询
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$search = $_GET['search'] ?? '';
$search = addcslashes($search, '%_');
$status = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($status) { $where[] = "c.status = ?"; $params[] = $status; }
if ($search) {
    $where[] = "(d.domain_name LIKE ? OR c.customer_email LIKE ?)";
    $params = array_merge($params, ["%{$search}%", "%{$search}%"]);
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total = (int)$db->queryOne("SELECT COUNT(*) as cnt FROM chat_conversations c LEFT JOIN domains d ON c.domain_id = d.id {$whereClause}", $params)['cnt'];

$conversations = $db->query(
    "SELECT c.*, d.domain_name, d.violation_reason, a.username as assigned_name,
            (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = c.id AND sender_type = 'customer' AND is_read = 0) as unread_count
     FROM chat_conversations c
     LEFT JOIN domains d ON c.domain_id = d.id
     LEFT JOIN admin_users a ON c.assigned_to = a.id
     {$whereClause}
     ORDER BY c.last_message_at DESC, c.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, ($page - 1) * $perPage])
);
$totalPages = max(1, ceil($total / $perPage));
$csrfToken = CsrfProtection::getToken();
?>
<?php $pageTitle = '会话列表'; include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <div class="ml-64 p-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-dark-100">会话列表</h1>
                <p class="text-dark-400 text-sm mt-1">管理客户沟通会话</p>
            </div>
        </div>

        <!-- 搜索和筛选 -->
        <div class="stat-card mb-6">
            <form method="GET" class="flex items-center space-x-4 flex-wrap gap-y-2">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="搜索域名或邮箱..."
                           class="search-input w-full">
                </div>
                <div class="flex items-center space-x-2">
                    <a href="?" class="page-link <?php echo !$status ? 'active' : ''; ?>">全部</a>
                    <a href="?status=active" class="page-link <?php echo $status === 'active' ? 'active' : ''; ?>">进行中</a>
                    <a href="?status=closed" class="page-link <?php echo $status === 'closed' ? 'active' : ''; ?>">已关闭</a>
                </div>
                <button type="submit" class="btn-primary text-sm">搜索</button>
            </form>
        </div>

        <!-- 会话列表 -->
        <div class="space-y-3">
            <?php foreach ($conversations as $conv): ?>
            <div class="stat-card !p-5 hover:border-lighthouse-500/30 transition-all duration-200">
                <div class="flex items-center justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center space-x-3 mb-2">
                            <a href="/admin/chat/view.php?id=<?php echo $conv['id']; ?>" class="font-mono text-sm text-lighthouse-400 hover:text-lighthouse-300 transition-colors flex items-center space-x-1">
                                <?php echo htmlspecialchars($conv['domain_name'] ?? '未关联域名'); ?>
                                <?php if (!empty($conv['unread_count']) && $conv['unread_count'] > 0): ?>
                                <span class="inline-block w-2.5 h-2.5 bg-red-500 rounded-full animate-pulse flex-shrink-0"></span>
                                <?php endif; ?>
                            </a>
                            <?php if ($conv['unread_count'] > 0): ?>
                            <span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded-full font-bold animate-pulse"><?php echo $conv['unread_count']; ?></span>
                            <?php endif; ?>
                            <span class="badge <?php echo $conv['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo $conv['status'] === 'active' ? '进行中' : '已关闭'; ?>
                            </span>
                        </div>
                        <p class="text-dark-400 text-sm truncate mb-1"><?php echo htmlspecialchars($conv['last_message_preview'] ?? '暂无消息'); ?></p>
                        <div class="flex items-center space-x-4 text-xs text-dark-500">
                            <span class="flex items-center space-x-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                <?php echo htmlspecialchars($conv['customer_email']); ?>
                            </span>
                            <?php if ($conv['assigned_name']): ?>
                            <span>负责人: <?php echo htmlspecialchars($conv['assigned_name']); ?></span>
                            <?php endif; ?>
                            <?php if ($conv['violation_reason']): ?>
                            <span class="text-red-400/60 truncate max-w-[200px]" title="<?php echo htmlspecialchars($conv['violation_reason']); ?>">原因: <?php echo htmlspecialchars(mb_substr($conv['violation_reason'], 0, 30)); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3 ml-4 flex-shrink-0">
                        <span class="text-dark-500 text-xs"><?php echo $conv['last_message_at'] ? timeAgo($conv['last_message_at']) : timeAgo($conv['created_at']); ?></span>
                        <a href="/admin/chat/view.php?id=<?php echo $conv['id']; ?>" class="btn-primary text-xs !py-1.5 !px-3">进入会话</a>
                        <?php if ($conv['status'] === 'active'): ?>
                        <button onclick="closeConv(<?php echo $conv['id']; ?>)" class="text-dark-500 hover:text-red-400 text-xs transition-colors">关闭</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($conversations)): ?>
            <div class="stat-card text-center py-12">
                <svg class="w-12 h-12 mx-auto mb-3 text-dark-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <p class="text-dark-500">暂无会话</p>
            </div>
            <?php endif; ?>
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
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        async function closeConv(id) {
            const confirmed = await showConfirm('确认关闭该会话？');
            if (!confirmed) return;
            const fd=new FormData(); fd.append('action','close'); fd.append('id',id); fd.append('_token',csrfToken);
            fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
                if(res.success){showToast(res.message,'success');setTimeout(()=>location.reload(),500);}else showToast(res.message,'error');
            });
        }
    </script>
</body>
</html>
