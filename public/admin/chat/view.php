<?php
/**
 * 灯塔DNS拦截响应平台 - 会话详情/对话页
 * 消息列表、发送文本/图片/视频、轮询刷新、关闭会话
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

// 权限检查：需要 chat 权限
if (!$auth->hasPermission('chat')) {
    http_response_code(403);
    echo '<div class="flex items-center justify-center h-screen"><div class="text-center"><h2 class="text-2xl font-bold text-red-400 mb-2">访问被拒绝</h2><p class="text-gray-400">您没有权限访问此页面</p><a href="/admin/" class="text-blue-400 hover:underline mt-4 inline-block">返回首页</a></div></div>';
    exit;
}

$conversationId = (int)($_GET['id'] ?? 0);
if (!$conversationId) { header('Location: /admin/chat/list.php'); exit; }

$conversation = $db->queryOne(
    "SELECT c.*, d.domain_name, d.violation_reason FROM chat_conversations c LEFT JOIN domains d ON c.domain_id = d.id WHERE c.id = ?",
    [$conversationId]
);
if (!$conversation) { header('Location: /admin/chat/list.php'); exit; }

// 标记客户消息为已读
$db->execute(
    "UPDATE chat_messages SET is_read = 1, read_at = NOW() WHERE conversation_id = ? AND sender_type = 'customer' AND is_read = 0",
    [$conversationId]
);

// 处理AJAX发送消息
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!CsrfProtection::validateFromRequest()) { die(json_encode(['success'=>false,'message'=>'CSRF验证失败'])); }

    // 关闭会话
    if (isset($_POST['action']) && $_POST['action'] === 'close') {
        $db->update('chat_conversations', ['status' => 'closed'], 'id = ?', [$conversationId]);
        $db->insert('chat_messages', [
            'conversation_id' => $conversationId,
            'sender_type' => 'admin',
            'sender_id' => null,
            'sender_name' => '系统',
            'message' => '会话已被管理员关闭，感谢您的咨询。',
            'message_type' => 'system',
        ]);
        die(json_encode(['success' => true, 'message' => '会话已关闭']));
    }

    $messageType = $_POST['message_type'] ?? 'text';
    $allowedTypes = ['text', 'image', 'video', 'file'];
    if (!in_array($messageType, $allowedTypes, true)) {
        die(json_encode(['success' => false, 'message' => '不支持的消息类型']));
    }
    $message = sanitize(strip_tags(trim($_POST['message'] ?? '')));

    if ($messageType === 'text' && empty($message)) {
        die(json_encode(['success'=>false,'message'=>'消息不能为空']));
    }

    // 处理文件上传
    $filePath = null;
    $fileName = null;
    $fileSize = null;

    if ($messageType === 'image' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // 文件大小限制 10MB
        if ($_FILES['file']['size'] > 10 * 1024 * 1024) die(json_encode(['success'=>false,'message'=>'图片大小不能超过10MB']));
        // 文件名长度限制
        $fileName = basename($_FILES['file']['name']);
        if (strlen($fileName) > 255) die(json_encode(['success'=>false,'message'=>'文件名过长']));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) die(json_encode(['success'=>false,'message'=>'不支持的图片格式']));
        // SEC-021: MIME类型验证
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['file']['tmp_name']);
        $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($mime, $allowedMimes)) die(json_encode(['success'=>false,'message'=>'图片MIME类型验证失败']));
        $uploadDir = BASEPATH . '/public/uploads/chat/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $newName = uniqid('img_', true) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $newName)) {
            // QUAL-012: move_uploaded_file失败时返回错误
            die(json_encode(['success'=>false,'message'=>'图片保存失败']));
        }
        $filePath = '/uploads/chat/' . $newName;
        $fileName = preg_replace('/[^\w\.\-]/', '', $fileName);
        $fileSize = $_FILES['file']['size'];
    }

    if ($messageType === 'video' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // 文件大小限制 50MB
        if ($_FILES['file']['size'] > 50 * 1024 * 1024) die(json_encode(['success'=>false,'message'=>'视频大小不能超过50MB']));
        // 文件名长度限制
        $fileName = basename($_FILES['file']['name']);
        if (strlen($fileName) > 255) die(json_encode(['success'=>false,'message'=>'文件名过长']));
        $allowed = ['mp4','webm','mov'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) die(json_encode(['success'=>false,'message'=>'不支持的视频格式']));
        // SEC-021: MIME类型验证
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['file']['tmp_name']);
        $allowedMimes = ['video/mp4','video/webm','video/quicktime'];
        if (!in_array($mime, $allowedMimes)) die(json_encode(['success'=>false,'message'=>'视频MIME类型验证失败']));
        $uploadDir = BASEPATH . '/public/uploads/chat/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $newName = uniqid('vid_', true) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $newName)) {
            // QUAL-012: move_uploaded_file失败时返回错误
            die(json_encode(['success'=>false,'message'=>'视频保存失败']));
        }
        $filePath = '/uploads/chat/' . $newName;
        $fileName = preg_replace('/[^\w\.\-]/', '', $fileName);
        $fileSize = $_FILES['file']['size'];
    }

    $db->insert('chat_messages', [
        'conversation_id' => $conversationId,
        'sender_type'     => 'admin',
        'sender_id'       => $currentUser['id'],
        'message'         => $message,
        'message_type'    => $messageType,
        'file_path'       => $filePath,
        'file_name'       => $fileName,
        'file_size'       => $fileSize,
    ]);

    $preview = $messageType === 'text' ? mb_substr($message, 0, 100) : ($messageType === 'image' ? '[图片]' : ($messageType === 'video' ? '[视频]' : '[文件]'));
    $db->execute(
        "UPDATE chat_conversations SET last_message_at = NOW(), last_message_preview = ?, updated_at = NOW() WHERE id = ?",
        [$preview, $conversationId]
    );

    die(json_encode(['success'=>true,'message'=>'发送成功']));
}

// 获取消息
$messages = $db->query(
    "SELECT m.*, a.username as admin_name FROM chat_messages m LEFT JOIN admin_users a ON m.sender_id = a.id WHERE m.conversation_id = ? ORDER BY m.created_at ASC",
    [$conversationId]
);

$csrfToken = CsrfProtection::getToken();
?>
<?php $pageTitle = '对话 - ' . ($conversation['domain_name'] ?? '会话'); include BASEPATH . '/public/admin/partials/header.php'; ?>
<body class="bg-dark-950 text-dark-100 min-h-screen">
    <?php include BASEPATH . '/public/admin/partials/sidebar.php'; ?>

    <div class="ml-64 flex flex-col h-screen">
        <!-- 顶部信息 -->
        <div class="border-b border-dark-800/50 p-4 flex items-center justify-between bg-dark-950/80 backdrop-blur-xl flex-shrink-0">
            <div class="flex items-center space-x-4">
                <a href="/admin/chat/list.php" class="text-dark-400 hover:text-dark-200 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div>
                    <h2 class="text-lg font-semibold text-dark-100"><?php echo htmlspecialchars($conversation['domain_name'] ?? '未关联域名'); ?></h2>
                    <div class="flex items-center space-x-3 text-xs text-dark-400">
                        <span><?php echo htmlspecialchars($conversation['customer_email']); ?></span>
                        <?php if ($conversation['violation_reason']): ?>
                        <span class="text-red-400/70">| <?php echo htmlspecialchars(mb_substr($conversation['violation_reason'], 0, 50)); ?></span>
                        <?php endif; ?>
                        <span>| 创建于 <?php echo htmlspecialchars($conversation['created_at']); ?></span>
                    </div>
                </div>
            </div>
            <div class="flex items-center space-x-3">
                <?php if ($conversation['status'] === 'active'): ?>
                <button onclick="closeConversation(<?php echo $conversationId; ?>)" class="text-dark-400 hover:text-red-400 text-sm transition-colors flex items-center space-x-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    <span>关闭会话</span>
                </button>
                <button onclick="showBanIpModal()" class="text-dark-400 hover:text-orange-400 text-sm transition-colors flex items-center space-x-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                    <span>封禁IP</span>
                </button>
                <?php else: ?>
                <span class="badge badge-warning">会话已关闭</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 消息区域 -->
        <div class="flex-1 overflow-y-auto p-6 space-y-4 chat-messages" id="messages-container">
            <?php foreach ($messages as $msg): ?>
            <?php if ($msg['sender_type'] === 'system'): ?>
            <div class="flex justify-center">
                <div class="bg-dark-800/30 border border-dark-700/30 rounded-xl px-4 py-2 text-xs text-dark-500">
                    <?php echo htmlspecialchars($msg['message']); ?>
                </div>
            </div>
            <?php elseif ($msg['sender_type'] === 'customer'): ?>
            <div class="flex justify-end">
                <div class="max-w-lg">
                    <div class="bg-lighthouse-500/10 border border-lighthouse-500/20 rounded-2xl rounded-br-sm px-4 py-3">
                        <?php if ($msg['message_type'] === 'text'): ?>
                        <p class="text-sm text-dark-200"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                        <?php elseif ($msg['message_type'] === 'image' && $msg['file_path']): ?>
                        <img src="<?php echo htmlspecialchars($msg['file_path']); ?>" class="max-w-xs rounded-lg cursor-pointer hover:opacity-80 transition-opacity" alt="图片" onclick="window.open(this.src)">
                        <?php elseif ($msg['message_type'] === 'video' && $msg['file_path']): ?>
                        <video src="<?php echo htmlspecialchars($msg['file_path']); ?>" controls class="max-w-sm rounded-lg"></video>
                        <?php elseif ($msg['message_type'] === 'file' && $msg['file_path']): ?>
                        <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" target="_blank" class="text-lighthouse-400 hover:text-lighthouse-300 text-sm flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <?php echo htmlspecialchars($msg['file_name'] ?? '文件'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <p class="text-[10px] text-dark-600 mt-1 text-right"><?php echo htmlspecialchars($msg['created_at']); ?></p>
                </div>
            </div>
            <?php else: ?>
            <?php if (($msg['message_type'] ?? '') === 'system'): ?>
            <div class="flex justify-center">
                <div class="bg-dark-800/30 border border-dark-700/30 rounded-xl px-4 py-2 text-xs text-dark-500">
                    <?php echo htmlspecialchars($msg['message']); ?>
                </div>
            </div>
            <?php else: ?>
            <div class="flex justify-start">
                <div class="max-w-lg">
                    <div class="bg-green-500/10 border border-green-500/20 rounded-2xl rounded-bl-sm px-4 py-3">
                        <?php if ($msg['message_type'] === 'text'): ?>
                        <p class="text-sm text-dark-200"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                        <?php elseif ($msg['message_type'] === 'image' && $msg['file_path']): ?>
                        <img src="<?php echo htmlspecialchars($msg['file_path']); ?>" class="max-w-xs rounded-lg cursor-pointer hover:opacity-80 transition-opacity" alt="图片" onclick="window.open(this.src)">
                        <?php elseif ($msg['message_type'] === 'video' && $msg['file_path']): ?>
                        <video src="<?php echo htmlspecialchars($msg['file_path']); ?>" controls class="max-w-sm rounded-lg"></video>
                        <?php endif; ?>
                    </div>
                    <p class="text-[10px] text-dark-600 mt-1"><?php echo htmlspecialchars($msg['admin_name'] ?? '管理员'); ?> · <?php echo htmlspecialchars($msg['created_at']); ?></p>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- 发送消息区域 -->
        <?php if ($conversation['status'] === 'active'): ?>
        <div class="border-t border-dark-800/50 p-4 bg-dark-950/80 backdrop-blur-xl flex-shrink-0">
            <form id="send-form" class="flex items-center space-x-3">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="message_type" value="text" id="message-type-input">
                <input type="text" name="message" id="message-input" placeholder="输入回复消息..." autofocus
                       class="flex-1 bg-dark-800 border border-dark-700 rounded-xl px-4 py-3 text-sm text-dark-100 placeholder-dark-500 focus:border-lighthouse-500 focus:outline-none focus:ring-1 focus:ring-lighthouse-500/50 transition-all">
                <!-- 图片上传 -->
                <label class="cursor-pointer text-dark-400 hover:text-lighthouse-400 transition-colors p-2" title="发送图片">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <input type="file" accept="image/*" class="hidden" id="image-upload" onchange="handleFileUpload(this, 'image')">
                </label>
                <!-- 视频上传 -->
                <label class="cursor-pointer text-dark-400 hover:text-lighthouse-400 transition-colors p-2" title="发送视频">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <input type="file" accept="video/mp4,video/webm,video/quicktime" class="hidden" id="video-upload" onchange="handleFileUpload(this, 'video')">
                </label>
                <button type="submit" id="send-btn" class="btn-primary text-sm !py-3 !px-5 flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                    <span>发送</span>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- 封禁IP模态框 -->
    <div id="ban-ip-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm">
        <div class="bg-dark-900 border border-dark-700 rounded-2xl p-6 w-full max-w-md mx-4 shadow-2xl">
            <h3 class="text-lg font-semibold text-dark-100 mb-4">封禁IP地址</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm text-dark-400 mb-1">IP地址</label>
                    <?php $customerIp = $conversation['customer_ip'] ?? ''; ?>
                    <input type="text" id="ban-ip-address" <?php echo empty($customerIp) ? '' : 'readonly'; ?>
                           value="<?php echo empty($customerIp) ? '' : htmlspecialchars($customerIp); ?>"
                           placeholder="<?php echo empty($customerIp) ? '未知IP，请手动输入IP地址' : ''; ?>"
                           class="w-full bg-dark-800 border border-dark-700 rounded-lg px-3 py-2 text-sm text-dark-100 placeholder-dark-500 focus:border-lighthouse-500 focus:outline-none">
                    <?php if (empty($customerIp)): ?>
                    <p class="text-xs text-orange-400/70 mt-1">未获取到客户IP，请手动输入要封禁的IP地址</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="block text-sm text-dark-400 mb-1">封禁类型</label>
                    <select id="ban-ip-type" class="w-full bg-dark-800 border border-dark-700 rounded-lg px-3 py-2 text-sm text-dark-100 focus:border-lighthouse-500 focus:outline-none">
                        <option value="temporary">临时封禁（24小时）</option>
                        <option value="permanent">永久封禁</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-dark-400 mb-1">封禁理由</label>
                    <textarea id="ban-ip-reason" rows="3" placeholder="请输入封禁理由..."
                              class="w-full bg-dark-800 border border-dark-700 rounded-lg px-3 py-2 text-sm text-dark-100 placeholder-dark-500 focus:border-lighthouse-500 focus:outline-none resize-none"></textarea>
                </div>
            </div>
            <div class="flex items-center justify-end space-x-3 mt-6">
                <button onclick="hideBanIpModal()" class="px-4 py-2 text-sm text-dark-400 hover:text-dark-200 transition-colors">取消</button>
                <button onclick="submitBanIp()" class="btn-primary text-sm !py-2 !px-4">确认封禁</button>
            </div>
        </div>
    </div>

    <script src="/admin/assets/js/admin.js"></script>
    <script>
        // 封禁IP模态框
        function showBanIpModal() {
            document.getElementById('ban-ip-modal').classList.remove('hidden');
            document.getElementById('ban-ip-modal').classList.add('flex');
        }
        function hideBanIpModal() {
            document.getElementById('ban-ip-modal').classList.add('hidden');
            document.getElementById('ban-ip-modal').classList.remove('flex');
        }
        function submitBanIp() {
            const ip = document.getElementById('ban-ip-address').value;
            const type = document.getElementById('ban-ip-type').value;
            const reason = document.getElementById('ban-ip-reason').value.trim();
            if (!ip) { alert('未获取到IP地址'); return; }
            if (!reason) { alert('请输入封禁理由'); return; }

            const fd = new FormData();
            fd.append('action', 'add');
            fd.append('ip', ip);
            fd.append('ban_type', type);
            fd.append('reason', reason);
            fd.append('_token', document.querySelector('input[name="_token"]').value);

            fetch('/api/ipban.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert('IP封禁成功');
                        hideBanIpModal();
                        location.reload();
                    } else {
                        alert(res.message || '封禁失败');
                    }
                })
                .catch(() => alert('请求失败'));
        }

        // SEC-017: 关闭会话使用POST请求
        function closeConversation(id) {
            if (!confirm('确认关闭该会话？')) return;
            const fd = new FormData();
            fd.append('action', 'close');
            fd.append('_token', document.querySelector('input[name="_token"]').value);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) window.location.href = '/admin/chat/list.php';
                    else alert(res.message);
                });
        }

        const container = document.getElementById('messages-container');
        container.scrollTop = container.scrollHeight;

        // 文件上传处理
        function handleFileUpload(input, type) {
            if (!input.files[0]) return;
            const fd = new FormData();
            fd.append('_token', document.querySelector('input[name="_token"]').value);
            fd.append('message_type', type);
            fd.append('message', '');
            fd.append('file', input.files[0]);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) setTimeout(() => location.reload(), 300);
                    else alert(res.message);
                });
            input.value = '';
        }

        // 文本消息发送
        document.getElementById('send-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const input = document.getElementById('message-input');
            const msg = input.value.trim();
            if (!msg) return;

            const fd = new FormData();
            fd.append('_token', document.querySelector('input[name="_token"]').value);
            fd.append('message_type', 'text');
            fd.append('message', msg);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        input.value = '';
                        setTimeout(() => location.reload(), 300);
                    } else alert(res.message);
                });
        });

        // 自动刷新新消息（每10秒轮询）
        <?php if ($conversation['status'] === 'active'): ?>
        let lastMsgTime = <?php echo json_encode(!empty($messages) && isset($messages[count($messages)-1]['created_at']) ? $messages[count($messages)-1]['created_at'] : ''); ?>;
        setInterval(() => {
            fetch(`/api/chat.php?action=messages&conversation_id=<?php echo $conversationId; ?>`)
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data && res.data.messages && res.data.messages.length > 0) {
                        lastMsgTime = res.data.messages[res.data.messages.length - 1].created_at;
                        location.reload();
                    }
                }).catch(() => {});
        }, 10000);
        <?php endif; ?>
    </script>
</body>
</html>
