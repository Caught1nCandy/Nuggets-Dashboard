<?php
// ============================================================
// sysadmin_test.php — Sysadmin Testing Panel
// Access controlled by $_SESSION['is_sysadmin'] — NOT role.
// This means it stays accessible even while impersonating.
// ============================================================
session_start();

// Gate: must be a sysadmin (the flag that never gets overwritten)
if (!isset($_SESSION['is_sysadmin']) || $_SESSION['is_sysadmin'] !== true) {
    header("Location: FEDEXHR.php");
    exit();
}

require_once __DIR__ . '/db_config.php';

// ============================================================
// Handle form actions
// ============================================================

// Action: impersonate a specific employee
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
            // Overwrite the active session identity — is_sysadmin stays untouched
            $_SESSION['employee_id'] = $emp['employee_id'];
            $_SESSION['role']        = strtolower($emp['role']);
            $_SESSION['first_name']  = $emp['first_name'];
            $_SESSION['last_name']   = $emp['last_name'];
        }
    }

    // Action: stop impersonating — restore sysadmin identity
    if ($_POST['action'] === 'stop_impersonate') {
        $_SESSION['employee_id'] = '0';
        $_SESSION['role']        = 'sysadmin';
        $_SESSION['first_name']  = 'Sys';
        $_SESSION['last_name']   = 'Admin';
    }

    // After any POST, redirect back to this page (PRG pattern — prevents re-submit on refresh)
    header("Location: sysadmin_test.php");
    exit();
}

// ============================================================
// Load employee lists grouped by role for the dropdowns
// ============================================================
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
      --purple: #4D148C;
      --orange: #FF6200;
      --red:    #c0392b;
      --green:  #1a7a4a;
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

    /* ── Header ── */
    .site-header {
      width: 100%;
      background-color: #1a1a1a;
      display: flex;
      align-items: center;
      padding: 0 24px;
      gap: 16px;
      min-height: 56px;
    }

    .site-header .page-title {
      color: #ffffff;
      font-size: 16px;
      font-weight: 700;
    }

    .site-header .badge {
      background: var(--orange);
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      padding: 3px 8px;
      border-radius: 4px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .site-header .go-dashboard {
      margin-left: auto;
      color: rgba(255,255,255,0.7);
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      padding: 8px 14px;
      border: 1px solid rgba(255,255,255,0.2);
      border-radius: 6px;
      transition: all 0.15s;
    }

    .site-header .go-dashboard:hover {
      background: rgba(255,255,255,0.1);
      color: #fff;
    }

    /* ── Page wrapper ── */
    .wrapper {
      width: 100%;
      max-width: 860px;
      padding: 32px 24px 0;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    /* ── Status banner ── */
    .status-banner {
      border-radius: 10px;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .status-banner.sysadmin {
      background: #f0f0f0;
      border: 1px solid var(--border);
    }

    .status-banner.impersonating {
      background: #fff8e6;
      border: 2px solid var(--orange);
    }

    .status-banner .status-left {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .status-icon {
      font-size: 22px;
    }

    .status-text strong {
      display: block;
      font-size: 14px;
      font-weight: 700;
    }

    .status-text span {
      font-size: 12px;
      color: var(--muted);
    }

    .stop-btn {
      background: var(--red);
      color: #fff;
      border: none;
      border-radius: 7px;
      padding: 9px 18px;
      font-size: 13px;
      font-family: 'Open Sans', sans-serif;
      font-weight: 700;
      cursor: pointer;
      transition: opacity 0.15s;
    }

    .stop-btn:hover { opacity: 0.85; }

    /* ── Section card ── */
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .card-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .card-header h2 {
      font-size: 14px;
      font-weight: 700;
      color: var(--text);
    }

    .card-header p {
      font-size: 12px;
      color: var(--muted);
      margin-top: 1px;
    }

    .card-body {
      padding: 20px;
    }

    /* ── Role grid ── */
    .role-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 16px;
    }

    .role-panel {
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
    }

    .role-panel-header {
      padding: 10px 14px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .role-panel-header .count {
      font-weight: 400;
      opacity: 0.7;
      font-size: 11px;
    }

    .role-panel.Employee .role-panel-header { background: #ede0f8; color: var(--purple); }
    .role-panel.Manager  .role-panel-header { background: #fff0e6; color: var(--orange); }
    .role-panel.Director .role-panel-header { background: #e6f0ff; color: #1a56c4; }
    .role-panel.VP       .role-panel-header { background: #e6faf0; color: #1a7a4a; }
    .role-panel.SVP      .role-panel-header { background: #fff8e6; color: #b07000; }

    .role-panel select {
      width: 100%;
      padding: 10px 12px;
      border: none;
      border-top: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
      font-size: 13px;
      font-family: 'Open Sans', sans-serif;
      color: var(--text);
      background: var(--bg);
      outline: none;
      cursor: pointer;
    }

    .role-panel select:focus {
      background: #fff;
    }

    .role-panel .impersonate-btn {
      display: block;
      width: 100%;
      padding: 10px;
      border: none;
      font-size: 13px;
      font-family: 'Open Sans', sans-serif;
      font-weight: 700;
      cursor: pointer;
      transition: opacity 0.15s;
      text-align: center;
    }

    .role-panel.Employee .impersonate-btn { background: #ede0f8; color: var(--purple); }
    .role-panel.Manager  .impersonate-btn { background: #fff0e6; color: var(--orange); }
    .role-panel.Director .impersonate-btn { background: #e6f0ff; color: #1a56c4; }
    .role-panel.VP       .impersonate-btn { background: #e6faf0; color: #1a7a4a; }
    .role-panel.SVP      .impersonate-btn { background: #fff8e6; color: #b07000; }

    .role-panel .impersonate-btn:hover { opacity: 0.75; }
    .role-panel .impersonate-btn:disabled { opacity: 0.35; cursor: not-allowed; }
  </style>
</head>
<body>

<div class="site-header">
  <span class="page-title">&#9881; Sysadmin Test Panel</span>
  <span class="badge">Testing Only</span>
  <a href="Fhome.php" class="go-dashboard">&#8594; Go to Dashboard</a>
</div>

<div class="wrapper">

  <!-- ── Current identity status ── -->
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

  <!-- ── Role impersonation panels ── -->
  <div class="card">
    <div class="card-header">
      <div>
        <h2>&#128100; Impersonate an Employee</h2>
        <p>Pick a role, choose a specific person, then click the button. All other pages will behave as that person until you stop.</p>
      </div>
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
            <button
              type="submit"
              class="impersonate-btn"
              <?= empty($list) ? 'disabled' : '' ?>>
              View as this <?= $r ?>
            </button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>

</body>
</html>
