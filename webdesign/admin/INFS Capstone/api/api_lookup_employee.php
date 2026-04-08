<?php
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../config/api_config.php';

header('Content-Type: application/json');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== OPENCLAW_API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$search     = $_GET['search']     ?? '';
$first_name = $_GET['first_name'] ?? '';
$last_name  = $_GET['last_name']  ?? '';

if (empty($search) && empty($first_name) && empty($last_name)) {
    http_response_code(400);
    echo json_encode(['error' => 'Provide search, first_name, or last_name']);
    exit;
}

try {
    $conditions = [];
    $params = [];

    if (!empty($search)) {
        $conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    } else {
        if (!empty($first_name)) {
            $conditions[] = "first_name LIKE ?";
            $params[] = '%' . $first_name . '%';
        }
        if (!empty($last_name)) {
            $conditions[] = "last_name LIKE ?";
            $params[] = '%' . $last_name . '%';
        }
    }

    $where = implode(' AND ', $conditions);
    $stmt = $pdo->prepare("
        SELECT employee_id, first_name, last_name, role, org_id, location_id
        FROM workforce
        WHERE $where
        ORDER BY last_name, first_name
        LIMIT 20
    ");
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'results' => $results, 'count' => count($results)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
