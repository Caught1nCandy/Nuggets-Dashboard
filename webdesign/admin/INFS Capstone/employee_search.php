<?php
// employee_search.php
// Live employee search with filters — pulls from DB via search_api.php

require_once __DIR__ . '/db_config.php';

// Load filter dropdown options from DB
$locations = $pdo->query("
    SELECT DISTINCT work_city, state
    FROM location
    ORDER BY work_city
")->fetchAll();

$orgs = $pdo->query("
    SELECT org_id, organization_name
    FROM organization
    ORDER BY organization_name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Employee Search — Workforce Dashboard</title>
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

    /* ── Header ── */
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
      flex-shrink: 0;
    }

    /* ── Main layout ── */
    .search-wrapper {
      width: 100%;
      max-width: 1000px;
      padding: 0 24px;
    }

    /* ── Search bar row ── */
    .search-row {
      display: flex;
      gap: 12px;
      align-items: center;
      margin-bottom: 20px;
    }

    .search-input-wrap {
      flex: 1;
      position: relative;
    }

    .search-input-wrap .search-icon {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--muted);
      font-size: 16px;
      pointer-events: none;
    }

    #search-input {
      width: 100%;
      padding: 14px 14px 14px 42px;
      border: 2px solid var(--purple);
      border-radius: 8px;
      font-size: 15px;
      font-family: 'Open Sans', sans-serif;
      color: var(--text);
      background: var(--surface);
      outline: none;
      transition: box-shadow 0.15s;
    }

    #search-input:focus {
      box-shadow: 0 0 0 3px rgba(77,20,140,0.15);
    }

    #search-input::placeholder { color: var(--muted); }

    /* ── Filters panel ── */
    .filters-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      position: relative;
    }

    .filters-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      border-radius: 10px 10px 0 0;
      background: linear-gradient(90deg, var(--purple), var(--orange));
    }

    .filters-title {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 14px;
    }

    .filters-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 12px;
    }

    .filter-group label {
      display: block;
      font-size: 11px;
      font-weight: 700;
      color: var(--purple);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 5px;
    }

    .filter-group select {
      width: 100%;
      padding: 8px 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 13px;
      font-family: 'Open Sans', sans-serif;
      color: var(--text);
      background: var(--bg);
      cursor: pointer;
      outline: none;
    }

    .filter-group select:focus {
      border-color: var(--purple);
    }

    .filter-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: 14px;
    }

    .clear-btn {
      background: none;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 7px 16px;
      font-size: 12px;
      font-family: 'Open Sans', sans-serif;
      color: var(--muted);
      cursor: pointer;
      transition: all 0.15s;
    }

    .clear-btn:hover {
      border-color: var(--purple);
      color: var(--purple);
    }

    /* ── Results area ── */
    .results-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px;
      min-height: 20px;
    }

    .results-count {
      font-size: 12px;
      color: var(--muted);
    }

    .results-count strong { color: var(--purple); }

    /* ── Result cards ── */
    #results-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .result-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 14px 16px;
      display: flex;
      align-items: center;
      gap: 16px;
      cursor: pointer;
      transition: border-color 0.15s, box-shadow 0.15s;
      box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }

    .result-card:hover {
      border-color: var(--purple);
      box-shadow: 0 3px 12px rgba(77,20,140,0.1);
    }

    /* Role badge / avatar */
    .role-badge {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 700;
      flex-shrink: 0;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .role-badge.Employee  { background: #ede0f8; color: var(--purple); }
    .role-badge.Manager   { background: #fff0e6; color: var(--orange); }
    .role-badge.Director  { background: #e6f0ff; color: #1a56c4; }
    .role-badge.VP        { background: #e6faf0; color: #1a7a4a; }
    .role-badge.SVP       { background: #fff8e6; color: #b07000; }

    .result-info {
      flex: 1;
      min-width: 0;
    }

    .result-name {
      font-size: 15px;
      font-weight: 700;
      color: var(--text);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .result-name mark {
      background: #ede0f8;
      color: var(--purple);
      border-radius: 2px;
      padding: 0 1px;
    }

    .result-meta {
      font-size: 12px;
      color: var(--muted);
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .result-meta span {
      margin-right: 12px;
    }

    .result-arrow {
      color: var(--purple);
      font-size: 18px;
      flex-shrink: 0;
      opacity: 0.4;
      transition: opacity 0.15s, transform 0.15s;
    }

    .result-card:hover .result-arrow {
      opacity: 1;
      transform: translateX(3px);
    }

    /* ── Empty / loading states ── */
    .state-msg {
      text-align: center;
      padding: 40px 0;
      color: var(--muted);
      font-size: 14px;
    }

    .state-msg .big { font-size: 32px; margin-bottom: 8px; }

    /* ── Employee detail modal ── */
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
      padding: 0;
      width: 90%;
      max-width: 500px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.18);
      overflow: hidden;
    }

    .modal-top {
      background: var(--purple);
      padding: 20px 24px;
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .modal-avatar {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      background: rgba(255,255,255,0.15);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      color: #fff;
      flex-shrink: 0;
    }

    .modal-top-info h2 {
      color: #fff;
      font-size: 18px;
      font-weight: 700;
    }

    .modal-top-info p {
      color: rgba(255,255,255,0.7);
      font-size: 13px;
      margin-top: 2px;
    }

    .modal-close {
      margin-left: auto;
      background: none;
      border: none;
      color: rgba(255,255,255,0.7);
      font-size: 22px;
      cursor: pointer;
      line-height: 1;
      padding: 0 4px;
      align-self: flex-start;
    }

    .modal-close:hover { color: #fff; }

    .modal-body {
      padding: 20px 24px 24px;
    }

    .detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .detail-item label {
      display: block;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 3px;
    }

    .detail-item span {
      font-size: 13px;
      font-weight: 600;
      color: var(--text);
    }

    .detail-divider {
      height: 1px;
      background: var(--border);
      margin: 16px 0;
    }

    @media (max-width: 600px) {
      .filters-grid { grid-template-columns: 1fr; }
      .detail-grid  { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- Header -->
<div class="page-header">
  <div class="orange-bar"></div>
  <h1>Employee Search</h1>
</div>

<div class="search-wrapper">

  <!-- Search input -->
  <div class="search-row">
    <div class="search-input-wrap">
      <span class="search-icon">&#128269;</span>
      <input
        type="text"
        id="search-input"
        placeholder="Begin typing a name, role, or employee ID..."
        autocomplete="off"
      />
    </div>
  </div>

  <!-- Filters -->
  <div class="filters-card">
    <div class="filters-title">&#9776;&nbsp; Filter by</div>
    <div class="filters-grid">

      <div class="filter-group">
        <label>Location</label>
        <select id="filter-location">
          <option value="">All locations</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= htmlspecialchars($loc['work_city']) ?>">
              <?= htmlspecialchars($loc['work_city']) ?>, <?= htmlspecialchars($loc['state']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-group">
        <label>Department</label>
        <select id="filter-org">
          <option value="">All departments</option>
          <?php foreach ($orgs as $org): ?>
            <option value="<?= htmlspecialchars($org['org_id']) ?>">
              <?= htmlspecialchars($org['organization_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-group">
        <label>Years Employed</label>
        <select id="filter-tenure">
          <option value="">Any tenure</option>
          <option value="0-1">0–1 years</option>
          <option value="2-4">2–4 years</option>
          <option value="5-9">5–9 years</option>
          <option value="10-19">10–19 years</option>
          <option value="20+">20+ years</option>
        </select>
      </div>

    </div>
    <div class="filter-actions">
      <button class="clear-btn" onclick="clearFilters()">Clear filters</button>
    </div>
  </div>

  <!-- Results -->
  <div class="results-header">
    <div class="results-count" id="results-count"></div>
  </div>

  <div id="results-list">
    <div class="state-msg">
      <div class="big">&#128269;</div>
      Start typing to search employees
    </div>
  </div>

</div>

<!-- Employee detail modal -->
<div class="modal-overlay" id="modal-overlay" onclick="closeModal(event)">
  <div class="modal" id="modal">
    <div class="modal-top">
      <div class="modal-avatar" id="modal-avatar"></div>
      <div class="modal-top-info">
        <h2 id="modal-name"></h2>
        <p id="modal-title-role"></p>
      </div>
      <button class="modal-close" onclick="closeModalDirect()">&#215;</button>
    </div>
    <div class="modal-body">
      <div class="detail-grid" id="modal-detail-grid"></div>
    </div>
  </div>
</div>

<script>
const searchInput  = document.getElementById('search-input');
const resultsList  = document.getElementById('results-list');
const resultsCount = document.getElementById('results-count');

let debounceTimer = null;

// ── Highlight matching text ────────────────────────────────────────────────
function highlight(text, query) {
  if (!query) return escHtml(text);
  const escaped = escHtml(text);
  const re = new RegExp('(' + escRe(query) + ')', 'gi');
  return escaped.replace(re, '<mark>$1</mark>');
}

function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function escRe(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// ── Role badge initials ────────────────────────────────────────────────────
function roleInitials(role) {
  if (!role) return '?';
  const map = { Employee:'EMP', Manager:'MGR', Director:'DIR', VP:'VP', SVP:'SVP' };
  return map[role] || role.substring(0,3).toUpperCase();
}

// ── Fetch results ──────────────────────────────────────────────────────────
function fetchResults() {
  const q        = searchInput.value.trim();
  const location = document.getElementById('filter-location').value;
  const org      = document.getElementById('filter-org').value;
  const tenure   = document.getElementById('filter-tenure').value;

  // Show placeholder if nothing entered
  if (!q && !location && !org && !tenure) {
    resultsList.innerHTML = `
      <div class="state-msg">
        <div class="big">&#128269;</div>
        Start typing to search employees
      </div>`;
    resultsCount.innerHTML = '';
    return;
  }

  // Show loading
  resultsList.innerHTML = `<div class="state-msg">Searching...</div>`;

  const params = new URLSearchParams({ q, location, org, tenure });

  fetch('search_api.php?' + params.toString())
    .then(r => r.json())
    .then(data => renderResults(data, q))
    .catch(() => {
      resultsList.innerHTML = `<div class="state-msg">Error contacting server. Please try again.</div>`;
    });
}

// ── Render results ─────────────────────────────────────────────────────────
function renderResults(data, query) {
  if (data.length === 0) {
    resultsList.innerHTML = `
      <div class="state-msg">
        <div class="big">&#128566;</div>
        No employees found
      </div>`;
    resultsCount.innerHTML = '';
    return;
  }

  resultsCount.innerHTML = `Showing <strong>${data.length}</strong> result${data.length !== 1 ? 's' : ''}`;

  resultsList.innerHTML = data.map(emp => {
    const fullName = emp.first_name + ' ' + emp.last_name;
    const role     = emp.role || 'Employee';
    const initials = roleInitials(role);
    const location = emp.work_city && emp.state ? `${emp.work_city}, ${emp.state}` : '—';
    const dept     = emp.organization_name || '—';
    const title    = emp.title || '—';
    const tenure   = emp.tenure !== null ? emp.tenure + ' yr' + (emp.tenure !== 1 ? 's' : '') : '—';

    return `
      <div class="result-card" onclick='openModal(${JSON.stringify(emp)})'>
        <div class="role-badge ${escHtml(role)}">${initials}</div>
        <div class="result-info">
          <div class="result-name">${highlight(fullName, query)}</div>
          <div class="result-meta">
            <span>&#128205; ${escHtml(location)}</span>
            <span>&#127970; ${escHtml(dept)}</span>
            <span>&#128188; ${escHtml(title)}</span>
            <span>&#8987; ${tenure}</span>
          </div>
        </div>
        <div class="result-arrow">&#8250;</div>
      </div>`;
  }).join('');
}

// ── Modal ──────────────────────────────────────────────────────────────────
function openModal(emp) {
  const role    = emp.role || 'Employee';
  const fullName = emp.first_name + ' ' + emp.last_name;

  document.getElementById('modal-avatar').textContent      = roleInitials(role);
  document.getElementById('modal-name').textContent        = fullName;
  document.getElementById('modal-title-role').textContent  = (emp.title || role) + ' · ' + role;

  const fields = [
    { label: 'Employee ID',  value: emp.employee_id },
    { label: 'Pay Band',     value: emp.pay_band || '—' },
    { label: 'Department',   value: emp.organization_name || '—' },
    { label: 'Location',     value: emp.work_city && emp.state ? emp.work_city + ', ' + emp.state : '—' },
    { label: 'Tenure',       value: emp.tenure !== null ? emp.tenure + ' years' : '—' },
    { label: 'Job Type',     value: emp.job_type || '—' },
  ];

  document.getElementById('modal-detail-grid').innerHTML = fields.map(f => `
    <div class="detail-item">
      <label>${escHtml(f.label)}</label>
      <span>${escHtml(String(f.value))}</span>
    </div>`).join('');
}

function closeModal(e) {
  if (e.target === document.getElementById('modal-overlay')) closeModalDirect();
}

function closeModalDirect() {
  document.getElementById('modal-overlay').classList.remove('open');
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeModalDirect();
});

// ── Wire up events ─────────────────────────────────────────────────────────
searchInput.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(fetchResults, 200); // 200ms debounce
});

['filter-location','filter-org','filter-tenure'].forEach(id => {
  document.getElementById(id).addEventListener('change', fetchResults);
});

function clearFilters() {
  document.getElementById('filter-location').value = '';
  document.getElementById('filter-org').value      = '';
  document.getElementById('filter-tenure').value   = '';
  fetchResults();
}

// Open modal — called from render
document.getElementById('modal-overlay').addEventListener('click', closeModal);
</script>

</body>
</html>
