<?php
// search_api.php
// Called by employee_search.php via fetch() as user types.
// Returns JSON array of matching employees scoped to the logged-in role.
// IMPORTANT: session + role check happens here too — never trust the front-end alone.

session_start();
header('Content-Type: application/json');

// Block unauthenticated direct calls to this endpoint
if (!isset($_SESSION['authorized'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/db_config.php';

$myRole = $_SESSION['role'];       // e.g. 'employee', 'manager', 'director', 'vp', 'svp', 'sysadmin'
$myId   = $_SESSION['employee_id'];

$query    = trim($_GET['q']        ?? '');
$location = trim($_GET['location'] ?? '');
$org      = trim($_GET['org']      ?? '');
$tenure   = trim($_GET['tenure']   ?? '');

if (strlen($query) < 1 && !$location && !$org && !$tenure) {
    echo json_encode([]);
    exit;
}

$where  = ['1=1'];
$params = [];

// ============================================================
// ROLE-BASED SCOPE
// This is the server-side enforcement. Even if someone calls
// this file directly, they only get rows their role allows.
//
// view_level is a computed column sent back in JSON:
//   'full'      — front-end shows all fields
//   'name_only' — front-end shows name + birthday only
// ============================================================

$viewLevelSQL = "'full'"; // default for most roles — overridden for manager below

switch ($myRole) {

    case 'sysadmin':
        // No restriction — sees every row in the database
        break;

    case 'svp':
        // Sees everyone whose svp_id points to them, plus themselves
        $where[] = '(w.svp_id = :scope_id OR w.employee_id = :scope_self)';
        $params[':scope_id']   = $myId;
        $params[':scope_self'] = $myId;
        break;

    case 'vp':
        // Sees everyone whose vp_id points to them, plus themselves
        $where[] = '(w.vp_id = :scope_id OR w.employee_id = :scope_self)';
        $params[':scope_id']   = $myId;
        $params[':scope_self'] = $myId;
        break;

    case 'director':
        // Sees everyone whose director_id points to them, plus themselves
        $where[] = '(w.director_id = :scope_id OR w.employee_id = :scope_self)';
        $params[':scope_id']   = $myId;
        $params[':scope_self'] = $myId;
        break;

    case 'manager':
        // Full detail: themselves + their direct reports (manager_id = myId)
        // Name only:   peer employees under the same director (different manager)
        $where[] = '(
            w.employee_id = :scope_self
            OR w.manager_id = :scope_mgr
            OR w.director_id = (
                SELECT director_id FROM workforce WHERE employee_id = :scope_dir_lookup
            )
        )';
        $params[':scope_self']       = $myId;
        $params[':scope_mgr']        = $myId;
        $params[':scope_dir_lookup'] = $myId;

        // Compute view_level per row
        $viewLevelSQL = "
            CASE
                WHEN w.employee_id = :vl_self THEN 'full'
                WHEN w.manager_id  = :vl_mgr  THEN 'full'
                ELSE 'name_only'
            END
        ";
        $params[':vl_self'] = $myId;
        $params[':vl_mgr']  = $myId;
        break;

    case 'employee':
    default:
        // Sees only themselves
        $where[] = 'w.employee_id = :scope_self';
        $params[':scope_self'] = $myId;
        break;
}

// ============================================================
// SEARCH FILTERS (applied on top of the role scope above)
// ============================================================

if ($query !== '') {
    $where[]       = "(CONCAT(w.first_name, ' ', w.last_name) LIKE :q
                       OR w.first_name  LIKE :q2
                       OR w.last_name   LIKE :q3
                       OR w.role        LIKE :q4
                       OR w.employee_id LIKE :q5)";
    $params[':q']  = '%' . $query . '%';
    $params[':q2'] = '%' . $query . '%';
    $params[':q3'] = '%' . $query . '%';
    $params[':q4'] = '%' . $query . '%';
    $params[':q5'] = '%' . $query . '%';
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
    ORDER BY w.last_name, w.first_name
    LIMIT 50
");

$stmt->execute($params);
echo json_encode($stmt->fetchAll());
?>
