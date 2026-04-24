<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}
require_once __DIR__ . '/db_config.php';

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1)  { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1;  $nextYear++; }

$monthName   = date('F', mktime(0, 0, 0, $month, 1, $year));
$daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
$firstDow    = (int)date('w', mktime(0, 0, 0, $month, 1, $year));

// Pull birthdays — include employee_id so we can click through
$stmtB = $pdo->prepare("
    SELECT w.employee_id, w.first_name, w.last_name, w.role, DAY(w.birthday) AS event_day
    FROM workforce w
    WHERE MONTH(w.birthday) = :month AND w.birthday IS NOT NULL
    ORDER BY DAY(w.birthday), w.last_name
");
$stmtB->execute([':month' => $month]);
$birthdayRows = $stmtB->fetchAll();

// Pull anniversaries — include employee_id
$stmtA = $pdo->prepare("
    SELECT w.employee_id, w.first_name, w.last_name, w.role, DAY(w.anniversary) AS event_day
    FROM workforce w
    WHERE MONTH(w.anniversary) = :month AND w.anniversary IS NOT NULL
    ORDER BY DAY(w.anniversary), w.last_name
");
$stmtA->execute([':month' => $month]);
$anniversaryRows = $stmtA->fetchAll();

// Index by day — store id + name so JS can use both
$birthdays     = [];
$anniversaries = [];

foreach ($birthdayRows as $r) {
    $birthdays[(int)$r['event_day']][] = [
        'id'   => $r['employee_id'],
        'name' => $r['first_name'] . ' ' . $r['last_name'],
        'role' => $r['role'] ?? 'Employee',
    ];
}
foreach ($anniversaryRows as $r) {
    $anniversaries[(int)$r['event_day']][] = [
        'id'   => $r['employee_id'],
        'name' => $r['first_name'] . ' ' . $r['last_name'],
        'role' => $r['role'] ?? 'Employee',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Events — <?= $monthName . ' ' . $year ?></title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --purple: #4D148C; --orange: #FF6200;
      --bg: #f4f4f4; --surface: #ffffff;
      --border: #e0e0e0; --text: #1a1a1a; --muted: #888888;
    }
    html, body { background: var(--bg); color: var(--text); font-family: 'Open Sans', sans-serif; min-height: 100vh; margin: 0; padding: 0; }
    body { display: flex; flex-direction: column; align-items: center; padding-bottom: 60px; }

    .cal-wrapper { width: 100%; max-width: 1280px; padding: 32px 24px 0; }

    .month-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .month-nav h2 { font-size: 24px; font-weight: 700; color: var(--purple); }
    .nav-btn { display: inline-flex; align-items: center; gap: 6px; background: var(--purple); color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-size: 13px; font-family: 'Open Sans', sans-serif; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.15s; }
    .nav-btn:hover { background: #3a0f6e; }

    .legend { display: flex; gap: 20px; margin-bottom: 16px; }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); }
    .legend-dot { width: 12px; height: 12px; border-radius: 3px; }

    .cal-grid { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .cal-dow-row { display: grid; grid-template-columns: repeat(7, 1fr); background: var(--purple); }
    .cal-dow { text-align: center; padding: 10px 0; font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.85); letter-spacing: 0.08em; text-transform: uppercase; }
    .cal-days { display: grid; grid-template-columns: repeat(7, 1fr); }
    .cal-day { border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 8px 6px; min-height: 110px; position: relative; }
    .cal-day:nth-child(7n) { border-right: none; }
    .cal-day.empty { background: #fafafa; }
    .cal-day.today .day-num { background: var(--orange); color: #fff; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
    .day-num { font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 6px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
    .events-area { display: flex; flex-direction: column; gap: 3px; }

    .event-pill { display: flex; align-items: center; gap: 4px; border-radius: 4px; padding: 2px 6px; font-size: 10px; font-weight: 600; line-height: 1.4; cursor: pointer; transition: opacity 0.15s; white-space: nowrap; overflow: hidden; max-width: 100%; }
    .event-pill .pill-name { overflow: hidden; text-overflow: ellipsis; flex: 1; min-width: 0; }
    .event-pill:hover { opacity: 0.8; }
    .event-pill.birthday    { background: #ede0f8; color: var(--purple); border-left: 3px solid var(--purple); }
    .event-pill.anniversary { background: #fff0e6; color: #c44d00;      border-left: 3px solid var(--orange); }
    .event-pill .pill-icon  { font-size: 9px; flex-shrink: 0; }
    .more-badge { font-size: 10px; color: var(--muted); padding-left: 4px; cursor: pointer; font-weight: 600; }
    .more-badge:hover { color: var(--purple); }

    /* ── Day events modal ── */
    .ev-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.35); z-index: 1000; align-items: center; justify-content: center; }
    .ev-modal-overlay.open { display: flex; }
    .ev-modal { background: var(--surface); border-radius: 12px; width: 90%; max-width: 460px; max-height: 80vh; box-shadow: 0 8px 32px rgba(0,0,0,0.18); overflow: hidden; display: flex; flex-direction: column; }
    .ev-modal-top { background: var(--purple); padding: 18px 24px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .ev-modal-top-info { flex: 1; }
    .ev-modal-top-info h2 { color: #fff; font-size: 18px; font-weight: 700; }
    .ev-modal-top-info p  { color: rgba(255,255,255,0.7); font-size: 13px; margin-top: 2px; }
    .ev-modal-back  { background: none; border: none; color: rgba(255,255,255,0.8); font-size: 13px; font-family: 'Open Sans', sans-serif; font-weight: 600; cursor: pointer; padding: 0; white-space: nowrap; flex-shrink: 0; }
    .ev-modal-back:hover { color: #fff; }
    .ev-modal-close { background: none; border: none; color: rgba(255,255,255,0.7); font-size: 22px; cursor: pointer; line-height: 1; padding: 0 4px; flex-shrink: 0; }
    .ev-modal-close:hover { color: #fff; }
    .ev-modal-body { padding: 16px 24px 24px; overflow-y: auto; flex: 1; }

    .ev-section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 10px; margin-top: 4px; }
    .ev-section-label.bday  { color: var(--purple); }
    .ev-section-label.anniv { color: var(--orange); }

    /* Clickable person rows in day modal */
    .ev-person-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; cursor: pointer; transition: background 0.12s; margin-bottom: 4px; }
    .ev-person-row.bday-row  { background: #ede0f8; color: var(--purple); }
    .ev-person-row.anniv-row { background: #fff0e6; color: #c44d00; }
    .ev-person-row:hover { opacity: 0.8; }
    .ev-person-name  { flex: 1; font-size: 13px; font-weight: 600; }
    .ev-person-arrow { font-size: 14px; opacity: 0.5; }

    /* Employee detail inside modal */
    .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
    .detail-item label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); margin-bottom: 3px; }
    .detail-item span  { font-size: 13px; font-weight: 600; color: var(--text); }
    .modal-restricted-notice { background: #f8f4ff; border: 1px solid #ddd0f0; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: var(--muted); margin-bottom: 16px; font-style: italic; }
    .subordinates-section { margin-top: 16px; border-top: 1px solid var(--border); padding-top: 14px; }
    .subordinates-title { font-size: 10px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); margin-bottom: 10px; }

    .sub-row { display: flex; align-items: center; gap: 10px; padding: 8px 8px; border-radius: 6px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.12s; }
    .sub-row:last-child { border-bottom: none; }
    .sub-row:hover { background: #f8f4ff; }
    .sub-badge { width: 30px; height: 30px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 700; flex-shrink: 0; text-transform: uppercase; }
    .sub-badge.Employee  { background: #ede0f8; color: var(--purple); }
    .sub-badge.Manager   { background: #fff0e6; color: var(--orange); }
    .sub-badge.Director  { background: #e6f0ff; color: #1a56c4; }
    .sub-badge.VP        { background: #e6faf0; color: #1a7a4a; }
    .sub-badge.SVP       { background: #fff8e6; color: #b07000; }
    .sub-info { flex: 1; min-width: 0; }
    .sub-name { font-size: 13px; font-weight: 700; color: var(--text); }
    .sub-meta { font-size: 11px; color: var(--muted); }
    .sub-limited { font-size: 11px; color: var(--muted); font-style: italic; }
    .sub-arrow { color: var(--purple); font-size: 14px; opacity: 0.4; }
    .sub-row:hover .sub-arrow { opacity: 1; }

    .ev-loading { text-align: center; padding: 24px; color: var(--muted); font-size: 14px; }

    .role-badge-pill { font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 3px; letter-spacing: 0.04em; flex-shrink: 0; }
    .role-badge-pill.Employee { background: #2e0a5e; color: #e0d0f8; }
    .role-badge-pill.Manager  { background: #7a3200; color: #ffe8d0; }
    .role-badge-pill.Director { background: #1a56c4; color: #e6f0ff; }
    .role-badge-pill.VP       { background: #1a7a4a; color: #e6faf0; }
    .role-badge-pill.SVP      { background: #7a5500; color: #fff3d0; }
  </style>
</head>
<body>
<?php include __DIR__ . '/impersonation_banner.php'; ?>
<?php $activePage = 'events'; include __DIR__ . '/navbar.php'; ?>

<div class="cal-wrapper">

  <div class="month-nav">
    <a class="nav-btn" href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>">&#8592; <?= date('M', mktime(0,0,0,$prevMonth,1,$prevYear)) ?></a>
    <h2><?= $monthName . ' ' . $year ?></h2>
    <a class="nav-btn" href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>"><?= date('M', mktime(0,0,0,$nextMonth,1,$nextYear)) ?> &#8594;</a>
  </div>

  <div class="legend">
    <div class="legend-item">
      <div class="legend-dot" style="background:#ede0f8;border-left:3px solid #4D148C;"></div>
      <span>Birthday</span>
    </div>
    <div class="legend-item">
      <div class="legend-dot" style="background:#fff0e6;border-left:3px solid #FF6200;"></div>
      <span>Work Anniversary</span>
    </div>
  </div>

  <div class="cal-grid">
    <div class="cal-dow-row">
      <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
        <div class="cal-dow"><?= $d ?></div>
      <?php endforeach; ?>
    </div>

    <div class="cal-days">
      <?php for ($i = 0; $i < $firstDow; $i++): ?>
        <div class="cal-day empty"></div>
      <?php endfor; ?>

      <?php
      $today      = (int)date('j');
      $todayMonth = (int)date('n');
      $todayYear  = (int)date('Y');
      $maxVisible = 3;

      for ($day = 1; $day <= $daysInMonth; $day++):
        $isToday = ($day === $today && $month === $todayMonth && $year === $todayYear);
        $bdays   = $birthdays[$day]     ?? [];
        $annivs  = $anniversaries[$day] ?? [];

        // Pass full objects (id + name) to JS
        $modalData = json_encode([
          'day'           => $day,
          'month'         => $monthName,
          'birthdays'     => $bdays,
          'anniversaries' => $annivs,
        ]);
      ?>
        <div class="cal-day <?= $isToday ? 'today' : '' ?>">
          <div class="day-num"><?= $day ?></div>
          <div class="events-area">
            <?php
            $allEvents = [];
            foreach ($bdays  as $p) $allEvents[] = ['type' => 'birthday',     'name' => $p['name'], 'id' => $p['id'], 'role' => $p['role']];
            foreach ($annivs as $p) $allEvents[] = ['type' => 'anniversary',  'name' => $p['name'], 'id' => $p['id'], 'role' => $p['role']];

            $visible  = array_slice($allEvents, 0, $maxVisible);
            $overflow = count($allEvents) - count($visible);

            foreach ($visible as $ev):
              $cls   = $ev['type'];
              $icon  = $cls === 'birthday' ? '🎂' : '🎉';
              $short = strlen($ev['name']) > 14 ? substr($ev['name'], 0, 13) . '…' : $ev['name'];
            ?>
              <div class="event-pill <?= $cls ?>"
                   onclick='openDayModal(<?= htmlspecialchars($modalData, ENT_QUOTES) ?>)'
                   title="<?= htmlspecialchars($ev['name']) ?>">
                <span class="pill-icon"><?= $icon ?></span>
                <span class="pill-name"><?= htmlspecialchars($short) ?></span>
                <?php
                  $roleMap = ['Employee'=>'EMP','Manager'=>'MGR','Director'=>'DIR','VP'=>'VP','SVP'=>'SVP'];
                  $roleLabel = $roleMap[$ev['role']] ?? substr($ev['role'],0,3);
                ?>
                <span class="role-badge-pill <?= htmlspecialchars($ev['role']) ?>"><?= htmlspecialchars($roleLabel) ?></span>
              </div>
            <?php endforeach; ?>

            <?php if ($overflow > 0): ?>
              <div class="more-badge" onclick='openDayModal(<?= htmlspecialchars($modalData, ENT_QUOTES) ?>)'>
                +<?= $overflow ?> more
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endfor; ?>

      <?php
      $totalCells = $firstDow + $daysInMonth;
      $remainder  = $totalCells % 7;
      if ($remainder > 0) for ($i = 0; $i < (7 - $remainder); $i++):
      ?>
        <div class="cal-day empty"></div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- Events modal (day view + employee detail) -->
<div class="ev-modal-overlay" id="ev-modal-overlay" onclick="handleOverlayClick(event)">
  <div class="ev-modal">
    <div class="ev-modal-top">
      <button class="ev-modal-back" id="ev-modal-back" onclick="modalGoBack()" style="display:none;">&#8592; Back</button>
      <div class="ev-modal-top-info">
        <h2 id="ev-modal-title">Events</h2>
        <p id="ev-modal-subtitle"></p>
      </div>
      <button class="ev-modal-close" onclick="closeEvModal()">&#215;</button>
    </div>
    <div class="ev-modal-body" id="ev-modal-body">
      <div class="ev-loading">Loading...</div>
    </div>
  </div>
</div>

<script>
let modalStack = [];

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function roleInitials(role) {
  const map = { Employee:'EMP', Manager:'MGR', Director:'DIR', VP:'VP', SVP:'SVP' };
  return map[role] || (role||'?').substring(0,3).toUpperCase();
}

function formatDate(d) {
  if (!d) return '—';
  const dt = new Date(d + 'T00:00:00');
  return isNaN(dt) ? '—' : dt.toLocaleDateString('en-US', { month:'long', day:'numeric' });
}

function formatDateFull(d) {
  if (!d) return '—';
  const dt = new Date(d + 'T00:00:00');
  return isNaN(dt) ? '—' : dt.toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' });
}

// ── Day events list ────────────────────────────────────────
function openDayModal(data) {
  modalStack = [{ type: 'day', data }];
  document.getElementById('ev-modal-back').style.display    = 'none';
  document.getElementById('ev-modal-title').textContent     = data.month + ' ' + data.day;
  document.getElementById('ev-modal-subtitle').textContent  = data.birthdays.length + ' birthday(s) · ' + data.anniversaries.length + ' anniversary(ies)';
  document.getElementById('ev-modal-overlay').classList.add('open');
  renderDayModal(data);
}

function renderDayModal(data) {
  let html = '';

  if (data.birthdays.length > 0) {
    html += `<div class="ev-section-label bday">🎂 Birthdays</div>`;
    data.birthdays.forEach(p => {
      html += `
        <div class="ev-person-row bday-row" onclick="openEmpDetail('${escHtml(p.id)}')">
          <span class="ev-person-name">${escHtml(p.name)}</span>
          <span class="ev-person-arrow">&#8250;</span>
        </div>`;
    });
  }

  if (data.anniversaries.length > 0) {
    html += `<div class="ev-section-label anniv" style="margin-top:${data.birthdays.length?'16px':'0'}">🎉 Work Anniversaries</div>`;
    data.anniversaries.forEach(p => {
      html += `
        <div class="ev-person-row anniv-row" onclick="openEmpDetail('${escHtml(p.id)}')">
          <span class="ev-person-name">${escHtml(p.name)}</span>
          <span class="ev-person-arrow">&#8250;</span>
        </div>`;
    });
  }

  if (!html) html = '<p style="color:#888;font-size:13px;">No events on this day.</p>';
  document.getElementById('ev-modal-body').innerHTML = html;
}

// ── Employee detail ────────────────────────────────────────
function openEmpDetail(employeeId) {
  modalStack.push({ type: 'emp', employeeId });
  document.getElementById('ev-modal-back').style.display    = 'flex';
  document.getElementById('ev-modal-title').textContent     = 'Loading...';
  document.getElementById('ev-modal-subtitle').textContent  = '';
  document.getElementById('ev-modal-body').innerHTML        = '<div class="ev-loading">&#8987; Fetching details...</div>';

  fetch('employee_detail_api.php?id=' + encodeURIComponent(employeeId))
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        document.getElementById('ev-modal-body').innerHTML = `<div class="ev-loading">&#10060; ${escHtml(data.error)}</div>`;
        return;
      }
      renderEmpDetail(data.employee, data.subordinates);
    })
    .catch(() => {
      document.getElementById('ev-modal-body').innerHTML = '<div class="ev-loading">Error loading details.</div>';
    });
}

function renderEmpDetail(emp, subordinates) {
  const role = emp.role || 'Employee';
  const restricted = emp.view_level === 'name_only';
  const reportsLabel = emp.manager_role ? (emp.manager_role.charAt(0).toUpperCase()+emp.manager_role.slice(1).toLowerCase()) : 'Reports To';
  const reportsValue = emp.manager_first ? emp.manager_first+' '+emp.manager_last : '—';

  document.getElementById('ev-modal-title').textContent    = emp.first_name + ' ' + emp.last_name;
  document.getElementById('ev-modal-subtitle').textContent = (emp.title||role) + ' · ' + role;

  let html = '';

  if (restricted) {
    html += `<div class="modal-restricted-notice">&#128274; Limited view: name and role only.</div>
      <div class="detail-grid">
        <div class="detail-item"><label>Role</label><span>${escHtml(role)}</span></div>
        <div class="detail-item"><label>${escHtml(reportsLabel)}</label><span>${escHtml(reportsValue)}</span></div>
      </div>`;
  } else {
    html += `<div class="detail-grid">
      <div class="detail-item"><label>Employee ID</label><span>${escHtml(emp.employee_id)}</span></div>
      <div class="detail-item"><label>Department</label><span>${escHtml(emp.organization_name||'—')}</span></div>
      <div class="detail-item"><label>Title</label><span>${escHtml(emp.title||'—')}</span></div>
      <div class="detail-item"><label>Job Type</label><span>${escHtml(emp.job_type||'—')}</span></div>
      <div class="detail-item"><label>Pay Band</label><span>${escHtml(emp.pay_band||'—')}</span></div>
      <div class="detail-item"><label>Location</label><span>${emp.work_city&&emp.state?escHtml(emp.work_city+', '+emp.state):'—'}</span></div>
      <div class="detail-item"><label>Tenure</label><span>${emp.tenure!==null?emp.tenure+' years':'—'}</span></div>
      <div class="detail-item"><label>Anniversary</label><span>${formatDateFull(emp.anniversary)}</span></div>
      <div class="detail-item"><label>Birthday</label><span>${formatDate(emp.birthday)}</span></div>
      <div class="detail-item"><label>${escHtml(reportsLabel)}</label><span>${escHtml(reportsValue)}</span></div>
    </div>`;
  }

  if (subordinates && subordinates.length > 0) {
    html += `<div class="subordinates-section"><div class="subordinates-title">&#128101; Direct Reports (${subordinates.length})</div>`;
    subordinates.forEach(sub => {
      const subRole = sub.role || 'Employee';
      const subRestricted = sub.view_level === 'name_only';
      html += `
        <div class="sub-row" onclick="openEmpDetail('${escHtml(sub.employee_id)}')">
          <div class="sub-badge ${escHtml(subRole)}">${roleInitials(subRole)}</div>
          <div class="sub-info">
            <div class="sub-name">${escHtml(sub.first_name+' '+sub.last_name)}</div>
            ${subRestricted
              ? `<div class="sub-limited">&#128274; Limited visibility</div>`
              : `<div class="sub-meta">${escHtml(sub.title||subRole)}${sub.work_city?' · '+escHtml(sub.work_city):''}</div>`}
          </div>
          <div class="sub-arrow">&#8250;</div>
        </div>`;
    });
    html += `</div>`;
  }

  document.getElementById('ev-modal-body').innerHTML = html;
}

// ── Navigation ─────────────────────────────────────────────
function modalGoBack() {
  modalStack.pop();
  const prev = modalStack[modalStack.length - 1];
  if (!prev) { closeEvModal(); return; }

  if (prev.type === 'day') {
    document.getElementById('ev-modal-back').style.display    = 'none';
    document.getElementById('ev-modal-title').textContent     = prev.data.month + ' ' + prev.data.day;
    document.getElementById('ev-modal-subtitle').textContent  = prev.data.birthdays.length + ' birthday(s) · ' + prev.data.anniversaries.length + ' anniversary(ies)';
    renderDayModal(prev.data);
  } else if (prev.type === 'emp') {
    const empId = prev.employeeId;
    modalStack.pop();
    openEmpDetail(empId);
  }
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('ev-modal-overlay')) closeEvModal();
}

function closeEvModal() {
  document.getElementById('ev-modal-overlay').classList.remove('open');
  document.getElementById('ev-modal-back').style.display = 'none';
  modalStack = [];
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEvModal(); });
</script>
<?php include __DIR__ . '/chat_widget.php'; ?>
</body>
</html>
