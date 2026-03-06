<?php
// search_api.php
// Called by employee_search.php via fetch() as user types
// Returns JSON array of matching employees

header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$query    = trim($_GET['q']          ?? '');
$location = trim($_GET['location']   ?? '');
$org      = trim($_GET['org']        ?? '');
$tenure   = trim($_GET['tenure']     ?? '');

// Need at least 1 character to search
if (strlen($query) < 1 && !$location && !$org && !$tenure) {
    echo json_encode([]);
    exit;
}

$where  = ['1=1'];
$params = [];

// Name or role search
if ($query !== '') {
    $where[]        = "(CONCAT(w.first_name, ' ', w.last_name) LIKE :q
                        OR w.first_name LIKE :q2
                        OR w.last_name  LIKE :q3
                        OR w.role       LIKE :q4
                        OR w.employee_id LIKE :q5)";
    $params[':q']   = '%' . $query . '%';
    $params[':q2']  = '%' . $query . '%';
    $params[':q3']  = '%' . $query . '%';
    $params[':q4']  = '%' . $query . '%';
    $params[':q5']  = '%' . $query . '%';
}

// Filter: location (city)
if ($location !== '') {
    $where[]            = 'l.work_city = :location';
    $params[':location'] = $location;
}

// Filter: organization/department
if ($org !== '') {
    $where[]     = 'o.org_id = :org';
    $params[':org'] = (int)$org;
}

// Filter: years employed (tenure band)
if ($tenure !== '') {
    switch ($tenure) {
        case '0-1':   $where[] = 'w.tenure < 2';              break;
        case '2-4':   $where[] = 'w.tenure BETWEEN 2 AND 4';  break;
        case '5-9':   $where[] = 'w.tenure BETWEEN 5 AND 9';  break;
        case '10-19': $where[] = 'w.tenure BETWEEN 10 AND 19';break;
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
        j.title,
        j.pay_band,
        o.organization_name,
        l.work_city,
        l.state
    FROM workforce w
    LEFT JOIN job          j ON j.job_code    = w.job_code
    LEFT JOIN organization o ON o.org_id      = w.org_id
    LEFT JOIN location     l ON l.location_id = w.location_id
    WHERE $whereSQL
    ORDER BY w.last_name, w.first_name
    LIMIT 10
");

$stmt->execute($params);
echo json_encode($stmt->fetchAll());
?>
