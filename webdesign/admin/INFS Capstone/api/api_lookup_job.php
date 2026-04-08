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
    // Return all jobs
    try {
        $stmt = $pdo->query("SELECT job_code, title, job_type, pay_band FROM job ORDER BY title");
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'results' => $jobs]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT job_code, title, job_type, pay_band
        FROM job
        WHERE title LIKE ? OR job_code LIKE ?
        ORDER BY title
        LIMIT 10
    ");
    $stmt->execute(['%' . $search . '%', '%' . $search . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'results' => $results, 'query' => $search]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
