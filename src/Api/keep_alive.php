<?php
/**
 * Keep Alive API
 * 
 * Simple endpoint to extend the user's session timestamp.
 */

// config/app.php is included by public/index.php, which also starts the session.
// So we just need to ensure the user is logged in.
requireLogin();

// requireLogin() automatically updates $_SESSION['last_activity'] under the hood.
// We just return a success response.

header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'message' => 'Session extended'
]);
