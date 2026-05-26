/**
 * 灯塔DNS拦截响应平台 - 后台管理JS
 */

// HTML转义函数（防止XSS）
function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// Toast通知
function showToast(msg, type = 'success') {
    const colors = { success: 'bg-green-600', error: 'bg-red-600', warning: 'bg-yellow-600', info: 'bg-blue-600' };
    const t = document.createElement('div');
    t.className = `fixed top-4 right-4 z-50 ${colors[type] || colors.info} text-white px-6 py-3 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;
    const span = document.createElement('span');
    span.className = 'text-sm';
    span.textContent = msg;
    t.appendChild(span);
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.remove('translate-x-full'));
    setTimeout(() => { t.classList.add('translate-x-full'); setTimeout(() => t.remove(), 300); }, 3000);
}

// 关闭模态框
function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.remove();
}

/**
 * 自定义确认弹窗（替代浏览器原生 confirm）
 * 与暗色主题风格一致
 *
 * @param string message  确认提示信息
 * @param object options  可选配置 { title, confirmText, cancelText, confirmClass }
 * @returns Promise<boolean> true=确认, false=取消
 */
function showConfirm(message, options = {}) {
    const {
        title = '操作确认',
        confirmText = '确认',
        cancelText = '取消',
        confirmClass = 'bg-red-600 hover:bg-red-700',
    } = options;

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.id = 'confirm-modal-' + Date.now();
        overlay.className = 'fixed inset-0 z-[100] flex items-center justify-center';
        overlay.style.cssText = 'background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);';
        overlay.innerHTML = `
            <div class="bg-dark-800 border border-dark-700 rounded-xl shadow-2xl p-6 mx-4 max-w-md w-full transform transition-all" style="animation:modalFadeIn 0.2s ease-out">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 rounded-full bg-yellow-600/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-dark-100">${escapeHtml(title)}</h3>
                </div>
                <p class="text-dark-300 text-sm mb-6 leading-relaxed pl-[52px]">${escapeHtml(message)}</p>
                <div class="flex space-x-3 justify-end">
                    <button id="confirm-cancel" class="px-4 py-2 text-sm rounded-lg bg-dark-700 text-dark-300 hover:bg-dark-600 hover:text-dark-100 transition-colors border border-dark-600">
                        ${escapeHtml(cancelText)}
                    </button>
                    <button id="confirm-ok" class="px-4 py-2 text-sm rounded-lg text-white ${confirmClass} transition-colors">
                        ${escapeHtml(confirmText)}
                    </button>
                </div>
            </div>
            <style>
                @keyframes modalFadeIn {
                    from { opacity: 0; transform: scale(0.95) translateY(-10px); }
                    to { opacity: 1; transform: scale(1) translateY(0); }
                }
            </style>
        `;

        document.body.appendChild(overlay);

        // 点击取消
        overlay.querySelector('#confirm-cancel').addEventListener('click', () => {
            overlay.remove();
            resolve(false);
        });

        // 点击确认
        overlay.querySelector('#confirm-ok').addEventListener('click', () => {
            overlay.remove();
            resolve(true);
        });

        // 点击遮罩关闭（等同于取消）
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                resolve(false);
            }
        });

        // ESC 键关闭
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                overlay.remove();
                resolve(false);
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);

        // 聚焦确认按钮
        overlay.querySelector('#confirm-ok').focus();
    });
}

/**
 * 自定义输入弹窗（替代浏览器原生 prompt）
 * 与暗色主题风格一致
 *
 * @param string message  提示信息
 * @param object options  可选配置 { title, placeholder, defaultValue, confirmText, cancelText }
 * @returns Promise<string|null> 输入值或 null（取消）
 */
function showPrompt(message, options = {}) {
    const {
        title = '请输入',
        placeholder = '',
        defaultValue = '',
        confirmText = '确认',
        cancelText = '取消',
    } = options;

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.id = 'prompt-modal-' + Date.now();
        overlay.className = 'fixed inset-0 z-[100] flex items-center justify-center';
        overlay.style.cssText = 'background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);';
        overlay.innerHTML = `
            <div class="bg-dark-800 border border-dark-700 rounded-xl shadow-2xl p-6 mx-4 max-w-md w-full transform transition-all" style="animation:modalFadeIn 0.2s ease-out">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 rounded-full bg-blue-600/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-dark-100">${escapeHtml(title)}</h3>
                </div>
                <p class="text-dark-300 text-sm mb-3 leading-relaxed pl-[52px]">${escapeHtml(message)}</p>
                <input id="prompt-input" type="text" value="${escapeHtml(defaultValue)}" placeholder="${escapeHtml(placeholder)}"
                    class="w-full px-4 py-2.5 mb-6 bg-dark-900 border border-dark-600 rounded-lg text-dark-100 text-sm placeholder-dark-500 focus:outline-none focus:border-lighthouse-500 focus:ring-1 focus:ring-lighthouse-500 transition-colors" />
                <div class="flex space-x-3 justify-end">
                    <button id="prompt-cancel" class="px-4 py-2 text-sm rounded-lg bg-dark-700 text-dark-300 hover:bg-dark-600 hover:text-dark-100 transition-colors border border-dark-600">
                        ${escapeHtml(cancelText)}
                    </button>
                    <button id="prompt-ok" class="px-4 py-2 text-sm rounded-lg text-white bg-lighthouse-600 hover:bg-lighthouse-700 transition-colors">
                        ${escapeHtml(confirmText)}
                    </button>
                </div>
            </div>
            <style>
                @keyframes modalFadeIn {
                    from { opacity: 0; transform: scale(0.95) translateY(-10px); }
                    to { opacity: 1; transform: scale(1) translateY(0); }
                }
            </style>
        `;

        document.body.appendChild(overlay);

        const input = overlay.querySelector('#prompt-input');
        input.focus();
        input.select();

        overlay.querySelector('#prompt-cancel').addEventListener('click', () => {
            overlay.remove();
            resolve(null);
        });

        overlay.querySelector('#prompt-ok').addEventListener('click', () => {
            const value = input.value.trim();
            overlay.remove();
            resolve(value);
        });

        // 回车确认
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const value = input.value.trim();
                overlay.remove();
                resolve(value);
            }
        });

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                resolve(null);
            }
        });

        const escHandler = (e) => {
            if (e.key === 'Escape') {
                overlay.remove();
                resolve(null);
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    });
}

/**
 * 自定义提示弹窗（替代浏览器原生 alert）
 * 与暗色主题风格一致
 *
 * @param string message  提示信息
 * @param string type     类型: info/success/warning/error
 */
function showAlert(message, type = 'info') {
    const icons = {
        info: '<svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        success: '<svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        warning: '<svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
        error: '<svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    };
    const bgColors = { info: 'bg-blue-600/20', success: 'bg-green-600/20', warning: 'bg-yellow-600/20', error: 'bg-red-600/20' };

    const overlay = document.createElement('div');
    overlay.id = 'alert-modal-' + Date.now();
    overlay.className = 'fixed inset-0 z-[100] flex items-center justify-center';
    overlay.style.cssText = 'background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);';
    overlay.innerHTML = `
        <div class="bg-dark-800 border border-dark-700 rounded-xl shadow-2xl p-6 mx-4 max-w-md w-full transform transition-all" style="animation:modalFadeIn 0.2s ease-out">
            <div class="flex items-start space-x-3 mb-4">
                <div class="w-10 h-10 rounded-full ${bgColors[type] || bgColors.info} flex items-center justify-center flex-shrink-0">
                    ${icons[type] || icons.info}
                </div>
                <p class="text-dark-200 text-sm leading-relaxed pt-2">${escapeHtml(message)}</p>
            </div>
            <div class="flex justify-end">
                <button id="alert-ok" class="px-4 py-2 text-sm rounded-lg bg-dark-700 text-dark-300 hover:bg-dark-600 hover:text-dark-100 transition-colors border border-dark-600">
                    我知道了
                </button>
            </div>
        </div>
        <style>
            @keyframes modalFadeIn {
                from { opacity: 0; transform: scale(0.95) translateY(-10px); }
                to { opacity: 1; transform: scale(1) translateY(0); }
            }
        </style>
    `;

    document.body.appendChild(overlay);

    overlay.querySelector('#alert-ok').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

    const escHandler = (e) => {
        if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); }
    };
    document.addEventListener('keydown', escHandler);
    overlay.querySelector('#alert-ok').focus();
}

const AdminApp = {
    /**
     * 删除域名
     */
    async deleteDomain(id) {
        const confirmed = await showConfirm('确认删除该域名？此操作不可撤销。');
        if (!confirmed) return;

        try {
            await LighthouseApp.delete(`/domains/${id}`);
            showToast('域名已删除', 'success');
            setTimeout(() => location.reload(), 500);
        } catch (error) {
            showToast('删除失败', 'error');
        }
    },

};

// 全局暴露（供 onclick 调用）
window.deleteDomain = (id) => AdminApp.deleteDomain(id);
