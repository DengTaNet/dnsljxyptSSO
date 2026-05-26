<?php
/**
 * 灯塔DNS拦截响应平台 - 域名违规页面
 * 深色科技风格 + 丰富动画效果
 * 包含申诉功能（邮箱验证 -> 验证码 -> 填写申诉）和对话功能
 */

$siteName = config('site.name', '灯塔DNS拦截响应平台');
$contactEmail = config('site.contact_email', 'support@dengtanet.com');

$domain = $domain ?? null;

// 安全获取域名参数（防止XSS和注入）
$rawDomain = $_GET['domain'] ?? '';
$safeDomain = preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $rawDomain) ? $rawDomain : '';

// 获取域名信息
$domainName = $domain['domain_name'] ?? $safeDomain ?? $_SERVER['HTTP_HOST'] ?? '';
$violationReason = $domain['violation_reason'] ?? $domain['reason'] ?? '该域名因违反相关法规已被拦截。';
$reviewDate = $domain['review_date'] ?? null;
$reviewNote = $domain['review_note'] ?? '';
$domainId = (int)($domain['id'] ?? 0);

// 如果有 URL 参数域名，尝试从数据库查询
if (empty($domain) && !empty($safeDomain)) {
    try {
        $domainData = db()->queryOne(
            "SELECT * FROM domains WHERE domain_name = ? AND status = 'active' AND type = 'violation'",
            [$safeDomain]
        );
        if ($domainData) {
            $domainName = $domainData['domain_name'];
            $violationReason = $domainData['violation_reason'] ?? $domainData['reason'] ?? '该域名因违反相关法规已被拦截。';
            $reviewDate = $domainData['review_date'];
            $reviewNote = $domainData['review_note'] ?? '';
            $domainId = (int)$domainData['id'];
        }
    } catch (\Throwable $e) {
        error_log('[violation_template] 数据库查询失败: ' . $e->getMessage());
    }
}

$pageTitle = '域名违规拦截';
include BASEPATH . '/includes/header.php';
?>

<!-- 扫描线效果背景 -->
<div id="scanline-bg" class="fixed inset-0 pointer-events-none z-0 overflow-hidden"></div>

<!-- 粒子背景增强层 -->
<div id="particles-enhanced" class="fixed inset-0 pointer-events-none z-0 overflow-hidden"></div>

<!-- 主内容区域 -->
<div class="flex flex-col items-center justify-center min-h-[calc(100vh-12rem)] px-4 py-12 relative">

    <!-- 安全审查图标 -->
    <div class="relative mb-8 fade-in-up" style="animation-delay: 0.1s;">
        <!-- 外层脉冲环 -->
        <div class="absolute inset-[-20px] rounded-full border-2 border-red-400/30 animate-ping" style="animation-duration: 2s;"></div>
        <div class="absolute inset-[-40px] rounded-full border border-red-400/10 animate-ping" style="animation-duration: 3s; animation-delay: 0.5s;"></div>
        <div class="absolute inset-[-60px] rounded-full border border-red-400/5 animate-ping" style="animation-duration: 4s; animation-delay: 1s;"></div>

        <!-- 图标主体 -->
        <div class="relative w-28 h-28 bg-gradient-to-br from-red-500/20 to-orange-500/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-red-500/30">
            <svg class="w-14 h-14 text-red-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <div class="absolute inset-0 bg-red-400/20 rounded-full blur-xl animate-pulse-slow"></div>
        </div>
    </div>

    <!-- 标题区域 -->
    <div class="text-center mb-8 fade-in-up" style="animation-delay: 0.2s;">
        <h1 class="text-3xl md:text-5xl font-bold mb-3">
            <span class="bg-gradient-to-r from-red-400 via-rose-300 to-orange-400 bg-clip-text text-transparent" id="violation-title">
                合 规 审 查 中
            </span>
        </h1>
        <p class="text-dark-400 text-base md:text-lg max-w-xl mx-auto">
            该网站可能因违反灯塔DNS用户协议或相关法律法规正在进行合规审查
        </p>
    </div>

    <!-- 域名信息卡片 -->
    <div class="glass rounded-2xl p-8 max-w-xl w-full mt-4 fade-in-up" style="animation-delay: 0.3s; border-color: rgba(239, 68, 68, 0.2);">
        <!-- 状态标签 -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-2">
                <span class="relative flex h-3 w-3">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                </span>
                <span class="text-red-400 text-sm font-medium">审查中</span>
            </div>
            <span class="text-dark-500 text-xs font-mono" id="current-time"></span>
        </div>

        <!-- 域名信息 -->
        <div class="space-y-4">
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-dark-500 group-hover:text-lighthouse-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                    </svg>
                    <span>当前域名</span>
                </span>
                <span class="text-white font-mono text-sm font-medium glow-text"><?php echo e($domainName); ?></span>
            </div>

            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-dark-500 group-hover:text-red-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <span>审查状态</span>
                </span>
                <span class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-medium status-banned">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                    </span>
                    <span>审查中</span>
                </span>
            </div>

            <?php if ($reviewDate): ?>
            <div class="flex items-center justify-between py-3 border-b border-dark-800/50 group">
                <span class="text-dark-400 text-sm flex items-center space-x-2">
                    <svg class="w-4 h-4 text-dark-500 group-hover:text-red-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>审查时间</span>
                </span>
                <span class="text-dark-300 text-sm"><?php echo e($reviewDate); ?></span>
            </div>
            <?php endif; ?>


        </div>

        <!-- 操作按钮 -->
        <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <button onclick="openAppealModal()"
                    id="appeal-btn"
                    class="btn-primary flex items-center justify-center space-x-2 py-3 px-6">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 1118 0z"/>
                </svg>
                <span id="appeal-btn-text">发起申辩</span>
            </button>
            <button onclick="openChatPanel()"
                    class="btn-secondary flex items-center justify-center space-x-2 py-3 px-6">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <span>联系客服</span>
            </button>
        </div>
    </div>

    <!-- 法律声明 -->
    <div class="mt-8 max-w-xl w-full fade-in-up" style="animation-delay: 0.5s;">
        <div class="glass-light rounded-xl p-5">
            <div class="flex items-start space-x-3">
                <svg class="w-5 h-5 text-red-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="text-dark-300 text-sm leading-relaxed">
                        该网站可能因违反灯塔DNS用户协议或相关法律法规正在进行合规审查。如您认为存在误判，可通过上方申辩通道提交申辩材料。
                    </p>
                    <p class="text-dark-500 text-xs mt-2">
                        联系邮箱：<?php echo e($contactEmail); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== 申诉模态框 ==================== -->
