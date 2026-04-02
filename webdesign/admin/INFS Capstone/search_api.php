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

$myRole = $_SESSION['role'];
$myId   = $_SESSION['employee_id'];

$query    = trim($_GET['q']        ?? '');
$location = trim($_GET['location'] ?? '');
$org      = trim($_GET['org']      ?? '');
$tenure   = trim($_GET['tenure']   ?? '');
$role     = trim($_GET['role']     ?? '');

if (strlen($query) < 1 && !$location && !$org && !$tenure && !$role) {
    echo json_encode([]);
    exit;
}

// ── Role scope from permissions.php ────────────────────────
$scope  = getScopeClause($myRole, $myId, $pdo);
$where  = [$scope['sql']];
$params = $scope['params'];

// ── view_level SQL ──────────────────────────────────────────
$p = $PERMISSIONS[$myRole] ?? $PERMISSIONS['employee'];

if ($myRole === 'employee') {
    $viewLevelSQL = "
        CASE
            WHEN w.employee_id = :vl_self THEN 'full'
            ELSE 'name_only'
        END
    ";
    $params[':vl_self'] = $myId;
} elseif ($myRole === 'manager') {
    $viewLevelSQL = "
        CASE
            WHEN w.employee_id = :vl_self THEN 'full'
            WHEN w.manager_id  = :vl_mgr  THEN 'full'
            ELSE 'name_only'
        END
    ";
    $params[':vl_self'] = $myId;
    $params[':vl_mgr']  = $myId;
} elseif ($myRole === 'director') {
    $viewLevelSQL = "
        CASE
            WHEN w.employee_id = :vl_self THEN 'full'
            WHEN w.director_id = :vl_dir  THEN 'full'
            ELSE 'name_only'
        END
    ";
    $params[':vl_self'] = $myId;
    $params[':vl_dir']  = $myId;
} elseif ($myRole === 'vp') {
    $viewLevelSQL = "
        CASE
            WHEN w.employee_id = :vl_self THEN 'full'
            WHEN w.vp_id       = :vl_vp   THEN 'full'
            ELSE 'name_only'
        END
    ";
    $params[':vl_self'] = $myId;
    $params[':vl_vp']   = $myId;
} else {
    $viewLevelSQL = "'" . $p['view_fields'] . "'";
}

// ── Search filters ──────────────────────────────────────────
if ($query !== '') {
    $where[]       = "(CONCAT(w.first_name, ' ', w.last_name) LIKE :q
                       OR w.first_name  LIKE :q2
                       OR w.last_name   LIKE :q3
                       OR w.employee_id LIKE :q4)";
    $params[':q']  = '%' . $query . '%';
    $params[':q2'] = '%' . $query . '%';
    $params[':q3'] = '%' . $query . '%';
    $params[':q4'] = '%' . $query . '%';
}

if ($location !== '') {
    $where[]             = 'l.work_city = :location';
    $params[':location'] = $location;
}

if ($org !== '') {
    $where[]        = 'o.org_id = :org';
    $params[':org'] = (int)$org;
}

if ($tenure !== '') {
    switch ($tenure) {
        case '0-1':   $where[] = 'w.tenure < 2';               break;
        case '2-4':   $where[] = 'w.tenure BETWEEN 2 AND 4';   break;
        case '5-9':   $where[] = 'w.tenure BETWEEN 5 AND 9';   break;
        case '10-19': $where[] = 'w.tenure BETWEEN 10 AND 19'; break;
        case '20+':   $where[] = 'w.tenure >= 20';             break;
    }
}

if ($role !== '') {
    $where[]         = 'LOWER(w.role) = LOWER(:role)';
    $params[':role'] = $role;
}

$whereSQL = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        w.employee_id,
        w.first_name,
        w.last_name,
        w.role,
        w.tenure,
        w.birthday,
        j.title,
        j.pay_band,
        j.job_type,
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
        -- Own chain first, then peers
        CASE
            WHEN w.employee_id = :sort_self THEN 0
            WHEN w.director_id = :sort_dir  THEN 0
            WHEN w.manager_id  = :sort_mgr  THEN 0
            ELSE 1
        END ASC,
        w.last_name, w.first_name
    LIMIT 500
");

$params[':sort_self'] = $myId;
$params[':sort_dir']  = $myId;
$params[':sort_mgr']  = $myId;

$stmt->execute($params);
echo json_encode($stmt->fetchAll());
?>
