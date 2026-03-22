<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/intelligence.php';
redirect_if_not_logged_in();

// Get dashboard statistics
try {
    $database = new Database();
    $db = $database->getConnection();
    $rule_settings = pcims_get_rule_settings($db);

    // Total products
    $query = "SELECT COUNT(*) as total FROM products WHERE status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_products = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $query = "SELECT product_id FROM products WHERE status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $dashboard_product_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $dashboard_product_intelligence = !empty($dashboard_product_ids)
        ? pcims_get_product_intelligence($db, $dashboard_product_ids, $rule_settings)
        : [];

    $low_stock_products = count(array_filter($dashboard_product_intelligence, function ($item) {
        return !empty($item['is_low_stock']) || !empty($item['is_out_of_stock']) || !empty($item['is_restock_recommended']);
    }));

    // Total inventory value
    $query = "SELECT SUM(i.quantity_on_hand * p.unit_price) as total_value 
              FROM inventory i 
              JOIN products p ON i.product_id = p.product_id 
              WHERE p.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_inventory_value = $stmt->fetch(PDO::FETCH_ASSOC)['total_value'] ?? 0;

    // Recent stock movements
    $query = "SELECT sm.*, p.product_name, u.full_name 
              FROM stock_movements sm 
              JOIN products p ON sm.product_id = p.product_id 
              JOIN users u ON sm.user_id = u.user_id 
              ORDER BY sm.movement_date DESC LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Unread notifications
    $query = "SELECT COUNT(*) as total FROM notifications 
              WHERE user_id = ? AND is_read = FALSE";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $_SESSION['user_id']);
    $stmt->execute();
    $unread_notifications = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Recent notifications (get more for scrolling)
    $query = "SELECT * FROM notifications 
              WHERE user_id = ? OR user_id IS NULL 
              ORDER BY created_at DESC LIMIT 20";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $_SESSION['user_id']);
    $stmt->execute();
    $recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dashboard_insights = pcims_get_dashboard_insights($db, $rule_settings);
    $total_sales_today = (float) ($dashboard_insights['total_sales_today'] ?? 0);
    $top_sellers = $dashboard_insights['top_sellers'] ?? [];
    $slow_movers = $dashboard_insights['slow_movers'] ?? [];
    $low_stock_items_detail = $dashboard_insights['low_stock_items'] ?? [];
    $peak_sales_day = $dashboard_insights['peak_sales_day'] ?? null;
    $peak_sales_hour = $dashboard_insights['peak_sales_hour'] ?? null;
    $top_seller_labels = array_map(function ($item) {
        return $item['product_name'];
    }, $top_sellers);
    $top_seller_values = array_map(function ($item) {
        return (int) ($item['quantity_sold'] ?? 0);
    }, $top_sellers);

    // --- Chart data (Dashboard Analytics) ---
    // Summary chart data is safe for all roles; sales/order analytics are limited to staff+.
    $sales_series = [];
    $purchase_series = [];
    $chart_labels = [];
    $chart_sales = [];
    $chart_purchases = [];
    $chart_net = [];
    $top_stock_labels = [];
    $top_stock_values = [];

    // Stock status distribution (active products only)
    $query = "SELECT
                SUM(CASE WHEN i.quantity_on_hand = 0 THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN i.quantity_on_hand BETWEEN 1 AND 5 THEN 1 ELSE 0 END) AS low_stock,
                SUM(CASE WHEN i.quantity_on_hand > 5 THEN 1 ELSE 0 END) AS in_stock
              FROM inventory i
              JOIN products p ON i.product_id = p.product_id
              WHERE p.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stock_status_counts = [
        'Out of Stock' => (int)($row['out_of_stock'] ?? 0),
        'Low Stock' => (int)($row['low_stock'] ?? 0),
        'In Stock' => (int)($row['in_stock'] ?? 0),
    ];

    if (has_permission('staff')) {
        // Top stock levels (quantity on hand)
        $query = "SELECT p.product_name, i.quantity_on_hand
                  FROM inventory i
                  JOIN products p ON i.product_id = p.product_id
                  WHERE p.status = 'active'
                  ORDER BY i.quantity_on_hand DESC
                  LIMIT 8";
        $stmt = $db->prepare($query);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $top_stock_labels[] = $r['product_name'];
            $top_stock_values[] = (int)$r['quantity_on_hand'];
        }

        // Sales & purchases totals by day (last 14 days)
        $query = "SELECT DATE(order_date) AS day, SUM(total_amount) AS total
                  FROM sales_orders
                  WHERE " . pcims_completed_sales_condition('sales_orders') . "
                    AND order_date >= (CURDATE() - INTERVAL 13 DAY)
                  GROUP BY DATE(order_date)
                  ORDER BY day ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sales_series[$r['day']] = (float)$r['total'];
        }

        $query = "SELECT DATE(order_date) AS day, SUM(total_amount) AS total
                  FROM purchase_orders
                  WHERE order_date >= (CURDATE() - INTERVAL 13 DAY)
                  GROUP BY DATE(order_date)
                  ORDER BY day ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $purchase_series[$r['day']] = (float)$r['total'];
        }

        // Align dates and compute net (Sales - Purchases) as a proxy for profit.
        $start = new DateTimeImmutable('today -13 days');
        for ($i = 0; $i < 14; $i++) {
            $d = $start->modify("+$i day")->format('Y-m-d');
            $chart_labels[] = $d;
            $s = (float)($sales_series[$d] ?? 0);
            $p = (float)($purchase_series[$d] ?? 0);
            $chart_sales[] = $s;
            $chart_purchases[] = $p;
            $chart_net[] = $s - $p;
        }
    }
} catch (PDOException $exception) {
    error_log("Dashboard Error: " . $exception->getMessage());
    $total_products = 0;
    $low_stock_products = 0;
    $total_inventory_value = 0;
    $total_sales_today = 0;
    $recent_movements = [];
    $unread_notifications = 0;
    $recent_notifications = [];
    $top_sellers = [];
    $slow_movers = [];
    $low_stock_items_detail = [];
    $peak_sales_day = null;
    $peak_sales_hour = null;
    $top_seller_labels = [];
    $top_seller_values = [];
    $chart_labels = [];
    $chart_sales = [];
    $chart_purchases = [];
    $chart_net = [];
    $top_stock_labels = [];
    $top_stock_values = [];
    $stock_status_counts = ['Out of Stock' => 0, 'Low Stock' => 0, 'In Stock' => 0];
}