<div id="appeal-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm">
    <div class="glass rounded-2xl p-8 max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto border border-red-500/20" style="animation: modalSlideIn 0.3s ease-out;">
        <!-- 模态框头部 -->
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-semibold text-white flex items-center space-x-2">
                <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>提交申辩</span>
            </h2>
            <button onclick="closeAppealModal()" class="text-dark-500 hover:text-white transition-colors p-1 rounded-lg hover:bg-dark-800">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- 身份验证步骤 -->
        <div id="appeal-verify-modal" class="appeal-step">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-lighthouse-500/10 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-lighthouse-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-white mb-2">验证身份</h3>
                <p class="text-dark-400 text-sm">请输入您的灯塔DNS账户邮箱以验证身份</p>
            </div>
            <form id="appeal-verify-form" class="space-y-4">
                <div>
                    <label class="text-dark-400 text-sm mb-1.5 block">灯塔DNS账户邮箱 <span class="text-red-400">*</span></label>
                    <input type="email" id="appeal-verify-email" placeholder="请输入您的灯塔DNS账户邮箱" required  
                           class="form-input w-full text-sm">
                    <span id="verify-email-error" class="hidden text-red-400 text-xs mt-1 block"></span>
                </div>
                <div class="flex space-x-3">
                    <button type="button" onclick="closeAppealModal()" class="btn-secondary flex-1 py-2.5">取消</button>
                    <button type="submit" id="verify-email-btn" class="btn-primary flex-1 py-2.5">验证并继续</button>
                </div>
            </form>
        </div>

        <!-- 填写申诉（直接提交，无需验证码） -->
        <div id="appeal-step-3" class="appeal-step hidden">
            <form id="appeal-content-form" class="space-y-4">
                <div>
                    <label class="text-dark-400 text-sm mb-1.5 block">灯塔DNS账户邮箱 <span class="text-red-400">*</span></label>
                    <input type="email" name="customer_email" id="appeal-customer-email" placeholder="请输入您的灯塔DNS账户邮箱" required readonly
                           class="form-input w-full text-sm bg-dark-800/50 cursor-not-allowed" style="opacity:0.8">
                </div>
                <div>
                    <label class="text-dark-400 text-sm mb-1.5 block">您的姓名</label>
                    <input type="text" name="customer_name" placeholder="请输入您的姓名"
                           class="form-input w-full text-sm">
                </div>
                <div>
                    <label class="text-dark-400 text-sm mb-1.5 block">联系电话（选填）</label>
                    <input type="tel" name="customer_phone" placeholder="请输入联系电话"
                           class="form-input w-full text-sm">
                </div>
                <div>
                    <label class="text-dark-400 text-sm mb-1.5 block">申辩内容 <span class="text-red-400">*</span></label>
                    <textarea name="appeal_content" rows="5" placeholder="请详细描述您的申辩理由..." required
                              class="form-input w-full text-sm resize-none"></textarea>
                    <div class="flex justify-between mt-1">
                        <span id="content-error" class="hidden text-red-400 text-xs"></span>
                        <span id="content-count" class="text-dark-500 text-xs">0/5000</span>
                    </div>
                </div>
                <div>
                    <label class="text-dark-400 text-sm mb-1.5 block">证据材料（选填）</label>
                    <div class="form-input w-full text-sm flex items-center justify-center py-6 cursor-pointer hover:border-lighthouse-500/50 transition-colors" onclick="document.getElementById('evidence-input').click()">
                        <div class="text-center">
                            <svg class="w-8 h-8 text-dark-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                            <p class="text-dark-500 text-xs">点击上传文件</p>
                            <p class="text-dark-600 text-xs mt-1">支持 PDF、图片、压缩包，最大 10MB</p>
                        </div>
                    </div>
                    <input type="file" id="evidence-input" name="evidence" multiple class="hidden" accept=".pdf,.jpg,.jpeg,.png,.gif,.zip,.rar,.7z">
                    <div id="file-list" class="mt-2 space-y-1"></div>
                </div>
                <button type="submit" id="submit-appeal-btn"
                        class="btn-primary w-full flex items-center justify-center space-x-2 py-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                    <span>提交申辩</span>
                </button>
            </form>
        </div>

        <!-- 申诉成功 -->
        <div id="appeal-success" class="appeal-step hidden text-center py-8">
            <div class="w-20 h-20 bg-green-500/10 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-white mb-2">申辩提交成功</h3>
            <p class="text-dark-400 text-sm mb-6">我们已收到您的申辩，将在1-3个工作日内处理。</p>
            <button onclick="closeAppealModal()" class="btn-secondary py-2 px-6">关闭</button>
        </div>

        <!-- 申诉状态查看（已有申诉记录时显示） -->
        <div id="appeal-status-view" class="appeal-step hidden">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-lighthouse-500/10 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-lighthouse-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-white mb-2">申辩审核状态</h3>
            </div>
            <div id="appeal-status-content" class="space-y-4">
                <!-- 动态填充 -->
            </div>
            <div class="mt-6">
                <button onclick="closeAppealModal()" class="btn-secondary w-full py-2.5">关闭</button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== 对话面板 ==================== -->
