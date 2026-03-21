<?php
// impersonation_banner.php
// Drop one line into any page that needs it, right after <body>:
//   <?php include __DIR__ . '/impersonation_banner.php'; ?>
// Outputs nothing if not impersonating, so safe to include everywhere.

if (
    isset($_SESSION['is_sysadmin']) &&
    $_SESSION['is_sysadmin'] === true &&
    $_SESSION['role'] !== 'sysadmin'
): ?>
<style>
  /* Forces the banner above everything else on the page */
  #impersonation-banner {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 99999;
    background: #FF6200;
    color: #fff;
    font-family: 'Open Sans', sans-serif;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
    gap: 12px;
  }

  #impersonation-banner .banner-left {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  #impersonation-banner a {
    color: #fff;
    text-decoration: underline;
    font-weight: 700;
    white-space: nowrap;
  }

  #impersonation-banner a:hover { opacity: 0.8; }

  /* Push the rest of the page down so content isn't hidden under the banner */
  body { padding-top: 38px !important; }
</style>

<div id="impersonation-banner">
  <div class="banner-left">
    &#127918;
    <span>
      Impersonating: <strong><?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?></strong>
      &nbsp;·&nbsp;
      <?= htmlspecialchars(ucfirst($_SESSION['role'])) ?>
      &nbsp;·&nbsp;
      ID: <?= htmlspecialchars($_SESSION['employee_id']) ?>
    </span>
  </div>
  <div>
    <a href="sysadmin_test.php">&#8592; Back to Test Panel</a>
  </div>
</div>
<?php endif; ?>
