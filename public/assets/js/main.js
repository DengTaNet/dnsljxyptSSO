/**
 * 灯塔DNS拦截响应平台 - 公共JS
 */

const LighthouseApp = {
    /**
     * API 基础URL
     */
    apiBase: '/api',

    /**
     * CSRF Token
     */
    get csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    },

    get csrfName() {
        const meta = document.querySelector('meta[name="csrf-name"]');
        return meta ? meta.content : '_token';
    },

    /**
     * 通用 API 请求
     */
    async request(url, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfToken,
            },
        };

        const mergedOptions = { ...defaultOptions, ...options };
        mergedOptions.headers = { ...defaultOptions.headers, ...options.headers };

        try {
            const response = await fetch(this.apiBase + url, mergedOptions);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || '请求失败');
            }

            return data;
        } catch (error) {
            console.error('API请求失败:', error);
            if (typeof LighthouseAnimations !== 'undefined') {
                LighthouseAnimations.showToast(error.message, 'error');
            }
            throw error;
        }
    },

    /**
     * GET 请求
     */
    async get(url, params = {}) {
        const query = new URLSearchParams(params).toString();
        const fullUrl = query ? `${url}?${query}` : url;
        return this.request(fullUrl, { method: 'GET' });
    },

    /**
     * POST 请求
     */
    async post(url, data = {}) {
        return this.request(url, {
            method: 'POST',
            body: JSON.stringify(data),
        });
    },

    /**
     * PUT 请求
     */
    async put(url, data = {}) {
        return this.request(url, {
            method: 'PUT',
            body: JSON.stringify(data),
        });
    },

    /**
     * DELETE 请求
     */
    async delete(url) {
        return this.request(url, { method: 'DELETE' });
    },

    /**
     * 文件上传
     */
    async upload(url, formData) {
        return fetch(this.apiBase + url, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': this.csrfToken,
            },
            body: formData,
        }).then(res => res.json());
    },

    /**
     * 表单序列化
     */
    serializeForm(form) {
        const formData = new FormData(form);
        const data = {};
        for (const [key, value] of formData.entries()) {
            data[key] = value;
        }
        return data;
    },

    /**
     * 确认对话框
     */
    confirm(message) {
        return new Promise((resolve) => {
            // 使用自定义确认框（如可用）
            if (window.customConfirm) {
                window.customConfirm(message, resolve);
            } else {
                resolve(window.confirm(message));
            }
        });
    },

    /**
     * 防抖
     */
    debounce(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * 节流
     */
    throttle(func, limit = 300) {
        let inThrottle;
        return function executedFunction(...args) {
            if (!inThrottle) {
                func(...args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },

    /**
     * 复制到剪贴板
     */
    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            if (typeof LighthouseAnimations !== 'undefined') {
                LighthouseAnimations.showToast('已复制到剪贴板', 'success');
            }
            return true;
        } catch (err) {
            // 降级方案
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            return true;
        }
    },

    /**
     * 格式化日期
     */
    formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    },

    /**
     * 格式化相对时间
     */
    formatRelativeTime(dateStr) {
        const now = new Date();
        const date = new Date(dateStr);
        const diff = Math.floor((now - date) / 1000);

        if (diff < 60) return '刚刚';
        if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
        if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
        if (diff < 2592000) return Math.floor(diff / 86400) + ' 天前';
        return this.formatDate(dateStr);
    },
};

// 全局暴露
window.LighthouseApp = LighthouseApp;
