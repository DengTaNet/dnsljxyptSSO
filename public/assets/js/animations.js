/**
 * 灯塔DNS拦截响应平台 - 动画效果库
 */

const LighthouseAnimations = {
    /**
     * 初始化背景粒子效果
     */
    initParticles(containerId = 'particles-bg', count = 30) {
        const container = document.getElementById(containerId);
        if (!container) return;

        for (let i = 0; i < count; i++) {
            this.createParticle(container);
        }
    },

    createParticle(container) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        
        const size = Math.random() * 3 + 1;
        const left = Math.random() * 100;
        const duration = Math.random() * 15 + 10;
        const delay = Math.random() * 15;
        const opacity = Math.random() * 0.5 + 0.1;

        particle.style.cssText = `
            width: ${size}px;
            height: ${size}px;
            left: ${left}%;
            animation-duration: ${duration}s;
            animation-delay: ${delay}s;
            opacity: ${opacity};
        `;

        container.appendChild(particle);
    },

    /**
     * 元素滚动渐入动画
     */
    initScrollAnimations() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });

        document.querySelectorAll('[data-animate]').forEach(el => {
            const animation = el.dataset.animate;
            el.classList.add(`animate-${animation}-init`);
            observer.observe(el);
        });
    },

    /**
     * 数字递增动画
     */
    animateNumber(element, target, duration = 1000) {
        const start = 0;
        const startTime = performance.now();

        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // 缓动函数
            const easeOut = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(start + (target - start) * easeOut);

            element.textContent = current.toLocaleString();

            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }

        requestAnimationFrame(update);
    },

    /**
     * 打字机效果
     */
    typeWriter(element, text, speed = 50) {
        let i = 0;
        element.textContent = '';
        
        function type() {
            if (i < text.length) {
                element.textContent += text.charAt(i);
                i++;
                setTimeout(type, speed);
            }
        }

        type();
    },

    /**
     * 脉冲发光效果
     */
    pulseGlow(element, color = 'rgba(14, 165, 233, 0.5)', duration = 2000) {
        element.style.transition = `box-shadow ${duration}ms ease-in-out`;
        
        setInterval(() => {
            element.style.boxShadow = `0 0 20px ${color}, 0 0 40px ${color}`;
            setTimeout(() => {
                element.style.boxShadow = 'none';
            }, duration);
        }, duration * 2);
    },

    /**
     * 波纹效果
     */
    ripple(event, element) {
        const rect = element.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;
        
        const ripple = document.createElement('span');
        ripple.className = 'ripple-effect';
        ripple.style.cssText = `
            position: absolute;
            border-radius: 50%;
            background: rgba(14, 165, 233, 0.3);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
            left: ${x}px;
            top: ${y}px;
            width: 200px;
            height: 200px;
            margin-left: -100px;
            margin-top: -100px;
        `;

        element.style.position = 'relative';
        element.style.overflow = 'hidden';
        element.appendChild(ripple);

        setTimeout(() => ripple.remove(), 600);
    },

    /**
     * 震动效果（用于错误提示）
     */
    shake(element) {
        element.style.animation = 'none';
        element.offsetHeight; // 触发重绘
        element.style.animation = 'shake 0.5s ease-in-out';
        
        setTimeout(() => {
            element.style.animation = '';
        }, 500);
    },

    /**
     * 淡入效果
     */
    fadeIn(element, duration = 300) {
        element.style.opacity = '0';
        element.style.display = '';
        element.style.transition = `opacity ${duration}ms ease`;
        
        requestAnimationFrame(() => {
            element.style.opacity = '1';
        });
    },

    /**
     * 淡出效果
     */
    fadeOut(element, duration = 300) {
        element.style.transition = `opacity ${duration}ms ease`;
        element.style.opacity = '0';
        
        setTimeout(() => {
            element.style.display = 'none';
        }, duration);
    },

    /**
     * 滑入效果
     */
    slideIn(element, direction = 'up', duration = 300) {
        const transforms = {
            up: 'translateY(20px)',
            down: 'translateY(-20px)',
            left: 'translateX(20px)',
            right: 'translateX(-20px)',
        };

        element.style.opacity = '0';
        element.style.transform = transforms[direction];
        element.style.transition = `all ${duration}ms ease`;
        element.style.display = '';

        requestAnimationFrame(() => {
            element.style.opacity = '1';
            element.style.transform = 'translate(0, 0)';
        });
    },

    /**
     * Toast 通知动画
     */
    showToast(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        const colors = {
            info: 'border-lighthouse-500 bg-lighthouse-500/10',
            success: 'border-green-500 bg-green-500/10',
            error: 'border-red-500 bg-red-500/10',
            warning: 'border-amber-500 bg-amber-500/10',
        };

        toast.className = `fixed top-4 right-4 z-50 border-l-4 ${colors[type]} backdrop-blur-xl rounded-lg p-4 shadow-lg`;
        toast.style.animation = 'fadeInRight 0.3s ease-out';
        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-center space-x-3';
        const span = document.createElement('span');
        span.className = 'text-sm text-slate-200';
        span.textContent = message;
        wrapper.appendChild(span);
        toast.appendChild(wrapper);

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'fadeInLeft 0.3s ease-out reverse';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },
};

// 添加 shake 动画样式
const shakeStyle = document.createElement('style');
shakeStyle.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
    }
    @keyframes ripple {
        to { transform: scale(4); opacity: 0; }
    }
`;
document.head.appendChild(shakeStyle);

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    LighthouseAnimations.initParticles();
    LighthouseAnimations.initScrollAnimations();
});
