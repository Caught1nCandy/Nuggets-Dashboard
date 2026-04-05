<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/permissions.php';

$myRole = $_SESSION['role'];
$myId   = $_SESSION['employee_id'];

// Get the scope clause based on role 
$scope  = getScopeClause($myRole, $myId, $pdo);
$whereSQL = $scope['sql'];
$params   = $scope['params'];

// KPI Cards Data 
// Total Employees in scope
$stmt = $pdo->prepare("SELECT COUNT(employee_id) as total_emp FROM workforce w WHERE $whereSQL");
$stmt->execute($params);
$totalEmployees = $stmt->fetch()['total_emp'];

// Number of Managers in scope
$stmt = $pdo->prepare("SELECT COUNT(employee_id) as total_mgrs FROM workforce w WHERE $whereSQL AND role IN ('Manager', 'Director', 'VP', 'SVP')");
$stmt->execute($params);
$totalManagers = $stmt->fetch()['total_mgrs'];

// Average Tenure in scope
$stmt = $pdo->prepare("SELECT ROUND(AVG(tenure), 1) as avg_tenure FROM workforce w WHERE $whereSQL AND tenure IS NOT NULL");
$stmt->execute($params);
$avgTenure = $stmt->fetch()['avg_tenure'] ?? 0;

// Locations Covered in scope
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT location_id) as total_locs FROM workforce w WHERE $whereSQL AND location_id IS NOT NULL");
$stmt->execute($params);
$totalLocations = $stmt->fetch()['total_locs'];

// Personal Snapshot Data (For Employee View or Top Section)
$stmt = $pdo->prepare("
    SELECT
        w.first_name, w.last_name, w.role, w.tenure, w.anniversary,
        j.title, o.organization_name, l.work_city, l.state,
        mgr.first_name AS manager_first, mgr.last_name AS manager_last
    FROM workforce w
    LEFT JOIN job j ON j.job_code = w.job_code
    LEFT JOIN organization o ON o.org_id = w.org_id
    LEFT JOIN location l ON l.location_id = w.location_id
    LEFT JOIN workforce mgr ON mgr.employee_id = w.manager_id
    WHERE w.employee_id = ?
");
$stmt->execute([$myId]);
$myDetails = $stmt->fetch();

// --- Data for Charts (Passed to JS later) ---

// Roles Distribution
$stmt = $pdo->prepare("SELECT role, COUNT(employee_id) as count FROM workforce w WHERE $whereSQL GROUP BY role");
$stmt->execute($params);
$rolesData = $stmt->fetchAll();

// Job Type Distribution
$stmt = $pdo->prepare("SELECT j.job_type, COUNT(w.employee_id) as count FROM workforce w JOIN job j ON w.job_code = j.job_code WHERE $whereSQL GROUP BY j.job_type");
$stmt->execute($params);
$jobTypeData = $stmt->fetchAll();

// Location Distribution
$stmt = $pdo->prepare("SELECT l.state, COUNT(w.employee_id) as count FROM workforce w JOIN location l ON w.location_id = l.location_id WHERE $whereSQL GROUP BY l.state");
$stmt->execute($params);
$locationData = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Home — Workforce Dashboard</title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <style>
    @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --purple: #4D148C;
      --orange: #FF6200;
      --bg:     #f4f4f4;
      --surface:#ffffff;
      --border: #e0e0e0;
      --text:   #1a1a1a;
      --muted:  #888888;
    }

    html, body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Open Sans', sans-serif;
      min-height: 100vh;
      margin: 0;
      padding: 0;
    }

    body { display: flex; flex-direction: column; align-items: center; padding-bottom: 60px; }

    /* Main Container */
    .dashboard-container {
      width: 100%;
      max-width: 1200px;
      padding: 32px 24px;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    /* Common Card Style */
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 24px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      position: relative;
    }
    
    .card-top-accent::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      border-radius: 10px 10px 0 0;
      background: linear-gradient(90deg, var(--purple), var(--orange));
    }

    .card-title {
      font-size: 14px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--muted);
      margin-bottom: 16px;
    }

    /* Personal Snapshot */
    .snapshot-header { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; }
    .snapshot-avatar {
      width: 60px; height: 60px; border-radius: 12px;
      background: #ede0f8; color: var(--purple);
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; font-weight: 700;
    }
    .snapshot-name { font-size: 24px; font-weight: 700; color: var(--purple); }
    .snapshot-role { font-size: 14px; color: var(--muted); }
    
    .snapshot-details {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;
      background: #fcfcfc; padding: 16px; border-radius: 8px; border: 1px solid var(--border);
    }
    .detail-item label { display: block; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; }
    .detail-item span { font-size: 14px; font-weight: 600; color: var(--text); }

    /* KPI Grid */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .kpi-card {
      background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
      padding: 20px; text-align: center; border-bottom: 3px solid var(--purple);
    }
    .kpi-value { font-size: 32px; font-weight: 700; color: var(--purple); margin-bottom: 4px; }
    .kpi-label { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; }

    /* Chart Grid */
    .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
    .chart-container { position: relative; height: 250px; width: 100%; }

  </style>
