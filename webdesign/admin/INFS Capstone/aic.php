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

$myRole     = $_SESSION['role'];
$myId       = $_SESSION['employee_id'];
$TOTAL_POOL = 2500000.00;

// ── Load settings ─────────────────────────────────────────────
$discRow = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'discretionary_pool'")->fetch();
$DISC_POOL = $discRow ? floatval($discRow['setting_value']) : 50000.00;
$MAIN_POOL = $TOTAL_POOL - $DISC_POOL;

$finalizedRow = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'aic_finalized'")->fetch();
$is_finalized = $finalizedRow && $finalizedRow['setting_value'] === '1';

$message = null;
$msgType = 'success';

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_finalized) {
    $action = $_POST['action'] ?? '';

    // ── Update discretionary pool size (sysadmin only) ────────
    if ($action === 'save_disc_pool' && $myRole === 'sysadmin') {
        $newDisc = floatval($_POST['disc_pool_amount'] ?? 50000);

        if ($newDisc < 0) {
            $message = "Discretionary pool cannot be negative.";
            $msgType = 'error';
        } elseif ($newDisc >= $TOTAL_POOL) {
            $message = "Discretionary pool cannot equal or exceed the total $2,500,000 pool.";
            $msgType = 'error';
        } else {
            $newMain = $TOTAL_POOL - $newDisc;

            // Save new disc pool setting
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'discretionary_pool'")
                ->execute([number_format($newDisc, 2, '.', '')]);

            // Recalculate all eligible employees' main calculated_amount
            $pdo->exec("
                UPDATE aic_ratings a
                JOIN (
                    SELECT SUM(performance_rating) AS total_rating
                    FROM aic_ratings
                    WHERE is_eligible = 1
                ) t
                SET a.calculated_amount = ROUND(($newMain * a.performance_rating / t.total_rating), 2)
                WHERE a.is_eligible = 1
            ");

            $DISC_POOL = $newDisc;
            $MAIN_POOL = $newMain;
            $message   = "Pools updated — Main: $" . number_format($newMain, 2) . " | Discretionary: $" . number_format($newDisc, 2) . ". Ratings recalculated.";
        }
    }

    // ── Save discretionary awards ─────────────────────────────
    if ($action === 'save') {
        $awards = $_POST['discretionary'] ?? [];
        $errors = 0;
        $stmt   = $pdo->prepare("
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

    // ── Finalize and lock ─────────────────────────────────────
    if ($action === 'finalize') {
        $check = $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'aic_finalized'")->fetchColumn();
        if ($check == 0) {
            $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('aic_finalized', '1')");
        } else {
            $pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'aic_finalized'");
        }
        $pdo->exec("UPDATE aic_ratings SET finalized = 1 WHERE is_eligible = 1");
        $message      = "AIC awards finalized and sent to payroll. No further changes are permitted.";
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
$totalCalc     = 0;
$totalDisc     = 0;
$eligibleCount = 0;
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

    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
    .alert.success { background: #e6faf0; color: var(--green); border: 1px solid #a0dfc0; }
    .alert.warning { background: #fff8e6; color: #7a5500; border: 1px solid #f5d98a; }
    .alert.error   { background: #fde8e8; color: var(--red);   border: 1px solid #f5a0a0; }
    .alert.info    { background: #e8f0fe; color: var(--blue);  border: 1px solid #a0b8f0; }

    .finalized-banner {
      background: #e6faf0; border: 2px solid var(--green); border-radius: 10px;
      padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center;
      gap: 12px; font-weight: 700; color: var(--green); font-size: 15px;
    }

    /* Pool config — sysadmin only */
    .pool-config {
      background: white; border: 1px solid var(--border); border-radius: 10px;
      padding: 16px 20px; margin-bottom: 20px; border-left: 4px solid var(--purple);
    }
    .pool-config-header { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 12px; }
    .pool-config-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .pool-config-row label { font-size: 13px; font-weight: 600; color: var(--text); }
    .pool-config-row input { padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; font-family: 'Open Sans', sans-serif; width: 150px; }
    .pool-config-row input:focus { outline: none; border-color: var(--purple); }
    .pool-preview { font-size: 13px; color: var(--muted); }
    .pool-preview strong { color: var(--purple); }

    .btn-sm { padding: 7px 16px; border: none; border-radius: 6px; font-size: 13px; font-family: 'Open Sans', sans-serif; font-weight: 700; cursor: pointer; transition: opacity 0.15s; }
    .btn-sm:hover { opacity: 0.85; }
    .btn-purple { background: var(--purple); color: white; }
    .btn-green  { background: var(--green);  color: white; }

    /* KPI bar */
    .kpi-bar { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-bottom: 20px; }
    .kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; border-bottom: 3px solid var(--purple); }
    .kpi-card.disc  { border-bottom-color: var(--orange); }
    .kpi-card.warn  { border-bottom-color: var(--red); }
    .kpi-card.ok    { border-bottom-color: var(--green); }
    .kpi-card.total { border-bottom-color: var(--blue); }
    .kpi-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 5px; }
    .kpi-value { font-size: 20px; font-weight: 700; color: var(--purple); }
    .kpi-card.disc  .kpi-value { color: var(--orange); }
    .kpi-card.warn  .kpi-value { color: var(--red); }
    .kpi-card.ok    .kpi-value { color: var(--green); }
    .kpi-card.total .kpi-value { color: var(--blue); }

    /* Disc progress bar */
    .disc-progress { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 14px 20px; margin-bottom: 20px; }
    .disc-progress-label { display: flex; justify-content: space-between; font-size: 12px; font-weight: 700; color: var(--muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.07em; }
    .disc-progress-track { height: 8px; background: #eeeeee; border-radius: 99px; overflow: hidden; }
    .disc-progress-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--purple), var(--orange)); transition: width 0.3s; }
    .disc-progress-fill.over { background: var(--red); }

    /* Table */
    .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; }
    .table-card::before { content: ''; display: block; height: 3px; background: linear-gradient(90deg, var(--purple), var(--orange)); }
    .table-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); gap: 12px; flex-wrap: wrap; }
    .table-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); }
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

    .calc-amount { font-weight: 600; color: var(--purple); }
    .disc-input { width: 100px; padding: 5px 8px; border: 1px solid var(--border); border-radius: 5px; font-size: 13px; font-family: 'Open Sans', sans-serif; text-align: right; }
    .disc-input:focus { outline: none; border-color: var(--orange); }
    .disc-input.has-award { border-color: var(--orange); background: #fff8f0; font-weight: 700; }
    .disc-input:disabled { background: #f5f5f5; color: var(--muted); cursor: not-allowed; }
    .total-amt { font-weight: 700; color: var(--blue); }
    .zero-badge { color: var(--muted); font-style: italic; font-size: 12px; }
    .finalized-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--green); display: inline-block; margin-right: 4px; }

    /* Finalize section */
    .finalize-section { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; border-left: 4px solid var(--green); }
    .finalize-text h3 { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
    .finalize-text p { font-size: 13px; color: var(--muted); }
    .btn-finalize { background: var(--green); color: white; border: none; border-radius: 8px; padding: 12px 28px; font-size: 14px; font-family: 'Open Sans', sans-serif; font-weight: 700; cursor: pointer; transition: opacity 0.15s; }
    .btn-finalize:hover { opacity: 0.85; }
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
  <div class="alert info">
    <strong>How this works:</strong>
    The <strong>Main Pool ($<?php echo number_format($MAIN_POOL, 0); ?>)</strong> is automatically distributed to all eligible employees based on their performance rating — these amounts are fixed.
    Use the <strong>Discretionary Award</strong> column to add bonus amounts from the separate <strong>Discretionary Pool ($<?php echo number_format($DISC_POOL, 0); ?>)</strong>.
    The two pools always add up to exactly <strong>$2,500,000</strong>.
    When done, click <em>Finalize &amp; Send to Payroll</em>.
  </div>
  <?php endif; ?>

  <?php if ($myRole === 'sysadmin' && !$is_finalized): ?>
  <form method="POST">
    <input type="hidden" name="action" value="save_disc_pool">
    <div class="pool-config">
      <div class="pool-config-header">⚙ Pool Configuration (Sysadmin Only)</div>
      <div class="pool-config-row">
        <label>Discretionary Pool:</label>
        <input type="number" name="disc_pool_amount"
               value="<?php echo number_format($DISC_POOL, 2, '.', ''); ?>"
               step="1000" min="0" max="2499999"
               id="disc-pool-input"
               oninput="previewPools(this.value)">
        <button type="submit" class="btn-sm btn-purple">Update Pools</button>
        <span class="pool-preview" id="pool-preview">
          Main pool will be: <strong>$<?php echo number_format($MAIN_POOL, 0); ?></strong>
          &nbsp;|&nbsp; Total: <strong>$2,500,000</strong>
        </span>
      </div>
    </div>
  </form>
  <?php endif; ?>

  <!-- KPI Bar -->
  <div class="kpi-bar">
    <div class="kpi-card">
      <div class="kpi-label">Total AIC Pool</div>
      <div class="kpi-value">$<?php echo number_format($TOTAL_POOL, 0); ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Main Pool</div>
      <div class="kpi-value" id="kpi-main-pool">$<?php echo number_format($MAIN_POOL, 0); ?></div>
    </div>
    <div class="kpi-card disc">
      <div class="kpi-label">Discretionary Pool</div>
      <div class="kpi-value">$<?php echo number_format($DISC_POOL, 0); ?></div>
    </div>
    <div class="kpi-card disc">
      <div class="kpi-label">Discretionary Awarded</div>
      <div class="kpi-value" id="kpi-disc-awarded">$<?php echo number_format($totalDisc, 0); ?></div>
    </div>
    <div class="kpi-card <?php echo $discRemaining < -0.01 ? 'warn' : ($discRemaining < 0.01 ? 'ok' : 'disc'); ?>" id="kpi-disc-remaining-card">
      <div class="kpi-label">Discretionary Remaining</div>
      <div class="kpi-value" id="kpi-disc-remaining">$<?php echo number_format($discRemaining, 0); ?></div>
    </div>
    <div class="kpi-card total">
      <div class="kpi-label">Grand Total Payout</div>
      <div class="kpi-value" id="kpi-grand-total">$<?php echo number_format($grandTotal, 0); ?></div>
    </div>
  </div>

  <!-- Discretionary progress bar -->
  <?php $discPct = $DISC_POOL > 0 ? min(100, ($totalDisc / $DISC_POOL) * 100) : 0; ?>
  <div class="disc-progress">
    <div class="disc-progress-label">
      <span>Discretionary Pool Usage</span>
      <span id="disc-pct-label">
        <?php echo number_format($discPct, 1); ?>% used —
        $<?php echo number_format($totalDisc, 2); ?> of
        $<?php echo number_format($DISC_POOL, 2); ?>
      </span>
    </div>
    <div class="disc-progress-track">
      <div class="disc-progress-fill <?php echo $discPct >= 100 ? 'over' : ''; ?>"
           id="disc-progress-fill"
           style="width:<?php echo $discPct; ?>%"></div>
    </div>
  </div>

  <!-- Main table -->
  <form method="POST" id="aic-form">
    <input type="hidden" name="action" value="save">
    <div class="table-card">
      <div class="table-toolbar">
        <span class="table-title">AIC Allocations — FY25</span>
        <?php if (!$is_finalized): ?>
        <button type="submit" class="btn-sm btn-purple">💾 Save Discretionary Awards</button>
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
              <th>Status</th>
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
                <?php echo $r['is_eligible']
                    ? '<span class="calc-amount">$' . number_format($calc, 2) . '</span>'
                    : '<span class="zero-badge">—</span>'; ?>
              </td>
              <td>
                <?php if ($r['is_eligible']): ?>
                <input type="number"
                       class="disc-input <?php echo $disc > 0 ? 'has-award' : ''; ?>"
                       name="discretionary[<?php echo htmlspecialchars($r['employee_id']); ?>]"
                       value="<?php echo number_format($disc, 2, '.', ''); ?>"
                       step="0.01" min="0"
                       data-empid="<?php echo htmlspecialchars($r['employee_id']); ?>"
                       oninput="onDiscChange(this)"
                       <?php echo $is_finalized ? 'disabled' : ''; ?>/>
                <?php else: ?>
                <span class="zero-badge">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php echo $r['is_eligible']
                    ? '<span class="total-amt" id="total-' . htmlspecialchars($r['employee_id']) . '">$' . number_format($total, 2) . '</span>'
                    : '<span class="zero-badge">$0.00</span>'; ?>
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
const DISC_POOL  = <?php echo $DISC_POOL; ?>;
const TOTAL_POOL = <?php echo $TOTAL_POOL; ?>;

// Preview pool split before submitting
function previewPools(val) {
    const disc = parseFloat(val) || 0;
    const main = TOTAL_POOL - disc;
    const preview = document.getElementById('pool-preview');
    if (preview) {
        preview.innerHTML = 'Main pool will be: <strong>$' +
            main.toLocaleString('en-US', {maximumFractionDigits:0}) +
            '</strong> &nbsp;|&nbsp; Total: <strong>$2,500,000</strong>';
    }
}

function onDiscChange(input) {
    const empId   = input.dataset.empid;
    const discVal = parseFloat(input.value) || 0;
    input.classList.toggle('has-award', discVal > 0);

    const row  = input.closest('tr');
    const calc = parseFloat(row.dataset.calc) || 0;
    const tot  = calc + discVal;
    const totalEl = document.getElementById('total-' + empId);
    if (totalEl) totalEl.textContent = '$' + tot.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

    updateDiscTotals();
}

function updateDiscTotals() {
    let totalDisc  = 0;
    let totalGrand = 0;

    document.querySelectorAll('tr:not(.ineligible)').forEach(row => {
        const calc = parseFloat(row.dataset.calc) || 0;
        const inp  = row.querySelector('.disc-input');
        const disc = inp ? (parseFloat(inp.value) || 0) : (parseFloat(row.dataset.disc) || 0);
        totalDisc  += disc;
        totalGrand += calc + disc;
    });

    const discRemaining = DISC_POOL - totalDisc;
    const discPct       = DISC_POOL > 0 ? Math.min(100, (totalDisc / DISC_POOL) * 100) : 0;

    document.getElementById('kpi-disc-awarded').textContent   = '$' + totalDisc.toLocaleString('en-US', {maximumFractionDigits:0});
    document.getElementById('kpi-disc-remaining').textContent = '$' + discRemaining.toLocaleString('en-US', {maximumFractionDigits:0});
    document.getElementById('kpi-grand-total').textContent    = '$' + totalGrand.toLocaleString('en-US', {maximumFractionDigits:0});

    const fill = document.getElementById('disc-progress-fill');
    fill.style.width = discPct + '%';
    fill.classList.toggle('over', discPct >= 100);

    document.getElementById('disc-pct-label').textContent =
        discPct.toFixed(1) + '% used — $' +
        totalDisc.toLocaleString('en-US', {minimumFractionDigits:2}) +
        ' of $' + DISC_POOL.toLocaleString('en-US', {minimumFractionDigits:2});

    const remCard = document.getElementById('kpi-disc-remaining-card');
    remCard.className = 'kpi-card';
    if (Math.abs(discRemaining) < 0.01) remCard.classList.add('ok');
    else if (discRemaining < -0.01)     remCard.classList.add('warn');
    else                                 remCard.classList.add('disc');
}

function confirmFinalize() {
    let totalDisc = 0;
    document.querySelectorAll('.disc-input').forEach(inp => { totalDisc += parseFloat(inp.value) || 0; });
    const remaining = DISC_POOL - totalDisc;
    let msg = 'Finalize AIC awards and send to payroll?\n\nThis CANNOT be undone.';
    if (remaining > 0.01) {
        msg += '\n\nNote: $' + remaining.toLocaleString('en-US', {minimumFractionDigits:2}) +
               ' of the discretionary pool has not been awarded.';
    }
    if (totalDisc > DISC_POOL + 0.01) {
        msg = 'WARNING: Discretionary awards exceed the pool by $' +
              (totalDisc - DISC_POOL).toLocaleString('en-US', {minimumFractionDigits:2}) +
              '.\n\n' + msg;
    }
    return confirm(msg);
}

// Sortable columns
let sortCol = -1, sortAsc = true;
function sortTable(col) {
    const tbody = document.querySelector('#aic-table tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    if (sortCol === col) sortAsc = !sortAsc;
    else { sortCol = col; sortAsc = true; }
    rows.sort((a, b) => {
        const aCell = a.querySelectorAll('td')[col];
        const bCell = b.querySelectorAll('td')[col];
        let aVal = aCell ? aCell.innerText.trim().replace(/[$,]/g, '') : '';
        let bVal = bCell ? bCell.innerText.trim().replace(/[$,]/g, '') : '';
        if (col === 5) { aVal = parseFloat(a.dataset.rating); bVal = parseFloat(b.dataset.rating); }
        if (col === 6) { aVal = parseFloat(a.dataset.calc);   bVal = parseFloat(b.dataset.calc); }
        if (col === 8) { aVal = parseFloat(a.dataset.total);  bVal = parseFloat(b.dataset.total); }
        const aNum = parseFloat(aVal), bNum = parseFloat(bVal);
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
    <?php include __DIR__ . '/chat_widget.php'; ?>
</body>
</html>
