<?php

function pcims_completed_sales_condition($alias = 'so')
{
    return sprintf("%s.status IN ('completed', 'delivered')", $alias);
}

function pcims_round_amount($amount)
{
    return round((float) $amount, 2);
}

function pcims_get_rule_settings(PDO $db)
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'forecast_days' => 7,
        'sales_window_days' => 30,
        'restock_days' => 5,
        'default_lead_time_days' => 3,
    ];

    $key_map = [
        'forecast_days' => 'forecast_days',
        'intelligence_sales_window_days' => 'sales_window_days',
        'restock_cover_days' => 'restock_days',
        'default_lead_time_days' => 'default_lead_time_days',
    ];

    $query = "SELECT setting_key, setting_value
              FROM system_settings
              WHERE setting_key IN ('forecast_days', 'intelligence_sales_window_days', 'restock_cover_days', 'default_lead_time_days')";
    $stmt = $db->prepare($query);
    $stmt->execute();

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $setting_key = $row['setting_key'];
        $mapped_key = $key_map[$setting_key] ?? null;

        if ($mapped_key === null) {
            continue;
        }

        $defaults[$mapped_key] = max(1, (int) $row['setting_value']);
    }

    $cached = $defaults;
    return $cached;
}

function pcims_get_product_intelligence(PDO $db, array $product_ids = [], array $settings = [])
{
    $resolved_settings = array_merge(pcims_get_rule_settings($db), $settings);
    $window_days = max(1, (int) $resolved_settings['sales_window_days']);
    $forecast_days = max(1, (int) $resolved_settings['forecast_days']);
    $restock_days = max(1, (int) $resolved_settings['restock_days']);
    $default_lead_time_days = max(1, (int) $resolved_settings['default_lead_time_days']);

    $conditions = [];
    $params = [];

    if (!empty($product_ids)) {
        $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
        $placeholders = [];
        foreach ($product_ids as $index => $product_id) {
            $placeholder = ':product_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $product_id;
        }
        $conditions[] = 'p.product_id IN (' . implode(', ', $placeholders) . ')';
    }

    $query = "SELECT p.product_id,
                     p.product_name,
                     p.reorder_level,
                     COALESCE(NULLIF(p.lead_time_days, 0), {$default_lead_time_days}) AS lead_time_days,
                     COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
                     COALESCE(i.quantity_available, 0) AS quantity_available,
                     COALESCE(window_sales.quantity_sold_window, 0) AS quantity_sold_window,
                     COALESCE(all_time_sales.quantity_sold_all_time, 0) AS quantity_sold_all_time
              FROM products p
              LEFT JOIN inventory i ON p.product_id = i.product_id
              LEFT JOIN (
                  SELECT soi.product_id, SUM(soi.quantity) AS quantity_sold_window
                  FROM sales_order_items soi
                  INNER JOIN sales_orders so ON so.so_id = soi.so_id
                  WHERE " . pcims_completed_sales_condition('so') . "
                    AND so.order_date >= DATE_SUB(CURDATE(), INTERVAL {$window_days} DAY)
                  GROUP BY soi.product_id
              ) AS window_sales ON window_sales.product_id = p.product_id
              LEFT JOIN (
                  SELECT soi.product_id, SUM(soi.quantity) AS quantity_sold_all_time
                  FROM sales_order_items soi
                  INNER JOIN sales_orders so ON so.so_id = soi.so_id
                  WHERE " . pcims_completed_sales_condition('so') . "
                  GROUP BY soi.product_id
              ) AS all_time_sales ON all_time_sales.product_id = p.product_id";

    if (!empty($conditions)) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $stmt = $db->prepare($query);
    foreach ($params as $placeholder => $value) {
        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $best_seller_ids = pcims_get_best_seller_ids($db, 5);
    $best_seller_lookup = array_fill_keys($best_seller_ids, true);

    $intelligence = [];

    foreach ($rows as $row) {
        $product_id = (int) $row['product_id'];
        $average_daily_sales = $window_days > 0
            ? ((float) $row['quantity_sold_window'] / $window_days)
            : 0.0;
        $lead_time_days = max(1, (int) $row['lead_time_days']);
        $lead_time_threshold = (int) ceil($average_daily_sales * $lead_time_days);
        $restock_threshold = (int) ceil($average_daily_sales * $restock_days);
        $forecast_quantity = (int) ceil($average_daily_sales * $forecast_days);
        $quantity_on_hand = (int) $row['quantity_on_hand'];

        $intelligence[$product_id] = [
            'product_id' => $product_id,
            'product_name' => $row['product_name'],
            'quantity_on_hand' => $quantity_on_hand,
            'quantity_available' => (int) $row['quantity_available'],
            'reorder_level' => (int) $row['reorder_level'],
            'lead_time_days' => $lead_time_days,
            'sales_window_days' => $window_days,
            'forecast_days' => $forecast_days,
            'restock_days' => $restock_days,
            'quantity_sold_window' => (int) $row['quantity_sold_window'],
            'quantity_sold_all_time' => (int) $row['quantity_sold_all_time'],
            'average_daily_sales' => $average_daily_sales,
            'lead_time_threshold' => $lead_time_threshold,
            'restock_threshold' => $restock_threshold,
            'forecast_quantity' => $forecast_quantity,
            'is_best_seller' => isset($best_seller_lookup[$product_id]),
            'is_out_of_stock' => $quantity_on_hand <= 0,
            'is_low_stock' => $quantity_on_hand > 0 && $quantity_on_hand <= max((int) $row['reorder_level'], $lead_time_threshold),
            'is_restock_recommended' => $quantity_on_hand < $restock_threshold,
        ];
    }

    return $intelligence;
}

function pcims_get_best_seller_ids(PDO $db, $limit = 5)
{
    $limit = max(1, (int) $limit);

    $query = "SELECT soi.product_id
              FROM sales_order_items soi
              INNER JOIN sales_orders so ON so.so_id = soi.so_id
              WHERE " . pcims_completed_sales_condition('so') . "
              GROUP BY soi.product_id
              ORDER BY SUM(soi.quantity) DESC, soi.product_id ASC
              LIMIT {$limit}";
    $stmt = $db->prepare($query);
    $stmt->execute();

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function pcims_get_product_pair_map(PDO $db, array $source_product_ids = [])
{
    $query = "SELECT pps.source_product_id,
                     pps.suggested_product_id,
                     p.product_name,
                     p.product_code,
                     p.unit_price,
                     p.image_url,
                     COALESCE(i.quantity_available, 0) AS quantity_available
              FROM product_pair_suggestions pps
              INNER JOIN products p ON p.product_id = pps.suggested_product_id
              LEFT JOIN inventory i ON i.product_id = p.product_id
              WHERE p.status = 'active'";

    $params = [];
    if (!empty($source_product_ids)) {
        $source_product_ids = array_values(array_unique(array_map('intval', $source_product_ids)));
        $placeholders = [];
        foreach ($source_product_ids as $index => $product_id) {
            $placeholder = ':source_product_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $product_id;
        }
        $query .= ' AND pps.source_product_id IN (' . implode(', ', $placeholders) . ')';
    }

    $query .= ' ORDER BY pps.display_order ASC, p.product_name ASC';

    $stmt = $db->prepare($query);
    foreach ($params as $placeholder => $value) {
        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $pairs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $source_product_id = (int) $row['source_product_id'];
        if (!isset($pairs[$source_product_id])) {
            $pairs[$source_product_id] = [];
        }

        $pairs[$source_product_id][] = [
            'product_id' => (int) $row['suggested_product_id'],
            'product_name' => $row['product_name'],
            'product_code' => $row['product_code'],
            'unit_price' => (float) $row['unit_price'],
            'image_url' => $row['image_url'],
            'quantity_available' => (int) $row['quantity_available'],
        ];
    }

    return $pairs;
}

function pcims_save_product_pairs(PDO $db, $source_product_id, array $suggested_product_ids, $user_id = null)
{
    $source_product_id = (int) $source_product_id;
    $unique_ids = [];

    foreach ($suggested_product_ids as $suggested_id) {
        $suggested_id = (int) $suggested_id;
        if ($suggested_id > 0 && $suggested_id !== $source_product_id && !in_array($suggested_id, $unique_ids, true)) {
            $unique_ids[] = $suggested_id;
        }
    }

    $delete_stmt = $db->prepare('DELETE FROM product_pair_suggestions WHERE source_product_id = :source_product_id');
    $delete_stmt->bindValue(':source_product_id', $source_product_id, PDO::PARAM_INT);
    $delete_stmt->execute();

    if (empty($unique_ids)) {
        return;
    }

    $insert_stmt = $db->prepare(
        'INSERT INTO product_pair_suggestions (source_product_id, suggested_product_id, display_order, created_by)
         VALUES (:source_product_id, :suggested_product_id, :display_order, :created_by)'
    );

    foreach ($unique_ids as $index => $suggested_id) {
        $insert_stmt->bindValue(':source_product_id', $source_product_id, PDO::PARAM_INT);
        $insert_stmt->bindValue(':suggested_product_id', $suggested_id, PDO::PARAM_INT);
        $insert_stmt->bindValue(':display_order', $index + 1, PDO::PARAM_INT);
        if ($user_id !== null) {
            $insert_stmt->bindValue(':created_by', (int) $user_id, PDO::PARAM_INT);
        } else {
            $insert_stmt->bindValue(':created_by', null, PDO::PARAM_NULL);
        }
        $insert_stmt->execute();
    }
}

function pcims_normalize_global_discount_type($type)
{
    $type = strtolower(trim((string) $type));
    $allowed = ['none', 'percent', 'fixed'];
    return in_array($type, $allowed, true) ? $type : 'none';
}

function pcims_get_discount_code_map()
{
    $config = pcims_get_business_configuration();
    $discount_catalog = $config['pricing']['discount_catalog'] ?? [];

    if (!is_array($discount_catalog) || empty($discount_catalog)) {
        return [
            'none' => ['label' => null, 'percent' => 0],
        ];
    }

    return $discount_catalog;
}

function pcims_resolve_item_discount($discount_code, $discount_percent)
{
    $discount_map = pcims_get_discount_code_map();
    $discount_code = strtolower(trim((string) $discount_code));

    if (isset($discount_map[$discount_code])) {
        return [
            'discount_type' => $discount_map[$discount_code]['label'],
            'discount_percent' => (float) $discount_map[$discount_code]['percent'],
        ];
    }

    $discount_percent = max(0, min(100, (float) $discount_percent));
    return [
        'discount_type' => $discount_percent > 0 ? 'Custom Discount' : null,
        'discount_percent' => $discount_percent,
    ];
}

function pcims_calculate_global_discount_amount($base_amount, $discount_type, $discount_value)
{
    $base_amount = max(0, (float) $base_amount);
    $discount_value = max(0, (float) $discount_value);
    $discount_type = pcims_normalize_global_discount_type($discount_type);

    if ($discount_type === 'percent') {
        return min($base_amount, pcims_round_amount($base_amount * min(100, $discount_value) / 100));
    }

    if ($discount_type === 'fixed') {
        return min($base_amount, pcims_round_amount($discount_value));
    }

    return 0.0;
}

function pcims_get_customer_type($customer_name, $customer_email, $customer_phone)
{
    foreach ([$customer_name, $customer_email, $customer_phone] as $value) {
        if (trim((string) $value) !== '') {
            return 'registered';
        }
    }

    return 'walk_in';
}

function pcims_calculate_sale_payload(PDO $db, array $posted_items, $global_discount_type = 'none', $global_discount_value = 0)
{
    $consolidated_items = [];

    foreach ($posted_items as $item) {
        $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;

        if ($product_id <= 0 || $quantity <= 0) {
            continue;
        }

        if (!isset($consolidated_items[$product_id])) {
            $consolidated_items[$product_id] = [
                'product_id' => $product_id,
                'quantity' => 0,
                'discount_type' => null,
                'discount_percent' => 0.0,
            ];
        }

        $consolidated_items[$product_id]['quantity'] += $quantity;
    }

    if (empty($consolidated_items)) {
        throw new RuntimeException('Please add at least one product to the sale.');
    }

    $product_ids = array_keys($consolidated_items);
    $placeholders = [];
    foreach ($product_ids as $index => $product_id) {
        $placeholders[] = ':product_id_' . $index;
    }

    $query = "SELECT p.product_id, p.product_name, p.product_code, p.unit_price,
                     COALESCE(i.quantity_available, 0) AS quantity_available
              FROM products p
              LEFT JOIN inventory i ON i.product_id = p.product_id
              WHERE p.status = 'active'
                AND p.product_id IN (" . implode(', ', $placeholders) . ")";
    $stmt = $db->prepare($query);
    foreach ($product_ids as $index => $product_id) {
        $stmt->bindValue(':product_id_' . $index, $product_id, PDO::PARAM_INT);
    }
    $stmt->execute();

    $products = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $products[(int) $product['product_id']] = $product;
    }

    $validated_items = [];
    $subtotal = 0.0;
    $item_discount_total = 0.0;

    foreach ($consolidated_items as $product_id => $item) {
        if (!isset($products[$product_id])) {
            throw new RuntimeException('One or more selected products are no longer available.');
        }

        $product = $products[$product_id];
        $available_quantity = (int) $product['quantity_available'];
        if ($item['quantity'] > $available_quantity) {
            throw new RuntimeException(sprintf(
                'Insufficient stock for %s. Available: %d.',
                $product['product_name'],
                $available_quantity
            ));
        }

        $unit_price = (float) $product['unit_price'];
        $line_subtotal = pcims_round_amount($unit_price * $item['quantity']);
        $line_discount_amount = 0.0;
        $line_total = pcims_round_amount($line_subtotal);

        $subtotal += $line_subtotal;
        $item_discount_total += $line_discount_amount;

        $validated_items[] = [
            'product_id' => $product_id,
            'product_name' => $product['product_name'],
            'product_code' => $product['product_code'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => $unit_price,
            'discount_type' => $item['discount_type'],
            'discount_percent' => (float) $item['discount_percent'],
            'line_subtotal' => $line_subtotal,
            'line_discount_amount' => $line_discount_amount,
            'line_total' => $line_total,
            'available_quantity' => $available_quantity,
        ];
    }

    $subtotal = pcims_round_amount($subtotal);
    $item_discount_total = 0.0;
    $subtotal_after_item_discount = pcims_round_amount($subtotal);
    $global_discount_amount = pcims_calculate_global_discount_amount($subtotal_after_item_discount, $global_discount_type, $global_discount_value);
    $total_amount = pcims_round_amount($subtotal_after_item_discount - $global_discount_amount);

    return [
        'items' => $validated_items,
        'subtotal' => $subtotal,
        'item_discount_total' => $item_discount_total,
        'subtotal_after_item_discount' => $subtotal_after_item_discount,
        'global_discount_type' => pcims_normalize_global_discount_type($global_discount_type),
        'global_discount_value' => max(0, (float) $global_discount_value),
        'global_discount_amount' => pcims_round_amount($global_discount_amount),
        'total_savings' => pcims_round_amount($item_discount_total + $global_discount_amount),
        'total_amount' => $total_amount,
    ];
}

function pcims_get_dashboard_insights(PDO $db, array $settings = [])
{
    $resolved_settings = array_merge(pcims_get_rule_settings($db), $settings);
    $window_days = max(1, (int) $resolved_settings['sales_window_days']);

    $insights = [
        'total_sales_today' => 0.0,
        'top_sellers' => [],
        'slow_movers' => [],
        'low_stock_items' => [],
        'peak_sales_day' => null,
        'peak_sales_hour' => null,
    ];

    $query = "SELECT COALESCE(SUM(total_amount), 0) AS total_sales_today
              FROM sales_orders so
              WHERE " . pcims_completed_sales_condition('so') . "
                AND so.order_date = CURDATE()";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $insights['total_sales_today'] = (float) $stmt->fetchColumn();

    $query = "SELECT p.product_id,
                     p.product_name,
                     COALESCE(SUM(CASE WHEN so.so_id IS NOT NULL THEN soi.quantity ELSE 0 END), 0) AS quantity_sold,
                     COALESCE(SUM(CASE WHEN so.so_id IS NOT NULL THEN soi.quantity * soi.unit_price * (1 - (soi.discount_percent / 100)) ELSE 0 END), 0) AS total_revenue
              FROM products p
              LEFT JOIN sales_order_items soi ON soi.product_id = p.product_id
              LEFT JOIN sales_orders so ON so.so_id = soi.so_id
                 AND " . pcims_completed_sales_condition('so') . "
              WHERE p.status = 'active'
              GROUP BY p.product_id, p.product_name
              ORDER BY quantity_sold DESC, total_revenue DESC, p.product_name ASC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $insights['top_sellers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $query = "SELECT p.product_id,
                     p.product_name,
                     COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
                     COALESCE(SUM(CASE WHEN so.order_date >= DATE_SUB(CURDATE(), INTERVAL {$window_days} DAY)
                                       THEN soi.quantity ELSE 0 END), 0) AS quantity_sold_window
              FROM products p
              LEFT JOIN inventory i ON i.product_id = p.product_id
              LEFT JOIN sales_order_items soi ON soi.product_id = p.product_id
              LEFT JOIN sales_orders so ON so.so_id = soi.so_id
                 AND " . pcims_completed_sales_condition('so') . "
              WHERE p.status = 'active'
              GROUP BY p.product_id, p.product_name, i.quantity_on_hand
              ORDER BY quantity_sold_window ASC, quantity_on_hand DESC, p.product_name ASC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $insights['slow_movers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $low_stock_query = "SELECT p.product_id
                        FROM products p
                        WHERE p.status = 'active'";
    $stmt = $db->prepare($low_stock_query);
    $stmt->execute();
    $product_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $product_intelligence = pcims_get_product_intelligence($db, $product_ids, $resolved_settings);

    $low_stock_items = array_filter($product_intelligence, function ($item) {
        return $item['is_out_of_stock'] || $item['is_low_stock'] || $item['is_restock_recommended'];
    });

    usort($low_stock_items, function ($left, $right) {
        $left_score = ($left['is_out_of_stock'] ? 3 : 0) + ($left['is_restock_recommended'] ? 2 : 0) + ($left['is_low_stock'] ? 1 : 0);
        $right_score = ($right['is_out_of_stock'] ? 3 : 0) + ($right['is_restock_recommended'] ? 2 : 0) + ($right['is_low_stock'] ? 1 : 0);

        if ($left_score === $right_score) {
            return $left['quantity_on_hand'] <=> $right['quantity_on_hand'];
        }

        return $right_score <=> $left_score;
    });

    $insights['low_stock_items'] = array_slice(array_values($low_stock_items), 0, 5);

    $query = "SELECT DAYNAME(created_at) AS weekday_name,
                     SUM(total_amount) AS total_sales,
                     COUNT(*) AS order_count
              FROM sales_orders so
              WHERE " . pcims_completed_sales_condition('so') . "
              GROUP BY DAYOFWEEK(created_at), DAYNAME(created_at)
              ORDER BY total_sales DESC, order_count DESC
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $peak_day = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($peak_day) {
        $insights['peak_sales_day'] = $peak_day;
    }

    $query = "SELECT HOUR(created_at) AS sale_hour,
                     SUM(total_amount) AS total_sales,
                     COUNT(*) AS order_count
              FROM sales_orders so
              WHERE " . pcims_completed_sales_condition('so') . "
              GROUP BY HOUR(created_at)
              ORDER BY total_sales DESC, order_count DESC
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $peak_hour = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($peak_hour) {
        $sale_hour = isset($peak_hour['sale_hour']) ? (int) $peak_hour['sale_hour'] : 0;
        $peak_hour['hour_label'] = date('g:00 A', strtotime(sprintf('%02d:00:00', $sale_hour)));
        $insights['peak_sales_hour'] = $peak_hour;
    }

    return $insights;
}
