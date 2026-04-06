<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}
require_once __DIR__ . '/db_config.php';

$stmt = $pdo->query("
    SELECT l.state, COUNT(*) AS employees
    FROM workforce w
    JOIN location l ON l.location_id = w.location_id
    WHERE l.state IS NOT NULL AND l.state != ''
    GROUP BY l.state
    ORDER BY employees DESC
");
$rows = $stmt->fetchAll();

$stateData = [];
foreach ($rows as $row) {
    $stateData[$row['state']] = (int)$row['employees'];
}
$stateDataJson = json_encode($stateData);
$total = array_sum($stateData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Employee Distribution — Workforce Dashboard</title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/topojson/3.0.2/topojson.min.js"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --purple:  #4D148C;
      --orange:  #FF6200;
      --bg:      #f4f4f4;
      --surface: #ffffff;
      --border:  #e0e0e0;
      --text:    #1a1a1a;
      --muted:   #666666;
    }

    html, body {
      height: 100%;
      background: var(--bg);
      color: var(--text);
      font-family: 'Open Sans', sans-serif;
      margin: 0; padding: 0;
    }

    body {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding-bottom: 60px;
    }

    .page-subheader {
      width: 100%;
      max-width: 1100px;
      padding: 24px 24px 0;
      margin-bottom: 20px;
    }

    .page-subheader p { font-size: 13px; color: var(--muted); }
    .page-subheader strong { color: var(--purple); }

    .dashboard {
      width: 100%;
      max-width: 1100px;
      padding: 0 24px;
      display: grid;
      grid-template-columns: 1fr 260px;
      gap: 20px;
      align-items: start;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 24px;
      position: relative;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    .card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--purple), var(--orange));
    }

    #map-svg { width: 100%; height: auto; display: block; }

    .state-path {
      stroke: #cccccc;
      stroke-width: 0.8;
      cursor: pointer;
      transition: opacity 0.15s;
    }

    .state-path:hover { opacity: 0.7; }
    .state-path.selected { stroke: var(--orange); stroke-width: 2; opacity: 1; }

    /* Tooltip */
    #tooltip {
      position: fixed;
      background: var(--surface);
      border: 1px solid var(--border);
      border-left: 3px solid var(--purple);
      border-radius: 6px;
      padding: 10px 14px;
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.15s;
      font-size: 12px;
      color: var(--text);
      z-index: 999;
      white-space: nowrap;
      box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    }

    #tooltip .tt-state  { font-weight: 700; font-size: 14px; margin-bottom: 4px; color: var(--purple); }
    #tooltip .tt-count  { color: var(--orange); font-weight: 600; }
    #tooltip .tt-hint   { font-size: 11px; color: var(--muted); margin-top: 4px; }

    /* Sidebar */
    .sidebar-title {
      font-size: 11px; font-weight: 700;
      letter-spacing: 0.12em; text-transform: uppercase;
      color: var(--muted); margin-bottom: 16px;
    }

    .total-count { font-size: 44px; font-weight: 700; color: var(--purple); line-height: 1; margin-bottom: 2px; }
    .total-label { font-size: 12px; color: var(--muted); margin-bottom: 20px; }
    .divider { height: 1px; background: var(--border); margin-bottom: 16px; }

    .state-list { display: flex; flex-direction: column; gap: 10px; }

    .state-row {
      display: flex; align-items: center; gap: 10px;
      cursor: pointer; border-radius: 4px; padding: 2px 4px;
      transition: background 0.12s;
    }

    .state-row:hover { background: #f0ebf8; }
    .state-row:hover .bar-fill { opacity: 0.75; }

    .state-abbr { font-size: 12px; font-weight: 600; color: var(--text); width: 28px; flex-shrink: 0; }

    .bar-track { flex: 1; height: 6px; background: #eeeeee; border-radius: 99px; overflow: hidden; }
    .bar-fill {
      height: 100%; border-radius: 99px;
      background: linear-gradient(90deg, var(--purple), var(--orange));
      transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .state-num { font-size: 12px; color: var(--muted); width: 28px; text-align: right; flex-shrink: 0; }

    .legend { margin-top: 20px; display: flex; align-items: center; gap: 8px; }
    .legend-label { font-size: 11px; color: var(--muted); }
    .legend-bar { flex: 1; height: 8px; border-radius: 99px; background: linear-gradient(90deg, var(--purple), var(--orange)); }

    /* ── State employee modal ── */
    .map-modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.35);
      z-index: 1000;
      align-items: center;
      justify-content: center;
    }

    .map-modal-overlay.open { display: flex; }

    .map-modal {
      background: var(--surface);
      border-radius: 12px;
      width: 90%;
      max-width: 560px;
      max-height: 80vh;
      box-shadow: 0 8px 32px rgba(0,0,0,0.18);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    .map-modal-top {
      background: var(--purple);
      padding: 18px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }

    .map-modal-top h2 { color: #fff; font-size: 18px; font-weight: 700; }
    .map-modal-top p  { color: rgba(255,255,255,0.7); font-size: 13px; margin-top: 2px; }

    .map-modal-close {
      background: none; border: none;
      color: rgba(255,255,255,0.7); font-size: 22px;
      cursor: pointer; line-height: 1; padding: 0 4px;
    }

    .map-modal-close:hover { color: #fff; }

    .map-modal-body {
      padding: 16px 24px 24px;
      overflow-y: auto;
      flex: 1;
    }

    .map-modal-loading {
      text-align: center; padding: 32px;
      color: var(--muted); font-size: 14px;
    }

    .state-emp-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 8px;
      border-radius: 6px;
      border-bottom: 1px solid var(--border);
      transition: background 0.12s;
    }

    .state-emp-row:last-child { border-bottom: none; }
    .state-emp-row:hover { background: #f8f4ff; }

    .state-emp-badge {
      width: 34px; height: 34px;
      border-radius: 7px;
      display: flex; align-items: center; justify-content: center;
      font-size: 10px; font-weight: 700;
      flex-shrink: 0; text-transform: uppercase;
    }

    .state-emp-badge.Employee  { background: #ede0f8; color: var(--purple); }
    .state-emp-badge.Manager   { background: #fff0e6; color: var(--orange); }
    .state-emp-badge.Director  { background: #e6f0ff; color: #1a56c4; }
    .state-emp-badge.VP        { background: #e6faf0; color: #1a7a4a; }
    .state-emp-badge.SVP       { background: #fff8e6; color: #b07000; }

    .state-emp-info { flex: 1; min-width: 0; }

    .state-emp-name { font-size: 14px; font-weight: 700; color: var(--text); }
    .state-emp-meta { font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .state-emp-restricted { font-size: 11px; color: var(--muted); font-style: italic; }

    @media (max-width: 720px) { .dashboard { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<?php include __DIR__ . '/impersonation_banner.php'; ?>
<?php $activePage = 'map'; include __DIR__ . '/navbar.php'; ?>

  <div class="page-subheader">
    <p>Live data from <strong>dashboard_prod</strong> &mdash; <strong><?= $total ?></strong> employees across <strong><?= count($stateData) ?></strong> states &mdash; <em>Click any state to see its employees</em></p>
  </div>

  <div class="dashboard">

    <div class="card">
      <svg id="map-svg" viewBox="0 0 960 600"></svg>
      <div class="legend">
        <span class="legend-label">Fewer</span>
        <div class="legend-bar"></div>
        <span class="legend-label">More</span>
      </div>
    </div>

    <div class="card">
      <div class="sidebar-title">By State</div>
      <div class="total-count"><?= $total ?></div>
      <div class="total-label">total employees</div>
      <div class="divider"></div>
      <div class="state-list" id="state-list"></div>
    </div>

  </div>

  <div id="tooltip"></div>

  <!-- State employee modal -->
  <div class="map-modal-overlay" id="map-modal-overlay" onclick="closeStateModal(event)">
    <div class="map-modal">
      <div class="map-modal-top">
        <div>
          <h2 id="modal-state-title">State</h2>
          <p id="modal-state-count"></p>
        </div>
        <button class="map-modal-close" onclick="closeStateModalDirect()">&#215;</button>
      </div>
      <div class="map-modal-body" id="modal-state-body">
        <div class="map-modal-loading">Loading...</div>
      </div>
    </div>
  </div>

  <script>
  const stateData = <?= $stateDataJson ?>;
  const maxVal    = Math.max(...Object.values(stateData));

  const colorScale = d3.scaleSequential()
    .domain([0, maxVal])
    .interpolator(d3.interpolate('#4D148C', '#FF6200'));

  const getColor = val => val ? colorScale(val) : '#ffffff';

  function roleInitials(role) {
    if (!role) return '?';
    const map = { Employee:'EMP', Manager:'MGR', Director:'DIR', VP:'VP', SVP:'SVP' };
    return map[role] || role.substring(0,3).toUpperCase();
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Build sidebar list — also clickable
  const list = document.getElementById('state-list');
  Object.entries(stateData)
    .sort((a, b) => b[1] - a[1])
    .forEach(([state, count]) => {
      const pct = (count / maxVal * 100).toFixed(1);
      const row = document.createElement('div');
      row.className    = 'state-row';
      row.dataset.state = state;
      row.innerHTML = `
        <span class="state-abbr">${state}</span>
        <div class="bar-track"><div class="bar-fill" style="width:${pct}%"></div></div>
        <span class="state-num">${count}</span>
      `;
      row.addEventListener('click', () => openStateModal(state, count));
      list.appendChild(row);
    });

  // Draw map
  const svg        = d3.select('#map-svg');
  const tooltip    = document.getElementById('tooltip');
  const projection = d3.geoAlbersUsa().scale(1280).translate([480, 300]);
  const path       = d3.geoPath().projection(projection);

  const fipsToAbbr = {
    "01":"AL","02":"AK","04":"AZ","05":"AR","06":"CA","08":"CO","09":"CT",
    "10":"DE","11":"DC","12":"FL","13":"GA","15":"HI","16":"ID","17":"IL",
    "18":"IN","19":"IA","20":"KS","21":"KY","22":"LA","23":"ME","24":"MD",
    "25":"MA","26":"MI","27":"MN","28":"MS","29":"MO","30":"MT","31":"NE",
    "32":"NV","33":"NH","34":"NJ","35":"NM","36":"NY","37":"NC","38":"ND",
    "39":"OH","40":"OK","41":"OR","42":"PA","44":"RI","45":"SC","46":"SD",
    "47":"TN","48":"TX","49":"UT","50":"VT","51":"VA","53":"WA","54":"WV",
    "55":"WI","56":"WY"
  };

  d3.json('https://cdn.jsdelivr.net/npm/us-atlas@3/states-10m.json').then(us => {
    const states = topojson.feature(us, us.objects.states);

    svg.selectAll('.state-path')
      .data(states.features)
      .enter()
      .append('path')
      .attr('class', 'state-path')
      .attr('d', path)
      .attr('fill', d => {
        const abbr  = fipsToAbbr[d.id.toString().padStart(2, '0')];
        const count = stateData[abbr] || 0;
        return getColor(count);
      })
      .on('mousemove', (event, d) => {
        const abbr  = fipsToAbbr[d.id.toString().padStart(2, '0')];
        const count = stateData[abbr] || 0;
        tooltip.style.opacity = '1';
        tooltip.style.left    = (event.clientX + 14) + 'px';
        tooltip.style.top     = (event.clientY - 10) + 'px';
        tooltip.innerHTML = `
          <div class="tt-state">${abbr}</div>
          ${count
            ? `<div><span class="tt-count">${count}</span> employee${count !== 1 ? 's' : ''}</div><div class="tt-hint">Click to view</div>`
            : '<div>No employees</div>'
          }
        `;
      })
      .on('mouseleave', () => {
        tooltip.style.opacity = '0';
      })
      .on('click', (event, d) => {
        const abbr  = fipsToAbbr[d.id.toString().padStart(2, '0')];
        const count = stateData[abbr] || 0;
        if (!abbr) return;
        openStateModal(abbr, count);
      });
  });

  // ── State modal ──────────────────────────────────────────

  function openStateModal(state, count) {
    document.getElementById('modal-state-title').textContent = state + ' — Employees';
    document.getElementById('modal-state-count').textContent = count + ' employee' + (count !== 1 ? 's' : '') + ' in this state';
    document.getElementById('modal-state-body').innerHTML    = '<div class="map-modal-loading">&#8987; Loading...</div>';
    document.getElementById('map-modal-overlay').classList.add('open');

    fetch('map_state_api.php?state=' + encodeURIComponent(state))
      .then(r => r.json())
      .then(data => renderStateModal(data))
      .catch(() => {
        document.getElementById('modal-state-body').innerHTML = '<div class="map-modal-loading">Error loading employees.</div>';
      });
  }

  function renderStateModal(employees) {
    if (!employees.length) {
      document.getElementById('modal-state-body').innerHTML = '<div class="map-modal-loading">No employees found.</div>';
      return;
    }

    const html = employees.map(emp => {
      const role       = emp.role || 'Employee';
      const fullName   = emp.first_name + ' ' + emp.last_name;
      const restricted = emp.view_level === 'name_only';
      const title      = emp.title || role;
      const city       = emp.work_city || '';

      return `
        <div class="state-emp-row">
          <div class="state-emp-badge ${escHtml(role)}">${roleInitials(role)}</div>
          <div class="state-emp-info">
            <div class="state-emp-name">${escHtml(fullName)}</div>
            ${restricted
              ? `<div class="state-emp-restricted">&#128274; Name &amp; role only</div>`
              : `<div class="state-emp-meta">${escHtml(title)}${city ? ' &middot; ' + escHtml(city) : ''}</div>`
            }
          </div>
        </div>`;
    }).join('');

    document.getElementById('modal-state-body').innerHTML = html;
  }

  function closeStateModal(e) {
    if (e.target === document.getElementById('map-modal-overlay')) closeStateModalDirect();
  }

  function closeStateModalDirect() {
    document.getElementById('map-modal-overlay').classList.remove('open');
  }

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeStateModalDirect();
  });
  </script>

</body>
</html>
