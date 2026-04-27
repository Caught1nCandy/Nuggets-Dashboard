<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}
// Employees cannot submit update requests
if ($_SESSION['role'] === 'employee') {
    header("Location: employee_search.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Update Request — Workforce Dashboard</title>
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
      background-image: url('fimg/tarmac.jpg');
      opacity: .50;
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      background-attachment: fixed;
      display: flex;
      flex-direction: column;
      align-items: stretch;
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

    /* ── Form styles ── */
    .form-container {
      background-color: var(--purple);
      width: 400px;
      margin: 80px auto;
      padding: 30px;
      border-radius: 20px;
      color: white;
      text-align: left;
    }

    .form-container h1 {
      text-align: center;
      margin-bottom: 20px;
      font-family: 'Open Sans', sans-serif;
    }

    .form-container input,
    .form-container textarea,
    .form-container select {
      width: 100%;
      padding: 10px;
      margin: 10px 0 20px 0;
      border: none;
      border-radius: 5px;
      font-family: 'Open Sans', sans-serif;
    }

    .form-container button {
      width: 100%;
      padding: 12px;
      background-color: white;
      color: var(--purple);
      border: none;
      border-radius: 5px;
      font-weight: bold;
      font-family: 'Open Sans', sans-serif;
      cursor: pointer;
    }

    .form-container button:hover {
      background-color: #ddd;
    }

    #charCount {
      text-align: right;
      font-size: 12px;
      margin-top: -15px;
      margin-bottom: 15px;
      color: #ddd;
    }
      .site-header {
  align-self: stretch !important;
  width: 100% !important;
}

    textarea {
      resize: none;
      overflow-y: auto;
      height: 120px;
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/impersonation_banner.php'; ?>
<?php $activePage = 'request'; include __DIR__ . '/navbar.php'; ?>


  <div class="form-container">
    <h1>Update Request</h1>
    <form action="Frequest_handler.php" method="POST">
      <label>Employee Name</label><br>
      <input type="text" name="employee_name" maxlength="30" required><br>
      <label>Employee ID</label><br>
      <input type="text" name="employee_id" maxlength="10" required pattern="[0-9]*" oninput="this.value = this.value.replace(/[^0-9]/g, '')"><br>
      <label>Reason for Update</label><br>
      <select name="update_reason" required>
        <option value="">-- Select Reason --</option>
        <option value="Address Change">Address Change</option>
        <option value="Promotion">Promotion</option>
        <option value="Termination">Termination</option>
        <option value="Department Transfer">Department Transfer</option>
        <option value="Other">Other</option>
      </select><br>
      <label>Details</label><br>
      <textarea name="details" rows="4" maxlength="500" required oninput="updateCounter(this)"></textarea>
      <div id="charCount">0 / 500</div>
      <button type="submit">Submit Request</button>
    </form>
  </div>

  <script>
    function updateCounter(textarea) {
      const counter = document.getElementById("charCount");
      counter.textContent = textarea.value.length + " / 500";
    }
  </script>
<?php include __DIR__ . '/chat_widget.php'; ?>
</body>
</html>
