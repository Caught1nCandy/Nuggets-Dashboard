<?php
// employee_map.php
// Queries the DB directly and injects state headcount into the map JS

require_once __DIR__ . '/db_config.php';

// Pull employee count per state live from the database
$stmt = $pdo->query("
    SELECT 
        l.state,
        COUNT(*) AS employees
    FROM workforce w
    JOIN location l ON l.location_id = w.location_id
    WHERE l.state IS NOT NULL
      AND l.state != ''
    GROUP BY l.state
    ORDER BY employees DESC
");
$rows = $stmt->fetchAll();

// Build a JS-safe associative object: { "AR": 42, "TN": 38, ... }
$stateData = [];
foreach ($rows as $row) {
    $stateData[$row['state']] = (int)$row['employees'];
}
$stateDataJson = json_encode($stateData);

// Total for display
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
    @import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:      #0d0f14;
      --surface: #13161e;
      --border:  #1e2330;
      --accent:  #FF6200;
      --accent2: #4D148C;
      --text:    #e8ecf5;
      --muted:   #5a6278;
    }

    html, body {
      height: 100%;
      background: var(--bg);
      color: var(--text);
      font-family: 'Syne', sans-serif;
    }

    body {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 40px 24px 60px;
    }

    header {
      width: 100%;
      max-width: 1100px;
      margin-bottom: 36px;
    }

    .eyebrow {
      font-family: 'DM Mono', monospace;
      font-size: 11px;
      letter-spacing: 0.2em;
      text-transform: uppercase;
      color: var(--accent);
      margin-bottom: 10px;
    }

    h1 {
      font-size: clamp(26px, 4vw, 44px);
      font-weight: 800;
      letter-spacing: -0.02em;
      background: linear-gradient(135deg, var(--text) 40%, var(--accent));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .subtitle {
      margin-top: 8px;
      font-family: 'DM Mono', monospace;
      font-size: 13px;
      color: var(--muted);
    }

    .dashboard {
      width: 100%;
      max-width: 1100px;
      display: grid;
      grid-template-columns: 1fr 260px;
      gap: 20px;
      align-items: start;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 28px;
      position: relative;
      overflow: hidden;
    }

    .card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 2px;
      background: linear-gradient(90deg, #4D148C, #FF6200);
    }

    #map-svg {
      width: 100%;
      height: auto;
      display: block;
    }

    .state-path {
      stroke: #1a1e2a;
      stroke-width: 0.8;
      cursor: pointer;
      transition: opacity 0.15s;
    }

    .state-path:hover { opacity: 0.75; }

    /* Tooltip */
    #tooltip {
      position: fixed;
      background: #1e2330;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px 14px;
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.15s;
      font-family: 'DM Mono', monospace;
      font-size: 12px;
      color: var(--text);
      z-index: 999;
      white-space: nowrap;
    }

    #tooltip .tt-state {
      font-family: 'Syne', sans-serif;
      font-weight: 700;
      font-size: 14px;
      margin-bottom: 4px;
      color: var(--accent);
    }

    /* Sidebar */
    .sidebar-title {
      font-family: 'DM Mono', monospace;
      font-size: 11px;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 16px;
    }

    .total-count {
      font-size: 42px;
      font-weight: 800;
      color: var(--accent);
      line-height: 1;
      margin-bottom: 4px;
    }

    .total-label {
      font-family: 'DM Mono', monospace;
      font-size: 12px;
      color: var(--muted);
      margin-bottom: 24px;
    }

    .state-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .state-row {
      display: flex;
      align-items: center;
      gap: 10px;
      cursor: pointer;
    }

    .state-row:hover .bar-fill { opacity: 0.8; }

    .state-abbr {
      font-family: 'DM Mono', monospace;
      font-size: 12px;
      font-weight: 500;
      color: var(--text);
      width: 28px;
      flex-shrink: 0;
    }

    .bar-track {
      flex: 1;
      height: 6px;
      background: var(--border);
      border-radius: 99px;
      overflow: hidden;
    }

    .bar-fill {
      height: 100%;
      border-radius: 99px;
      background: linear-gradient(90deg, #4D148C, #FF6200);
      transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .state-num {
      font-family: 'DM Mono', monospace;
      font-size: 12px;
      color: var(--muted);
      width: 28px;
      text-align: right;
      flex-shrink: 0;
    }

    /* Legend */
    .legend {
      margin-top: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .legend-label {
      font-family: 'DM Mono', monospace;
      font-size: 11px;
      color: var(--muted);
    }

    .legend-bar {
      flex: 1;
      height: 8px;
      border-radius: 99px;
      background: linear-gradient(90deg, #4D148C, #7a1fa0, #FF6200);
    }

    @media (max-width: 720px) {
      .dashboard { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<header>
  <div class="eyebrow">Workforce Dashboard</div>
  <h1>Employee Distribution</h1>
  <p class="subtitle">Live data from dashboard_prod &mdash; <?= $total ?> employees across <?= count($stateData) ?> states</p>
</header>

<div class="dashboard">

  <!-- Map -->
  <div class="card">
    <svg id="map-svg" viewBox="0 0 960 600"></svg>
    <div class="legend">
      <span class="legend-label">Fewer</span>
      <div class="legend-bar"></div>
      <span class="legend-label">More</span>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="card">
    <div class="sidebar-title">By State</div>
    <div class="total-count"><?= $total ?></div>
    <div class="total-label">total employees</div>
    <div class="state-list" id="state-list"></div>
  </div>

</div>

<div id="tooltip"></div>

<script>
// ─── Data injected directly from PHP/MySQL ───────────────────────────────────
const stateData = <?= $stateDataJson ?>;

const maxVal = Math.max(...Object.values(stateData));

// Color scale: grey for 0, blue gradient for active states
const colorScale = d3.scaleSequential()
  .domain([0, maxVal])
  .interpolator(d3.interpolate('#4D148C', '#FF6200'));

const getColor = val => val ? colorScale(val) : '#ffffff';

// ─── Build sidebar list ───────────────────────────────────────────────────────
const list = document.getElementById('state-list');
Object.entries(stateData)
  .sort((a, b) => b[1] - a[1])
  .forEach(([state, count]) => {
    const pct = (count / maxVal * 100).toFixed(1);
    const row = document.createElement('div');
    row.className = 'state-row';
    row.dataset.state = state;
    row.innerHTML = `
      <span class="state-abbr">${state}</span>
      <div class="bar-track">
        <div class="bar-fill" style="width: ${pct}%"></div>
      </div>
      <span class="state-num">${count}</span>
    `;
    list.appendChild(row);
  });

// ─── Draw map ─────────────────────────────────────────────────────────────────
const svg       = d3.select('#map-svg');
const tooltip   = document.getElementById('tooltip');
const projection = d3.geoAlbersUsa().scale(1280).translate([480, 300]);
const path       = d3.geoPath().projection(projection);

// State FIPS → abbreviation lookup
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

// Load US TopoJSON from CDN
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
      tooltip.innerHTML     = `
        <div class="tt-state">${abbr}</div>
        ${count ? `<div>${count} employee${count !== 1 ? 's' : ''}</div>` : '<div>No employees</div>'}
      `;
    })
    .on('mouseleave', () => {
      tooltip.style.opacity = '0';
    });
});
</script>
</body>
</html>
