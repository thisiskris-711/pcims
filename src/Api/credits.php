<?php
/**
 * Credit Management API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'history';
        $dealerId = (int)($_GET['dealer_id'] ?? 0);

        if (!$dealerId) jsonResponse(['error' => 'Dealer ID required'], 400);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM credit_transactions WHERE dealer_id = ?");
        $countStmt->execute([$dealerId]);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT ct.*, u.full_name as processed_by
            FROM credit_transactions ct
            LEFT JOIN users u ON ct.created_by = u.id
            WHERE ct.dealer_id = ?
            ORDER BY ct.created_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute([$dealerId]);

        jsonResponse([
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
        break;

    case 'POST':
        requireRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER);
        $action = $_GET['action'] ?? '';

        if ($action !== 'payment') {
            jsonResponse(['error' => 'Invalid action'], 400);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $dealerId = (int)($input['dealer_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);
        $notes = trim($input['notes'] ?? '');

        if (!$dealerId) jsonResponse(['error' => 'Dealer ID required'], 400);
        if ($amount <= 0) jsonResponse(['error' => 'Payment amount must be greater than zero'], 400);

        $db->beginTransaction();
        try {
            // Get current dealer balance
            $dealerStmt = $db->prepare("SELECT id, name, credit_balance FROM dealers WHERE id = ? FOR UPDATE");
            $dealerStmt->execute([$dealerId]);
            $dealer = $dealerStmt->fetch();

            if (!$dealer) throw new Exception('Dealer not found');

            $currentBalance = (float)$dealer['credit_balance'];
            if ($amount > $currentBalance) {
                throw new Exception("Payment amount (\${$amount}) exceeds outstanding balance (\${$currentBalance})");
            }

            $newBalance = round($currentBalance - $amount, 2);

            // Update dealer balance
            $db->prepare("UPDATE dealers SET credit_balance = ? WHERE id = ?")->execute([$newBalance, $dealerId]);

            // Generate reference number
            $refNo = 'PAY-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Log credit transaction
            $db->prepare("
                INSERT INTO credit_transactions (dealer_id, type, amount, balance_after, reference_no, notes, created_by)
                VALUES (?, 'payment', ?, ?, ?, ?, ?)
            ")->execute([$dealerId, $amount, $newBalance, $refNo, $notes ?: "Payment received from {$dealer['name']}", getCurrentUserId()]);

            $db->commit();

            jsonResponse([
                'success' => true,
                'message' => "Payment of \${$amount} recorded. New balance: \${$newBalance}",
                'new_balance' => $newBalance,
                'reference_no' => $refNo,
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => $e->getMessage()], 400);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
