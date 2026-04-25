<?php
// ============================================================
// sysadmin_test.php — Sysadmin Testing Panel
// Access controlled by $_SESSION['is_sysadmin'] — NOT role.
// This means it stays accessible even while impersonating.
// ============================================================
session_start();
if (!isset($_SESSION['is_sysadmin']) || $_SESSION['is_sysadmin'] !== true) {
    header("Location: FEDEXHR.php");
    exit();
}
require_once __DIR__ . '/db_config.php';

// ── Handle form actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'impersonate' && !empty($_POST['employee_id'])) {
        $empId = $_POST['employee_id'];
        $stmt = $pdo->prepare("
            SELECT w.employee_id, w.first_name, w.last_name, w.role
            FROM workforce w
            WHERE w.employee_id = ?
            LIMIT 1
        ");
        $stmt->execute([$empId]);
        $emp = $stmt->fetch();
        if ($emp) {
            $_SESSION['employee_id'] = $emp['employee_id'];
            $_SESSION['role']        = strtolower($emp['role']);
            $_SESSION['first_name']  = $emp['first_name'];
            $_SESSION['last_name']   = $emp['last_name'];
        }
    }
    if ($_POST['action'] === 'stop_impersonate') {
        $_SESSION['employee_id'] = '0';
        $_SESSION['role']        = 'sysadmin';
        $_SESSION['first_name']  = 'Sys';
        $_SESSION['last_name']   = 'Admin';
    }
    header("Location: sysadmin_test.php");
    exit();
}

