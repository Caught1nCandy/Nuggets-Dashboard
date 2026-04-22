<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}
if (!in_array($_SESSION['role'], ['vp', 'svp', 'sysadmin'])) {
    header("Location: Fhome.php");
    exit();
}
require_once __DIR__ . '/db_config.php';

$myRole = $_SESSION['role'];
$myId   = $_SESSION['employee_id'];

// ── Constants ─────────────────────────────────────────────────
$MAIN_POOL = 2450000.00; // Fixed, never changes

// ── Load discretionary pool from settings ────────────────────
$discRow = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'discretionary_pool'")->fetch();
$DISC_POOL = $discRow ? floatval($discRow['setting_value']) : 50000.00;

// ── Check if finalized ────────────────────────────────────────
$finalizedRow = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'aic_finalized'")->fetch();
$is_finalized = $finalizedRow && $finalizedRow['setting_value'] === '1';

$message = null;
$msgType = 'success';

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_finalized) {
    $action = $_POST['action'] ?? '';

    // Save discretionary pool size (sysadmin only)
    if ($action === 'save_disc_pool' && $myRole === 'sysadmin') {
        $newPool = floatval($_POST['disc_pool_amount'] ?? 50000);
        if ($newPool >= 0) {
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'discretionary_pool'")
                ->execute([number_format($newPool, 2, '.', '')]);
            $DISC_POOL = $newPool;
            $message = "Discretionary pool updated to $" . number_format($newPool, 2);
        }
    }

    // Save discretionary awards
    if ($action === 'save') {
        $awards  = $_POST['discretionary'] ?? [];
        $errors  = 0;
        $stmt    = $pdo->prepare("
            UPDATE aic_ratings
            SET discretionary_amount = :amt,
                finalized_by         = :by,
                finalized_at         = NOW()
            WHERE employee_id = :id AND is_eligible = 1
        ");
        foreach ($awards as $empId => $amount) {
            $amount = trim($amount);
            if (!is_numeric($amount) || floatval($amount) < 0) {
                $errors++;
                continue;
            }
            $stmt->execute([
                ':amt' => round(floatval($amount), 2),
                ':by'  => $myId,
                ':id'  => $empId,
            ]);
        }
        $message = $errors === 0
            ? "Discretionary awards saved."
            : "$errors invalid entries skipped. All valid amounts saved.";
        $msgType = $errors > 0 ? 'warning' : 'success';
        header("Location: aic.php?saved=1");
        exit();
    }

    // Finalize and lock
    if ($action === 'finalize') {
        // Check if we need to init the setting
        $check = $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'aic_finalized'")->fetchColumn();
        if ($check == 0) {
            $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('aic_finalized', '1')");
        } else {
            $pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'aic_finalized'");
        }
        $pdo->exec("UPDATE aic_ratings SET finalized = 1 WHERE is_eligible = 1");
        $message = "AIC awards finalized and sent to payroll. No further changes are permitted.";
        $msgType = 'success';
        $is_finalized = true;
    }
}

if (isset($_GET['saved'])) {
    $message = "Discretionary awards saved successfully.";
}

// ── Load data ─────────────────────────────────────────────────
$rows = $pdo->query("
    SELECT
        a.employee_id,
        w.first_name,
        w.last_name,
        w.role,
        j.title,
        o.organization_name,
        a.performance_rating,
        a.calculated_amount,
        COALESCE(a.discretionary_amount, 0) AS discretionary_amount,
        a.is_eligible,
        a.finalized,
        mgr.first_name  AS manager_first,
        mgr.last_name   AS manager_last,
        dir.first_name  AS director_first,
        dir.last_name   AS director_last
    FROM aic_ratings a
    LEFT JOIN workforce    w   ON w.employee_id   = a.employee_id
    LEFT JOIN job          j   ON j.job_code      = w.job_code
    LEFT JOIN organization o   ON o.org_id        = w.org_id
    LEFT JOIN workforce    mgr ON mgr.employee_id = w.manager_id
    LEFT JOIN workforce    dir ON dir.employee_id = w.director_id
    ORDER BY a.is_eligible DESC, a.performance_rating DESC, w.last_name, w.first_name
")->fetchAll();

// ── Totals ────────────────────────────────────────────────────
$totalCalc       = 0;
$totalDisc       = 0;
$eligibleCount   = 0;
foreach ($rows as $r) {
    if (!$r['is_eligible']) continue;
    $eligibleCount++;
    $totalCalc += floatval($r['calculated_amount']);
    $totalDisc += floatval($r['discretionary_amount']);
}
$discRemaining = $DISC_POOL - $totalDisc;
$grandTotal    = $totalCalc + $totalDisc;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>AIC Management — Workforce Dashboard</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --purple: #4D148C; --orange: #FF6200;
      --bg: #f4f4f4; --surface: #ffffff;
      --border: #e0e0e0; --text: #1a1a1a; --muted: #888888;
      --green: #1a7a4a; --red: #c0392b; --blue: #1a5a8a;
    }
    html, body { background: var(--bg); color: var(--text); font-family: 'Open Sans', sans-serif; min-height: 100vh; }
    body { display: flex; flex-direction: column; align-items: center; padding-bottom: 60px; }
    .page-wrapper { width: 100%; max-width: 1400px; padding: 28px 24px 0; }

    /* ── Alerts ── */
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
    .alert.success { background: #e6faf0; color: var(--green); border: 1px solid #a0dfc0; }
    .alert.warning { background: #fff8e6; color: #7a5500; border: 1px solid #f5d98a; }
    .alert.error   { background: #fde8e8; color: var(--red);   border: 1px solid #f5a0a0; }
    .alert.info    { background: #e8f0fe; color: var(--blue);  border: 1px solid #a0b8f0; }

    /* ── Finalized banner ── */
    .finalized-banner {
      background: #e6faf0; border: 2px solid var(--green); border-radius: 10px;
      padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px;
      font-weight: 700; color: var(--green); font-size: 15px;
    }

    /* ── Pool config bar (sysadmin only) ── */
    .pool-config {
      background: white; border: 1px solid var(--border); border-radius: 10px;
      padding: 16px 20px; margin-bottom: 20px; display: flex; align-items: center;
      gap: 16px; flex-wrap: wrap; border-left: 4px solid var(--purple);
    }
    .pool-config label { font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.07em; }
    .pool-config input { padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; font-family: 'Open Sans', sans-serif; width: 140px; }
    .pool-config input:focus { outline: none; border-color: var(--purple); }
    .btn-sm { padding: 7px 16px; border: none; border-radius: 6px; font-size: 13px; font-family: 'Open Sans', sans-serif; font-weight: 700; cursor: pointer; transition: opacity 0.15s; }
    .btn-sm:hover { opacity: 0.85; }
    .btn-purple { background: var(--purple); color: white; }
    .btn-green  { background: var(--green);  color: white; }
    .btn-orange { background: var(--orange); color: white; }

    /* ── KPI bar ── */
    .kpi-bar { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-bottom: 24px; }
    .kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; border-bottom: 3px solid var(--purple); }
    .kpi-card.disc  { border-bottom-color: var(--orange); }
    .kpi-card.warn  { border-bottom-color: var(--red); }
    .kpi-card.ok    { border-bottom-color: var(--green); }
    .kpi-card.total { border-bottom-color: var(--blue); }
    .kpi-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 5px; }
    .kpi-value { font-size: 22px; font-weight: 700; color: var(--purple); }
    .kpi-card.disc  .kpi-value { color: var(--orange); }
    .kpi-card.warn  .kpi-value { color: var(--red); }
    .kpi-card.ok    .kpi-value { color: var(--green); }
    .kpi-card.total .kpi-value { color: var(--blue); }

    /* ── Table card ── */
    .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; }
    .table-card::before { content: ''; display: block; height: 3px; background: linear-gradient(90deg, var(--purple), var(--orange)); }
    .table-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); gap: 12px; flex-wrap: wrap; }
    .table-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); }
    .toolbar-actions { display: flex; gap: 10px; align-items: center; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { background: #f8f6ff; color: var(--purple); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border); cursor: pointer; white-space: nowrap; user-select: none; }
    th:hover { background: #f0ebf8; }
    th .sort-arrow { margin-left: 4px; opacity: 0.4; }
    th.sorted .sort-arrow { opacity: 1; }
    td { padding: 10px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    tr.ineligible td { opacity: 0.45; }

    .role-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .role-badge.Employee { background: #ede0f8; color: var(--purple); }
    .role-badge.Manager  { background: #fff0e6; color: var(--orange); }

    .rating-bar { display: flex; align-items: center; gap: 8px; }
    .rating-num { font-weight: 700; width: 16px; text-align: right; }
    .rating-track { flex: 1; height: 5px; background: #eeeeee; border-radius: 99px; overflow: hidden; max-width: 80px; }
    .rating-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--purple), var(--orange)); }

    /* Calculated amount — read only, no input */
    .calc-amount { font-weight: 600; color: var(--purple); }

    /* Discretionary input */
    .disc-input { width: 100px; padding: 5px 8px; border: 1px solid var(--border); border-radius: 5px; font-size: 13px; font-family: 'Open Sans', sans-serif; text-align: right; }
    .disc-input:focus { outline: none; border-color: var(--orange); }
    .disc-input.has-award { border-color: var(--orange); background: #fff8f0; font-weight: 700; }
    .disc-input:disabled { background: #f5f5f5; color: var(--muted); cursor: not-allowed; }

    /* Total column */
    .total-amt { font-weight: 700; color: var(--blue); }

    .zero-badge { color: var(--muted); font-style: italic; font-size: 12px; }
    .finalized-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--green); display: inline-block; margin-right: 4px; }

    /* Finalize section */
    .finalize-section { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; border-left: 4px solid var(--green); }
    .finalize-text h3 { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
    .finalize-text p { font-size: 13px; color: var(--muted); }
    .btn-finalize { background: var(--green); color: white; border: none; border-radius: 8px; padding: 12px 28px; font-size: 14px; font-family: 'Open Sans', sans-serif; font-weight: 700; cursor: pointer; transition: opacity 0.15s; }
    .btn-finalize:hover { opacity: 0.85; }

    /* Disc pool progress bar */
    .disc-progress { margin-bottom: 20px; background: white; border: 1px solid var(--border); border-radius: 10px; padding: 16px 20px; }
    .disc-progress-label { display: flex; justify-content: space-between; font-size: 12px; font-weight: 700; color: var(--muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.07em; }
    .disc-progress-track { height: 8px; background: #eeeeee; border-radius: 99px; overflow: hidden; }
    .disc-progress-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--purple), var(--orange)); transition: width 0.3s; }
    .disc-progress-fill.over { background: var(--red); }
  </style>
</head>
<body>
<?php include __DIR__ . '/impersonation_banner.php'; ?>
<?php $activePage = 'aic'; include __DIR__ . '/navbar.php'; ?>

<div class="page-wrapper">

  <?php if ($is_finalized): ?>
  <div class="finalized-banner">
    ✓ AIC awards have been finalized and sent to payroll. No further changes are permitted.
  </div>
  <?php endif; ?>

  <?php if ($message): ?>
    <div class="alert <?php echo $msgType; ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <?php if (!$is_finalized): ?>
  <div class="alert info" style="margin-bottom:20px;">
    <strong>How this works:</strong> The <strong>$<?php echo number_format($MAIN_POOL, 0); ?> main pool</strong> has already been distributed to all eligible employees based on their performance rating — these amounts are fixed. Use the <strong>Discretionary Award</strong> column to add bonus amounts from the separate <strong>$<?php echo number_format($DISC_POOL, 0); ?> discretionary pool</strong>. When you are done, click <em>Finalize &amp; Send to Payroll</em>.
  </div>
  <?php endif; ?>

  <?php if ($myRole === 'sysadmin' && !$is_finalized): ?>
  <!-- Sysadmin: configure discretionary pool size -->
  <form method="POST">
    <input type="hidden" name="action" value="save_disc_pool">
    <div class="pool-config">
      <label>⚙ Discretionary Pool Size (Sysadmin)</label>
      <input type="number" name="disc_pool_amount" value="<?php echo number_format($DISC_POOL, 2, '.', ''); ?>" step="1000" min="0">
      <button type="submit" class="btn-sm btn-purple">Update Pool</button>
      <span style="font-size:12px;color:var(--muted);">Default: $50,000. Changes take effect immediately.</span>
    </div>
  </form>
  <?php endif; ?>

  <!-- KPI Bar -->
  <div class="kpi-bar">
    <div class="kpi-card">
      <div class="kpi-label">Main Pool</div>
      <div class="kpi-value">$<?php echo number_format($MAIN_POOL, 0); ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Eligible Employees</div>
      <div class="kpi-value"><?php echo $eligibleCount; ?></div>
    </div>
    <div class="kpi-card disc">
      <div class="kpi-label">Discretionary Pool</div>
      <div class="kpi-value" id="kpi-disc-pool">$<?php echo number_format($DISC_POOL, 0); ?></div>
    </div>
    <div class="kpi-card <?php echo $discRemaining < -0.01 ? 'warn' : ($discRemaining < 0.01 ? 'ok' : 'disc'); ?>" id="kpi-disc-remaining-card">
      <div class="kpi-label">Discretionary Remaining</div>
      <div class="kpi-value" id="kpi-disc-remaining">$<?php echo number_format($discRemaining, 0); ?></div>
    </div>
    <div class="kpi-card disc">
      <div class="kpi-label">Discretionary Awarded</div>
      <div class="kpi-value" id="kpi-disc-awarded">$<?php echo number_format($totalDisc, 0); ?></div>
    </div>
    <div class="kpi-card total">
      <div class="kpi-label">Grand Total Payout</div>
      <div class="kpi-value" id="kpi-grand-total">$<?php echo number_format($grandTotal, 0); ?></div>
    </div>
  </div>

  <!-- Discretionary pool progress bar -->
  <?php $discPct = $DISC_POOL > 0 ? min(100, ($totalDisc / $DISC_POOL) * 100) : 0; ?>
  <div class="disc-progress">
    <div class="disc-progress-label">
      <span>Discretionary Pool Usage</span>
      <span id="disc-pct-label"><?php echo number_format($discPct, 1); ?>% used — $<?php echo number_format($totalDisc, 2); ?> of $<?php echo number_format($DISC_POOL, 2); ?></span>
    </div>
    <div class="disc-progress-track">
      <div class="disc-progress-fill <?php echo $discPct >= 100 ? 'over' : ''; ?>" id="disc-progress-fill" style="width:<?php echo $discPct; ?>%"></div>
    </div>
  </div>

  <!-- Main table -->
  <form method="POST" id="aic-form">
    <input type="hidden" name="action" value="save">
    <div class="table-card">
      <div class="table-toolbar">
        <span class="table-title">AIC Allocations — FY25</span>
        <?php if (!$is_finalized): ?>
        <div class="toolbar-actions">
          <button type="submit" class="btn-sm btn-purple">💾 Save Discretionary Awards</button>
        </div>
        <?php endif; ?>
      </div>
      <div class="table-wrap">
        <table id="aic-table">
          <thead>
            <tr>
              <th onclick="sortTable(0)">Employee <span class="sort-arrow">⇅</span></th>
              <th onclick="sortTable(1)">Role <span class="sort-arrow">⇅</span></th>
              <th onclick="sortTable(2)">Manager <span class="sort-arrow">⇅</span></th>
              <th onclick="sortTable(3)">Director <span class="sort-arrow">⇅</span></th>
              <th onclick="sortTable(4)">Dept <span class="sort-arrow">⇅</span></th>
              <th onclick="sortTable(5)">Rating <span class="sort-arrow">⇅</span></th>
              <th onclick="sortTable(6)">Main Award <span class="sort-arrow">⇅</span></th>
              <th>+ Discretionary</th>
              <th onclick="sortTable(8)">Total <span class="sort-arrow">⇅</span></th>
              <th onclick="sortTable(9)">Status <span class="sort-arrow">⇅</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $calc  = floatval($r['calculated_amount']);
              $disc  = floatval($r['discretionary_amount']);
              $total = $calc + $disc;
              $managerName  = $r['manager_first']  ? $r['manager_first']  . ' ' . $r['manager_last']  : '—';
              $directorName = $r['director_first'] ? $r['director_first'] . ' ' . $r['director_last'] : '—';
            ?>
            <tr class="<?php echo !$r['is_eligible'] ? 'ineligible' : ''; ?>"
                data-rating="<?php echo $r['performance_rating']; ?>"
                data-calc="<?php echo $calc; ?>"
                data-disc="<?php echo $disc; ?>"
                data-total="<?php echo $total; ?>">
              <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
              <td><span class="role-badge <?php echo htmlspecialchars($r['role']); ?>"><?php echo htmlspecialchars($r['role']); ?></span></td>
              <td><?php echo htmlspecialchars($managerName); ?></td>
              <td><?php echo htmlspecialchars($directorName); ?></td>
              <td><?php echo htmlspecialchars($r['organization_name'] ?? '—'); ?></td>
              <td>
                <?php if ($r['is_eligible']): ?>
                <div class="rating-bar">
                  <span class="rating-num"><?php echo $r['performance_rating']; ?></span>
                  <div class="rating-track"><div class="rating-fill" style="width:<?php echo $r['performance_rating'] * 10; ?>%"></div></div>
                </div>
                <?php else: ?>
                <span class="zero-badge"><?php echo $r['performance_rating']; ?> — ineligible</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['is_eligible']): ?>
                  <span class="calc-amount">$<?php echo number_format($calc, 2); ?></span>
                <?php else: ?>
                  <span class="zero-badge">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['is_eligible']): ?>
                <input
                  type="number"
                  class="disc-input <?php echo $disc > 0 ? 'has-award' : ''; ?>"
                  name="discretionary[<?php echo htmlspecialchars($r['employee_id']); ?>]"
                  value="<?php echo number_format($disc, 2, '.', ''); ?>"
                  step="0.01"
                  min="0"
                  data-empid="<?php echo htmlspecialchars($r['employee_id']); ?>"
                  oninput="onDiscChange(this)"
                  <?php echo $is_finalized ? 'disabled' : ''; ?>
                />
                <?php else: ?>
                  <span class="zero-badge">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['is_eligible']): ?>
                  <span class="total-amt" id="total-<?php echo htmlspecialchars($r['employee_id']); ?>">$<?php echo number_format($total, 2); ?></span>
                <?php else: ?>
                  <span class="zero-badge">$0.00</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['finalized']): ?>
                  <span class="finalized-dot"></span>Finalized
                <?php elseif (!$r['is_eligible']): ?>
                  <span style="color:var(--muted);font-size:12px;">N/A</span>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:12px;">Pending</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </form>

  <!-- Finalize section -->
  <?php if (!$is_finalized): ?>
  <div class="finalize-section">
    <div class="finalize-text">
      <h3>Ready to finalize?</h3>
      <p>Once finalized, all award amounts are locked and sent to payroll. This action cannot be undone.</p>
    </div>
    <form method="POST" onsubmit="return confirmFinalize()">
      <input type="hidden" name="action" value="finalize">
      <button type="submit" class="btn-finalize">✓ Finalize &amp; Send to Payroll</button>
    </form>
  </div>
  <?php endif; ?>

