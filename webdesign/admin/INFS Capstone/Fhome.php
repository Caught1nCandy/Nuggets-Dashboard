<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}

// Database connection
require_once __DIR__ . '/db_config.php';

// Store session variables
$myRole = $_SESSION['role']; 
$myId   = $_SESSION['employee_id'];

// Dashboard scope logic
$dashWhere = "1=0";
$dashParams = [];

if ($myRole === 'svp') {
    $dashWhere = "1=1"; // SVPs see the whole company
} elseif ($myRole === 'vp') {
    $dashWhere = "w.vp_id = ?"; // VPs see their org
    $dashParams = [$myId];
} elseif ($myRole === 'director') {
    $dashWhere = "w.director_id = ?"; // Directors see their department
    $dashParams = [$myId];
} elseif ($myRole === 'manager') {
    $dashWhere = "w.manager_id = ?"; // Managers see their team
    $dashParams = [$myId];
}

// Find personal details
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

// Initialize chart data arrays
$titleData = [];
$payBandData = [];
$roleData = [];
$empPerMgrData = [];
$deptData = [];
$stateData = [];

// Get manager or higher data
if ($myRole !== 'employee') {
    
    // KPI card calculations 
    $stmt = $pdo->prepare("SELECT COUNT(employee_id) as total_emp FROM workforce w WHERE $dashWhere");
    $stmt->execute($dashParams);
    $totalEmployees = $stmt->fetch()['total_emp'];

    $stmt = $pdo->prepare("SELECT COUNT(employee_id) as total_mgrs FROM workforce w WHERE $dashWhere AND role IN ('Manager', 'Director', 'VP', 'SVP')");
    $stmt->execute($dashParams);
    $totalManagers = $stmt->fetch()['total_mgrs'];

    $stmt = $pdo->prepare("SELECT ROUND(AVG(tenure), 1) as avg_tenure FROM workforce w WHERE $dashWhere AND tenure IS NOT NULL");
    $stmt->execute($dashParams);
    $avgTenure = $stmt->fetch()['avg_tenure'] ?? 0;

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT location_id) as total_locs FROM workforce w WHERE $dashWhere AND location_id IS NOT NULL");
    $stmt->execute($dashParams);
    $totalLocations = $stmt->fetch()['total_locs'];

    // Chart queries 
    
    // Job title chart (managers & directors only)
    if (in_array($myRole, ['manager', 'director'])) {
        $stmt = $pdo->prepare("SELECT j.title, COUNT(w.employee_id) as count FROM workforce w JOIN job j ON w.job_code = j.job_code WHERE $dashWhere GROUP BY j.title");
        $stmt->execute($dashParams);
        $titleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Pay band distribution
    $stmt = $pdo->prepare("SELECT j.pay_band, COUNT(w.employee_id) as count FROM workforce w JOIN job j ON w.job_code = j.job_code WHERE $dashWhere AND j.pay_band IS NOT NULL GROUP BY j.pay_band ORDER BY j.pay_band");
    $stmt->execute($dashParams);
    $payBandData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // State data
    $stmt = $pdo->prepare("SELECT l.state, COUNT(w.employee_id) as count FROM workforce w JOIN location l ON w.location_id = l.location_id WHERE $dashWhere AND l.state IS NOT NULL GROUP BY l.state ORDER BY count DESC");
    $stmt->execute($dashParams);
    $stateData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Role distribution (for vp & svp only)
    if (in_array($myRole, ['vp', 'svp'])) {
        $stmt = $pdo->prepare("
            SELECT w.role, COUNT(w.employee_id) as count 
            FROM workforce w 
            WHERE $dashWhere 
            GROUP BY w.role 
            ORDER BY FIELD(w.role, 'Employee', 'Manager', 'Director', 'VP', 'SVP')
        ");
        $stmt->execute($dashParams);
        $roleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Department distribution (director+ only)
    if (in_array($myRole, ['director', 'vp', 'svp'])) {
        $stmt = $pdo->prepare("SELECT o.organization_name, COUNT(w.employee_id) as count FROM workforce w JOIN organization o ON w.org_id = o.org_id WHERE $dashWhere GROUP BY o.organization_name ORDER BY count DESC");
        $stmt->execute($dashParams);
        $deptData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Direct reports per manager (director only)
    if ($myRole === 'director') {
        $stmt = $pdo->prepare("
            SELECT CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name, COUNT(w.employee_id) as count 
            FROM workforce w 
            JOIN workforce mgr ON w.manager_id = mgr.employee_id 
            WHERE $dashWhere 
            GROUP BY w.manager_id
        ");
        $stmt->execute($dashParams);
        $empPerMgrData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Home — Workforce Dashboard</title>
  
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');
    
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    
    /* Brand colors */
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
	}
    body { 
        display: flex;
		flex-direction: column;
		align-items: center;
		padding-bottom: 60px;
	}
    
    /* Main layout */
    .dashboard-container {
		width: 100%;
		max-width: 1200px;
		padding: 32px 24px;
		display: flex;
		flex-direction: column;
		gap: 24px;
	}
    
    .card {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: 10px; padding: 24px;
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
    
    /* Snapshot ui */
    .snapshot-header { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; }
    .snapshot-avatar { width: 60px; height: 60px; border-radius: 12px; background: #ede0f8; color: var(--purple); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; }
    .snapshot-name { font-size: 24px; font-weight: 700; color: var(--purple); }
    .snapshot-role { font-size: 14px; color: var(--muted); }
    .snapshot-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; background: #fcfcfc; padding: 16px; border-radius: 8px; border: 1px solid var(--border); }
    .detail-item label { display: block; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; }
    .detail-item span { font-size: 14px; font-weight: 600; color: var(--text); }
    
    /*KPI card grid */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 20px; text-align: center; border-bottom: 3px solid var(--purple); }
    .kpi-value { font-size: 32px; font-weight: 700; color: var(--purple); margin-bottom: 4px; }
    .kpi-label { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; }
    
    /* Chart layout css */
    .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 24px; }
    .chart-container { position: relative; width: 100%; }
    .chart-tall { height: 400px; }
    .chart-standard { height: 280px; }
    .full-width { grid-column: 1 / -1; } /* Forces a card to span the entire width of the grid */

    /* Leaderboard ui */
    .leaderboard-list {
      display: flex; flex-direction: column; gap: 12px;
      max-height: 280px; overflow-y: auto; padding-right: 8px;
    }
    .leaderboard-list::-webkit-scrollbar { width: 6px; }
    .leaderboard-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
    .leaderboard-list::-webkit-scrollbar-track { background: transparent; }
    .leaderboard-item { display: flex; align-items: center; gap: 12px; }
    .leaderboard-label { flex: 0 0 140px; font-size: 12px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .leaderboard-bar-track { flex: 1; height: 6px; background: #eeeeee; border-radius: 4px; overflow: hidden; }
    .leaderboard-bar-fill { height: 100%; background: linear-gradient(90deg, var(--purple), var(--orange)); border-radius: 4px; }
    .leaderboard-value { flex: 0 0 35px; font-size: 12px; font-weight: 700; color: var(--purple); text-align: right; }
  </style>
</head>
<body>

<?php
include __DIR__ . '/impersonation_banner.php';
$activePage = 'home';
include __DIR__ . '/navbar.php';
?>

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
    
    <?php if ($myRole !== 'manager'): ?>
    <div class="kpi-card">
      <div class="kpi-value"><?= number_format($totalManagers) ?></div>
      <div class="kpi-label">Managers & Leaders</div>
    </div>
    <?php endif; ?>

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
    
    <?php if (in_array($myRole, ['manager', 'director'])): ?>
    <div class="card full-width">
      <div class="card-title">Workforce by Job Title</div>
      <div class="chart-container chart-tall"><canvas id="titleChart"></canvas></div>
    </div>
    <?php endif; ?>
    
    <div class="card">
      <div class="card-title">Pay Band Distribution</div>
      <div class="chart-container chart-standard"><canvas id="payBandChart"></canvas></div>
    </div>

    <div class="card">
      <div class="card-title">Employees by State</div>
      <div class="chart-container chart-standard">
          <div class="leaderboard-list">
              <?php 
              // Scales progress bars relative to the highest state
              $maxState = 0;
              foreach ($stateData as $s) { if ($s['count'] > $maxState) $maxState = $s['count']; }
              foreach ($stateData as $s): 
                  $pct = $maxState > 0 ? round(($s['count'] / $maxState) * 100) : 0;
              ?>
              <div class="leaderboard-item">
                  <div class="leaderboard-label" title="<?= htmlspecialchars($s['state']) ?>"><?= htmlspecialchars($s['state']) ?></div>
                  <div class="leaderboard-bar-track">
                      <div class="leaderboard-bar-fill" style="width: <?= $pct ?>%;"></div>
                  </div>
                  <div class="leaderboard-value"><?= number_format($s['count']) ?></div>
              </div>
              <?php endforeach; ?>
              <?php if (empty($stateData)): ?>
                  <div style="color: var(--muted); font-size: 13px;">No data available.</div>
              <?php endif; ?>
          </div>
      </div>
    </div>

    <?php if (in_array($myRole, ['vp', 'svp'])): ?>
    <div class="card">
      <div class="card-title">Workforce by Role</div>
      <div class="chart-container chart-standard"><canvas id="roleChart"></canvas></div>
    </div>
    <?php endif; ?>

    <?php if (in_array($myRole, ['director', 'vp', 'svp'])): ?>
    <div class="card">
      <div class="card-title">Employees by Department</div>
      <div class="chart-container chart-standard">
          <div class="leaderboard-list">
              <?php 
              // Scale progress bars relative to the largest department
              $maxDept = 0;
              foreach ($deptData as $d) { if ($d['count'] > $maxDept) $maxDept = $d['count']; }
              foreach ($deptData as $d): 
                  $pct = $maxDept > 0 ? round(($d['count'] / $maxDept) * 100) : 0;
              ?>
              <div class="leaderboard-item">
                  <div class="leaderboard-label" title="<?= htmlspecialchars($d['organization_name']) ?>"><?= htmlspecialchars($d['organization_name']) ?></div>
                  <div class="leaderboard-bar-track">
                      <div class="leaderboard-bar-fill" style="width: <?= $pct ?>%;"></div>
                  </div>
                  <div class="leaderboard-value"><?= number_format($d['count']) ?></div>
              </div>
              <?php endforeach; ?>
              <?php if (empty($deptData)): ?>
                  <div style="color: var(--muted); font-size: 13px;">No data available.</div>
              <?php endif; ?>
          </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($myRole === 'director'): ?>
    <div class="card">
      <div class="card-title">Employees per Manager</div>
      <div class="chart-container chart-standard"><canvas id="empPerMgrChart"></canvas></div>
    </div>
    <?php endif; ?>

  </div>
  <?php endif; ?>

</div>

<script>
<?php if ($myRole !== 'employee'): ?>
  
  const brandPurple = '#4D148C';
  const brandOrange = '#FF6200';
  
  // Custom color palette 
  const palette = [brandPurple, brandOrange, '#6b2bc2', '#ff8533', '#1a56c4', '#ede0f8', '#ffccaa', '#009966', '#cc0000', '#2E0C54', '#FF9F66'];

  // Custom configuration for Doughnut/Pie charts to show % values 
  const percentageTooltipConfig = {
    callbacks: {
      label: function(context) {
        let label = context.label || '';
        if (label) { label += ': '; }
        let value = context.parsed;
        let total = context.dataset.data.reduce((a, b) => a + b, 0);
        let percentage = Math.round((value / total) * 100) + '%';
        return label + value + ' (' + percentage + ')';
      }
    }
  };

  // Title Chart
  <?php if (in_array($myRole, ['manager', 'director'])): ?>
  const titleData = <?= json_encode($titleData) ?>;
  new Chart(document.getElementById('titleChart'), {
    type: 'doughnut',
    data: {
      labels: titleData.map(d => d.title || 'Unknown'),
      datasets: [{
        data: titleData.map(d => d.count),
        backgroundColor: palette,
        borderWidth: 0
      }]
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        plugins: { 
            legend: { position: 'right' },
            tooltip: percentageTooltipConfig
        } 
    }
  });
  <?php endif; ?>

  // Payband Chart
  const payBandData = <?= json_encode($payBandData) ?>;
  new Chart(document.getElementById('payBandChart'), {
    type: 'bar',
    data: {
      labels: payBandData.map(d => d.pay_band || 'Unknown'),
      datasets: [{
        label: 'Employees',
        data: payBandData.map(d => d.count),
        backgroundColor: brandPurple,
        borderRadius: 4
      }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });

  // Role Breakdown
  <?php if (in_array($myRole, ['vp', 'svp'])): ?>
  const roleData = <?= json_encode($roleData) ?>;
  new Chart(document.getElementById('roleChart'), {
    type: 'doughnut',
    data: {
      labels: roleData.map(d => d.role || 'Unknown'),
      datasets: [{
        data: roleData.map(d => d.count),
        // 5 distinct colors mapped to the 5 roles (ordered via SQL)
        backgroundColor: [brandOrange, brandPurple, '#1a56c4', '#009966', '#e834eb'],
        borderWidth: 0
      }]
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        plugins: { 
            legend: { position: 'right' },
            tooltip: percentageTooltipConfig
        } 
    }
  });
  <?php endif; ?>
  
  // Employees Per Manager
  <?php if ($myRole === 'director'): ?>
  const empPerMgrData = <?= json_encode($empPerMgrData) ?>;
  new Chart(document.getElementById('empPerMgrChart'), {
    type: 'bar',
    data: {
      labels: empPerMgrData.map(d => d.manager_name || 'Unknown'),
      datasets: [{
        label: 'Employees on Team',
        data: empPerMgrData.map(d => d.count),
        backgroundColor: '#1a56c4',
        borderRadius: 4
      }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });
  <?php endif; ?>

<?php endif; ?>
</script>

</body>
</html>
