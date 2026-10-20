function initDashboardCharts(salesData, inventoryData) {
    if (typeof Chart === 'undefined') return;

    var salesCtx = document.getElementById('salesChart');
    if (salesCtx && salesData) {
        new Chart(salesCtx, {
            type: 'bar',
            data: {
                labels: salesData.labels,
                datasets: [{
                    label: 'Daily Sales',
                    data: salesData.values,
                    backgroundColor: '#1976D2',
                    borderColor: '#1565C0',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    var invCtx = document.getElementById('inventoryChart');
    if (invCtx && inventoryData) {
        new Chart(invCtx, {
            type: 'doughnut',
            data: {
                labels: inventoryData.labels,
                datasets: [{
                    data: inventoryData.values,
                    backgroundColor: ['#ADD8E6', '#90EE90', '#FFD700', '#FFA500', '#FF6B6B', '#9370DB', '#FF69B4', '#8B4513']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right' } }
            }
        });
    }
}