// ── Search API (called via AJAX) ──────────────────────────────
if (isset($_GET['search'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['search']);
    if (strlen($q) < 1) {
        echo json_encode([]);
        exit();
    }
    $stmt = $pdo->prepare("
        SELECT w.employee_id, w.first_name, w.last_name, w.role,
               o.organization_name
        FROM workforce w
        LEFT JOIN organization o ON o.org_id = w.org_id
        WHERE w.first_name LIKE ?
           OR w.last_name  LIKE ?
           OR w.employee_id LIKE ?
           OR CONCAT(w.first_name, ' ', w.last_name) LIKE ?
        ORDER BY w.last_name, w.first_name
        LIMIT 10
    ");
    $like = "%{$q}%";
    $stmt->execute([$like, $like, $like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── Load employee lists ───────────────────────────────────────
$roles = ['Employee', 'Manager', 'Director', 'VP', 'SVP'];
$employeesByRole = [];
foreach ($roles as $r) {
    $stmt = $pdo->prepare("
        SELECT employee_id, first_name, last_name
        FROM workforce
        WHERE LOWER(role) = LOWER(?)
        ORDER BY last_name, first_name
    ");
    $stmt->execute([$r]);
    $employeesByRole[$r] = $stmt->fetchAll();
}

$isImpersonating = ($_SESSION['role'] !== 'sysadmin');
$currentName     = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$currentRole     = $_SESSION['role'];
$currentId       = $_SESSION['employee_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sysadmin Test Panel</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --purple: #4D148C; --orange: #FF6200;
      --red: #c0392b; --green: #1a7a4a;
      --bg: #f4f4f4; --surface: #ffffff;
      --border: #e0e0e0; --text: #1a1a1a; --muted: #888888;
    }
    html, body { background: var(--bg); color: var(--text); font-family: 'Open Sans', sans-serif; min-height: 100vh; }
    body { display: flex; flex-direction: column; align-items: center; padding-bottom: 60px; }

    .site-header { width: 100%; background-color: #1a1a1a; display: flex; align-items: center; padding: 0 24px; gap: 16px; min-height: 56px; }
    .site-header .page-title { color: #ffffff; font-size: 16px; font-weight: 700; }
    .site-header .badge { background: var(--orange); color: #fff; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 4px; letter-spacing: 0.08em; text-transform: uppercase; }
    .site-header .go-dashboard { margin-left: auto; color: rgba(255,255,255,0.7); text-decoration: none; font-size: 13px; font-weight: 600; padding: 8px 14px; border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; transition: all 0.15s; }
    .site-header .go-dashboard:hover { background: rgba(255,255,255,0.1); color: #fff; }

    .wrapper { width: 100%; max-width: 860px; padding: 32px 24px 0; display: flex; flex-direction: column; gap: 24px; }

    .status-banner { border-radius: 10px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .status-banner.sysadmin { background: #f0f0f0; border: 1px solid var(--border); }
    .status-banner.impersonating { background: #fff8e6; border: 2px solid var(--orange); }
    .status-left { display: flex; align-items: center; gap: 10px; }
    .status-icon { font-size: 22px; }
    .status-text strong { display: block; font-size: 14px; font-weight: 700; }
    .status-text span { font-size: 12px; color: var(--muted); }
    .stop-btn { background: var(--red); color: #fff; border: none; border-radius: 7px; padding: 9px 18px; font-size: 13px; font-family: 'Open Sans', sans-serif; font-weight: 700; cursor: pointer; transition: opacity 0.15s; }
    .stop-btn:hover { opacity: 0.85; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .card-header { padding: 14px 20px; border-bottom: 1px solid var(--border); }
    .card-header h2 { font-size: 14px; font-weight: 700; color: var(--text); }
    .card-header p { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .card-body { padding: 20px; }

    /* ── Role grid ── */
    .role-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
    .role-panel { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .role-panel-header { padding: 10px 14px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; display: flex; align-items: center; justify-content: space-between; }
    .role-panel-header .count { font-weight: 400; opacity: 0.7; font-size: 11px; }
    .role-panel.Employee .role-panel-header { background: #ede0f8; color: var(--purple); }
    .role-panel.Manager  .role-panel-header { background: #fff0e6; color: var(--orange); }
    .role-panel.Director .role-panel-header { background: #e6f0ff; color: #1a56c4; }
    .role-panel.VP       .role-panel-header { background: #e6faf0; color: #1a7a4a; }
    .role-panel.SVP      .role-panel-header { background: #fff8e6; color: #b07000; }
    .role-panel select { width: 100%; padding: 10px 12px; border: none; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); font-size: 13px; font-family: 'Open Sans', sans-serif; color: var(--text); background: var(--bg); outline: none; cursor: pointer; }
    .role-panel select:focus { background: #fff; }
    .role-panel .impersonate-btn { display: block; width: 100%; padding: 10px; border: none; font-size: 13px; font-family: 'Open Sans', sans-serif; font-weight: 700; cursor: pointer; transition: opacity 0.15s; text-align: center; }
    .role-panel.Employee .impersonate-btn { background: #ede0f8; color: var(--purple); }
    .role-panel.Manager  .impersonate-btn { background: #fff0e6; color: var(--orange); }
    .role-panel.Director .impersonate-btn { background: #e6f0ff; color: #1a56c4; }
    .role-panel.VP       .impersonate-btn { background: #e6faf0; color: #1a7a4a; }
    .role-panel.SVP      .impersonate-btn { background: #fff8e6; color: #b07000; }
    .role-panel .impersonate-btn:hover { opacity: 0.75; }
    .role-panel .impersonate-btn:disabled { opacity: 0.35; cursor: not-allowed; }

    /* ── Search box ── */
    .search-wrap { margin-bottom: 4px; }
    .search-wrap input {
      width: 100%; padding: 11px 16px; border: 1px solid var(--border);
      border-radius: 8px; font-size: 14px; font-family: 'Open Sans', sans-serif;
      outline: none; transition: border-color 0.15s;
    }
    .search-wrap input:focus { border-color: var(--purple); }
    .search-results {
      background: white; border: 1px solid var(--border); border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.12);
      margin-top: 4px;
      display: none;
    }
    .search-results.visible { display: block; }
    .search-result-item {
      padding: 10px 16px; cursor: pointer; border-bottom: 1px solid #f0f0f0;
      display: flex; align-items: center; justify-content: space-between;
      transition: background 0.1s;
    }
    .search-result-item:last-child { border-bottom: none; }
    .search-result-item:hover { background: #f8f6ff; }
    .result-name { font-size: 14px; font-weight: 600; color: var(--text); }
    .result-meta { font-size: 12px; color: var(--muted); margin-top: 1px; }
    .result-role { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; flex-shrink: 0; }
    .result-role.employee  { background: #ede0f8; color: var(--purple); }
    .result-role.manager   { background: #fff0e6; color: var(--orange); }
    .result-role.director  { background: #e6f0ff; color: #1a56c4; }
    .result-role.vp        { background: #e6faf0; color: #1a7a4a; }
    .result-role.svp       { background: #fff8e6; color: #b07000; }
    .search-empty { padding: 16px; text-align: center; color: var(--muted); font-size: 13px; }

    #search-impersonate-form { display: none; }
  </style>
</head>
<body>

<div class="site-header">
  <span class="page-title">&#9881; Sysadmin Test Panel</span>
  <span class="badge">Testing Only</span>
  <a href="Fhome.php" class="go-dashboard">&#8594; Go to Dashboard</a>
</div>

<div class="wrapper">

  <!-- Status banner -->
  <div class="status-banner <?= $isImpersonating ? 'impersonating' : 'sysadmin' ?>">
    <div class="status-left">
      <div class="status-icon"><?= $isImpersonating ? '🎭' : '🛡️' ?></div>
      <div class="status-text">
        <?php if ($isImpersonating): ?>
          <strong>Impersonating: <?= htmlspecialchars($currentName) ?></strong>
          <span>Role: <?= htmlspecialchars(ucfirst($currentRole)) ?> &nbsp;·&nbsp; ID: <?= htmlspecialchars($currentId) ?> &nbsp;·&nbsp; Sysadmin flag active</span>
        <?php else: ?>
          <strong>Logged in as Sysadmin</strong>
          <span>Not impersonating anyone. All pages will show sysadmin view.</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($isImpersonating): ?>
      <form method="POST">
        <input type="hidden" name="action" value="stop_impersonate">
        <button type="submit" class="stop-btn">&#9632; Stop Impersonating</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Role panels -->
  <div class="card">
    <div class="card-header">
      <h2>&#128100; Impersonate by Role</h2>
      <p>Pick a role, choose a specific person, then click the button.</p>
    </div>
    <div class="card-body">
      <div class="role-grid">
        <?php foreach ($roles as $r):
          $list = $employeesByRole[$r];
        ?>
        <div class="role-panel <?= $r ?>">
          <div class="role-panel-header">
            <?= htmlspecialchars($r) ?>
            <span class="count"><?= count($list) ?> found</span>
          </div>
          <form method="POST">
            <input type="hidden" name="action" value="impersonate">
            <select name="employee_id" <?= empty($list) ? 'disabled' : '' ?>>
              <?php if (empty($list)): ?>
                <option value="">— No <?= $r ?>s found —</option>
              <?php else: ?>
                <option value="">— Select a <?= $r ?> —</option>
                <?php foreach ($list as $emp): ?>
                  <option value="<?= htmlspecialchars($emp['employee_id']) ?>"
                    <?= $emp['employee_id'] === $currentId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?>
                    (<?= htmlspecialchars($emp['employee_id']) ?>)
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
            <button type="submit" class="impersonate-btn" <?= empty($list) ? 'disabled' : '' ?>>
              View as this <?= $r ?>
            </button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Quick search card -->
  <div class="card">
    <div class="card-header">
      <h2>🔍 Quick Search</h2>
      <p>Search by name or employee ID and click a result to impersonate instantly.</p>
    </div>
    <div class="card-body">
      <div class="search-wrap">
        <input type="text" id="emp-search" placeholder="Type a name or employee ID..." autocomplete="off">
        <div class="search-results" id="search-results"></div>
      </div>
    </div>
  </div>

</div>

<!-- Hidden form for search impersonation -->
<form method="POST" id="search-impersonate-form">
  <input type="hidden" name="action" value="impersonate">
  <input type="hidden" name="employee_id" id="search-emp-id">
</form>

<script>
const searchInput   = document.getElementById('emp-search');
const searchResults = document.getElementById('search-results');
const searchForm    = document.getElementById('search-impersonate-form');
const searchEmpId   = document.getElementById('search-emp-id');

let debounceTimer = null;

searchInput.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  const q = searchInput.value.trim();
  if (q.length < 1) {
    searchResults.innerHTML = '';
    searchResults.classList.remove('visible');
    return;
  }
  debounceTimer = setTimeout(() => fetchResults(q), 250);
});

async function fetchResults(q) {
  const res  = await fetch('sysadmin_test.php?search=' + encodeURIComponent(q));
  const data = await res.json();
  renderResults(data);
}

function renderResults(data) {
  if (data.length === 0) {
    searchResults.innerHTML = '<div class="search-empty">No employees found</div>';
    searchResults.classList.add('visible');
    return;
  }
  searchResults.innerHTML = data.map(emp => `
    <div class="search-result-item" onclick="impersonate('${emp.employee_id}')">
      <div>
        <div class="result-name">${emp.first_name} ${emp.last_name}</div>
        <div class="result-meta">ID: ${emp.employee_id} &nbsp;·&nbsp; ${emp.organization_name || '—'}</div>
      </div>
      <span class="result-role ${emp.role.toLowerCase()}">${emp.role}</span>
    </div>
  `).join('');
  searchResults.classList.add('visible');
}

function impersonate(empId) {
  searchEmpId.value = empId;
  searchForm.submit();
}
</script>

</body>
</html>
