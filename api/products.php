<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

function api_response($status_code, $payload)
{
    http_response_code($status_code);
    echo json_encode($payload);
    exit();
}

function get_json_payload()
{
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    return is_array($data) ? $data : [];
}

function normalize_product_status($status)
{
    $status = strtolower(trim((string) $status));
    $allowed_statuses = ['active', 'inactive', 'discontinued'];
    return in_array($status, $allowed_statuses, true) ? $status : 'active';
}

function parse_nullable_int($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException('A related record ID must be numeric.');
    }

    return (int) $value;
}

function parse_non_negative_number($value, $field_name)
{
    if ($value === null || $value === '') {
        throw new InvalidArgumentException($field_name . ' is required.');
    }

    if (!is_numeric($value) || $value < 0) {
        throw new InvalidArgumentException($field_name . ' must be a non-negative number.');
    }

    return (float) $value;
}

function generate_product_code($db)
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = 'PC' . date('Ymd') . mt_rand(1000, 9999);

        $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE product_code = :product_code");
        $stmt->bindValue(':product_code', $candidate);
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate a unique product code.');
}

function find_product($db, $product_id)
{
    $stmt = $db->prepare(
        "SELECT product_id, product_code, product_name, description, category_id, supplier_id,
                unit_price, cost_price, reorder_level, unit_of_measure, image_url, status
         FROM products
         WHERE product_id = :product_id"
    );
    $stmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
    $stmt->execute();

    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    return $product ?: null;
}

