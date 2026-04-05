<?php
// ============================================================
// FHandler.php — Login Handler
// Must stay at the very top — no HTML before this block,
// or session_start() and header() redirects will fail.
// ============================================================
session_start();

// ------------------------------------------------------------
// Grab POST values
// ------------------------------------------------------------
$un = isset($_POST['name']) ? trim($_POST['name']) : '';
$ps = isset($_POST['pswd']) ? $_POST['pswd']       : '';
$ip = isset($_POST['ip'])   ? $_POST['ip']         : '';

// Basic sanity check — don't even hit the DB if fields are empty
if ($un === '' || $ps === '') {
    header("Location: FEDEXHR.php?error=1");
    exit();
}

// ------------------------------------------------------------
// SYSADMIN BYPASS — hardcoded for testing only
// Username: 79723646 (S-Y-S-A-D-M-I-N on a phone keypad)
// Password: sys4dm1n
// REMOVE THIS BLOCK before going to production
// ------------------------------------------------------------
if ($un === '79723646' && $ps === 'sys4dm1n') {
    $_SESSION['authorized']  = true;
    $_SESSION['employee_id'] = '0';
    $_SESSION['role']        = 'sysadmin';
    $_SESSION['first_name']  = 'Sys';
    $_SESSION['last_name']   = 'Admin';
    $_SESSION['is_sysadmin'] = true;  // persists even during impersonation
    header("Location: sysadmin_test.php");
    exit();
}

// ------------------------------------------------------------
// DB connection — all credentials live in db_config.php
// This gives us the $pdo object, nothing else needed here
// ------------------------------------------------------------
require_once 'db_config.php';

// ------------------------------------------------------------
// Look up the user — JOIN login → workforce to get role
// Using a prepared statement to prevent SQL injection
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        l.password,
        w.employee_id,
        w.first_name,
        w.last_name,
        w.role
    FROM login l
    JOIN workforce w ON w.employee_id = l.employee_id
    WHERE l.username = ?
    LIMIT 1
");

$stmt->execute([$un]);
$row = $stmt->fetch();

if (!$row) {
    // Username not found
    header("Location: FEDEXHR.php?error=1");
    exit();
}

// ------------------------------------------------------------
// Password verification
//
// NOTE: The seed.sql currently stores SHA2(employee_id, 256)
// as a placeholder. Once the real password CSV is imported
// using password_hash() (Option A), swap the block below to:
//
//   if (password_verify($ps, $row['password'])) { ... }
//
// For now, comparing against SHA2 hash:
// ------------------------------------------------------------
$inputHash = hash('sha256', $ps);

if ($inputHash !== $row['password']) {
    // Wrong password
    header("Location: FEDEXHR.php?error=1");
    exit();
}

// ------------------------------------------------------------
// Success — store everything the app will need in session
// so downstream pages don't need to re-query role constantly
// ------------------------------------------------------------
$_SESSION['authorized']  = true;
$_SESSION['employee_id'] = $row['employee_id'];
$_SESSION['role']        = strtolower($row['role']); // normalize to lowercase
$_SESSION['first_name']  = $row['first_name'];
$_SESSION['last_name']   = $row['last_name'];

// ------------------------------------------------------------
// Route to the right landing page based on role
// Adjust destination pages as your dashboard grows
// ------------------------------------------------------------
switch ($_SESSION['role']) {
    case 'svp':
    case 'vp':
    case 'director':
    case 'manager':
        header("Location: Fhome.php");
        break;
    case 'employee':
    default:
        header("Location: Fhome.php");
        break;
}

exit();
?>
