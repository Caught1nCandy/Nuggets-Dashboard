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

$search = $_GET['search'] ?? '';
if (empty($search)) {
    // No search term — return all orgs
    try {
        $stmt = $pdo->query("SELECT org_id, organization_name FROM organization ORDER BY organization_name");
        $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'results' => $orgs]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT org_id, organization_name
        FROM organization
        WHERE organization_name LIKE ?
        ORDER BY organization_name
        LIMIT 10
    ");
    $stmt->execute(['%' . $search . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'results' => $results, 'query' => $search]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
