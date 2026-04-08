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

$city   = $_GET['city']   ?? '';
$state  = $_GET['state']  ?? '';
$postal = $_GET['postal'] ?? '';
$search = $_GET['search'] ?? ''; // generic fallback search

if (empty($city) && empty($state) && empty($postal) && empty($search)) {
    // Return all locations
    try {
        $stmt = $pdo->query("SELECT location_id, work_city, state, work_postal FROM location ORDER BY state, work_city");
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'results' => $locations]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

try {
    $conditions = [];
    $params = [];

    if (!empty($search)) {
        $conditions[] = "(work_city LIKE ? OR state LIKE ? OR work_postal LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    } else {
        if (!empty($city)) {
            $conditions[] = "work_city LIKE ?";
            $params[] = '%' . $city . '%';
        }
        if (!empty($state)) {
            $conditions[] = "state LIKE ?";
            $params[] = '%' . $state . '%';
        }
        if (!empty($postal)) {
            $conditions[] = "work_postal LIKE ?";
            $params[] = '%' . $postal . '%';
        }
    }

    $where = implode(' AND ', $conditions);
    $stmt = $pdo->prepare("
        SELECT location_id, work_city, state, work_postal
        FROM location
        WHERE $where
        ORDER BY state, work_city
        LIMIT 10
    ");
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'results' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
