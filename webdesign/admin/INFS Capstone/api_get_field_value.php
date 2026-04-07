<?php
// ============================================================
// api/api_get_field_value.php — Get current field value
// Called by process_requests.py to get old value before
// submitting a proposed change, so change_log has real data.
//
// GET params: api_key, employee_id, table_name, column_name
// Returns: { "success": true, "value": "current_value" }
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../config/api_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$key = $_GET['api_key'] ?? '';
if ($key !== OPENCLAW_API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$employee_id = $_GET['employee_id'] ?? '';
$table_name  = $_GET['table_name']  ?? '';
$column_name = $_GET['column_name'] ?? '';

if (!$employee_id || !$table_name || !$column_name) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required params']);
    exit();
}

// Whitelist tables and columns
$allowed = [
    'workforce' => [
        'first_name', 'last_name', 'tenure', 'anniversary',
        'birthday', 'role', 'job_code', 'org_id', 'location_id',
        'manager_id', 'director_id', 'vp_id', 'svp_id'
    ],
    'location' => ['work_city', 'state', 'work_postal'],
    'organization' => ['organization_name']
];

if (!isset($allowed[$table_name]) || !in_array($column_name, $allowed[$table_name])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Table/column not allowed']);
    exit();
}

require_once __DIR__ . '/../db_config.php';

try {
    $stmt = $pdo->prepare("SELECT {$column_name} FROM {$table_name} WHERE employee_id = ? LIMIT 1");
    $stmt->execute([$employee_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit();
    }

    echo json_encode(['success' => true, 'value' => $row[$column_name]]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
