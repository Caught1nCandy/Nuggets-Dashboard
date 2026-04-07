<?php
// impersonation_banner.php
// Include once per page right after <body>:
//   <?php include __DIR__ . '/impersonation_banner.php'; ?>
// Shows orange impersonation bar when impersonating.
// Shows a smaller sysadmin bar when logged in as sysadmin but not impersonating.

if (!isset($_SESSION['is_sysadmin']) || $_SESSION['is_sysadmin'] !== true) return;

$isImpersonating = ($_SESSION['role'] !== 'sysadmin');

if ($isImpersonating) {
    $bannerName = htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
    $bannerRole = htmlspecialchars(ucfirst($_SESSION['role']));
    $bannerId   = htmlspecialchars($_SESSION['employee_id']);
    echo '
<style>
  #impersonation-banner {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 99999;
    background: #FF6200;
    color: #fff;
    font-family: \'Open Sans\', sans-serif;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
    gap: 12px;
  }
  #impersonation-banner a { color: #fff; text-decoration: underline; font-weight: 700; white-space: nowrap; }
  #impersonation-banner a:hover { opacity: 0.8; }
  body { padding-top: 38px !important; }
</style>
<div id="impersonation-banner">
  <div>
    &#127918;
    Impersonating: <strong>' . $bannerName . '</strong>
    &nbsp;&middot;&nbsp;
    ' . $bannerRole . '
    &nbsp;&middot;&nbsp;
    ID: ' . $bannerId . '
  </div>
  <div>
    <a href="sysadmin_test.php">&#8592; Back to Test Panel</a>
  </div>
</div>';
} else {
    echo '
<style>
  #sysadmin-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 99999;
    background: #1a1a1a;
    color: rgba(255,255,255,0.8);
    font-family: \'Open Sans\', sans-serif;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 18px;
    gap: 12px;
  }
  #sysadmin-bar a { color: #FF6200; text-decoration: none; font-weight: 700; white-space: nowrap; }
  #sysadmin-bar a:hover { text-decoration: underline; }
  body { padding-top: 34px !important; }
</style>
<div id="sysadmin-bar">
  <div>&#9881; Sysadmin mode &nbsp;&middot;&nbsp; Not impersonating</div>
  <div><a href="sysadmin_test.php">&#9881; Go to Test Panel</a></div>
</div>';
}
?>
