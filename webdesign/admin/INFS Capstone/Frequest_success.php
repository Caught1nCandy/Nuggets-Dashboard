<?php
session_start();
if (!isset($_SESSION['data_request'])) {
    echo "No request data found.";
    exit();
}
$data = $_SESSION['data_request'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Request Submitted — Workforce Dashboard</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --purple: #4D148C;
      --orange: #FF6200;
    }

    html, body {
      min-height: 100vh;
      margin: 0;
      padding: 0;
      font-family: 'Open Sans', sans-serif;
      display: flex;
      flex-direction: column;
      align-items: stretch;
    }

    body::before {
        content: "";
        position: fixed;
        inset: 0;
        background-image: url('fimg/closeup.jpg');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        background-attachment: fixed;
        opacity: 0.5;
        z-index: -1;
    }
      
      .form-container {
  align-self: center;
}

    /* ── Combined header + navbar ── */
    .site-header {
      width: 100%;
      align-self: stretch;
      background-color: var(--purple);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 24px;
      gap: 16px;
      min-height: 56px;
    }

    .site-header .orange-bar {
      width: 4px;
      height: 28px;
      background: var(--orange);
      border-radius: 2px;
      flex-shrink: 0;
    }

    .site-header .page-title {
      color: #ffffff;
      font-size: 18px;
      font-weight: 700;
      font-family: 'Open Sans', sans-serif;
      white-space: nowrap;
      margin-right: 8px;
    }

    .site-header .nav-divider {
      width: 1px;
      height: 20px;
      background: rgba(255,255,255,0.25);
      flex-shrink: 0;
    }

    .site-header nav {
      display: flex;
      align-items: center;
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
      border-bottom: 3px solid var(--orange);
    }

    /* ── Success box ── */
    .box {
      background-color: var(--purple);
      color: white;
      width: 400px;
      margin: 100px auto;
      padding: 30px;
      border-radius: 20px;
      font-family: 'Open Sans', sans-serif;
    }

    .box h2 {
      margin-bottom: 20px;
    }

    .box p {
      margin-bottom: 10px;
      font-size: 14px;
    }

    .box button {
      margin: 10px 10px 0 0;
      padding: 10px 20px;
      border: none;
      background-color: white;
      color: var(--purple);
      font-weight: bold;
      font-family: 'Open Sans', sans-serif;
      border-radius: 5px;
      cursor: pointer;
    }

    .box button:hover {
      background-color: #ddd;
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/impersonation_banner.php'; ?>
<?php $activePage = 'request'; include __DIR__ . '/navbar.php'; ?>
  <div class="box">
    <h2>Request Submitted</h2>
    <p><b>Name: </b><?php echo $data['name']; ?></p>
    <p><b>ID: </b><?php echo $data['id']; ?></p>
    <p><b>Reason: </b><?php echo $data['reason']; ?></p>
    <p><b>Details: </b><?php echo $data['details']; ?></p>
    <br>
    <a href="Frequest.php"><button>Submit Another Request</button></a>
    <a href="Fhome.php"><button>Return to Home</button></a>
  </div>

  <?php unset($_SESSION['data_request']); ?>

</body>
</html>
