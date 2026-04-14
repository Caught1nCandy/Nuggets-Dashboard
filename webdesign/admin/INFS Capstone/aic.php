<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}

// VP and SVP only (sysadmin also allowed for testing)
if (!in_array($_SESSION['role'], ['vp', 'svp', 'sysadmin'])) {
    header("Location: Fhome.php");
    exit();
}

require_once __DIR__ . '/db_config.php';

$myRole = $_SESSION['role'];
$myId   = $_SESSION['employee_id'];

$AIC_POOL = 2500000.00;
$message  = null;
$msgType  = 'success';

// ── Handle save ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $adjustments = $_POST['adjusted'] ?? [];
    $errors = 0;

    $stmt = $pdo->prepare("
        UPDATE aic_ratings
        SET adjusted_amount = :amt,
            finalized       = 1,
            finalized_by    = :by,
            finalized_at    = NOW()
        WHERE employee_id = :id AND is_eligible = 1
    ");

    foreach ($adjustments as $empId => $amount) {
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

    if ($errors === 0) {
        $message = "AIC amounts saved successfully.";
    } else {
        $message = "$errors invalid amounts were skipped. All valid amounts were saved.";
        $msgType = 'warning';
    }

    header("Location: aic.php?saved=1");
    exit();
}

if (isset($_GET['saved'])) {
    $message = "AIC amounts saved successfully.";
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
        a.adjusted_amount,
        a.is_eligible,
        a.finalized,
        mgr.first_name  AS manager_first,
        mgr.last_name   AS manager_last,
        mgr.employee_id AS manager_id,
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

// Totals
$totalCalc     = array_sum(array_column(array_filter($rows, fn($r) => $r['is_eligible']), 'calculated_amount'));
$totalAdjusted = 0;
foreach ($rows as $r) {
    if (!$r['is_eligible']) continue;
    $totalAdjusted += $r['adjusted_amount'] !== null ? floatval($r['adjusted_amount']) : floatval($r['calculated_amount']);
}
$remaining = $AIC_POOL - $totalAdjusted;
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
      --green: #1a7a4a; --red: #c0392b;
    }
    html, body { background: var(--bg); color: var(--text); font-family: 'Open Sans', sans-serif; min-height: 100vh; }
    body { display: flex; flex-direction: column; align-items: center; padding-bottom: 60px; }

    .page-wrapper { width: 100%; max-width: 1300px; padding: 28px 24px 0; }

    /* ── KPI bar ── */
    .kpi-bar {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    .kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; border-bottom: 3px solid var(--purple); }
    .kpi-card.warning { border-bottom-color: var(--red); }
    .kpi-card.ok { border-bottom-color: var(--green); }
    .kpi-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 6px; }
    .kpi-value { font-size: 26px; font-weight: 700; color: var(--purple); }
    .kpi-card.warning .kpi-value { color: var(--red); }
    .kpi-card.ok .kpi-value { color: var(--green); }

    /* ── Alert ── */
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
    .alert.success { background: #e6faf0; color: var(--green); border: 1px solid #a0dfc0; }
    .alert.warning { background: #fff8e6; color: #7a5500; border: 1px solid #f5d98a; }
    .alert.error   { background: #fde8e8; color: var(--red);   border: 1px solid #f5a0a0; }

    /* ── Table card ── */
    .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; }
    .table-card::before { content: ''; display: block; height: 3px; background: linear-gradient(90deg, var(--purple), var(--orange)); }

    .table-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); gap: 12px; flex-wrap: wrap; }
    .table-title { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); }

    .save-btn { background: var(--purple); color: #fff; border: none; border-radius: 7px; padding: 9px 22px; font-size: 13px; font-family: 'Open Sans', sans-serif; font-weight: 700; cursor: pointer; transition: opacity 0.15s; }
    .save-btn:hover { opacity: 0.85; }

    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { background: #f8f6ff; color: var(--purple); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border); cursor: pointer; white-space: nowrap; user-select: none; }
    th:hover { background: #f0ebf8; }
    th .sort-arrow { margin-left: 4px; opacity: 0.4; }
    th.sorted .sort-arrow { opacity: 1; }
    td { padding: 10px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    tr.ineligible td { opacity: 0.5; }

    .role-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .role-badge.Employee  { background: #ede0f8; color: var(--purple); }
    .role-badge.Manager   { background: #fff0e6; color: var(--orange); }

    .rating-bar { display: flex; align-items: center; gap: 8px; }
    .rating-num { font-weight: 700; width: 16px; text-align: right; }
    .rating-track { flex: 1; height: 5px; background: #eeeeee; border-radius: 99px; overflow: hidden; max-width: 80px; }
    .rating-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--purple), var(--orange)); }

    .amount-input { width: 110px; padding: 5px 8px; border: 1px solid var(--border); border-radius: 5px; font-size: 13px; font-family: 'Open Sans', sans-serif; text-align: right; }
    .amount-input:focus { outline: none; border-color: var(--purple); }
    .amount-input.modified { border-color: var(--orange); background: #fff8f0; }
    .amount-input:disabled { background: #f5f5f5; color: var(--muted); }

    .zero-badge { color: var(--muted); font-style: italic; font-size: 12px; }
    .finalized-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--green); display: inline-block; margin-right: 4px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/impersonation_banner.php'; ?>
<?php $activePage = 'aic'; include __DIR__ . '/navbar.php'; ?>

<div class="page-wrapper">

  <?php if ($message): ?>
    <div class="alert <?php echo $msgType; ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <!-- KPI bar -->
  <div class="kpi-bar">
    <div class="kpi-card">
      <div class="kpi-label">AIC Pool</div>
      <div class="kpi-value" id="kpi-pool">$<?php echo number_format($AIC_POOL, 2); ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Eligible Employees</div>
      <div class="kpi-value"><?php echo count(array_filter($rows, fn($r) => $r['is_eligible'])); ?></div>
    </div>
    <div class="kpi-card" id="kpi-allocated-card">
      <div class="kpi-label">Total Allocated</div>
      <div class="kpi-value" id="kpi-allocated">$<?php echo number_format($totalAdjusted, 2); ?></div>
    </div>
    <div class="kpi-card <?php echo abs($remaining) < 0.01 ? 'ok' : ($remaining < 0 ? 'warning' : ''); ?>" id="kpi-remaining-card">
      <div class="kpi-label">Remaining</div>
      <div class="kpi-value" id="kpi-remaining">$<?php echo number_format($remaining, 2); ?></div>
    </div>
  </div>

  <!-- Main table -->
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <div class="table-card">
      <div class="table-toolbar">
        <span class="table-title">AIC Allocations — FY25</span>
        <button type="submit" class="save-btn" onclick="return confirmSave()">&#10003; Save Final Amounts</button>
      </div>
      <div class="table-wrap">
        <table id="aic-table">
          <thead>
            <tr>
              <th onclick="sortTable(0)">Employee <span class="sort-arrow">&#8597;</span></th>
              <th onclick="sortTable(1)">Role <span class="sort-arrow">&#8597;</span></th>
              <th onclick="sortTable(2)">Manager <span class="sort-arrow">&#8597;</span></th>
              <th onclick="sortTable(3)">Director <span class="sort-arrow">&#8597;</span></th>
              <th onclick="sortTable(4)">Dept <span class="sort-arrow">&#8597;</span></th>
              <th onclick="sortTable(5)">Rating <span class="sort-arrow">&#8597;</span></th>
              <th onclick="sortTable(6)">Calculated <span class="sort-arrow">&#8597;</span></th>
              <th>Final Amount</th>
              <th onclick="sortTable(8)">Status <span class="sort-arrow">&#8597;</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $finalAmt = $r['adjusted_amount'] !== null ? floatval($r['adjusted_amount']) : floatval($r['calculated_amount']);
              $managerName  = $r['manager_first']  ? $r['manager_first']  . ' ' . $r['manager_last']  : '—';
              $directorName = $r['director_first'] ? $r['director_first'] . ' ' . $r['director_last'] : '—';
            ?>
            <tr class="<?php echo !$r['is_eligible'] ? 'ineligible' : ''; ?>"
                data-rating="<?php echo $r['performance_rating']; ?>"
                data-calc="<?php echo $r['calculated_amount']; ?>"
                data-final="<?php echo $finalAmt; ?>">
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
                <?php echo $r['is_eligible'] ? '$' . number_format(floatval($r['calculated_amount']), 2) : '—'; ?>
              </td>
              <td>
                <?php if ($r['is_eligible']): ?>
                <input
                  type="number"
                  class="amount-input <?php echo $r['adjusted_amount'] !== null ? 'modified' : ''; ?>"
                  name="adjusted[<?php echo htmlspecialchars($r['employee_id']); ?>]"
                  value="<?php echo number_format($finalAmt, 2, '.', ''); ?>"
                  step="0.01"
                  min="0"
                  data-original="<?php echo $finalAmt; ?>"
                  oninput="onAmountChange(this)"
                />
                <?php else: ?>
                <span class="zero-badge">$0.00</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['finalized']): ?>
                  <span class="finalized-dot"></span>Saved
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

</div>

<script>
const POOL = <?php echo $AIC_POOL; ?>;

function onAmountChange(input) {
    const original = parseFloat(input.dataset.original);
    const current  = parseFloat(input.value) || 0;
    input.classList.toggle('modified', Math.abs(current - original) > 0.005);
    updateTotals();
}

function updateTotals() {
    let total = 0;
    document.querySelectorAll('.amount-input').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });

    const remaining = POOL - total;
    document.getElementById('kpi-allocated').textContent = '$' + total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('kpi-remaining').textContent = '$' + remaining.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

    const remCard = document.getElementById('kpi-remaining-card');
    remCard.className = 'kpi-card';
    if (Math.abs(remaining) < 0.01)  remCard.classList.add('ok');
    if (remaining < -0.01)           remCard.classList.add('warning');
}

function confirmSave() {
    let total = 0;
    document.querySelectorAll('.amount-input').forEach(inp => { total += parseFloat(inp.value) || 0; });
    const remaining = POOL - total;
    if (remaining < -0.01) {
        return confirm(`WARNING: Total allocated ($${total.toFixed(2)}) EXCEEDS the pool ($${POOL.toFixed(2)}) by $${Math.abs(remaining).toFixed(2)}.\n\nSave anyway?`);
    }
    return confirm(`Save final AIC amounts? Total: $${total.toFixed(2)} of $${POOL.toFixed(2)} pool.`);
}

// ── Sortable columns ─────────────────────────────────────────
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
        let aVal = aCell ? aCell.innerText.trim().replace(/[$,]/g, '') : '';
        let bVal = bCell ? bCell.innerText.trim().replace(/[$,]/g, '') : '';

        // For rating column use data attribute
        if (col === 5) { aVal = parseFloat(a.dataset.rating); bVal = parseFloat(b.dataset.rating); }
        if (col === 6) { aVal = parseFloat(a.dataset.calc);   bVal = parseFloat(b.dataset.calc); }

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
