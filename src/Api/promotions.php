<?php
/**
 * Promotions API
 * GET    — List promotions
 * POST   — Create promotion
 * PUT    — Update promotion (id in query)
 * DELETE — Delete promotion (id in query)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        requirePermission('manage_products');
        $action = $_GET['action'] ?? 'list';

        if ($action === 'active') {
            // Return only currently active promotions (used by POS)
            $stmt = $db->query("
                SELECT * FROM promotions
                WHERE is_active = 1
                  AND (start_date IS NULL OR start_date <= CURDATE())
                  AND (end_date IS NULL OR end_date >= CURDATE())
                ORDER BY type, name
            ");
            jsonResponse(['data' => $stmt->fetchAll()]);
        }

        // List all promotions (admin)
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);
        $offset = ($page - 1) * $perPage;

        $countStmt = $db->query("SELECT COUNT(*) FROM promotions");
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));

        $stmt = $db->prepare("
            SELECT * FROM promotions
            ORDER BY is_active DESC, created_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute();

        jsonResponse([
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
        break;

    case 'POST':
        requirePermission('manage_products');
        verifyCSRFToken();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $name = trim($input['name'] ?? '');
        $type = $input['type'] ?? '';
        $description = trim($input['description'] ?? '');
        $config = $input['config'] ?? [];
        $isActive = (int)($input['is_active'] ?? 1);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? null;

        if (empty($name) || empty($type)) {
            jsonResponse(['error' => 'Name and type are required'], 400);
        }

        $validTypes = ['category_discount', 'spend_threshold', 'bundle_deal', 'buy_x_get_y'];
        if (!in_array($type, $validTypes)) {
            jsonResponse(['error' => 'Invalid promotion type'], 400);
        }

        // Validate config based on type
        if ($type === 'category_discount') {
            if (empty($config['rule'])) {
                jsonResponse(['error' => 'Promo requires a rule selection'], 400);
            }
            if (empty($config['buy_target'])) {
                jsonResponse(['error' => 'Promo requires a buy target'], 400);
            }
            if ($config['rule'] !== 'buy_any_x_for_y' && empty($config['get_target'])) {
                jsonResponse(['error' => 'Promo requires a get target'], 400);
            }
            if (empty($config['buy_qty'])) {
                jsonResponse(['error' => 'Promo requires a valid quantity'], 400);
            }
        } elseif ($type === 'bundle_deal') {
            if (empty($config['components']) || empty($config['bundle_price'])) {
                jsonResponse(['error' => 'Bundle deal requires components and bundle_price'], 400);
            }
        }

        $stmt = $db->prepare("
            INSERT INTO promotions (name, type, description, config, is_active, start_date, end_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name, $type, $description ?: null,
            json_encode($config), $isActive,
            $startDate ?: null, $endDate ?: null,
        ]);

        jsonResponse(['message' => 'Promotion created', 'id' => $db->lastInsertId()], 201);
        break;

    case 'PUT':
        requirePermission('manage_products');
        verifyCSRFToken();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'ID required'], 400);

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $name = trim($input['name'] ?? '');
        $type = $input['type'] ?? '';
        $description = trim($input['description'] ?? '');
        $config = $input['config'] ?? [];
        $isActive = (int)($input['is_active'] ?? 1);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? null;

        if (empty($name) || empty($type)) {
            jsonResponse(['error' => 'Name and type are required'], 400);
        }

        if ($type === 'category_discount') {
            if (empty($config['rule'])) {
                jsonResponse(['error' => 'Promo requires a rule selection'], 400);
            }
            if (empty($config['buy_target'])) {
                jsonResponse(['error' => 'Promo requires a buy target'], 400);
            }
            if ($config['rule'] !== 'buy_any_x_for_y' && empty($config['get_target'])) {
                jsonResponse(['error' => 'Promo requires a get target'], 400);
            }
            if (empty($config['buy_qty'])) {
                jsonResponse(['error' => 'Promo requires a valid quantity'], 400);
            }
        } elseif ($type === 'bundle_deal') {
            if (empty($config['components']) || empty($config['bundle_price'])) {
                jsonResponse(['error' => 'Bundle deal requires components and bundle_price'], 400);
            }
        }

        $stmt = $db->prepare("
            UPDATE promotions
            SET name = ?, type = ?, description = ?, config = ?, is_active = ?, start_date = ?, end_date = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $name, $type, $description ?: null,
            json_encode($config), $isActive,
            $startDate ?: null, $endDate ?: null,
            $id,
        ]);

        jsonResponse(['message' => 'Promotion updated']);
        break;

    case 'DELETE':
        requirePermission('manage_products');
        verifyCSRFToken();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'ID required'], 400);

        $stmt = $db->prepare("DELETE FROM promotions WHERE id = ?");
        $stmt->execute([$id]);

        jsonResponse(['message' => 'Promotion deleted']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
