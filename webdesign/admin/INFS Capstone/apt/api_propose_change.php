<?php
// ============================================================
// api/propose_change.php — OpenClaw API Endpoint
// Accepts a POST request from OpenClaw with a proposed change
// and inserts it into the proposed_changes table.
//
// Expected POST body (JSON):
// {
//   "api_key":     "YOUR_SECRET_KEY",
//   "request_id":  123,
//   "employee_id": "641185",
//   "table_name":  "workforce",
//   "column_name": "last_name",
//   "old_value":   "Smith",
//   "new_value":   "Rodriguez",
//   "confidence":  "high",
//   "notes":       "Employee stated last name changed due to marriage."
// }
//
// Returns JSON: { "success": true, "proposal_id": 456 }
// ============================================================

header('Content-Type: application/json');

// ── API key check ────────────────────────────────────────────
// Change this to a strong random string and keep it secret.
// OpenClaw must send this exact key or requests are rejected.
define('OPENCLAW_API_KEY', 'REPLACE_WITH_STRONG_RANDOM_KEY');

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// ── Parse JSON body ──────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit();
}

// ── Validate API key ─────────────────────────────────────────
if (($body['api_key'] ?? '') !== OPENCLAW_API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// ── Validate required fields ─────────────────────────────────
$required = ['request_id', 'employee_id', 'table_name', 'column_name', 'new_value'];
foreach ($required as $field) {
    if (empty($body[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit();
    }
}

// ── Whitelist allowed tables and columns ─────────────────────
// IMPORTANT: Only allow changes to safe columns.
// This prevents OpenClaw from accidentally modifying
// sensitive fields like employee_id, login passwords, etc.
$allowed = [
    'workforce' => [
        'first_name', 'last_name', 'tenure', 'anniversary',
        'birthday', 'role', 'job_code', 'org_id', 'location_id',
        'manager_id', 'director_id', 'vp_id', 'svp_id'
    ],
    'location' => [
        'work_city', 'state', 'work_postal'
    ],
    'organization' => [
        'organization_name'
    ]
];

$table  = $body['table_name'];
$column = $body['column_name'];

if (!isset($allowed[$table]) || !in_array($column, $allowed[$table])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Table/column not allowed: $table.$column"]);
    exit();
}

// ── Validate confidence value ────────────────────────────────
$confidence = $body['confidence'] ?? 'medium';
if (!in_array($confidence, ['high', 'medium', 'low'])) {
    $confidence = 'medium';
}

// ── Connect to DB and insert proposal ───────────────────────
require_once __DIR__ . '/../db_config.php';

try {
    // Verify the request_id actually exists and is still pending
    $stmt = $pdo->prepare("SELECT id FROM update_requests WHERE id = ? AND status IN ('pending', 'proposed')");
    $stmt->execute([$body['request_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Request not found or already processed']);
        exit();
    }

    // Insert the proposed change
    $stmt = $pdo->prepare("
        INSERT INTO proposed_changes
            (request_id, employee_id, table_name, column_name, old_value, new_value, confidence, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $body['request_id'],
        $body['employee_id'],
        $table,
        $column,
        $body['old_value'] ?? null,
        $body['new_value'],
        $confidence,
        $body['notes'] ?? null
    ]);

    $proposal_id = $pdo->lastInsertId();

    // Mark the update_request as proposed so it moves to the right section
    $stmt = $pdo->prepare("UPDATE update_requests SET status = 'proposed' WHERE id = ?");
    $stmt->execute([$body['request_id']]);

    echo json_encode(['success' => true, 'proposal_id' => (int)$proposal_id]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
