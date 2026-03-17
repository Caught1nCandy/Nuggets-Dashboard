<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}
?>
<?php
// employee_search.php
// Live employee search with filters — pulls from DB via search_api.php

@@ -22,6 +30,7 @@
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link rel="stylesheet" href="FPriv.css">
<title>Employee Search — Workforce Dashboard</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');
@@ -442,264 +451,267 @@
<body>

<!-- Header -->
<div class="page-header">
  <div class="orange-bar"></div>
  <h1>Employee Search</h1>
<div class="navbar">
  <a href="Fprivhome.php">Employee Search</a>
  <a href="Fmap.php">Maps</a>
  <a href="Fevent.php">Events</a>
  <a href="Fdrill.php">Drill Down</a>
  <a href="Frequest.php">Update Request</a>
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