if (!is_logged_in()) {
    api_response(401, ['error' => 'Unauthorized']);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
            $search = trim((string) ($_GET['search'] ?? ''));
            $offset = ($page - 1) * $limit;

            $query = "SELECT p.product_id, p.product_code, p.product_name, p.description,
                             p.category_id, c.category_name, p.supplier_id, s.supplier_name,
                             p.unit_price, p.cost_price, p.reorder_level, p.unit_of_measure,
                             p.image_url, p.status, p.created_at, p.updated_at,
                             COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
                             COALESCE(i.quantity_reserved, 0) AS quantity_reserved,
                             COALESCE(i.quantity_available, COALESCE(i.quantity_on_hand, 0) - COALESCE(i.quantity_reserved, 0)) AS quantity_available,
                             i.last_updated
                      FROM products p
                      LEFT JOIN categories c ON p.category_id = c.category_id
                      LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
                      LEFT JOIN inventory i ON p.product_id = i.product_id
                      WHERE p.status = :status";

            $count_query = "SELECT COUNT(*) as total
                            FROM products p
                            WHERE p.status = :status";

            if ($search !== '') {
                $query .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR p.description LIKE :search)";
                $count_query .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR p.description LIKE :search)";
            }

            $query .= " ORDER BY p.product_name LIMIT :limit OFFSET :offset";

            $stmt = $db->prepare($query);
            $count_stmt = $db->prepare($count_query);

            $stmt->bindValue(':status', 'active');
            $count_stmt->bindValue(':status', 'active');

            if ($search !== '') {
                $search_param = '%' . $search . '%';
                $stmt->bindValue(':search', $search_param);
                $count_stmt->bindValue(':search', $search_param);
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            $count_stmt->execute();

            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int) ($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            api_response(200, [
                'success' => true,
                'data' => [
                    'products' => $products,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => $total > 0 ? (int) ceil($total / $limit) : 0
                    ]
                ]
            ]);
            break;

        case 'POST':
            if (!has_permission('staff')) {
                api_response(403, ['error' => 'Insufficient permissions']);
            }

            $data = get_json_payload();
            $product_name = trim((string) ($data['product_name'] ?? ''));
            $description = trim((string) ($data['description'] ?? ''));

            if ($product_name === '') {
                api_response(422, ['success' => false, 'error' => 'Product name is required.']);
            }

            $product_code = trim((string) ($data['product_code'] ?? ''));
            $unit_price = parse_non_negative_number($data['unit_price'] ?? null, 'Unit price');
            $cost_price = parse_non_negative_number($data['cost_price'] ?? $data['unit_price'] ?? null, 'Cost price');
            $reorder_level = max(0, (int) ($data['reorder_level'] ?? 10));
            $initial_quantity = max(0, (int) ($data['initial_quantity'] ?? $data['initial_stock'] ?? 0));
            $quantity_reserved = max(0, (int) ($data['quantity_reserved'] ?? 0));
            $unit_of_measure = trim((string) ($data['unit_of_measure'] ?? 'pcs'));
            $status = normalize_product_status($data['status'] ?? 'active');
            $category_id = parse_nullable_int($data['category_id'] ?? null);
            $supplier_id = parse_nullable_int($data['supplier_id'] ?? null);

            $db->beginTransaction();

            if ($product_code === '') {
                $product_code = generate_product_code($db);
            }

            $insert_query = "INSERT INTO products (
                                product_code, product_name, description, category_id, supplier_id,
                                unit_price, cost_price, reorder_level, unit_of_measure, image_url, status
                             ) VALUES (
                                :product_code, :product_name, :description, :category_id, :supplier_id,
                                :unit_price, :cost_price, :reorder_level, :unit_of_measure, :image_url, :status
                             )";

            $stmt = $db->prepare($insert_query);
            $stmt->bindValue(':product_code', $product_code);
            $stmt->bindValue(':product_name', $product_name);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':category_id', $category_id, $category_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':supplier_id', $supplier_id, $supplier_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':unit_price', $unit_price);
            $stmt->bindValue(':cost_price', $cost_price);
            $stmt->bindValue(':reorder_level', $reorder_level, PDO::PARAM_INT);
            $stmt->bindValue(':unit_of_measure', $unit_of_measure !== '' ? $unit_of_measure : 'pcs');
            $stmt->bindValue(':image_url', trim((string) ($data['image_url'] ?? '')));
            $stmt->bindValue(':status', $status);
            $stmt->execute();

            $product_id = (int) $db->lastInsertId();

            $inventory_stmt = $db->prepare(
                "INSERT INTO inventory (product_id, quantity_on_hand, quantity_reserved)
                 VALUES (:product_id, :quantity_on_hand, :quantity_reserved)"
            );
            $inventory_stmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
            $inventory_stmt->bindValue(':quantity_on_hand', $initial_quantity, PDO::PARAM_INT);
            $inventory_stmt->bindValue(':quantity_reserved', $quantity_reserved, PDO::PARAM_INT);
            $inventory_stmt->execute();

            $db->commit();

            log_activity($_SESSION['user_id'], 'product_created', 'Created product: ' . $product_name);

            api_response(201, [
                'success' => true,
                'message' => 'Product created successfully',
                'product_id' => $product_id,
                'product_code' => $product_code
            ]);
            break;

        case 'PUT':
            if (!has_permission('staff')) {
                api_response(403, ['error' => 'Insufficient permissions']);
            }

            $data = get_json_payload();
            $product_id = (int) ($data['product_id'] ?? 0);
            if ($product_id <= 0) {
                api_response(422, ['success' => false, 'error' => 'A valid product ID is required.']);
            }

            $current_product = find_product($db, $product_id);
            if (!$current_product) {
                api_response(404, ['success' => false, 'error' => 'Product not found.']);
            }

            $product_name = trim((string) ($data['product_name'] ?? $current_product['product_name']));
            if ($product_name === '') {
                api_response(422, ['success' => false, 'error' => 'Product name is required.']);
            }

            $product_code = trim((string) ($data['product_code'] ?? $current_product['product_code']));
            if ($product_code === '') {
                $product_code = $current_product['product_code'];
            }

            $description = trim((string) ($data['description'] ?? $current_product['description'] ?? ''));
            $unit_price = parse_non_negative_number($data['unit_price'] ?? $current_product['unit_price'], 'Unit price');
            $cost_price = parse_non_negative_number($data['cost_price'] ?? $current_product['cost_price'], 'Cost price');
            $reorder_level = max(0, (int) ($data['reorder_level'] ?? $current_product['reorder_level']));
            $unit_of_measure = trim((string) ($data['unit_of_measure'] ?? $current_product['unit_of_measure'] ?? 'pcs'));
            $status = normalize_product_status($data['status'] ?? $current_product['status']);
            $category_id = array_key_exists('category_id', $data)
                ? parse_nullable_int($data['category_id'])
                : ($current_product['category_id'] !== null ? (int) $current_product['category_id'] : null);
            $supplier_id = array_key_exists('supplier_id', $data)
                ? parse_nullable_int($data['supplier_id'])
                : ($current_product['supplier_id'] !== null ? (int) $current_product['supplier_id'] : null);
            $image_url = trim((string) ($data['image_url'] ?? $current_product['image_url'] ?? ''));

            $db->beginTransaction();

            $update_query = "UPDATE products SET
                                product_code = :product_code,
                                product_name = :product_name,
                                description = :description,
                                category_id = :category_id,
                                supplier_id = :supplier_id,
                                unit_price = :unit_price,
                                cost_price = :cost_price,
                                reorder_level = :reorder_level,
                                unit_of_measure = :unit_of_measure,
                                image_url = :image_url,
                                status = :status
                             WHERE product_id = :product_id";

            $stmt = $db->prepare($update_query);
            $stmt->bindValue(':product_code', $product_code);
            $stmt->bindValue(':product_name', $product_name);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':category_id', $category_id, $category_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':supplier_id', $supplier_id, $supplier_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':unit_price', $unit_price);
            $stmt->bindValue(':cost_price', $cost_price);
            $stmt->bindValue(':reorder_level', $reorder_level, PDO::PARAM_INT);
            $stmt->bindValue(':unit_of_measure', $unit_of_measure !== '' ? $unit_of_measure : 'pcs');
            $stmt->bindValue(':image_url', $image_url);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
            $stmt->execute();

            if (array_key_exists('quantity_on_hand', $data) || array_key_exists('quantity_reserved', $data)) {
                $inventory_check = $db->prepare(
                    "SELECT quantity_on_hand, quantity_reserved
                     FROM inventory
                     WHERE product_id = :product_id"
                );
                $inventory_check->bindValue(':product_id', $product_id, PDO::PARAM_INT);
                $inventory_check->execute();
                $current_inventory = $inventory_check->fetch(PDO::FETCH_ASSOC);

                $quantity_on_hand = array_key_exists('quantity_on_hand', $data)
                    ? max(0, (int) $data['quantity_on_hand'])
                    : (int) ($current_inventory['quantity_on_hand'] ?? 0);
                $quantity_reserved = array_key_exists('quantity_reserved', $data)
                    ? max(0, (int) $data['quantity_reserved'])
                    : (int) ($current_inventory['quantity_reserved'] ?? 0);

                if ($current_inventory) {
                    $inventory_stmt = $db->prepare(
                        "UPDATE inventory
                         SET quantity_on_hand = :quantity_on_hand,
                             quantity_reserved = :quantity_reserved,
                             last_updated = NOW()
                         WHERE product_id = :product_id"
                    );
                } else {
                    $inventory_stmt = $db->prepare(
                        "INSERT INTO inventory (product_id, quantity_on_hand, quantity_reserved)
                         VALUES (:product_id, :quantity_on_hand, :quantity_reserved)"
                    );
                }

                $inventory_stmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
                $inventory_stmt->bindValue(':quantity_on_hand', $quantity_on_hand, PDO::PARAM_INT);
                $inventory_stmt->bindValue(':quantity_reserved', $quantity_reserved, PDO::PARAM_INT);
                $inventory_stmt->execute();
            }

            $db->commit();

            log_activity($_SESSION['user_id'], 'product_updated', 'Updated product ID: ' . $product_id);

            api_response(200, [
                'success' => true,
                'message' => 'Product updated successfully'
            ]);
            break;

        case 'DELETE':
            if (!has_permission('admin')) {
                api_response(403, ['error' => 'Insufficient permissions']);
            }

            $data = get_json_payload();
            $product_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($data['product_id'] ?? 0);

            if ($product_id <= 0) {
                api_response(422, ['success' => false, 'error' => 'A valid product ID is required.']);
            }

            $stmt = $db->prepare("UPDATE products SET status = 'inactive' WHERE product_id = :product_id");
            $stmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
            $stmt->execute();

            log_activity($_SESSION['user_id'], 'product_deleted', 'Deleted product ID: ' . $product_id);

            api_response(200, [
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
            break;

        default:
            api_response(405, ['error' => 'Method not allowed']);
    }
} catch (InvalidArgumentException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    api_response(422, [
        'success' => false,
        'error' => $exception->getMessage()
    ]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Products API Database Error: ' . $exception->getMessage());
    api_response(500, [
        'success' => false,
        'error' => 'Database error'
    ]);
} catch (Exception $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Products API Unexpected Error: ' . $exception->getMessage());
    api_response(500, [
        'success' => false,
        'error' => 'Server error'
    ]);
}
?>
