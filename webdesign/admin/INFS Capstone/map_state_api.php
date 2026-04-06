<?php
// map_state_api.php
// Returns scoped employees in a state + total count for out-of-scope calculation

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['authorized'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/permissions.php';

$state  = trim($_GET['state'] ?? '');
$myRole = $_SESSION['role'];
$myId   = $_SESSION['employee_id'];

if ($state === '') {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

// Total employees in this state regardless of scope
$stmtTotal = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM workforce w
    JOIN location l ON l.location_id = w.location_id
    WHERE l.state = ?
");
$stmtTotal->execute([$state]);
$totalInState = (int)$stmtTotal->fetch()['total'];

// Scoped employees in this state
$scope  = getScopeClause($myRole, $myId, $pdo);
$where  = [$scope['sql'], 'l.state = :state'];
$params = $scope['params'];
$params[':state'] = $state;

// view_level per role
$p = $PERMISSIONS[$myRole] ?? $PERMISSIONS['employee'];

if ($myRole === 'employee') {
    $viewLevelSQL = "CASE WHEN w.employee_id = :vl_self THEN 'full' ELSE 'name_only' END";
    $params[':vl_self'] = $myId;
} elseif ($myRole === 'manager') {
    $viewLevelSQL = "CASE WHEN w.employee_id = :vl_self THEN 'full' WHEN w.manager_id = :vl_mgr THEN 'full' ELSE 'name_only' END";
    $params[':vl_self'] = $myId;
    $params[':vl_mgr']  = $myId;
} elseif ($myRole === 'director') {
    $viewLevelSQL = "CASE WHEN w.employee_id = :vl_self THEN 'full' WHEN w.director_id = :vl_dir THEN 'full' ELSE 'name_only' END";
    $params[':vl_self'] = $myId;
    $params[':vl_dir']  = $myId;
} else {
    $viewLevelSQL = "'" . $p['view_fields'] . "'";
}

$whereSQL = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        w.employee_id,
        w.first_name,
        w.last_name,
        w.role,
        j.title,
        o.organization_name,
        l.work_city,
        l.state,
        ($viewLevelSQL) AS view_level
    FROM workforce w
    LEFT JOIN job          j ON j.job_code    = w.job_code
    LEFT JOIN organization o ON o.org_id      = w.org_id
    LEFT JOIN location     l ON l.location_id = w.location_id
    WHERE $whereSQL
    ORDER BY
        CASE w.role
            WHEN 'SVP'      THEN 1
            WHEN 'VP'       THEN 2
            WHEN 'Director' THEN 3
            WHEN 'Manager'  THEN 4
            WHEN 'Employee' THEN 5
            ELSE 6
        END ASC,
        w.last_name, w.first_name
    LIMIT 500
");

$stmt->execute($params);
$employees = $stmt->fetchAll();

echo json_encode([
    'employees'     => $employees,
    'total_in_state'=> $totalInState,
    'scoped_count'  => count($employees),
    'out_of_scope'  => $totalInState - count($employees),
]);
