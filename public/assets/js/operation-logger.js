/**
 * 灯塔DNS拦截响应平台 - 操作日志记录器
 *
 * 全局JS自动捕获操作日志：
 * - 拦截所有 fetch 请求，记录API调用
 * - 拦截所有表单提交，记录表单操作
 * - 拦截所有按钮点击，记录点击操作
 * - 记录页面访问（page_view）
 * - 使用 navigator.sendBeacon 或 fetch（async，不阻塞）发送到 /api/log.php?action=record
 */

class OperationLogger {
    constructor(options = {}) {
        this.apiEndpoint = options.apiEndpoint || '/api/log.php?action=record';
        this.enabled = options.enabled !== false;
        this.batchQueue = [];
        this.batchTimer = null;
        this.batchInterval = options.batchInterval || 3000; // 3秒批量发送
        this.maxBatchSize = options.maxBatchSize || 10;
        this.pageLoadTime = performance.now();
        this.pageViewRecorded = false;

        if (this.enabled) {
            this.init();
        }
    }

    init() {
        // 记录页面访问
        this.recordPageView();

        // 拦截 fetch 请求
        this.interceptFetch();

        // 拦截表单提交
        this.interceptFormSubmit();

        // 拦截按钮点击
        this.interceptButtonClick();

        // 页面离开时发送剩余日志
        this.setupBeforeUnload();
    }

    /**
     * 记录页面访问
     */
    recordPageView() {
        if (this.pageViewRecorded) return;
        this.pageViewRecorded = true;

        const path = window.location.pathname;
        let module = 'unknown';
        let description = '页面访问: ' + path;

        // 根据路径推断模块
        if (path.includes('/admin/domains/')) {
            module = 'domains';
            description = '访问域名管理页面';
        } else if (path.includes('/admin/chat/')) {
            module = 'chat';
            description = '访问客服中心页面';
        } else if (path.includes('/admin/logs/')) {
            module = 'logs';
            description = '访问日志页面';
        } else if (path.includes('/admin/ipban/')) {
            module = 'ipban';
            description = '访问IP封禁页面';
        } else if (path.includes('/admin/staff/')) {
            module = 'staff';
            description = '访问组织管理页面';
        } else if (path.includes('/admin/')) {
            module = 'admin';
            description = '访问后台管理页面';
        } else if (path.includes('/violation')) {
            module = 'violation';
            description = '访问违规拦截页面';
        } else if (path.includes('/expired')) {
            module = 'expired';
            description = '访问到期域名页面';
        } else if (path.includes('/banned')) {
            module = 'ipban';
            description = '访问封禁提示页面';
        }

        this.send({
            action: 'page_view',
            module: module,
            description: description,
            request_url: window.location.href,
            result: 'success'
        });
    }