</div>

<script>
const DISC_POOL = <?php echo $DISC_POOL; ?>;

function onDiscChange(input) {
    const empId = input.dataset.empid;
    const discVal = parseFloat(input.value) || 0;

    // Update has-award styling
    input.classList.toggle('has-award', discVal > 0);

    // Update the row's total display
    const row = input.closest('tr');
    const calc = parseFloat(row.dataset.calc) || 0;
    const total = calc + discVal;
    const totalEl = document.getElementById('total-' + empId);
    if (totalEl) totalEl.textContent = '$' + total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

    updateDiscTotals();
}

function updateDiscTotals() {
    let totalDisc = 0;
    let totalGrand = 0;
    document.querySelectorAll('.disc-input').forEach(inp => {
        const disc = parseFloat(inp.value) || 0;
        totalDisc += disc;
        const row = inp.closest('tr');
        const calc = parseFloat(row.dataset.calc) || 0;
        totalGrand += calc + disc;
    });

    // Add calc amounts for rows without disc inputs (ineligible)
    document.querySelectorAll('tr:not(.ineligible)').forEach(row => {
        if (!row.querySelector('.disc-input')) {
            totalGrand += parseFloat(row.dataset.calc) || 0;
        }
    });

    const discRemaining = DISC_POOL - totalDisc;
    const discPct = DISC_POOL > 0 ? Math.min(100, (totalDisc / DISC_POOL) * 100) : 0;

    document.getElementById('kpi-disc-awarded').textContent    = '$' + totalDisc.toLocaleString('en-US', {maximumFractionDigits:0});
    document.getElementById('kpi-disc-remaining').textContent  = '$' + discRemaining.toLocaleString('en-US', {maximumFractionDigits:0});
    document.getElementById('kpi-grand-total').textContent     = '$' + totalGrand.toLocaleString('en-US', {maximumFractionDigits:0});

    // Progress bar
    const fill = document.getElementById('disc-progress-fill');
    fill.style.width = discPct + '%';
    fill.classList.toggle('over', discPct >= 100);

    // Remaining card color
    const remCard = document.getElementById('kpi-disc-remaining-card');
    remCard.className = 'kpi-card';
    if (Math.abs(discRemaining) < 0.01) remCard.classList.add('ok');
    else if (discRemaining < -0.01)     remCard.classList.add('warn');
    else                                 remCard.classList.add('disc');

    // Update progress label
    document.getElementById('disc-pct-label').textContent =
        discPct.toFixed(1) + '% used — $' +
        totalDisc.toLocaleString('en-US', {minimumFractionDigits:2}) +
        ' of $' + DISC_POOL.toLocaleString('en-US', {minimumFractionDigits:2});
}

