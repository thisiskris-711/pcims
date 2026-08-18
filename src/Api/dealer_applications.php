<?php
/**
 * Dealer Applications API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        requireLogin(); // Only admins/managers should see the list
        requireRole(ROLE_ADMIN, ROLE_MANAGER);

        $status = $_GET['status'] ?? 'pending';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);

        $where = "status = ?";
        $params = [$status];

        $countStmt = $db->prepare("SELECT COUNT(*) FROM dealer_applications WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT * FROM dealer_applications 
            WHERE $where 
            ORDER BY created_at DESC 
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);

        jsonResponse([
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
        break;

    case 'POST':
        $action = $_GET['action'] ?? 'submit';
        
        if ($action === 'submit') {
            // Public endpoint: Submit new application
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $firstName = trim($input['first_name'] ?? '');
            $lastName = trim($input['last_name'] ?? '');
            $phone = trim($input['phone'] ?? '');

            if (empty($firstName) || empty($lastName) || empty($phone)) {
                jsonResponse(['error' => 'First Name, Last Name, and Phone are required'], 400);
            }

            $stmt = $db->prepare("
                INSERT INTO dealer_applications (
                    first_name, last_name, middle_name, phone, email, 
                    address1, region, province, city, barangay, 
                    preferred_branch, source, recruiter_id, recruiter_name, 
                    recruiter_phone, recruiter_fb, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $stmt->execute([
                $firstName, $lastName, trim($input['middle_name'] ?? ''),
                $phone, trim($input['email'] ?? ''), trim($input['address1'] ?? ''),
                trim($input['region'] ?? ''), trim($input['province'] ?? ''),
                trim($input['city'] ?? ''), trim($input['barangay'] ?? ''),
                trim($input['preferred_branch'] ?? ''), trim($input['source'] ?? ''),
                trim($input['recruiter_id'] ?? ''), trim($input['recruiter_name'] ?? ''),
                trim($input['recruiter_phone'] ?? ''), trim($input['recruiter_fb'] ?? '')
            ]);

            jsonResponse(['success' => true, 'message' => 'Application submitted successfully']);
        }

        if ($action === 'add_and_approve') {
            requireLogin();
            requireRole(ROLE_ADMIN, ROLE_MANAGER);
            
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            $firstName = trim($input['first_name'] ?? '');
            $lastName = trim($input['last_name'] ?? '');
            $phone = trim($input['phone'] ?? '');

            if (empty($firstName) || empty($lastName) || empty($phone)) {
                jsonResponse(['error' => 'First Name, Last Name, and Phone are required'], 400);
            }

            $db->beginTransaction();
            try {
                // Insert into applications as approved
                $stmt = $db->prepare("
                    INSERT INTO dealer_applications (
                        first_name, last_name, middle_name, phone, email, 
                        address1, region, province, city, barangay, 
                        preferred_branch, source, recruiter_id, recruiter_name, 
                        recruiter_phone, recruiter_fb, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')
                ");

                $stmt->execute([
                    $firstName, $lastName, trim($input['middle_name'] ?? ''),
                    $phone, trim($input['email'] ?? ''), trim($input['address1'] ?? ''),
                    trim($input['region'] ?? ''), trim($input['province'] ?? ''),
                    trim($input['city'] ?? ''), trim($input['barangay'] ?? ''),
                    trim($input['preferred_branch'] ?? ''), trim($input['source'] ?? ''),
                    trim($input['recruiter_id'] ?? ''), trim($input['recruiter_name'] ?? ''),
                    trim($input['recruiter_phone'] ?? ''), trim($input['recruiter_fb'] ?? '')
                ]);
                $appId = $db->lastInsertId();

                // Generate dealer code
                $codeStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM dealers");
                $nextId = $codeStmt->fetchColumn();
                $dealerCode = 'DLR-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

                // Make sure dealer code is unique
                $codeCheck = $db->prepare("SELECT id FROM dealers WHERE dealer_code = ?");
                $codeCheck->execute([$dealerCode]);
                if ($codeCheck->fetch()) {
                    $dealerCode = 'DLR-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                }

                $name = trim($firstName . ' ' . $lastName);
                $addressParts = array_filter([trim($input['address1'] ?? ''), trim($input['barangay'] ?? ''), trim($input['city'] ?? ''), trim($input['province'] ?? '')]);
                $address = implode(', ', $addressParts);

                // Insert to dealers
                $dealerStmt = $db->prepare("
                    INSERT INTO dealers (dealer_code, name, email, phone, address, credit_limit, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $dealerStmt->execute([
                    $dealerCode, 
                    $name, 
                    trim($input['email'] ?? ''), 
                    $phone, 
                    $address, 
                    0, 
                    'Added manually via application form', 
                    getCurrentUserId()
                ]);

                $db->commit();
                jsonResponse(['success' => true, 'message' => 'Dealer created successfully']);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
        }
        
        if ($action === 'approve') {
            requireLogin();
            requireRole(ROLE_ADMIN, ROLE_MANAGER);
            
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'Application ID required'], 400);

            $stmt = $db->prepare("SELECT * FROM dealer_applications WHERE id = ? AND status = 'pending'");
            $stmt->execute([$id]);
            $application = $stmt->fetch();

            if (!$application) {
                jsonResponse(['error' => 'Application not found or not pending'], 404);
            }

            $db->beginTransaction();
            try {
                // Generate dealer code
                $codeStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM dealers");
                $nextId = $codeStmt->fetchColumn();
                $dealerCode = 'DLR-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

                // Make sure dealer code is unique
                $codeCheck = $db->prepare("SELECT id FROM dealers WHERE dealer_code = ?");
                $codeCheck->execute([$dealerCode]);
                if ($codeCheck->fetch()) {
                    $dealerCode = 'DLR-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                }

                $name = trim($application['first_name'] . ' ' . $application['last_name']);
                $address = trim($application['address1'] . ' ' . $application['barangay'] . ' ' . $application['city'] . ' ' . $application['province']);

                // Insert to dealers
                $dealerStmt = $db->prepare("
                    INSERT INTO dealers (dealer_code, name, email, phone, address, credit_limit, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $dealerStmt->execute([
                    $dealerCode, 
                    $name, 
                    $application['email'], 
                    $application['phone'], 
                    $address, 
                    0, 
                    'Migrated from application #' . $application['id'], 
                    getCurrentUserId()
                ]);

                // Update application status
                $updateStmt = $db->prepare("UPDATE dealer_applications SET status = 'approved' WHERE id = ?");
                $updateStmt->execute([$id]);

                $db->commit();
                jsonResponse(['success' => true, 'message' => 'Application approved and dealer created']);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
        }

        if ($action === 'reject') {
            requireLogin();
            requireRole(ROLE_ADMIN, ROLE_MANAGER);
            
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'Application ID required'], 400);

            $stmt = $db->prepare("UPDATE dealer_applications SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$id]);

            jsonResponse(['success' => true, 'message' => 'Application rejected']);
        }

        jsonResponse(['error' => 'Invalid action'], 400);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
