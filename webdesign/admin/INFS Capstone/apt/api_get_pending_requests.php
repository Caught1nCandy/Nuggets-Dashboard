<?php
// ============================================================
// api/get_pending_requests.php — OpenClaw API Endpoint
// Returns all pending update requests as JSON so OpenClaw
// can read and interpret them.
// ============================================================

header('Content-Type: application/json');

define('OPENCLAW_API_KEY', 'REPLACE_WITH_STRONG_RANDOM_KEY');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Check API key passed as query param or header
$key = $_GET['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($key !== OPENCLAW_API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../db_config.php';

try {
    $stmt = $pdo->query("
        SELECT id, employee_name, employee_id, reason, details, submitted_at
        FROM update_requests
        WHERE status = 'pending'
        ORDER BY submitted_at ASC
    ");
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'requests' => $requests, 'count' => count($requests)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
