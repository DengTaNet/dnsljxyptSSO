<?php
/**
 * 灯塔DNS拦截响应平台 - 公共底部模板
 * 参照官网 www.dengtanet.com 页脚设计
 */
?>
    </main>

    <!-- 页脚 -->
    <footer class="relative z-10 border-t border-dark-800/50 bg-dark-950/80 backdrop-blur-xl mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex flex-col items-center space-y-4">
                <!-- 品牌信息 -->
                <div class="flex items-center space-x-2">
                    <span class="text-dark-400 text-sm">&copy; <?php echo date('Y'); ?></span>
                    <a href="https://www.dengtanet.com" target="_blank" class="text-lighthouse-500 text-sm hover:text-lighthouse-400 transition-colors">灯塔网络科技（甘肃）有限公司</a>
                    <span class="text-dark-500 text-sm">版权所有</span>
                </div>
                <!-- ICP备案号 -->
                <div class="flex items-center space-x-4">
                    <a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener" class="text-dark-500 text-xs hover:text-dark-300 transition-colors">陇ICP备2025025190号</a>
                    <span class="text-dark-700">|</span>
                    <span class="text-dark-500 text-xs">Powered by Lighthouse DNS</span>
                    <span class="text-dark-700">|</span>
                    <a href="mailto:mayijie@dengtanet.com" class="text-dark-500 text-xs hover:text-lighthouse-400 transition-colors">联系我们</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- 公共JS -->
    <script src="/assets/js/main.js"></script>
    <script src="/assets/js/animations.js"></script>

    <!-- 移动端菜单切换 -->
    <script>
        document.getElementById('mobile-menu-btn')?.addEventListener('click', function() {
            const menu = document.getElementById('mobile-menu');
            menu?.classList.toggle('hidden');
        });
    </script>
</body>
</html>