$page_title = 'Dashboard';
include 'includes/header.php';
?>

<style>
    /* Dashboard Responsive Styles */
    @media (max-width: 1200px) {
        .dashboard-stats-card {
            margin-bottom: 1rem;
        }

        .dashboard-stats-card .card-body {
            padding: 1rem;
        }

        .dashboard-stats-card .h5 {
            font-size: 1.25rem;
        }

        .dashboard-header h1 {
            font-size: 1.75rem;
        }

        .dashboard-header small {
            font-size: 0.9rem;
        }
    }

    @media (max-width: 992px) {
        .dashboard-stats-card {
            margin-bottom: 1rem;
        }

        .dashboard-stats-card .card-body {
            padding: 0.875rem;
        }

        .dashboard-stats-card .h5 {
            font-size: 1.1rem;
        }

        .dashboard-stats-card .fa-2x {
            font-size: 1.5rem;
        }

        .dashboard-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .dashboard-header h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .dashboard-header small {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }

        .dashboard-card {
            margin-bottom: 1rem;
        }

        .dashboard-card .card-header {
            padding: 0.75rem 1rem;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .dashboard-card .card-header h6 {
            font-size: 0.9rem;
            text-align: center;
            width: 100%;
        }

        .dashboard-card .card-header .btn {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            width: 100%;
        }

        .dashboard-card .card-body {
            padding: 1rem;
        }

        .table-responsive {
            font-size: 0.85rem;
        }

        .table th,
        .table td {
            padding: 0.75rem 0.5rem;
            vertical-align: middle;
        }

        .badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }

        .quick-actions .row {
            gap: 0.5rem;
        }

        .quick-actions .col-md-3 {
            flex: 0 0 calc(50% - 0.25rem);
            margin-bottom: 0.5rem;
        }
    }

    @media (max-width: 768px) {
        .dashboard-header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding: 0 1rem;
        }

        .dashboard-header h1 {
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
        }

        .dashboard-header small {
            font-size: 0.8rem;
            display: block;
        }

        .dashboard-stats-card {
            margin-bottom: 0.75rem;
        }

        .dashboard-stats-card .card-body {
            padding: 0.75rem;
        }

        .dashboard-stats-card .row {
            align-items: center;
            text-align: center;
        }

        .dashboard-stats-card .col {
            flex: 1;
        }

        .dashboard-stats-card .h5 {
            font-size: 1.1rem;
            order: 2;
            margin-bottom: 0;
        }

        .dashboard-stats-card .text-xs {
            font-size: 0.75rem;
            order: 1;
            margin-bottom: 0;
        }

        .dashboard-stats-card .fa-2x {
            font-size: 1.25rem;
            order: 3;
            margin: 0.5rem 0 0 0;
        }

        .dashboard-row {
            flex-direction: column;
            gap: 1rem;
        }

        .dashboard-card {
            margin-bottom: 1rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .dashboard-card .card-header {
            padding: 0.75rem;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .dashboard-card .card-header h6 {
            font-size: 0.85rem;
            text-align: center;
            width: 100%;
            margin-bottom: 0.25rem;
        }

        .dashboard-card .card-header .btn {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            width: 100%;
        }

        .dashboard-card .card-body {
            padding: 0.75rem;
        }

        .table-responsive {
            font-size: 0.8rem;
            max-height: 400px;
            overflow-y: auto;
            border-radius: 0.25rem;
        }

        .table th,
        .table td {
            padding: 0.5rem 0.375rem;
            vertical-align: middle;
            white-space: nowrap;
        }

        .badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.4rem;
        }

        .notification-item {
            padding: 0.75rem 0;
            margin-bottom: 0.75rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item h6 {
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }

        .notification-item p {
            font-size: 0.75rem;
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }

        .notification-item small {
            font-size: 0.7rem;
        }

        .quick-actions .card-header {
            text-align: center;
        }

        .quick-actions .card-header h6 {
            font-size: 0.9rem;
        }

        .quick-actions .row {
            flex-direction: column;
            gap: 0.5rem;
        }

        .quick-actions .col-md-3 {
            flex: 0 0 100%;
            margin-bottom: 0;
        }

        .quick-actions .btn {
            font-size: 0.9rem;
            padding: 1rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100px;
            border-radius: 0.5rem;
        }

        .quick-actions .btn .fa-2x {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
    }

    @media (max-width: 576px) {
        .dashboard-header {
            padding: 0 0.75rem;
            margin-bottom: 1rem;
        }

        .dashboard-header h1 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .dashboard-header small {
            font-size: 0.75rem;
        }

        .dashboard-stats-card {
            margin-bottom: 0.5rem;
        }

        .dashboard-stats-card .card-body {
            padding: 0.5rem;
        }

        .dashboard-stats-card .row {
            flex-direction: column;
            text-align: center;
            gap: 0.25rem;
        }

        .dashboard-stats-card .col {
            flex: 1;
            margin-bottom: 0.25rem;
        }

        .dashboard-stats-card .h5 {
            font-size: 1.1rem;
            order: 2;
            margin-bottom: 0;
        }

        .dashboard-stats-card .text-xs {
            font-size: 0.75rem;
            order: 1;
            margin-bottom: 0;
        }

        .dashboard-stats-card .fa-2x {
            font-size: 1.5rem;
            order: 3;
            margin: 0.25rem 0 0 0;
        }

        .dashboard-row {
            gap: 0.75rem;
        }

        .dashboard-card {
            margin-bottom: 0.75rem;
        }

        .dashboard-card .card-header {
            padding: 0.5rem 0.75rem;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .dashboard-card .card-header h6 {
            font-size: 0.8rem;
            text-align: center;
            width: 100%;
            line-height: 1.3;
        }

        .dashboard-card .card-header .btn {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            width: 100%;
        }

        .dashboard-card .card-body {
            padding: 0.5rem;
        }

        .table-responsive {
            font-size: 0.75rem;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 0.25rem;
        }

        .table th,
        .table td {
            padding: 0.375rem 0.25rem;
            vertical-align: middle;
            white-space: nowrap;
            font-size: 0.7rem;
        }

        .badge {
            font-size: 0.6rem;
            padding: 0.15rem 0.35rem;
        }

        .notification-item {
            padding: 0.5rem 0;
            margin-bottom: 0.5rem;
        }

        .notification-item h6 {
            font-size: 0.75rem;
            margin-bottom: 0.2rem;
        }

        .notification-item p {
            font-size: 0.7rem;
            margin-bottom: 0.2rem;
        }

        .notification-item small {
            font-size: 0.65rem;
        }

        .quick-actions .card-header {
            padding: 0.5rem;
        }

        .quick-actions .card-header h6 {
            font-size: 0.85rem;
        }

        .quick-actions .card-body {
            padding: 0.5rem;
        }

        .quick-actions .btn {
            font-size: 0.85rem;
            padding: 0.75rem;
            min-height: 90px;
            border-radius: 0.375rem;
        }

        .quick-actions .btn .fa-2x {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }
    }

    /* Touch-friendly improvements */
    @media (hover: none) and (pointer: coarse) {
        .dashboard-stats-card {
            transition: transform 0.2s ease;
            cursor: pointer;
        }

        .dashboard-stats-card:hover {
            transform: scale(1.02);
        }

        .quick-actions .btn {
            transition: transform 0.2s ease;
            cursor: pointer;
        }

        .quick-actions .btn:hover {
            transform: scale(1.05);
        }

        .table-responsive {
            -webkit-overflow-scrolling: touch;
        }

        .dashboard-card .card-header .btn {
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quick-actions .btn {
            min-height: 44px;
            touch-action: manipulation;
        }
    }

    /* Landscape mobile adjustments */
    @media (max-width: 768px) and (orientation: landscape) {
        .dashboard-header {
            margin-bottom: 1rem;
        }

        .dashboard-stats-card .card-body {
            padding: 0.5rem;
        }

        .dashboard-stats-card .row {
            flex-direction: row;
            align-items: center;
        }

        .dashboard-stats-card .col {
            margin-bottom: 0;
        }

        .dashboard-stats-card .h5 {
            order: unset;
            margin-bottom: 0;
        }

        .dashboard-stats-card .text-xs {
            order: unset;
            margin-bottom: 0;
        }

        .dashboard-stats-card .fa-2x {
            order: unset;
            margin: 0 0 0 0.5rem;
        }

        .table-responsive {
            max-height: 250px;
        }

        .quick-actions .row {
            flex-direction: row;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .quick-actions .col-md-3 {
            flex: 0 0 calc(50% - 0.25rem);
        }
    }

    /* Print styles */
    @media print {

        .dashboard-header,
        .quick-actions {
            display: none !important;
        }

        .dashboard-card {
            break-inside: avoid;
            box-shadow: none !important;
            border: 1px solid #dee2e6 !important;
        }

        .table-responsive {
            page-break-inside: auto;
        }

        .table tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
    }

    /* Dashboard charts */
    .dashboard-chart {
        position: relative;
        width: 100%;
        height: 320px;
        transition: transform 380ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 280ms ease, opacity 320ms ease;
    }

    .insight-list-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid #eef1f5;
    }

    .dashboard-view .dashboard-card,
    .dashboard-view .dashboard-stats-card,
    .dashboard-view .quick-actions,
    .dashboard-view .dashboard-header {
        transition: transform 380ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 280ms ease, opacity 320ms ease;
        will-change: transform, opacity;
    }

    .dashboard-view .dashboard-card:hover,
    .dashboard-view .dashboard-stats-card:hover,
    .dashboard-view .quick-actions:hover {
        transform: translateY(-4px);
    }

    body.motion-enabled .dashboard-view .dashboard-motion-item {
        opacity: 0;
        transform: translateY(22px) scale(0.992);
        filter: saturate(0.94);
        transition:
            opacity 460ms cubic-bezier(0.22, 1, 0.36, 1),
            transform 460ms cubic-bezier(0.22, 1, 0.36, 1),
            filter 360ms ease;
        transition-delay: var(--dashboard-motion-delay, 0ms);
    }

    body.motion-enabled.page-is-ready .dashboard-view.dashboard-is-ready .dashboard-motion-item {
        opacity: 1;
        transform: translateY(0) scale(1);
        filter: none;
    }

    body.motion-enabled .dashboard-view .pattern-card,
    body.motion-enabled .dashboard-view .insight-list-item {
        opacity: 0;
        transform: translateX(-14px);
        transition:
            opacity 360ms cubic-bezier(0.22, 1, 0.36, 1),
            transform 360ms cubic-bezier(0.22, 1, 0.36, 1);
        transition-delay: var(--dashboard-detail-delay, 120ms);
    }

    body.motion-enabled.page-is-ready .dashboard-view.dashboard-is-ready .pattern-card,
    body.motion-enabled.page-is-ready .dashboard-view.dashboard-is-ready .insight-list-item {
        opacity: 1;
        transform: translateX(0);
    }

    body.motion-enabled .dashboard-view .dashboard-chart {
        opacity: 0;
        transform: translateY(18px) scale(0.986);
        transition:
            opacity 460ms cubic-bezier(0.22, 1, 0.36, 1),
            transform 460ms cubic-bezier(0.22, 1, 0.36, 1);
        transition-delay: var(--dashboard-chart-delay, 180ms);
    }

    body.motion-enabled.page-is-ready .dashboard-view.dashboard-is-ready .dashboard-chart {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    .insight-list-item:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .pattern-card {
        border: 1px solid #eef1f5;
        border-radius: 0.75rem;
        padding: 1rem;
        background: #f8fafc;
    }

    @media (max-width: 992px) {
        .dashboard-chart {
            height: 280px;
        }
    }

    @media (max-width: 576px) {
        .dashboard-chart {
            height: 240px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .dashboard-view .dashboard-card,
        .dashboard-view .dashboard-stats-card,
        .dashboard-view .quick-actions,
        .dashboard-view .dashboard-header,
        .dashboard-view .pattern-card,
        .dashboard-view .insight-list-item,
        .dashboard-view .dashboard-chart {
            transition: none !important;
            transform: none !important;
            opacity: 1 !important;
            filter: none !important;
        }
    }
</style>

<div class="container-fluid dashboard-view">
    <div class="dashboard-header">
        <div class="col-12">
            <h1 class="h3 mb-4">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                <small class="text-muted">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</small>
            </h1>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-4 mb-4">
            <div class="card shadow dashboard-card h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-fire me-2"></i>Top 5 Best Sellers
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($top_sellers)): ?>
                        <p class="text-muted mb-0">No completed sales data yet.</p>
                    <?php else: ?>
                        <?php foreach ($top_sellers as $seller): ?>
                            <div class="insight-list-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($seller['product_name']); ?></strong>
                                    <div class="small text-muted"><?php echo number_format($seller['quantity_sold']); ?> units sold</div>
                                </div>
                                <span class="badge bg-success"><?php echo format_currency($seller['total_revenue']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="card shadow dashboard-card h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-hourglass-half me-2"></i>Slow-Moving Products
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($slow_movers)): ?>
                        <p class="text-muted mb-0">No slow-moving products to highlight.</p>
                    <?php else: ?>
                        <?php foreach ($slow_movers as $slow_item): ?>
                            <div class="insight-list-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($slow_item['product_name']); ?></strong>
                                    <div class="small text-muted"><?php echo number_format($slow_item['quantity_sold_window']); ?> sold in the last <?php echo (int) ($rule_settings['sales_window_days'] ?? 30); ?> days</div>
                                </div>
                                <span class="badge bg-secondary"><?php echo number_format($slow_item['quantity_on_hand']); ?> on hand</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="card shadow dashboard-card h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-wave-square me-2"></i>Sales Patterns
                    </h6>
                </div>
                <div class="card-body">
                    <div class="pattern-card mb-3">
                        <div class="small text-muted text-uppercase">Peak Sales Day</div>
                        <div class="h5 mb-1"><?php echo htmlspecialchars($peak_sales_day['weekday_name'] ?? 'N/A'); ?></div>
                        <div class="small text-muted">
                            <?php echo !empty($peak_sales_day) ? format_currency($peak_sales_day['total_sales']) . ' across ' . number_format($peak_sales_day['order_count']) . ' orders' : 'No sales trend available yet.'; ?>
                        </div>
                    </div>
                    <div class="pattern-card">
                        <div class="small text-muted text-uppercase">Peak Sales Hour</div>
                        <div class="h5 mb-1"><?php echo htmlspecialchars($peak_sales_hour['hour_label'] ?? 'N/A'); ?></div>
                        <div class="small text-muted">
                            <?php echo !empty($peak_sales_hour) ? format_currency($peak_sales_hour['total_sales']) . ' across ' . number_format($peak_sales_hour['order_count']) . ' orders' : 'No hourly trend available yet.'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2 dashboard-stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Products
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($total_products); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-box fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2 dashboard-stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Low Stock Items
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($low_stock_products); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2 dashboard-stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Inventory Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($total_inventory_value); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-peso-sign fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2 dashboard-stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Total Sales Today
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($total_sales_today); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-cash-register fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Analytics (Charts) -->
    <div class="row mb-4">
        <?php if (has_permission('staff')): ?>
            <div class="col-lg-8 mb-4">
                <div class="card shadow dashboard-card">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-chart-line me-2"></i>Sales vs Purchases (Last 14 Days)
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="dashboard-chart">
                            <canvas id="salesPurchasesChart"></canvas>
                        </div>
                        <div class="small text-muted mt-2">
                            Net is a proxy: Sales - Purchases (based on order totals).
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-lg-4 mb-4">
            <div class="card shadow dashboard-card">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-layer-group me-2"></i>Stock Status Summary
                    </h6>
                </div>
                <div class="card-body">
                    <div class="dashboard-chart" style="height: 260px;">
                        <canvas id="stockStatusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <?php if (has_permission('staff')): ?>
            <div class="col-12">
                <div class="card shadow dashboard-card">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-boxes me-2"></i>Top Stock Levels
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="dashboard-chart">
                            <canvas id="topStockChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="row mb-4">
        <div class="col-lg-5 mb-4">
            <div class="card shadow dashboard-card h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-triangle-exclamation me-2"></i>Low Stock Items
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($low_stock_items_detail)): ?>
                        <p class="text-muted mb-0">No urgent low-stock alerts right now.</p>
                    <?php else: ?>
                        <?php foreach ($low_stock_items_detail as $stock_item): ?>
                            <div class="insight-list-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($stock_item['product_name']); ?></strong>
                                    <div class="small text-muted">
                                        On hand: <?php echo number_format($stock_item['quantity_on_hand']); ?>
                                        | Avg/day: <?php echo number_format($stock_item['average_daily_sales'], 2); ?>
                                    </div>
                                </div>
                                <span class="badge bg-<?php echo !empty($stock_item['is_out_of_stock']) ? 'danger' : (!empty($stock_item['is_restock_recommended']) ? 'warning text-dark' : 'info'); ?>">
                                    <?php echo !empty($stock_item['is_out_of_stock']) ? 'Out of Stock' : (!empty($stock_item['is_restock_recommended']) ? 'Restock' : 'Low Stock'); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7 mb-4">
            <div class="card shadow dashboard-card h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-bar me-2"></i>Best Seller Trend
                    </h6>
                </div>
                <div class="card-body">
                    <div class="dashboard-chart" style="height: 260px;">
                        <canvas id="bestSellerChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row dashboard-row">
        <!-- Recent Stock Movements -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow dashboard-card">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-exchange-alt me-2"></i>Recent Stock Movements
                    </h6>
                    <?php if (has_permission('staff')): ?>
                        <a href="stock_movements.php" class="btn btn-sm btn-outline-primary">View All</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_movements)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-inbox fa-3x mb-3"></i>
                            <p>No recent stock movements found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Type</th>
                                        <th>Quantity</th>
                                        <th>User</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_movements as $movement): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php
                                                                        echo $movement['movement_type'] === 'in' ? 'success' : ($movement['movement_type'] === 'out' ? 'danger' : 'warning');
                                                                        ?>">
                                                    <?php echo strtoupper($movement['movement_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                echo $movement['movement_type'] === 'in' ? '+' : '-';
                                                echo abs($movement['quantity']);
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($movement['full_name']); ?></td>
                                            <td><?php echo format_date($movement['movement_date'], 'M d, Y H:i'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Notifications -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow dashboard-card">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-bell me-2"></i>Recent Notifications
                    </h6>
                    <a href="notifications.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_notifications)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-bell-slash fa-3x mb-3"></i>
                            <p>No notifications found.</p>
                        </div>
                    <?php else: ?>
                        <div class="notification-list" style="max-height: 300px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #dee2e6 #f8f9fa;">
                            <?php foreach ($recent_notifications as $index => $notification): ?>
                                <div class="notification-item border-bottom pb-3 mb-3 <?php echo $notification['is_read'] ? 'opacity-50' : ''; ?>" style="<?php echo $index >= 3 ? 'display: none;' : ''; ?>" data-index="<?php echo $index; ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                                <?php if (!$notification['is_read']): ?>
                                                    <span class="badge bg-primary ms-2">New</span>
                                                <?php endif; ?>
                                            </h6>
                                            <p class="mb-1 text-muted small">
                                                <?php echo htmlspecialchars(substr($notification['message'], 0, 100)); ?>...
                                            </p>
                                            <small class="text-muted">
                                                <i class="far fa-clock me-1"></i>
                                                <?php echo format_date($notification['created_at'], 'M d, Y H:i'); ?>
                                            </small>
                                        </div>
                                        <div class="ms-2">
                                            <span class="badge bg-<?php
                                                                    echo $notification['type'] === 'error' ? 'danger' : ($notification['type'] === 'warning' ? 'warning' : ($notification['type'] === 'success' ? 'success' : 'info'));
                                                                    ?>">
                                                <?php echo ucfirst($notification['type']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($recent_notifications) > 3): ?>
                                <div class="text-center mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-arrows-alt-v me-1"></i>
                                        Scroll for more notifications
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (has_permission('staff')): ?>
        <!-- Quick Actions -->
        <div class="row">
            <div class="col-12">
                <div class="card shadow quick-actions">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-bolt me-2"></i>Quick Actions
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <a href="products.php?action=add" class="btn btn-outline-primary btn-lg w-100">
                                    <i class="fas fa-plus-circle fa-2x d-block mb-2"></i>
                                    Add New Product
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="stock_adjustment.php" class="btn btn-outline-success btn-lg w-100">
                                    <i class="fas fa-exchange-alt fa-2x d-block mb-2"></i>
                                    Stock Adjustment
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="purchase_orders.php?action=add" class="btn btn-outline-info btn-lg w-100">
                                    <i class="fas fa-shopping-cart fa-2x d-block mb-2"></i>
                                    New Purchase Order
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="sales_orders.php?action=add" class="btn btn-outline-warning btn-lg w-100">
                                    <i class="fas fa-cash-register fa-2x d-block mb-2"></i>
                                    New Sales Order
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Chart Initialization Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dashboardView = document.querySelector('.dashboard-view');
            const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const motionEnabled = !prefersReducedMotion;

            if (dashboardView) {
                const motionItems = dashboardView.querySelectorAll('.dashboard-header, .dashboard-stats-card, .dashboard-card, .quick-actions');
                motionItems.forEach(function(item, index) {
                    item.classList.add('dashboard-motion-item');
                    item.style.setProperty('--dashboard-motion-delay', Math.min(index * 70, 700) + 'ms');
                });

                dashboardView.querySelectorAll('.pattern-card, .insight-list-item').forEach(function(item, index) {
                    item.style.setProperty('--dashboard-detail-delay', Math.min(120 + (index * 30), 520) + 'ms');
                });

                dashboardView.querySelectorAll('.dashboard-chart').forEach(function(item, index) {
                    item.style.setProperty('--dashboard-chart-delay', Math.min(180 + (index * 65), 620) + 'ms');
                });

                if (motionEnabled) {
                    requestAnimationFrame(function() {
                        requestAnimationFrame(function() {
                            dashboardView.classList.add('dashboard-is-ready');
                        });
                    });
                } else {
                    dashboardView.classList.add('dashboard-is-ready');
                }
            }

            function getChartAnimation(stepDelay) {
                if (!motionEnabled) {
                    return false;
                }

                return {
                    duration: 900,
                    easing: 'easeOutCubic',
                    delay: function(context) {
                        if (context.type !== 'data') {
                            return 0;
                        }

                        return (context.dataIndex * stepDelay) + (context.datasetIndex * 90);
                    }
                };
            }

            // Initialize Sales vs Purchases Chart
            <?php if (has_permission('staff')): ?>
                const salesPurchasesCtx = document.getElementById('salesPurchasesChart');
                if (salesPurchasesCtx) {
                    new Chart(salesPurchasesCtx, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode($chart_labels); ?>,
                            datasets: [{
                                    label: 'Sales',
                                    data: <?php echo json_encode($chart_sales); ?>,
                                    borderColor: '#28a745',
                                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                    borderWidth: 2,
                                    pointRadius: 5,
                                    pointBackgroundColor: '#28a745',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    tension: 0.4,
                                    fill: true
                                },
                                {
                                    label: 'Purchases',
                                    data: <?php echo json_encode($chart_purchases); ?>,
                                    borderColor: '#dc3545',
                                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                    borderWidth: 2,
                                    pointRadius: 5,
                                    pointBackgroundColor: '#dc3545',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    tension: 0.4,
                                    fill: true
                                },
                                {
                                    label: 'Net (Sales - Purchases)',
                                    data: <?php echo json_encode($chart_net); ?>,
                                    borderColor: '#007bff',
                                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                                    borderWidth: 2,
                                    pointRadius: 5,
                                    pointBackgroundColor: '#007bff',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    tension: 0.4,
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            animation: getChartAnimation(55),
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top',
                                    labels: {
                                        font: {
                                            size: 12,
                                            weight: 'bold'
                                        },
                                        padding: 15,
                                        boxPadding: 5
                                    }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    padding: 12,
                                    titleFont: {
                                        size: 14,
                                        weight: 'bold'
                                    },
                                    bodyFont: {
                                        size: 12
                                    },
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.parsed.y !== null) {
                                                label += '₱' + context.parsed.y.toLocaleString('en-PH', {
                                                    minimumFractionDigits: 2,
                                                    maximumFractionDigits: 2
                                                });
                                            }
                                            return label;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Amount (₱)'
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString('en-PH', {
                                                minimumFractionDigits: 0,
                                                maximumFractionDigits: 0
                                            });
                                        }
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Date'
                                    }
                                }
                            }
                        }
                    });
                }
            <?php endif; ?>

            // Initialize Stock Status Chart (Pie)
            const stockStatusCtx = document.getElementById('stockStatusChart');
            if (stockStatusCtx) {
                const stockStatusData = <?php echo json_encode($stock_status_counts); ?>;
                const stockLabels = Object.keys(stockStatusData);
                const stockValues = Object.values(stockStatusData);

                new Chart(stockStatusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: stockLabels,
                        datasets: [{
                            data: stockValues,
                            backgroundColor: [
                                '#dc3545', // Out of Stock - Red
                                '#ffc107', // Low Stock - Yellow
                                '#28a745' // In Stock - Green
                            ],
                            borderColor: [
                                '#fff',
                                '#fff',
                                '#fff'
                            ],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        animation: getChartAnimation(85),
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: {
                                        size: 12,
                                        weight: 'bold'
                                    },
                                    padding: 15,
                                    boxPadding: 5
                                }
                            },
                            tooltip: {
                                mode: 'single',
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                bodyFont: {
                                    size: 12
                                },
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const currentValue = context.parsed;
                                        const percentage = ((currentValue / total) * 100).toFixed(1);
                                        return context.label + ': ' + currentValue + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            const bestSellerCtx = document.getElementById('bestSellerChart');
            if (bestSellerCtx) {
                new Chart(bestSellerCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($top_seller_labels); ?>,
                        datasets: [{
                            label: 'Units Sold',
                            data: <?php echo json_encode($top_seller_values); ?>,
                            backgroundColor: ['#0d6efd', '#20c997', '#ffc107', '#fd7e14', '#dc3545'],
                            borderRadius: 10,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        animation: getChartAnimation(75),
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }

            // Initialize Top Stock Levels Chart (Bar)
            <?php if (has_permission('staff')): ?>
                const topStockCtx = document.getElementById('topStockChart');
                if (topStockCtx) {
                    new Chart(topStockCtx, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($top_stock_labels); ?>,
                            datasets: [{
                                label: 'Quantity on Hand',
                                data: <?php echo json_encode($top_stock_values); ?>,
                                backgroundColor: '#4e73df',
                                borderColor: '#2e5090',
                                borderWidth: 1,
                                borderRadius: 5,
                                barPercentage: 0.8,
                                categoryPercentage: 0.9
                            }]
                        },
                        options: {
                            animation: getChartAnimation(65),
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top',
                                    labels: {
                                        font: {
                                            size: 12,
                                            weight: 'bold'
                                        },
                                        padding: 15,
                                        boxPadding: 5
                                    }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    padding: 12,
                                    titleFont: {
                                        size: 14,
                                        weight: 'bold'
                                    },
                                    bodyFont: {
                                        size: 12
                                    },
                                    callbacks: {
                                        label: function(context) {
                                            return (context.dataset.label || '') + ': ' + context.parsed.x + ' units';
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return Math.round(value) + ' units';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            <?php endif; ?>

            // Scrollable Notifications Functionality
            const notificationList = document.querySelector('.notification-list');
            if (notificationList) {
                const notifications = notificationList.querySelectorAll('.notification-item');
                const visibleCount = 3;
                
                // Initially show only first 3 notifications
                notifications.forEach((notification, index) => {
                    if (index < visibleCount) {
                        notification.style.display = 'block';
                    }
                });

                // Show/hide notifications based on scroll position
                notificationList.addEventListener('scroll', function() {
                    const scrollTop = this.scrollTop;
                    const itemHeight = notifications[0] ? notifications[0].offsetHeight + 16 : 0; // height + margin
                    
                    notifications.forEach((notification, index) => {
                        const shouldBeVisible = scrollTop <= index * itemHeight;
                        if (shouldBeVisible && index < visibleCount + Math.ceil(scrollTop / itemHeight)) {
                            notification.style.display = 'block';
                        }
                    });
                });

                // Smooth scroll behavior
                notificationList.style.scrollBehavior = 'smooth';
            }
        });
    </script>

    <?php include 'includes/footer.php'; ?>
