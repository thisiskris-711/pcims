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
        requireAnyPermission('manage_dealers', 'approve_sales', 'view_sales');
        $action = $_GET['action'] ?? 'history';
        $dealerId = (int)($_GET['dealer_id'] ?? 0);

        if ($action === 'history') {
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
        }
        
        elseif ($action === 'unpaid_invoices') {
            if (!$dealerId) jsonResponse(['error' => 'Dealer ID required'], 400);
            
            // Get unpaid or partially paid credit invoices
            $stmt = $db->prepare("
                SELECT s.id as sale_id, s.invoice_no, s.total, s.due_date, s.created_at,
                       (CASE WHEN s.payment_method = 'cash&credit' THEN (s.total - s.cash_received) ELSE s.total END - COALESCE((SELECT SUM(amount) FROM collections WHERE sale_id = s.id AND status = 'active'), 0)) as balance
                FROM sales s
                WHERE s.dealer_id = ? AND s.payment_method IN ('credit', 'cash&credit') AND s.payment_status != 'paid'
                HAVING balance > 0
                ORDER BY s.due_date ASC
            ");
            $stmt->execute([$dealerId]);
            jsonResponse(['data' => $stmt->fetchAll()]);
        }
        
        elseif ($action === 'overdue') {
            // Find dealers with overdue invoices and return their overdue status
            $stmt = $db->query("
                SELECT d.id as dealer_id, d.name, 
                       MIN(s.due_date) as oldest_due_date,
                       SUM((CASE WHEN s.payment_method = 'cash&credit' THEN (s.total - s.cash_received) ELSE s.total END) - COALESCE((SELECT SUM(amount) FROM collections WHERE sale_id = s.id AND status = 'active'), 0)) as overdue_amount,
                       DATEDIFF(CURDATE(), MIN(s.due_date)) as days_overdue
                FROM dealers d
                JOIN sales s ON d.id = s.dealer_id
                WHERE s.payment_method IN ('credit', 'cash&credit') AND s.payment_status != 'paid'
                  AND s.due_date < CURDATE()
                GROUP BY d.id
                HAVING overdue_amount > 0
            ");
            $overdueDealers = $stmt->fetchAll();
            
            // Trigger notifications for new threshold crossings (30, 60, 90 days)
            // In a real app this would be a cron job, but we'll hook it here for now
            foreach ($overdueDealers as $dealer) {
                $days = (int)$dealer['days_overdue'];
                $threshold = 0;
                if ($days >= 90) $threshold = 90;
                elseif ($days >= 60) $threshold = 60;
                elseif ($days >= 30) $threshold = 30;
                
                if ($threshold > 0) {
                    $noteKey = "overdue_{$threshold}_" . date('Y_m', strtotime($dealer['oldest_due_date']));
                    
                    // check if we already notified for this threshold/invoice combo
                    $checkStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE type = 'warning' AND link = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
                    $linkStr = "/dealers?id=" . $dealer['dealer_id'] . "&ref=" . $noteKey;
                    $checkStmt->execute([$linkStr]);
                    
                    if ($checkStmt->fetchColumn() == 0) {
                        createNotification(
                            null, 
                            "Overdue Account: " . $dealer['name'], 
                            "Account is {$days} days overdue with amount ₱" . number_format($dealer['overdue_amount'], 2), 
                            'warning', 
                            $linkStr
                        );
                    }
                }
            }
            
            jsonResponse(['data' => $overdueDealers]);
        }
        
        elseif ($action === 'list_memos') {
            if (!$dealerId) jsonResponse(['error' => 'Dealer ID required'], 400);
            
            $stmt = $db->prepare("
                SELECT cm.*, u.full_name as created_by_name 
                FROM credit_memos cm
                LEFT JOIN users u ON cm.created_by = u.id
                WHERE cm.dealer_id = ?
                ORDER BY cm.created_at DESC
            ");
            $stmt->execute([$dealerId]);
            jsonResponse(['data' => $stmt->fetchAll()]);
        }
        
        else {
            jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;

    case 'POST':
        requireRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER);
        $action = $_GET['action'] ?? '';

        if ($action === 'payment') {
            $input = json_decode(file_get_contents('php://input'), true);
            $dealerId = (int)($input['dealer_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $notes = trim($input['notes'] ?? '');
            $allocations = $input['allocations'] ?? []; // [{sale_id, amount}]

            if (!$dealerId) jsonResponse(['error' => 'Dealer ID required'], 400);
            if ($amount <= 0) jsonResponse(['error' => 'Payment amount must be greater than zero'], 400);
            
            $totalAllocated = array_sum(array_column($allocations, 'amount'));
            if (abs($totalAllocated - $amount) > 0.01) {
                jsonResponse(['error' => 'Allocations must exactly match total payment amount'], 400);
            }

            $db->beginTransaction();
            try {
                // Get current dealer balance
                $dealerStmt = $db->prepare("SELECT id, name, credit_balance FROM dealers WHERE id = ? FOR UPDATE");
                $dealerStmt->execute([$dealerId]);
                $dealer = $dealerStmt->fetch();

                if (!$dealer) throw new Exception('Dealer not found');

                $currentBalance = (float)$dealer['credit_balance'];
                if ($amount > $currentBalance) {
                    throw new Exception("Payment amount (₱{$amount}) exceeds outstanding balance (₱{$currentBalance})");
                }

                $newBalance = round($currentBalance - $amount, 2);

                // Update dealer balance
                $db->prepare("UPDATE dealers SET credit_balance = ? WHERE id = ?")->execute([$newBalance, $dealerId]);

                // Generate reference number
                $refNo = generateCreditReferenceNo('PAY');

                // Log credit transaction
                $saleIdForTransaction = count($allocations) === 1 ? $allocations[0]['sale_id'] : null;
                $db->prepare("
                    INSERT INTO credit_transactions (dealer_id, sale_id, type, amount, balance_after, reference_no, notes, created_by)
                    VALUES (?, ?, 'payment', ?, ?, ?, ?, ?)
                ")->execute([$dealerId, $saleIdForTransaction, $amount, $newBalance, $refNo, $notes ?: "Payment received from {$dealer['name']}", getCurrentUserId()]);

                // Insert into collections for each allocation
                $collectStmt = $db->prepare("
                    INSERT INTO collections (sale_id, amount, payment_method, reference_number, payment_date, notes, created_by)
                    VALUES (?, ?, 'cash_check', ?, CURDATE(), ?, ?)
                ");
                
                foreach ($allocations as $alloc) {
                    if ((float)$alloc['amount'] > 0) {
                        $collectStmt->execute([
                            $alloc['sale_id'],
                            $alloc['amount'],
                            $refNo,
                            "Allocated payment",
                            getCurrentUserId()
                        ]);
                        
                        // Update sales payment_status
                        // Update sales payment_status
                        $salesCheckStmt = $db->prepare("
                            SELECT s.total, s.cash_received, s.payment_method,
                                   (SELECT COALESCE(SUM(c.amount), 0) FROM collections c WHERE c.sale_id = s.id AND c.status = 'active') as total_paid
                            FROM sales s WHERE s.id = ?
                        ");
                        $salesCheckStmt->execute([$alloc['sale_id']]);
                        $saleData = $salesCheckStmt->fetch();
                        
                        if ($saleData) {
                            $targetTotal = ($saleData['payment_method'] === 'cash&credit') ? ($saleData['total'] - $saleData['cash_received']) : $saleData['total'];
                            $newStatus = ($saleData['total_paid'] >= $targetTotal - 0.01) ? 'paid' : 'pending';
                            $db->prepare("UPDATE sales SET payment_status = ? WHERE id = ?")->execute([$newStatus, $alloc['sale_id']]);
                        }
                    }
                }

                $db->commit();

                jsonResponse([
                    'success' => true,
                    'message' => "Payment of ₱" . number_format($amount, 2) . " recorded.",
                    'new_balance' => $newBalance,
                    'reference_no' => $refNo,
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(['error' => $e->getMessage()], 400);
            }
        }
        
        elseif ($action === 'create_memo') {
            requireRole(ROLE_ADMIN, ROLE_MANAGER);
            $input = json_decode(file_get_contents('php://input'), true);
            $dealerId = (int)($input['dealer_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $reason = trim($input['reason'] ?? '');
            
            if (!$dealerId || $amount <= 0 || empty($reason)) {
                jsonResponse(['error' => 'Dealer ID, Amount (>0), and Reason are required'], 400);
            }
            
            try {
                $refNo = generateCreditReferenceNo('CM');
                $stmt = $db->prepare("
                    INSERT INTO credit_memos (dealer_id, reference_no, amount, balance, reason, status, created_by)
                    VALUES (?, ?, ?, ?, ?, 'approved', ?)
                ");
                $stmt->execute([$dealerId, $refNo, $amount, $amount, $reason, getCurrentUserId()]);
                
                jsonResponse(['success' => true, 'message' => 'Credit memo created']);
            } catch (Exception $e) {
                jsonResponse(['error' => $e->getMessage()], 400);
            }
        }
        
        elseif ($action === 'apply_memo') {
            requireRole(ROLE_ADMIN, ROLE_MANAGER);
            $input = json_decode(file_get_contents('php://input'), true);
            $memoId = (int)($input['memo_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $allocations = $input['allocations'] ?? [];
            
            if (!$memoId || $amount <= 0 || empty($allocations)) {
                jsonResponse(['error' => 'Memo ID, Amount, and Allocations required'], 400);
            }
            
            $db->beginTransaction();
            try {
                // Get memo
                $memoStmt = $db->prepare("SELECT * FROM credit_memos WHERE id = ? FOR UPDATE");
                $memoStmt->execute([$memoId]);
                $memo = $memoStmt->fetch();
                
                if (!$memo || $memo['status'] !== 'approved' || $memo['balance'] < $amount) {
                    throw new Exception("Invalid memo or insufficient memo balance");
                }
                
                // Get dealer
                $dealerId = $memo['dealer_id'];
                $dealerStmt = $db->prepare("SELECT id, name, credit_balance FROM dealers WHERE id = ? FOR UPDATE");
                $dealerStmt->execute([$dealerId]);
                $dealer = $dealerStmt->fetch();
                
                $currentBalance = (float)$dealer['credit_balance'];
                if ($amount > $currentBalance) {
                    throw new Exception("Memo amount (₱{$amount}) exceeds outstanding balance (₱{$currentBalance})");
                }
                
                // 1. Update Memo Balance
                $newMemoBalance = $memo['balance'] - $amount;
                $newStatus = $newMemoBalance <= 0 ? 'used' : 'approved';
                $db->prepare("UPDATE credit_memos SET balance = ?, status = ? WHERE id = ?")->execute([$newMemoBalance, $newStatus, $memoId]);
                
                // 2. Update Dealer Balance
                $newBalance = round($currentBalance - $amount, 2);
                $db->prepare("UPDATE dealers SET credit_balance = ? WHERE id = ?")->execute([$newBalance, $dealerId]);
                
                // 3. Log credit transaction
                $saleIdForTransaction = count($allocations) === 1 ? $allocations[0]['sale_id'] : null;
                $db->prepare("
                    INSERT INTO credit_transactions (dealer_id, sale_id, type, amount, balance_after, reference_no, notes, created_by)
                    VALUES (?, ?, 'payment', ?, ?, ?, ?, ?)
                ")->execute([$dealerId, $saleIdForTransaction, $amount, $newBalance, $memo['reference_no'], "Applied credit memo " . $memo['reference_no'], getCurrentUserId()]);

                // 4. Insert into collections
                $collectStmt = $db->prepare("
                    INSERT INTO collections (sale_id, amount, payment_method, reference_no, payment_date, notes, created_by)
                    VALUES (?, ?, 'credit_memo', ?, CURDATE(), ?, ?)
                ");
                
                foreach ($allocations as $alloc) {
                    if ((float)$alloc['amount'] > 0) {
                        $collectStmt->execute([
                            $alloc['sale_id'],
                            $alloc['amount'],
                            $memo['reference_no'],
                            "Applied memo",
                            getCurrentUserId()
                        ]);
                    }
                }
                
                $db->commit();
                jsonResponse(['success' => true, 'message' => "Memo applied successfully"]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(['error' => $e->getMessage()], 400);
            }
        }
        
        elseif ($action === 'void_memo') {
            requireRole(ROLE_ADMIN, ROLE_MANAGER);
            $input = json_decode(file_get_contents('php://input'), true);
            $memoId = (int)($input['memo_id'] ?? 0);
            
            $db->prepare("UPDATE credit_memos SET status = 'void' WHERE id = ? AND status = 'approved'")->execute([$memoId]);
            jsonResponse(['success' => true, 'message' => 'Credit memo voided']);
        }
        
        else {
            jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
