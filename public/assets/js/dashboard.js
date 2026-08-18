/**
 * Dashboard Charts — Chart.js
 */
document.addEventListener('DOMContentLoaded', () => {
    loadSalesChart();
    loadCategoryChart();
    
    // Period selector
    const periodSelect = document.getElementById('salesChartPeriod');
    if (periodSelect) {
        periodSelect.addEventListener('change', () => loadSalesChart(periodSelect.value));
    }
});

async function loadSalesChart(days = 30) {
    try {
        const data = await apiRequest(`/api/dashboard?action=sales_chart&days=${days}`);
        renderSalesChart(data);
    } catch (e) {
        console.error('Failed to load sales chart:', e);
    }
}

function renderSalesChart(data) {
    const ctx = document.getElementById('salesChart');
    if (!ctx) return;
    
    // Destroy existing chart
    if (window.salesChartInstance) window.salesChartInstance.destroy();
    
        
    window.salesChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels || [],
            datasets: [{
                label: 'Revenue',
                data: data.values || [],
                borderColor: '#9A0002',
                backgroundColor: 'rgba(154, 0, 2, 0.1)',
                fill: true,
                tension: 0.4,
                borderWidth: 2,
                pointRadius: 0,
                pointHitRadius: 10,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: '#9A0002',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index',
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 22, 41, 0.9)',
                    titleColor: '#e2e8f0',
                    bodyColor: '#94a3b8',
                    borderColor: 'rgba(154, 0, 2, 0.3)',
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: (ctx) => `Revenue: ₱${ctx.parsed.y.toFixed(2)}`,
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(148, 163, 184, 0.06)' },
                    ticks: { color: '#64748b', font: { size: 11 }, maxTicksLimit: 10 },
                    border: { display: false },
                },
                y: {
                    grid: { color: 'rgba(148, 163, 184, 0.06)' },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11 },
                        callback: (v) => '₱' + v.toLocaleString(),
                    },
                    border: { display: false },
                    beginAtZero: true,
                }
            }
        }
    });
}

async function loadCategoryChart() {
    try {
        const data = await apiRequest('/api/dashboard?action=category_chart');
        renderCategoryChart(data);
    } catch (e) {
        console.error('Failed to load category chart:', e);
    }
}

function renderCategoryChart(data) {
    const ctx = document.getElementById('categoryChart');
    if (!ctx) return;
    
    if (window.categoryChartInstance) window.categoryChartInstance.destroy();
    
    window.categoryChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.labels || [],
            datasets: [{
                data: data.values || [],
                backgroundColor: data.colors || ['#9A0002', '#06b6d4', '#10b981', '#f59e0b', '#f43f5e', '#ec4899', '#6366f1', '#64748b'],
                borderWidth: 0,
                
                hoverOffset: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { right: 20 } },
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        color: '#94a3b8',
                        font: { size: 12, family: 'Inter' },
                        padding: 16,
                        usePointStyle: true,
                        boxWidth: 12,
                        boxHeight: 12,
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 22, 41, 0.9)',
                    titleColor: '#e2e8f0',
                    bodyColor: '#94a3b8',
                    borderColor: 'rgba(154, 0, 2, 0.3)',
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: (ctx) => ` ${ctx.label}: ${ctx.parsed} products`,
                    }
                }
            }
        }
    });
}
