/**
 * 灯塔DNS拦截响应平台 - 图表功能
 * 依赖 Chart.js CDN
 */

const AdminCharts = {
    /**
     * 初始化仪表盘图表
     */
    initDashboard() {
        this.initVisitChart();
        this.initDomainTypeChart();
    },

    /**
     * 访问趋势图
     */
    async initVisitChart() {
        const canvas = document.getElementById('visit-chart');
        if (!canvas) return;

        try {
            const response = await fetch('/api/logs.php?action=stats');
            const result = await response.json();
            const data = result.data || {};

            const ctx = canvas.getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: (data.trend_data || []).map(d => d.date),
                    datasets: [{
                        label: '访问量',
                        data: (data.trend_data || []).map(d => d.total_visits),
                        borderColor: '#38bdf8',
                        backgroundColor: 'rgba(56, 189, 248, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                    },
                    scales: {
                        x: {
                            grid: { color: 'rgba(51, 65, 85, 0.3)' },
                            ticks: { color: '#94a3b8', font: { size: 11 } },
                        },
                        y: {
                            grid: { color: 'rgba(51, 65, 85, 0.3)' },
                            ticks: { color: '#94a3b8', font: { size: 11 } },
                            beginAtZero: true,
                        }
                    }
                }
            });
        } catch (error) {
            console.error('加载访问统计失败:', error);
        }
    },

    /**
     * 域名类型分布图
     */
    async initDomainTypeChart() {
        const canvas = document.getElementById('domain-type-chart');
        if (!canvas) return;

        try {
            const response = await fetch('/api/domain.php?action=stats');
            const result = await response.json();
            const data = result.data || {};

            const ctx = canvas.getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['到期域名', '审查域名'],
                    datasets: [{
                        data: [data.expired_count || 0, data.violation_count || 0],
                        backgroundColor: ['rgba(251, 191, 36, 0.8)', 'rgba(248, 113, 113, 0.8)'],
                        borderColor: ['rgba(251, 191, 36, 1)', 'rgba(248, 113, 113, 1)'],
                        borderWidth: 1,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: '#94a3b8',
                                padding: 20,
                                font: { size: 12 },
                            }
                        }
                    },
                    cutout: '65%',
                }
            });
        } catch (error) {
            console.error('加载域名统计失败:', error);
        }
    },
};

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    AdminCharts.initDashboard();
});
