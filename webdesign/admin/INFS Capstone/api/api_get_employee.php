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

$employee_id = $_GET['employee_id'] ?? '';
if (empty($employee_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'employee_id required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            w.employee_id, w.first_name, w.last_name,
            w.tenure, w.anniversary, w.birthday, w.role,
            w.job_code, w.org_id, w.location_id,
            w.manager_id, w.director_id, w.vp_id, w.svp_id,
            j.title AS job_title, j.job_type, j.pay_band,
            o.organization_name,
            l.work_city, l.state, l.work_postal
        FROM workforce w
        LEFT JOIN job j ON w.job_code = j.job_code
        LEFT JOIN organization o ON w.org_id = o.org_id
        LEFT JOIN location l ON w.location_id = l.location_id
        WHERE w.employee_id = ?
    ");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        http_response_code(404);
        echo json_encode(['error' => 'Employee not found']);
        exit;
    }

    echo json_encode(['success' => true, 'employee' => $employee]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