<div id="chat-panel" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/70 backdrop-blur-sm">
    <div class="glass rounded-2xl max-w-lg w-full mx-4 h-[85vh] sm:h-[70vh] flex flex-col border border-lighthouse-500/20" style="animation: chatSlideUp 0.3s ease-out;">
        <!-- 对话头部 -->
        <div class="flex items-center justify-between p-4 border-b border-dark-800/50">
            <div class="flex items-center space-x-3">
                <div class="relative">
                    <div class="w-10 h-10 bg-lighthouse-500/10 rounded-full flex items-center justify-center">
                        <svg class="w-5 h-5 text-lighthouse-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                    </div>
                    <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-500 rounded-full border-2 border-dark-900"></span>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-white">在线客服</h3>
                    <p class="text-xs text-green-400">在线</p>
                </div>
            </div>
            <button onclick="closeChatPanel()" class="text-dark-500 hover:text-white transition-colors p-1 rounded-lg hover:bg-dark-800">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- 邮箱输入区域（未填写邮箱时显示） -->
        <div id="chat-email-prompt" class="flex-1 flex items-center justify-center p-6">
            <div class="text-center w-full max-w-xs">
                <div class="w-16 h-16 bg-dark-800 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-lighthouse-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h3 class="text-sm font-semibold text-white mb-1">开始对话</h3>
                <p class="text-dark-400 text-xs mb-4">请填写联系邮箱，以便我们关联您的会话</p>
                <div class="space-y-3">
                    <input type="email" id="chat-email-input" placeholder="请输入联系邮箱"
                           class="form-input w-full text-sm text-center">
                    <span id="chat-email-error" class="hidden text-red-400 text-xs block"></span>
                    <button onclick="startChatWithEmail()"
                            class="btn-primary w-full py-2.5 text-sm">
                        开始对话
                    </button>
                </div>
            </div>
        </div>

        <!-- 消息列表（填写邮箱后显示） -->
        <div id="chat-messages-area" class="hidden flex-1 overflow-y-auto p-4 space-y-4">
            <!-- 消息将通过JS动态加载 -->
        </div>

        <!-- 输入区域 -->
        <div id="chat-input-area" class="hidden p-4 border-t border-dark-800/50">
            <form id="chat-form" class="flex items-end space-x-2">
                <div class="flex-1 relative">
                    <textarea name="message" placeholder="输入消息..." rows="1"
                              class="form-input w-full text-sm pr-10 resize-none"
                              style="min-height: 40px; max-height: 120px;"
                              oninput="autoResizeTextarea(this)"></textarea>
                    <button type="button" onclick="document.getElementById('chat-file-input').click()"
                            class="absolute right-2 bottom-2 text-dark-500 hover:text-lighthouse-400 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                        </svg>
                    </button>
                    <input type="file" id="chat-file-input" class="hidden" accept="image/*,video/*" onchange="handleChatFileUpload(this)">
                </div>
                <button type="submit"
                        class="bg-gradient-to-r from-lighthouse-600 to-lighthouse-500 hover:from-lighthouse-500 hover:to-lighthouse-400 text-white p-2.5 rounded-xl transition-all duration-200 flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>