function confirmFinalize() {
    let totalDisc = 0;
    document.querySelectorAll('.disc-input').forEach(inp => { totalDisc += parseFloat(inp.value) || 0; });
    const remaining = DISC_POOL - totalDisc;
    let msg = 'Finalize AIC awards and send to payroll?\n\nThis CANNOT be undone.';
    if (remaining > 0.01) {
        msg += '\n\nNote: $' + remaining.toLocaleString('en-US', {minimumFractionDigits:2}) + ' of the discretionary pool has not been awarded.';
    }
    return confirm(msg);
}

// ── Sortable columns ──────────────────────────────────────────
let sortCol = -1, sortAsc = true;
function sortTable(col) {
    const table = document.getElementById('aic-table');
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    if (sortCol === col) sortAsc = !sortAsc;
    else { sortCol = col; sortAsc = true; }
    rows.sort((a, b) => {
        const aCell = a.querySelectorAll('td')[col];
        const bCell = b.querySelectorAll('td')[col];
        let aVal = aCell ? aCell.innerText.trim().replace(/[$,+]/g, '') : '';
        let bVal = bCell ? bCell.innerText.trim().replace(/[$,+]/g, '') : '';
        if (col === 5) { aVal = parseFloat(a.dataset.rating); bVal = parseFloat(b.dataset.rating); }
        if (col === 6) { aVal = parseFloat(a.dataset.calc);   bVal = parseFloat(b.dataset.calc); }
        if (col === 8) { aVal = parseFloat(a.dataset.total);  bVal = parseFloat(b.dataset.total); }
        const aNum = parseFloat(aVal);
        const bNum = parseFloat(bVal);
        if (!isNaN(aNum) && !isNaN(bNum)) return sortAsc ? aNum - bNum : bNum - aNum;
        return sortAsc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
    });
    rows.forEach(r => tbody.appendChild(r));
    document.querySelectorAll('th').forEach((th, i) => {
        th.classList.toggle('sorted', i === col);
        const arrow = th.querySelector('.sort-arrow');
        if (arrow) arrow.textContent = i === col ? (sortAsc ? '↑' : '↓') : '⇅';
    });
}
</script>
</body>
</html>
