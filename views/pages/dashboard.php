<?php
/**
 * Dashboard Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$db = getDB();

// KPI Data
$totalProducts = $db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$totalStockValue = $db->query("SELECT COALESCE(SUM(quantity * selling_price), 0) FROM products WHERE status='active'")->fetchColumn();
$lowStockCount = $db->query("SELECT COUNT(*) FROM products WHERE status='active' AND quantity <= low_stock_threshold")->fetchColumn();
$todaySales = $db->query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$totalSalesCount = $db->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// Monthly revenue comparison
$thisMonth = $db->query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())")->fetchColumn();
$lastMonth = $db->query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE MONTH(created_at) = MONTH(NOW()) - 1 AND YEAR(created_at) = YEAR(NOW())")->fetchColumn();
$revenueChange = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1) : 0;

// Low stock products
$lowStockProducts = $db->query("SELECT p.*, c.name as category_name, c.color as category_color 
    FROM products p LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.status='active' AND p.quantity <= p.low_stock_threshold 
    ORDER BY p.quantity ASC LIMIT 8")->fetchAll();

// Recent activity (stock transactions + sales)
$recentActivity = $db->query("
    (SELECT 'stock' as activity_type, st.type as sub_type, st.quantity, st.created_at, 
            p.name as product_name, st.reference_no, u.full_name as user_name
     FROM stock_transactions st 
     JOIN products p ON st.product_id = p.id 
     LEFT JOIN users u ON st.created_by = u.id 
     ORDER BY st.created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'sale' as activity_type, 'sale' as sub_type, 0 as quantity, s.created_at,
            s.invoice_no as product_name, s.total as reference_no, u.full_name as user_name
     FROM sales s 
     LEFT JOIN users u ON s.created_by = u.id 
     ORDER BY s.created_at DESC LIMIT 5)
    ORDER BY created_at DESC LIMIT 10
")->fetchAll();

// Top selling products (this month)
$topProducts = $db->query("
    SELECT p.name, p.sku, SUM(si.quantity) as total_sold, SUM(si.total) as total_revenue
    FROM sale_items si 
    JOIN products p ON si.product_id = p.id
    JOIN sales s ON si.sale_id = s.id
    WHERE MONTH(s.created_at) = MONTH(NOW()) AND YEAR(s.created_at) = YEAR(NOW())
    GROUP BY p.id ORDER BY total_sold DESC LIMIT 5
")->fetchAll();

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
$pageScripts = ['dashboard.js'];
include dirname(__DIR__) . '/layouts/header.php';
?>

<!-- KPI Stats -->
<div class="stats-grid">
    <div class="stat-card violet">
        <div class="stat-header">
            <div class="stat-icon">
                <i data-lucide="box" style="width:22px;height:22px;"></i>
            </div>
        </div>
        <div class="stat-value"><?= number_format($totalProducts) ?></div>
        <div class="stat-label">Total Products</div>
    </div>
    
    <div class="stat-card emerald">
        <div class="stat-header">
            <div class="stat-icon">
                <i data-lucide="dollar-sign" style="width:22px;height:22px;"></i>
            </div>
            <?php if ($revenueChange != 0): ?>
            <span class="stat-badge <?= $revenueChange > 0 ? 'up' : 'down' ?>">
                <i data-lucide="<?= $revenueChange > 0 ? 'trending-up' : 'trending-down' ?>" style="width:12px;height:12px;"></i>
                <?= abs($revenueChange) ?>%
            </span>
            <?php endif; ?>
        </div>
        <div class="stat-value"><?= formatCurrency($thisMonth) ?></div>
        <div class="stat-label">Revenue This Month</div>
    </div>
    
    <div class="stat-card cyan">
        <div class="stat-header">
            <div class="stat-icon">
                <i data-lucide="shopping-bag" style="width:22px;height:22px;"></i>
            </div>
        </div>
        <div class="stat-value"><?= formatCurrency($todaySales) ?></div>
        <div class="stat-label">Today's Sales (<?= $totalSalesCount ?> orders)</div>
    </div>
    
    <div class="stat-card <?= $lowStockCount > 0 ? 'rose' : 'amber' ?>">
        <div class="stat-header">
            <div class="stat-icon">
                <i data-lucide="alert-triangle" style="width:22px;height:22px;"></i>
            </div>
        </div>
        <div class="stat-value"><?= number_format($lowStockCount) ?></div>
        <div class="stat-label">Low Stock Alerts</div>
    </div>
</div>

<!-- Charts Row -->
<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Sales Overview</h3>
            <select class="form-control" style="width:auto;padding:6px 30px 6px 10px;font-size:0.78rem;" id="salesChartPeriod">
                <option value="7">Last 7 days</option>
                <option value="30" selected>Last 30 days</option>
                <option value="90">Last 90 days</option>
            </select>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Category Distribution</h3>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Row: Low Stock + Recent Activity -->
<div class="grid-2">
    <!-- Low Stock Alerts -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="alert-triangle" style="width:18px;height:18px;color:var(--accent-amber);vertical-align:middle;margin-right:6px;"></i>
                Low Stock Products
            </h3>
            <?php if ($lowStockCount > 0): ?>
            <a href="<?= APP_URL ?>/products?filter=low_stock" class="btn btn-ghost btn-sm">View All</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($lowStockProducts)): ?>
                <div class="empty-state" style="padding:30px;">
                    <i data-lucide="check-circle" style="width:40px;height:40px;color:var(--accent-emerald);"></i>
                    <h3>All Stocked Up!</h3>
                    <p>No products are below their low stock threshold.</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Stock</th>
                                <th>Threshold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStockProducts as $p): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-center gap-1">
                                        <span class="color-dot" style="background:<?= sanitize($p['category_color'] ?? '#8b5cf6') ?>"></span>
                                        <div>
                                            <div class="font-bold" style="font-size:0.85rem;"><?= sanitize($p['name']) ?></div>
                                            <div class="text-muted" style="font-size:0.75rem;"><?= sanitize($p['sku']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="<?= $p['quantity'] == 0 ? 'text-danger' : 'text-warning' ?> font-bold">
                                        <?= $p['quantity'] ?>
                                    </span>
                                </td>
                                <td class="text-muted"><?= $p['low_stock_threshold'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Recent Activity</h3>
        </div>
        <div class="card-body">
            <?php if (empty($recentActivity)): ?>
                <div class="empty-state" style="padding:30px;">
                    <i data-lucide="activity" style="width:40px;height:40px;"></i>
                    <h3>No Activity Yet</h3>
                    <p>Start by adding products or recording stock movements.</p>
                </div>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($recentActivity as $act): ?>
                    <li class="activity-item">
                        <?php if ($act['activity_type'] === 'sale'): ?>
                            <div class="activity-icon sale">
                                <i data-lucide="receipt" style="width:16px;height:16px;"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">
                                    Sale <strong><?= sanitize($act['product_name']) ?></strong> — <?= formatCurrency($act['reference_no']) ?>
                                </div>
                                <div class="activity-time"><?= timeAgo($act['created_at']) ?> by <?= sanitize($act['user_name'] ?? 'System') ?></div>
                            </div>
                        <?php else: ?>
                            <div class="activity-icon stock-<?= $act['sub_type'] ?>">
                                <i data-lucide="<?= $act['sub_type'] === 'in' ? 'arrow-down-left' : 'arrow-up-right' ?>" style="width:16px;height:16px;"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">
                                    Stock <?= $act['sub_type'] === 'in' ? 'received' : 'dispatched' ?>: <strong><?= $act['quantity'] ?></strong> × <?= sanitize($act['product_name']) ?>
                                </div>
                                <div class="activity-time"><?= timeAgo($act['created_at']) ?> by <?= sanitize($act['user_name'] ?? 'System') ?></div>
                            </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($topProducts)): ?>
<!-- Top Products -->
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">Top Selling Products (This Month)</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty Sold</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topProducts as $i => $tp): ?>
                    <tr>
                        <td><span class="badge badge-violet"><?= $i + 1 ?></span></td>
                        <td class="font-bold"><?= sanitize($tp['name']) ?></td>
                        <td class="text-muted"><?= sanitize($tp['sku']) ?></td>
                        <td><?= number_format($tp['total_sold']) ?></td>
                        <td class="text-success font-bold"><?= formatCurrency($tp['total_revenue']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