<?php include BASEPATH . '/includes/footer.php'; ?>

<!-- 违规页面专用样式 -->
<style>
    /* 扫描线背景 */
    #scanline-bg::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, rgba(239, 68, 68, 0.2), transparent);
        animation: scanlineMove 4s linear infinite;
    }

    @keyframes scanlineMove {
        0% { top: 0; }
        100% { top: 100%; }
    }

    /* 渐入动画 */
    .fade-in-up {
        opacity: 0;
        animation: fadeInUp 0.8s ease-out forwards;
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* 模态框动画 */
    @keyframes modalSlideIn {
        from { opacity: 0; transform: scale(0.95) translateY(20px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }

    @keyframes chatSlideUp {
        from { opacity: 0; transform: translateY(40px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* 步骤指示器 */
    .step-indicator {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
    }

    .step-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.875rem;
        font-weight: 600;
        background: rgba(30, 41, 59, 0.8);
        color: #64748b;
        border: 2px solid #334155;
        transition: all 0.3s ease;
    }

    .step-indicator.active .step-circle {
        background: linear-gradient(135deg, #0284c7, #0ea5e9);
        color: white;
        border-color: transparent;
        box-shadow: 0 0 15px rgba(14, 165, 233, 0.4);
    }

    .step-indicator.completed .step-circle {
        background: linear-gradient(135deg, #059669, #10b981);
        color: white;
        border-color: transparent;
        box-shadow: 0 0 15px rgba(16, 185, 129, 0.4);
    }

    .step-text {
        font-size: 0.7rem;
        color: #64748b;
        transition: color 0.3s ease;
    }

    .step-indicator.active .step-text {
        color: #0ea5e9;
    }

    .step-indicator.completed .step-text {
        color: #10b981;
    }

    .step-line {
        width: 40px;
        height: 2px;
        background: #334155;
        margin-bottom: 20px;
        transition: background 0.3s ease;
    }

    .step-line.active {
        background: linear-gradient(90deg, #10b981, #0ea5e9);
    }

    /* 验证码输入框 */
    .form-input[name^="code"] {
        text-align: center;
        font-size: 1.25rem;
        font-weight: 700;
        letter-spacing: 0;
        caret-color: #0ea5e9;
    }

    .form-input[name^="code"]:focus {
        border-color: #0ea5e9;
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15), 0 0 20px rgba(14, 165, 233, 0.1);
    }

    /* 聊天气泡增强 */
    .chat-msg-customer {
        background: linear-gradient(135deg, rgba(14, 165, 233, 0.15), rgba(14, 165, 233, 0.05));
        border: 1px solid rgba(14, 165, 233, 0.2);
        border-radius: 16px 16px 4px 16px;
        animation: msgSlideIn 0.3s ease-out;
    }

    .chat-msg-admin {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
        border: 1px solid rgba(16, 185, 129, 0.2);
        border-radius: 16px 16px 16px 4px;
        animation: msgSlideIn 0.3s ease-out;
    }

    .chat-msg-system {
        background: rgba(51, 65, 85, 0.3);
        border: 1px solid rgba(51, 65, 85, 0.3);
        border-radius: 12px;
        animation: msgSlideIn 0.3s ease-out;
    }

    @keyframes msgSlideIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* 文件列表项 */
    .file-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 8px 12px;
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(51, 65, 85, 0.5);
        border-radius: 8px;
        font-size: 0.75rem;
        color: #94a3b8;
    }

    .file-item .remove-file {
        cursor: pointer;
        color: #ef4444;
        margin-left: auto;
    }

    /* 渐变文字效果 */
    .gradient-text-animated {
        background: linear-gradient(90deg, #ef4444, #f59e0b, #ef4444);
        background-size: 200% auto;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        animation: gradientShift 3s linear infinite;
    }

    @keyframes gradientShift {
        0% { background-position: 0% center; }
        100% { background-position: 200% center; }
    }

    /* 呼吸灯边框 */
    @keyframes borderBreathing {
        0%, 100% { border-color: rgba(239, 68, 68, 0.2); box-shadow: 0 0 10px rgba(239, 68, 68, 0.1); }
        50% { border-color: rgba(239, 68, 68, 0.4); box-shadow: 0 0 25px rgba(239, 68, 68, 0.2); }
    }

    .glass[style*="border-red"] {
        animation: borderBreathing 3s ease-in-out infinite;
    }

    /* Textarea 自动调整 */
    textarea {
        overflow-y: auto;
    }
</style>

<!-- 违规页面脚本 -->
<script>
(function() {
    const domainName = <?php echo json_encode($domainName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const domainId = <?php echo (int)$domainId; ?>;
    let isVerified = true;
    let verifiedEmail = '';
    let chatConversationId = null;
    let chatPollInterval = null;
    let verificationToken = '';
    let chatCustomerEmail = ''; // 对话面板使用的客户邮箱
    let hasAppealRecord = false; // 是否已有申诉记录
    let cachedAppealInfo = null; // 缓存的申诉信息

    // 当前时间显示
    function updateCurrentTime() {
        const el = document.getElementById('current-time');
        if (el) {
            const now = new Date();
            el.textContent = now.toLocaleString('zh-CN', {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
        }
    }
    setInterval(updateCurrentTime, 1000);
    updateCurrentTime();

    // ==================== 申诉模态框 ====================
    window.openAppealModal = function() {
        // 重置到验证身份步骤（无论是否有申诉记录，都需要先验证邮箱）
        document.getElementById('appeal-verify-modal').classList.remove('hidden');
        document.getElementById('appeal-step-3').classList.add('hidden');
        document.getElementById('appeal-success').classList.add('hidden');
        document.getElementById('appeal-status-view').classList.add('hidden');
        document.getElementById('appeal-verify-email').value = '';
        document.getElementById('verify-email-error').classList.add('hidden');
        document.getElementById('appeal-modal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };

    window.closeAppealModal = function() {
        document.getElementById('appeal-modal').classList.add('hidden');
        document.body.style.overflow = '';
    };

    // 验证邮箱表单提交
    document.getElementById('appeal-verify-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const emailInput = document.getElementById('appeal-verify-email');
        const email = emailInput.value.trim();
        const errorEl = document.getElementById('verify-email-error');

        // 验证邮箱格式
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email || !emailRegex.test(email)) {
            errorEl.textContent = '请输入有效的邮箱地址';
            errorEl.classList.remove('hidden');
            return;
        }

        errorEl.classList.add('hidden');
        const btn = document.getElementById('verify-email-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="loader" style="width:18px;height:18px;border-width:2px;"></span><span>验证中...</span>';

        try {
            // 验证邮箱 - 调用API检查邮箱是否为已注册客户
            const formData = new FormData();
            formData.append('email', email);
            formData.append('domain_id', domainId);
            formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');

            const res = await fetch('/api/appeal.php?action=verify_email', {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();

            if (data.success || data.valid) {
                // 验证成功，将邮箱填入申诉表单
                document.getElementById('appeal-customer-email').value = email;

                // 检查是否有申诉记录
                const appealInfo = data.appeal || data.data?.appeal || null;
                if (appealInfo) {
                    // 已有申诉记录，显示申诉状态
                    hasAppealRecord = true;
                    cachedAppealInfo = appealInfo;
                    document.getElementById('appeal-verify-modal').classList.add('hidden');
                    showAppealStatus(appealInfo);
                } else {
                    // 无申诉记录，显示申诉表单
                    hasAppealRecord = false;
                    cachedAppealInfo = null;
                    document.getElementById('appeal-verify-modal').classList.add('hidden');
                    document.getElementById('appeal-step-3').classList.remove('hidden');
                }
            } else {
                errorEl.textContent = data.message || '邮箱验证失败，请检查后重试';
                errorEl.classList.remove('hidden');
            }
        } catch (err) {
            // 如果API不存在或出错，直接允许通过（兼容模式）
            document.getElementById('appeal-customer-email').value = email;
            document.getElementById('appeal-verify-modal').classList.add('hidden');
            document.getElementById('appeal-step-3').classList.remove('hidden');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '验证并继续';
        }
    });

    // 申诉内容字数统计
    const textarea = document.querySelector('textarea[name="appeal_content"]');
    if (textarea) {
        textarea.addEventListener('input', function() {
            document.getElementById('content-count').textContent = this.value.length + '/5000';
        });
    }

    // 证据文件选择
    document.getElementById('evidence-input').addEventListener('change', function() {
        const fileList = document.getElementById('file-list');
        fileList.innerHTML = '';
        Array.from(this.files).forEach(file => {
            const item = document.createElement('div');
            item.className = 'file-item flex items-center';
            item.innerHTML = `
                <svg class="w-4 h-4 mr-2 text-dark-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span class="truncate flex-1">${escapeHtml(file.name)}</span>
                <span class="text-dark-600 ml-2">${(file.size / 1024).toFixed(1)}KB</span>
            `;
            fileList.appendChild(item);
        });
    });

    // 提交申诉（直接提交，无需验证码）
    document.getElementById('appeal-content-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const content = this.appeal_content.value.trim();
        const customerEmail = this.customer_email.value.trim();
        const errorEl = document.getElementById('content-error');

        if (!customerEmail || !customerEmail.includes('@')) {
            errorEl.textContent = '请输入有效的邮箱地址';
            errorEl.classList.remove('hidden');
            return;
        }

        if (!content) {
            errorEl.textContent = '请填写申诉内容';
            errorEl.classList.remove('hidden');
            return;
        }

        errorEl.classList.add('hidden');
        const btn = document.getElementById('submit-appeal-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="loader" style="width:20px;height:20px;border-width:2px;"></span><span>提交中...</span>';

        try {
            const formData = new FormData();
            formData.append('domain_id', domainId);
            formData.append('customer_email', customerEmail);
            formData.append('customer_name', this.customer_name.value.trim());
            formData.append('customer_phone', this.customer_phone.value.trim());
            formData.append('appeal_content', content);
            formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');

            // 添加证据文件
            const fileInput = document.getElementById('evidence-input');
            if (fileInput.files.length > 0) {
                Array.from(fileInput.files).forEach(file => {
                    formData.append('evidence[]', file);
                });
            }

            const res = await fetch('/api/appeal.php?action=submit', {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();

            if (data.success) {
                document.querySelectorAll('.appeal-step').forEach(el => el.classList.add('hidden'));
                document.getElementById('appeal-success').classList.remove('hidden');
                if (typeof LighthouseAnimations !== 'undefined') {
                    LighthouseAnimations.showToast('申诉提交成功', 'success');
                }
            } else {
                errorEl.textContent = data.message || '提交失败';
                errorEl.classList.remove('hidden');
            }
        } catch (err) {
            errorEl.textContent = '网络错误，请稍后重试';
            errorEl.classList.remove('hidden');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg><span>提交申诉</span>';
        }
    });

    // ==================== 对话面板 ====================
    window.openChatPanel = function() {
        // 从申诉表单或URL参数获取客户邮箱
        const emailInput = document.querySelector('input[name="customer_email"]');
        if (emailInput && emailInput.value.trim()) {
            chatCustomerEmail = emailInput.value.trim();
        }
        const urlParams = new URLSearchParams(window.location.search);
        if (!chatCustomerEmail && urlParams.get('email')) {
            chatCustomerEmail = urlParams.get('email');
        }

        document.getElementById('chat-panel').classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        if (chatCustomerEmail) {
            // 已有邮箱，直接打开对话
            showChatArea();
            loadChatMessages();
        } else {
            // 没有邮箱，显示邮箱输入区域
            showChatEmailPrompt();
        }
    };

    // 显示邮箱输入区域
    function showChatEmailPrompt() {
        document.getElementById('chat-email-prompt').classList.remove('hidden');
        document.getElementById('chat-messages-area').classList.add('hidden');
        document.getElementById('chat-input-area').classList.add('hidden');
        // 清空之前的输入
        const emailInput = document.getElementById('chat-email-input');
        if (emailInput) emailInput.value = '';
        const errorEl = document.getElementById('chat-email-error');
        if (errorEl) errorEl.classList.add('hidden');
    }

    // 用户输入邮箱后开始对话
    window.startChatWithEmail = function() {
        const emailInput = document.getElementById('chat-email-input');
        const email = emailInput ? emailInput.value.trim() : '';
        const errorEl = document.getElementById('chat-email-error');

        // 验证邮箱格式
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email || !emailRegex.test(email)) {
            if (errorEl) {
                errorEl.textContent = '请输入格式正确的邮箱地址';
                errorEl.classList.remove('hidden');
            }
            return;
        }

        if (errorEl) errorEl.classList.add('hidden');
        chatCustomerEmail = email;
        showChatArea();
        loadChatMessages();
    };

    window.closeChatPanel = function() {
        document.getElementById('chat-panel').classList.add('hidden');
        document.body.style.overflow = '';
        if (chatPollInterval) {
            clearInterval(chatPollInterval);
            chatPollInterval = null;
        }
    };

    function showChatArea() {
        document.getElementById('chat-email-prompt').classList.add('hidden');
        document.getElementById('chat-messages-area').classList.remove('hidden');
        document.getElementById('chat-input-area').classList.remove('hidden');
    }

    async function loadChatMessages() {
        try {
            const res = await fetch(`/api/chat.php?action=customer_list&customer_email=${encodeURIComponent(chatCustomerEmail)}`);
            const data = await res.json();

            if (data.success && data.data && data.data.conversations && data.data.conversations.length > 0) {
                chatConversationId = data.data.conversations[0].id;
                await loadMessages(chatConversationId);
            } else {
                // 显示欢迎消息
                const container = document.getElementById('chat-messages-area');
                container.innerHTML = '<div class="flex justify-center my-4"><div class="chat-msg-system px-4 py-2 text-xs text-dark-400">系统消息：欢迎使用在线客服，请描述您的问题。</div></div>';
            }

            // 开始轮询
            if (chatPollInterval) clearInterval(chatPollInterval);
            chatPollInterval = setInterval(() => {
                if (chatConversationId) loadMessages(chatConversationId);
            }, 5000);
        } catch (err) {
            console.error('加载聊天失败:', err);
        }
    }

    async function loadMessages(conversationId) {
        try {
            const res = await fetch(`/api/chat.php?action=customer_messages&conversation_id=${conversationId}&customer_email=${encodeURIComponent(chatCustomerEmail)}`);
            const data = await res.json();
            if (data.success && data.data && data.data.messages) {
                renderMessages(data.data.messages);
            }
        } catch (err) {
            console.error('加载消息失败:', err);
        }
    }

    function renderMessages(messages) {
        const container = document.getElementById('chat-messages-area');
        container.innerHTML = messages.map(msg => {
            const isCustomer = msg.sender_type === 'customer';
            const isAdmin = msg.sender_type === 'admin';
            const isSystem = msg.sender_type === 'system';
            const align = isCustomer ? 'justify-end' : 'justify-start';
            const bubbleClass = isCustomer ? 'chat-msg-customer' : (isAdmin ? 'chat-msg-admin' : 'chat-msg-system');
            const senderLabel = isCustomer ? '您' : (isAdmin ? '客服' : '系统');
            const time = msg.created_at ? new Date(msg.created_at).toLocaleString('zh-CN', {month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}) : '';

            let content = '';
            if (msg.message_type === 'text') {
                content = `<p class="text-sm text-dark-100">${escapeHtml(msg.message)}</p>`;
            } else if (msg.message_type === 'image') {
                content = `<img src="${escapeHtml(msg.file_path)}" alt="图片" class="max-w-[200px] rounded-lg cursor-pointer">`;
            } else if (msg.message_type === 'video') {
                content = `<video src="${escapeHtml(msg.file_path)}" controls class="max-w-[200px] rounded-lg"></video>`;
            } else if (msg.message_type === 'file') {
                content = `<a href="${escapeHtml(msg.file_path)}" target="_blank" class="text-sm text-lighthouse-400 hover:underline flex items-center space-x-1"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg><span>${escapeHtml(msg.file_name || '文件')}</span></a>`;
            }

            if (isSystem) {
                return `<div class="flex justify-center my-4"><div class="${bubbleClass} px-4 py-2 text-xs text-dark-400">${escapeHtml(msg.message)}</div></div>`;
            }

            return `
                <div class="flex ${align}">
                    <div class="max-w-[80%] ${bubbleClass} px-4 py-3">
                        <p class="text-xs ${isCustomer ? 'text-lighthouse-400' : 'text-green-400'} mb-1">${senderLabel}</p>
                        ${content}
                        <p class="text-xs text-dark-500 mt-1">${time}</p>
                    </div>
                </div>
            `;
        }).join('');

        container.scrollTop = container.scrollHeight;
    }

    // 发送消息
    document.getElementById('chat-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const input = this.message;
        const message = input.value.trim();
        if (!message) return;

        input.value = '';
        autoResizeTextarea(input);

        try {
            const formData = new FormData();
            formData.append('conversation_id', chatConversationId || 0);
            formData.append('message', message);
            formData.append('message_type', 'text');
            formData.append('customer_email', chatCustomerEmail);
            formData.append('domain', domainName);
            formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');

            const res = await fetch('/api/chat.php?action=customer_send', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                if (!chatConversationId) chatConversationId = data.data?.conversation_id;
                await loadMessages(chatConversationId);
            } else {
                if (typeof LighthouseAnimations !== 'undefined') LighthouseAnimations.showToast(data.message || '发送失败', 'error');
            }
        } catch (err) {
            if (typeof LighthouseAnimations !== 'undefined') LighthouseAnimations.showToast('网络错误，请稍后重试', 'error');
        }
    });

    window.handleChatFileUpload = async function(input) {
        const file = input.files[0];
        if (!file) return;

        if (!chatConversationId) {
            if (typeof LighthouseAnimations !== 'undefined') LighthouseAnimations.showToast('请先发送一条文字消息', 'warning');
            input.value = '';
            return;
        }

        // 判断文件类型
        let messageType = 'file';
        if (file.type.startsWith('image/')) messageType = 'image';
        else if (file.type.startsWith('video/')) messageType = 'video';

        const formData = new FormData();
        formData.append('conversation_id', chatConversationId);
        formData.append('message', '');
        formData.append('message_type', messageType);
        formData.append('customer_email', chatCustomerEmail);
        formData.append('file', file);
        formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');

        try {
            const res = await fetch('/api/chat.php?action=customer_send', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                await loadMessages(chatConversationId);
                if (typeof LighthouseAnimations !== 'undefined') LighthouseAnimations.showToast('文件发送成功', 'success');
            } else {
                if (typeof LighthouseAnimations !== 'undefined') LighthouseAnimations.showToast(data.message || '上传失败', 'error');
            }
        } catch (err) {
            if (typeof LighthouseAnimations !== 'undefined') LighthouseAnimations.showToast('上传失败', 'error');
        }
        input.value = '';
    };

    // Textarea 自动调整高度
    window.autoResizeTextarea = function(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 120) + 'px';
    };

    // HTML转义
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // ==================== 申诉状态检查 ====================

    // 显示申诉审核状态信息
    function showAppealStatus(appeal) {
        const statusMap = {
            pending: '待审核',
            processing: '审核中',
            approved: '已通过',
            rejected: '已拒绝'
        };
        const statusColorMap = {
            pending: 'text-yellow-400',
            processing: 'text-blue-400',
            approved: 'text-green-400',
            rejected: 'text-red-400'
        };
        const statusBgMap = {
            pending: 'bg-yellow-500/10 border-yellow-500/30',
            processing: 'bg-blue-500/10 border-blue-500/30',
            approved: 'bg-green-500/10 border-green-500/30',
            rejected: 'bg-red-500/10 border-red-500/30'
        };

        const status = appeal.status || 'pending';
        const statusLabel = statusMap[status] || status;
        const statusColor = statusColorMap[status] || 'text-dark-300';
        const statusBg = statusBgMap[status] || 'bg-dark-800 border-dark-700';

        // 从PHP变量获取审查原因
        const violationReason = <?php echo json_encode($violationReason, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        const contentEl = document.getElementById('appeal-status-content');
        contentEl.innerHTML = `
            <div class="rounded-xl p-4 border ${statusBg}">
                <div class="flex items-center space-x-3 mb-3">
                    <span class="text-sm font-medium ${statusColor}">${escapeHtml(statusLabel)}</span>
                </div>
                <div class="mb-3">
                    <span class="text-dark-500 text-xs block mb-1">审查原因</span>
                    <p class="text-dark-200 text-sm">${escapeHtml(violationReason)}</p>
                </div>
                ${appeal.review_note ? `
                <div class="mb-3">
                    <span class="text-dark-500 text-xs block mb-1">审核备注</span>
                    <p class="text-dark-200 text-sm">${escapeHtml(appeal.review_note)}</p>
                </div>` : ''}
                <div class="text-dark-500 text-xs">
                    <span>提交时间：${escapeHtml(appeal.created_at || '-')}</span>
                    ${appeal.reviewed_at ? `<span class="ml-4">审核时间：${escapeHtml(appeal.reviewed_at)}</span>` : ''}
                </div>
            </div>
        `;
        document.getElementById('appeal-status-view').classList.remove('hidden');
    }

    // 页面加载时检查是否有申诉记录（通过domain_id查询，无需email）
    function checkAppealStatus() {
        if (!domainId) return;

        fetch(`/api/appeal.php?action=has_appeal&domain_id=${domainId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data && data.data.has_appeal) {
                hasAppealRecord = true;
                cachedAppealInfo = data.data.appeal;
                // 更新按钮文字
                const btnText = document.getElementById('appeal-btn-text');
                if (btnText) btnText.textContent = '查看申诉';
            }
        })
        .catch(() => {
            // 静默失败，不影响页面正常使用
        });
    }

    // 页面加载后检查申诉状态
    checkAppealStatus();

    // ==================== 动画效果 ====================

    // 打字机效果 - 标题
    const titleEl = document.getElementById('violation-title');
    if (titleEl && typeof LighthouseAnimations !== 'undefined') {
        const originalText = titleEl.textContent.trim();
        titleEl.textContent = '';
        setTimeout(() => {
            LighthouseAnimations.typeWriter(titleEl, originalText, 80);
        }, 500);
    }

    // 增强粒子效果
    if (typeof LighthouseAnimations !== 'undefined') {
        LighthouseAnimations.initParticles('particles-enhanced', 40);
    }

    // 按钮波纹效果
    document.querySelectorAll('.btn-primary, .btn-secondary').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (typeof LighthouseAnimations !== 'undefined') {
                LighthouseAnimations.ripple(e, this);
            }
        });
    });

    // ESC 关闭模态框
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAppealModal();
            closeChatPanel();
        }
    });

    // 点击遮罩关闭
    document.getElementById('appeal-modal').addEventListener('click', function(e) {
        if (e.target === this) closeAppealModal();
    });
    document.getElementById('chat-panel').addEventListener('click', function(e) {
        if (e.target === this) closeChatPanel();
    });
})();
</script>
