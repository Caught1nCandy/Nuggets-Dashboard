<?php
// events.php
// Displays a monthly calendar with employee birthdays (purple) and anniversaries (orange)
// pulled live from the database

require_once __DIR__ . '/db_config.php';

// ── Month navigation ──────────────────────────────────────────────────────────
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

// Clamp month 1-12
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$monthName  = date('F', mktime(0, 0, 0, $month, 1, $year));
$daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
$firstDow    = (int)date('w', mktime(0, 0, 0, $month, 1, $year)); // 0=Sun

// ── Pull birthdays for this month ────────────────────────────────────────────
$stmtB = $pdo->prepare("
    SELECT 
        w.employee_id,
        w.first_name,
        w.last_name,
        DAY(w.birthday) AS event_day
    FROM workforce w
    WHERE MONTH(w.birthday) = :month
      AND w.birthday IS NOT NULL
    ORDER BY DAY(w.birthday), w.last_name
");
$stmtB->execute([':month' => $month]);
$birthdayRows = $stmtB->fetchAll();

// ── Pull anniversaries for this month ────────────────────────────────────────
$stmtA = $pdo->prepare("
    SELECT 
        w.employee_id,
        w.first_name,
        w.last_name,
        DAY(w.anniversary) AS event_day
    FROM workforce w
    WHERE MONTH(w.anniversary) = :month
      AND w.anniversary IS NOT NULL
    ORDER BY DAY(w.anniversary), w.last_name
");
$stmtA->execute([':month' => $month]);
$anniversaryRows = $stmtA->fetchAll();

// ── Index by day ──────────────────────────────────────────────────────────────
$birthdays    = []; // day => [ ['first_name'=>..., 'last_name'=>...], ... ]
$anniversaries = [];

foreach ($birthdayRows as $r) {
    $birthdays[(int)$r['event_day']][] = $r['first_name'] . ' ' . $r['last_name'];
}
foreach ($anniversaryRows as $r) {
    $anniversaries[(int)$r['event_day']][] = $r['first_name'] . ' ' . $r['last_name'];
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
      padding: 0 0 60px;
    }

    /* ── Nav header ── */
    .page-header {
      width: 100%;
      background: var(--purple);
      padding: 18px 40px;
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 32px;
    }

    .page-header h1 {
      color: #fff;
      font-size: 22px;
      font-weight: 700;
    }

    .orange-bar {
      width: 4px;
      height: 28px;
      background: var(--orange);
      border-radius: 2px;
    }

    /* ── Calendar wrapper ── */
    .cal-wrapper {
      width: 100%;
      max-width: 1100px;
      padding: 0 24px;
    }

    /* ── Month nav ── */
    .month-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 20px;
    }

    .month-nav h2 {
      font-size: 24px;
      font-weight: 700;
      color: var(--purple);
    }

    .nav-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--purple);
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 8px 16px;
      font-size: 13px;
      font-family: 'Open Sans', sans-serif;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s;
    }

    .nav-btn:hover { background: #3a0f6e; }

    /* ── Legend ── */
    .legend {
      display: flex;
      gap: 20px;
      margin-bottom: 16px;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: var(--muted);
    }

    .legend-dot {
      width: 12px;
      height: 12px;
      border-radius: 3px;
    }

    /* ── Grid ── */
    .cal-grid {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    .cal-dow-row {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      background: var(--purple);
    }

    .cal-dow {
      text-align: center;
      padding: 10px 0;
      font-size: 12px;
      font-weight: 700;
      color: rgba(255,255,255,0.85);
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .cal-days {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
    }

    .cal-day {
      border-right: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
      padding: 8px 6px;
      min-height: 110px;
      vertical-align: top;
      position: relative;
    }

    .cal-day:nth-child(7n) { border-right: none; }

    .cal-day.empty {
      background: #fafafa;
    }

    .cal-day.today .day-num {
      background: var(--orange);
      color: #fff;
      border-radius: 50%;
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .day-num {
      font-size: 13px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 6px;
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .events-area {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    /* ── Event pills ── */
    .event-pill {
      display: flex;
      align-items: center;
      gap: 4px;
      border-radius: 4px;
      padding: 2px 6px;
      font-size: 10px;
      font-weight: 600;
      line-height: 1.4;
      cursor: pointer;
      transition: opacity 0.15s;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }

    .event-pill:hover { opacity: 0.8; }

    .event-pill.birthday {
      background: #ede0f8;
      color: var(--purple);
      border-left: 3px solid var(--purple);
    }

    .event-pill.anniversary {
      background: #fff0e6;
      color: #c44d00;
      border-left: 3px solid var(--orange);
    }

    .event-pill .pill-icon {
      font-size: 9px;
      flex-shrink: 0;
    }

    /* overflow count badge */
    .more-badge {
      font-size: 10px;
      color: var(--muted);
      padding-left: 4px;
      cursor: pointer;
      font-weight: 600;
    }

    .more-badge:hover { color: var(--purple); }

    /* ── Modal overlay ── */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.35);
      z-index: 1000;
      align-items: center;
      justify-content: center;
    }

    .modal-overlay.open { display: flex; }

    .modal {
      background: var(--surface);
      border-radius: 12px;
      padding: 28px;
      min-width: 300px;
      max-width: 420px;
      width: 90%;
      box-shadow: 0 8px 32px rgba(0,0,0,0.18);
      position: relative;
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }

    .modal-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--purple);
    }

    .modal-date {
      font-size: 12px;
      color: var(--muted);
      margin-top: 2px;
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 20px;
      cursor: pointer;
      color: var(--muted);
      line-height: 1;
      padding: 0 4px;
    }

    .modal-close:hover { color: var(--text); }

    .modal-section-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      margin-bottom: 8px;
    }

    .modal-section-label.bday { color: var(--purple); }
    .modal-section-label.anniv { color: var(--orange); }

    .modal-list {
      list-style: none;
      margin-bottom: 16px;
    }

    .modal-list li {
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 13px;
      margin-bottom: 4px;
    }

    .modal-list li.bday-item {
      background: #ede0f8;
      color: var(--purple);
    }

    .modal-list li.anniv-item {
      background: #fff0e6;
      color: #c44d00;
    }
  </style>
</head>
<body>

<!-- Header -->
<div class="page-header">
  <div class="orange-bar"></div>
  <h1>Events</h1>
</div>

<div class="cal-wrapper">

  <!-- Month navigation -->
  <div class="month-nav">
    <a class="nav-btn" href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>">&#8592; <?= date('M', mktime(0,0,0,$prevMonth,1,$prevYear)) ?></a>
    <h2><?= $monthName . ' ' . $year ?></h2>
    <a class="nav-btn" href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>"><?= date('M', mktime(0,0,0,$nextMonth,1,$nextYear)) ?> &#8594;</a>
  </div>

  <!-- Legend -->
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

  <!-- Calendar grid -->
  <div class="cal-grid">

    <!-- Day of week headers -->
    <div class="cal-dow-row">
      <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
        <div class="cal-dow"><?= $d ?></div>
      <?php endforeach; ?>
    </div>

    <!-- Day cells -->
    <div class="cal-days">

      <?php
      // Empty cells before first day
      for ($i = 0; $i < $firstDow; $i++):
      ?>
        <div class="cal-day empty"></div>
      <?php endfor; ?>

      <?php
      $today     = (int)date('j');
      $todayMonth = (int)date('n');
      $todayYear  = (int)date('Y');
      $maxVisible = 3; // max pills before "+X more"

      for ($day = 1; $day <= $daysInMonth; $day++):
        $isToday = ($day === $today && $month === $todayMonth && $year === $todayYear);
        $bdays   = $birthdays[$day]    ?? [];
        $annivs  = $anniversaries[$day] ?? [];

        // Build combined list for modal (encoded as JSON for JS)
        $modalData = json_encode([
          'day'       => $day,
          'month'     => $monthName,
          'birthdays' => $bdays,
          'anniversaries' => $annivs
        ]);
      ?>
        <div class="cal-day <?= $isToday ? 'today' : '' ?>">
          <div class="day-num"><?= $day ?></div>
          <div class="events-area">

            <?php
            $allEvents = [];
            foreach ($bdays   as $name) $allEvents[] = ['type'=>'birthday',    'name'=>$name];
            foreach ($annivs  as $name) $allEvents[] = ['type'=>'anniversary', 'name'=>$name];

            $visible = array_slice($allEvents, 0, $maxVisible);
            $overflow = count($allEvents) - count($visible);

            foreach ($visible as $ev):
              $cls   = $ev['type'];
              $icon  = $cls === 'birthday' ? '🎂' : '🎉';
              $short = strlen($ev['name']) > 14 ? substr($ev['name'], 0, 13) . '…' : $ev['name'];
            ?>
              <div
                class="event-pill <?= $cls ?>"
                onclick='openModal(<?= htmlspecialchars($modalData, ENT_QUOTES) ?>)'
                title="<?= htmlspecialchars($ev['name']) ?>"
              >
                <span class="pill-icon"><?= $icon ?></span>
                <?= htmlspecialchars($short) ?>
              </div>
            <?php endforeach; ?>

            <?php if ($overflow > 0): ?>
              <div
                class="more-badge"
                onclick='openModal(<?= htmlspecialchars($modalData, ENT_QUOTES) ?>)'
              >+<?= $overflow ?> more</div>
            <?php endif; ?>

          </div>
        </div>
      <?php endfor; ?>

      <?php
      // Trailing empty cells to complete last row
      $total     = $firstDow + $daysInMonth;
      $remainder = $total % 7;
      if ($remainder > 0):
        for ($i = 0; $i < (7 - $remainder); $i++):
      ?>
        <div class="cal-day empty"></div>
      <?php
        endfor;
      endif;
      ?>

    </div><!-- .cal-days -->
  </div><!-- .cal-grid -->

</div><!-- .cal-wrapper -->

<!-- Modal -->
<div class="modal-overlay" id="modal-overlay" onclick="closeModal(event)">
  <div class="modal" id="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="modal-title">Events</div>
        <div class="modal-date" id="modal-date"></div>
      </div>
      <button class="modal-close" onclick="closeModalDirect()">&#215;</button>
    </div>
    <div id="modal-body"></div>
  </div>
</div>

<script>
function openModal(data) {
  const overlay = document.getElementById('modal-overlay');
  const title   = document.getElementById('modal-title');
  const dateEl  = document.getElementById('modal-date');
  const body    = document.getElementById('modal-body');

  title.textContent = 'Events — ' + data.month + ' ' + data.day;
  dateEl.textContent = data.birthdays.length + ' birthday(s) · ' + data.anniversaries.length + ' anniversary(ies)';

  let html = '';

  if (data.birthdays.length > 0) {
    html += '<div class="modal-section-label bday">🎂 Birthdays</div><ul class="modal-list">';
    data.birthdays.forEach(name => {
      html += `<li class="bday-item">${name}</li>`;
    });
    html += '</ul>';
  }

  if (data.anniversaries.length > 0) {
    html += '<div class="modal-section-label anniv">🎉 Work Anniversaries</div><ul class="modal-list">';
    data.anniversaries.forEach(name => {
      html += `<li class="anniv-item">${name}</li>`;
    });
    html += '</ul>';
  }

  if (!html) html = '<p style="color:#888;font-size:13px;">No events on this day.</p>';

  body.innerHTML = html;
  overlay.classList.add('open');
}

function closeModal(e) {
  if (e.target === document.getElementById('modal-overlay')) {
    closeModalDirect();
  }
}

function closeModalDirect() {
  document.getElementById('modal-overlay').classList.remove('open');
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeModalDirect();
});
</script>

</body>
</html>
