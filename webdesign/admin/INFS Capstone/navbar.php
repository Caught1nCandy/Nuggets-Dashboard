<?php
// navbar.php — shared navigation bar
// Usage: set $activePage before including, then call include:
//
//   $activePage = 'search'; // or 'map', 'events', 'drill', 'request'
//   include __DIR__ . '/navbar.php';
//
// Remove the old site-header div from the page entirely.

$currentRole = $_SESSION['role'] ?? '';

$navPages = [
    'dashboard'=> ['label' => 'Dashboard',        'href' => 'Fhome.php',           'roles' => ['all']],
    'search'   => ['label' => 'Employee Search', 'href' => 'employee_search.php',  'roles' => ['all']],
    'map'      => ['label' => 'Maps',             'href' => 'employee_map.php',     'roles' => ['all']],
    'events'   => ['label' => 'Events',           'href' => 'events.php',           'roles' => ['all']],
    'request'  => ['label' => 'Update Request',   'href' => 'Frequest.php',         'roles' => ['manager','director','vp','svp','sysadmin']],
    'approval' => ['label' => 'Request Approval', 'href' => 'request_approval.php', 'roles' => ['sysadmin']],
];

$displayName = htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$displayRole = htmlspecialchars(ucfirst($_SESSION['role'] ?? ''));
$activePage  = $activePage ?? '';
?>
<style>
  .site-header {
    width: 100%;
    align-self: stretch;
    background-color: #4D148C;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 24px;
    gap: 0;
    min-height: 56px;
    box-sizing: border-box;
    position: relative;
  }

  .site-header .nav-user {
    display: flex;
    flex-direction: column;
    justify-content: center;
    margin-right: 20px;
    flex-shrink: 0;
  }

  .site-header .nav-user-name {
    color: #ffffff;
    font-size: 13px;
    font-weight: 700;
    font-family: 'Open Sans', sans-serif;
    white-space: nowrap;
    line-height: 1.2;
  }

  .site-header .nav-user-role {
    color: rgba(255,255,255,0.6);
    font-size: 11px;
    font-family: 'Open Sans', sans-serif;
    white-space: nowrap;
    line-height: 1.2;
  }

  .site-header .nav-divider {
    width: 1px;
    height: 20px;
    background: rgba(255,255,255,0.25);
    flex-shrink: 0;
    margin-right: 8px;
  }

  .site-header .orange-bar {
    width: 4px;
    height: 28px;
    background: #FF6200;
    border-radius: 2px;
    flex-shrink: 0;
    margin-right: 12px;
  }

  .site-header .page-title {
    color: #ffffff;
    font-size: 18px;
    font-weight: 700;
    font-family: 'Open Sans', sans-serif;
    white-space: nowrap;
    margin-right: 16px;
  }

  .site-header nav {
    display: flex;
    align-items: center;
    gap: 0;
  }

  .site-header nav a {
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    font-size: 14px;
    font-family: 'Open Sans', sans-serif;
    font-weight: 600;
    padding: 18px 16px;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
  }

  .site-header nav a:hover {
    background-color: rgba(255,255,255,0.12);
    color: #ffffff;
  }

  .site-header nav a.active {
    color: #ffffff;
    border-bottom: 3px solid #FF6200;
  }

  .site-header .nav-logout {
    position: absolute;
    right: 24px;
    color: rgba(255,255,255,0.6);
    text-decoration: none;
    font-size: 12px;
    font-family: 'Open Sans', sans-serif;
    font-weight: 600;
    padding: 8px 12px;
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 5px;
    flex-shrink: 0;
    transition: all 0.15s;
  }

  .site-header .nav-logout:hover {
    background: rgba(255,255,255,0.1);
    color: #ffffff;
  }
</style>

<div class="site-header">
  <!-- Left: logged-in user identity -->
  <div class="nav-user">
    <span class="nav-user-name"><?php echo $displayName; ?></span>
    <span class="nav-user-role"><?php echo $displayRole; ?></span>
  </div>

  <div class="nav-divider"></div>

  <!-- Page title -->
  <div class="orange-bar"></div>
  <span class="page-title"><?php echo htmlspecialchars($navPages[$activePage]['label'] ?? 'Dashboard'); ?></span>

  <!-- Nav links -->
  <nav>
    <?php foreach ($navPages as $key => $page):
        $allowed = $page['roles'] === ['all'] || in_array($currentRole, $page['roles']);
        if (!$allowed) continue;
    ?>
      <a href="<?php echo $page['href']; ?>"
         class="<?php echo $key === $activePage ? 'active' : ''; ?>">
        <?php echo $page['label']; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- Logout -->
  <a href="logout.php" class="nav-logout">&#x2192; Logout</a>
</div>
