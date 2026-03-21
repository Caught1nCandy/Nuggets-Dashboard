<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['authorized'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/permissions.php';

$myRole   = $_SESSION['role'];
$myId     = $_SESSION['employee_id'];
$targetId = trim($_GET['id'] ?? '');

if ($targetId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No employee ID provided']);
    exit;
}

$viewLevel = getViewLevel($myRole, $myId, $targetId, $pdo);

if ($viewLevel === 'none') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// ── Fetch target employee ───────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        w.employee_id,
        w.first_name,
        w.last_name,
        w.role,
        w.tenure,
        w.anniversary,
        w.birthday,
        j.title,
        j.pay_band,
        j.job_type,
        o.organization_name,
        l.work_city,
        l.state,
        mgr.first_name  AS manager_first,
        mgr.last_name   AS manager_last,
        mgr.employee_id AS manager_id,
        mgr.role        AS manager_role
    FROM workforce w
    LEFT JOIN job          j   ON j.job_code      = w.job_code
    LEFT JOIN organization o   ON o.org_id        = w.org_id
    LEFT JOIN location     l   ON l.location_id   = w.location_id
    LEFT JOIN workforce    mgr ON mgr.employee_id = w.manager_id
    WHERE w.employee_id = ?
    LIMIT 1
");
$stmt->execute([$targetId]);
$emp = $stmt->fetch();

if (!$emp) {
    http_response_code(404);
    echo json_encode(['error' => 'Employee not found']);
    exit;
}

$emp['view_level'] = $viewLevel;

// ── Fetch direct reports ────────────────────────────────────
$subordinates = [];

if (canSeeSubordinates($myRole) && $viewLevel !== 'none') {
    $stmt = $pdo->prepare("
        SELECT
            w.employee_id,
            w.first_name,
            w.last_name,
            w.role,
            w.tenure,
            w.birthday,
            j.title,
            l.work_city,
            l.state
        FROM workforce w
        LEFT JOIN job      j ON j.job_code    = w.job_code
        LEFT JOIN location l ON l.location_id = w.location_id
        WHERE w.manager_id = ?
        ORDER BY w.last_name, w.first_name
    ");
    $stmt->execute([$targetId]);
    $reports = $stmt->fetchAll();

    foreach ($reports as $report) {
        $childLevel = getViewLevel($myRole, $myId, $report['employee_id'], $pdo);
        // If we only have name_only on the parent, cap children at name_only too
        if ($viewLevel === 'name_only' && $childLevel === 'full') {
            $childLevel = 'name_only';
        }
        if ($childLevel !== 'none') {
            $report['view_level'] = $childLevel;
            $subordinates[] = $report;
        }
    }
}

echo json_encode(['employee' => $emp, 'subordinates' => $subordinates]);
