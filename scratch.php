<?php
require 'config/app.php';
$db = getDB();

$promoStmt = $db->query("
    SELECT name, description, config, type
    FROM promotions 
    WHERE is_active = 1
      AND (start_date IS NULL OR start_date <= CURDATE())
      AND (end_date IS NULL OR end_date >= CURDATE())
");
$activePromos = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

$products = $db->query("SELECT id, name, category_id FROM products WHERE id=113")->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as &$p) {
    foreach ($activePromos as $promo) {
        $config = json_decode($promo['config'], true);
        if (!$config) continue;
        
        $isMatch = false;
        if (isset($config['buy_target']) && $config['buy_target'] === 'product_' . $p['id']) $isMatch = true;
        if (isset($config['get_target']) && $config['get_target'] === 'product_' . $p['id']) $isMatch = true;
        if (isset($config['buy_target']) && $config['buy_target'] === 'category_' . $p['category_id']) $isMatch = true;
        
        if ($promo['type'] === 'bundle_deal' && !empty($config['components'])) {
            foreach ($config['components'] as $comp) {
                if (isset($comp['target']) && ($comp['target'] === 'product_' . $p['id'] || $comp['target'] === 'category_' . $p['category_id'])) {
                    $isMatch = true;
                    break;
                }
            }
        }
        
        if ($isMatch) {
            $p['active_promo'] = $promo['name'];
            $p['promo_desc'] = $promo['description'] ?: $promo['name'];
            break;
        }
    }
}
print_r($products);