</head>
<body>

<?php include __DIR__ . '/impersonation_banner.php'; ?>
<?php $activePage = 'home'; include __DIR__ . '/navbar.php'; ?>

<div class="dashboard-container">

  <div class="card card-top-accent">
    <div class="snapshot-header">
      <div class="snapshot-avatar"><?= substr($myDetails['first_name'],0,1) . substr($myDetails['last_name'],0,1) ?></div>
      <div>
        <div class="snapshot-name"><?= htmlspecialchars($myDetails['first_name'] . ' ' . $myDetails['last_name']) ?></div>
        <div class="snapshot-role"><?= htmlspecialchars($myDetails['title'] ?? $myDetails['role']) ?></div>
      </div>
    </div>
    <div class="snapshot-details">
      <div class="detail-item"><label>Department</label><span><?= htmlspecialchars($myDetails['organization_name'] ?? '—') ?></span></div>
      <div class="detail-item"><label>Location</label><span><?= htmlspecialchars(($myDetails['work_city'] ?? '') . ', ' . ($myDetails['state'] ?? '—')) ?></span></div>
      <div class="detail-item"><label>Manager</label><span><?= htmlspecialchars(($myDetails['manager_first'] ?? '') . ' ' . ($myDetails['manager_last'] ?? '—')) ?></span></div>
      <div class="detail-item"><label>Tenure</label><span><?= htmlspecialchars($myDetails['tenure'] ?? '0') ?> Years</span></div>
    </div>
  </div>

  <?php if ($myRole !== 'employee'): ?>
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-value"><?= number_format($totalEmployees) ?></div>
      <div class="kpi-label">Total Employees</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-value"><?= number_format($totalManagers) ?></div>
      <div class="kpi-label">Managers & Leaders</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-value"><?= $avgTenure ?></div>
      <div class="kpi-label">Avg Tenure (Yrs)</div>
    </div>
    <div class="kpi-card" style="border-bottom-color: var(--orange);">
      <div class="kpi-value"><?= number_format($totalLocations) ?></div>
      <div class="kpi-label">Locations Covered</div>
    </div>
  </div>

  <div class="chart-grid">
    <div class="card">
      <div class="card-title">Workforce by Role</div>
      <div class="chart-container"><canvas id="roleChart"></canvas></div>
    </div>
    <div class="card">
      <div class="card-title">Workforce by Job Type</div>
      <div class="chart-container"><canvas id="jobTypeChart"></canvas></div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
<?php if ($myRole !== 'employee'): ?>
  // Inject PHP data into JS variables 
  const rolesData = <?= json_encode($rolesData) ?>;
  const jobTypeData = <?= json_encode($jobTypeData) ?>;
  
  const brandPurple = '#4D148C';
  const brandOrange = '#FF6200';
  const palette = [brandPurple, brandOrange, '#ede0f8', '#fff0e6', '#1a56c4'];

  // Role Chart (Donut)
  new Chart(document.getElementById('roleChart'), {
    type: 'doughnut',
    data: {
      labels: rolesData.map(d => d.role || 'Unknown'),
      datasets: [{
        data: rolesData.map(d => d.count),
        backgroundColor: palette,
        borderWidth: 0
      }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
  });

  // Job Type Chart (Bar)
  new Chart(document.getElementById('jobTypeChart'), {
    type: 'bar',
    data: {
      labels: jobTypeData.map(d => d.job_type || 'Unknown'),
      datasets: [{
        label: 'Employees',
        data: jobTypeData.map(d => d.count),
        backgroundColor: brandPurple,
        borderRadius: 4
      }]
    },
    options: { 
      responsive: true, 
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });
<?php endif; ?>
</script>

</body>
</html>