    /**
     * 拦截 fetch 请求
     */
    interceptFetch() {
        const self = this;
        const originalFetch = window.fetch;

        window.fetch = function() {
            const startTime = performance.now();
            const args = arguments;
            const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url || '');
            const options = args[1] || {};
            const method = (options.method || 'GET').toUpperCase();

            // 解析模块和操作类型
            const parsed = self.parseApiUrl(url, method);

            return originalFetch.apply(this, arguments).then(function(response) {
                const duration = Math.round(performance.now() - startTime);
                const result = response.ok ? 'success' : 'failure';

                // 只记录API调用（排除日志记录本身的API调用，避免死循环）
                if (!url.includes('/api/log.php')) {
                    self.send({
                        action: 'api_call',
                        module: parsed.module,
                        description: parsed.description,
                        target_type: parsed.targetType,
                        target_id: parsed.targetId,
                        target_name: parsed.targetName,
                        request_url: url,
                        request_method: method,
                        response_code: response.status,
                        duration: duration,
                        result: result
                    });
                }

                return response;
            }).catch(function(error) {
                const duration = Math.round(performance.now() - startTime);

                if (!url.includes('/api/log.php')) {
                    self.send({
                        action: 'api_call',
                        module: parsed.module,
                        description: parsed.description + ' (失败)',
                        request_url: url,
                        request_method: method,
                        duration: duration,
                        result: 'failure',
                        error_msg: error.message?.substring(0, 500)
                    });
                }

                throw error;
            });
        };
    }

    /**
     * 拦截表单提交
     */
    interceptFormSubmit() {
        const self = this;
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || form.tagName !== 'FORM') return;

            const action = form.getAttribute('action') || '';
            const method = (form.getAttribute('method') || 'POST').toUpperCase();
            const parsed = self.parseApiUrl(action, method);

            self.send({
                action: 'form_submit',
                module: parsed.module,
                description: parsed.description,
                request_url: action || window.location.href,
                request_method: method,
                result: 'success'
            });
        }, true);
    }

    /**
     * 拦截按钮点击
     */
    interceptButtonClick() {
        const self = this;
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('button, a, [role="button"]');
            if (!btn) return;

            // 跳过导航链接和日志相关按钮
            if (btn.tagName === 'A' && (btn.getAttribute('href') || '').startsWith('#')) return;
            if (btn.closest('#clear-modal') || btn.closest('#ban-modal')) return;

            const text = (btn.textContent || btn.getAttribute('title') || btn.getAttribute('aria-label') || '').trim().substring(0, 100);
            const href = btn.getAttribute('href') || '';

            // 推断模块
            let module = 'unknown';
            let description = '点击: ' + text;
            let targetType = '';
            let targetName = '';

            if (text.includes('申辩') || text.includes('验证')) {
                module = 'appeal';
                targetType = 'appeal';
            } else if (text.includes('对话') || text.includes('聊天') || text.includes('发送')) {
                module = 'chat';
                targetType = 'conversation';
            } else if (text.includes('封禁') || text.includes('解封')) {
                module = 'ipban';
                targetType = 'ip';
            } else if (text.includes('删除')) {
                module = 'general';
                targetType = 'record';
            } else if (text.includes('创建') || text.includes('添加')) {
                module = 'general';
                targetType = 'record';
            } else if (text.includes('保存') || text.includes('更新')) {
                module = 'general';
                targetType = 'record';
            }

            // 获取最近的表格行中的目标名称
            const row = btn.closest('tr');
            if (row) {
                const nameCell = row.querySelector('td:nth-child(2)');
                if (nameCell) {
                    targetName = nameCell.textContent.trim().substring(0, 255);
                }
            }

            self.send({
                action: 'click',
                module: module,
                description: description,
                target_type: targetType,
                target_name: targetName,
                request_url: window.location.href,
                result: 'success'
            });
        }, true);
    }

    /**
     * 解析API URL，提取模块和操作信息
     */
    parseApiUrl(url, method) {
        const result = {
            module: 'unknown',
            description: method + ' ' + url,
            targetType: '',
            targetId: '',
            targetName: ''
        };

        try {
            const urlObj = new URL(url, window.location.origin);
            const pathname = urlObj.pathname;
            const actionParam = urlObj.searchParams.get('action') || '';

            // 从路径提取模块
            if (pathname.includes('/api/domain')) {
                result.module = 'domains';
                result.targetType = 'domain';
                result.description = this.getActionDescription(actionParam, '域名');
            } else if (pathname.includes('/api/ipban')) {
                result.module = 'ipban';
                result.targetType = 'ip';
                result.description = this.getActionDescription(actionParam, 'IP封禁');
            } else if (pathname.includes('/api/appeal')) {
                result.module = 'appeal';
                result.targetType = 'appeal';
                result.description = this.getActionDescription(actionParam, '申辩');
            } else if (pathname.includes('/api/chat')) {
                result.module = 'chat';
                result.targetType = 'conversation';
                result.description = this.getActionDescription(actionParam, '聊天');
            } else if (pathname.includes('/api/staff')) {
                result.module = 'staff';
                result.targetType = 'staff';
                result.description = this.getActionDescription(actionParam, '管理员');
            } else if (pathname.includes('/api/auth')) {
                result.module = 'auth';
                result.description = this.getActionDescription(actionParam, '认证');
            } else if (pathname.includes('/api/log')) {
                result.module = 'logs';
            } else if (pathname.includes('/api/')) {
                result.module = 'api';
            }
        } catch (e) {
            // URL解析失败，使用默认值
        }

        return result;
    }

    /**
     * 获取操作描述
     */
    getActionDescription(action, modulePrefix) {
        const actionMap = {
            'list': '获取' + modulePrefix + '列表',
            'create': '创建' + modulePrefix,
            'update': '更新' + modulePrefix,
            'delete': '删除' + modulePrefix,
            'add': '添加',
            'remove': '移除',
            'submit': '提交',
            'review': '审核' + modulePrefix,
            'login': '登录',
            'logout': '退出登录',
            'check': '检查',
            'send': '发送消息',
            'close': '关闭',
            'upload': '上传文件',
            'stats': '获取统计',
            'toggle_status': '切换状态',
            'batch_delete': '批量删除',
            'verify_email': '验证邮箱',
            'request_verification': '请求验证码',
            'verify_code': '验证验证码',
            'customer_send': '客户发送消息',
            'customer_list': '客户获取会话列表',
            'customer_messages': '客户获取消息',
            'mark_read': '标记已读',
            'unread_count': '获取未读数',
            'ban_ip': '封禁IP',
        };
        return actionMap[action] || (modulePrefix + ' ' + action);
    }

    /**
     * 发送日志（批量模式）
     */
    send(data) {
        if (!this.enabled) return;

        this.batchQueue.push(data);

        // 超过最大批量大小立即发送
        if (this.batchQueue.length >= this.maxBatchSize) {
            this.flush();
            return;
        }

        // 启动批量定时器
        if (!this.batchTimer) {
            this.batchTimer = setTimeout(() => {
                this.flush();
            }, this.batchInterval);
        }
    }

    /**
     * 立即发送所有待发送日志
     */
    flush() {
        if (this.batchTimer) {
            clearTimeout(this.batchTimer);
            this.batchTimer = null;
        }

        if (this.batchQueue.length === 0) return;

        const logs = this.batchQueue.splice(0, this.maxBatchSize);

        // 使用 sendBeacon 或 fetch 发送
        const payload = new FormData();
        payload.append('logs', JSON.stringify(logs));

        if (navigator.sendBeacon) {
            try {
                const blob = new Blob([JSON.stringify({logs: logs})], {type: 'application/json'});
                navigator.sendBeacon(this.apiEndpoint, blob);
                return;
            } catch (e) {
                // sendBeacon 失败，回退到 fetch
            }
        }

        // 回退到 fetch
        try {
            fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({logs: logs}),
                keepalive: true
            }).catch(() => {
                // 静默处理发送失败
            });
        } catch (e) {
            // 静默处理
        }
    }

    /**
     * 页面离开时发送剩余日志
     */
    setupBeforeUnload() {
        const self = this;
        window.addEventListener('beforeunload', function() {
            self.flush();
        });

        // 也监听 visibilitychange（移动端）
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                self.flush();
            }
        });
    }

    /**
     * 手动记录一条日志
     */
    record(data) {
        this.send(data);
    }
}

// 自动初始化（后台管理页面和前端客户页面通用）
window.OperationLogger = OperationLogger;

// DOM加载完成后自动初始化
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        window.opLogger = new OperationLogger();
    });
} else {
    window.opLogger = new OperationLogger();
}
