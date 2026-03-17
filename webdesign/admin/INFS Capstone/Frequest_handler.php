<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name    = trim($_POST['employee_name']);
    $id      = trim($_POST['employee_id']);
    $reason  = trim($_POST['update_reason']);
    $details = trim($_POST['details']);

    if (empty($name) || empty($id) || empty($reason) || empty($details)) {
        die("All fields are required.");
    }

    // --- DB Insert ---
    $conn = new mysqli("db_prod", "dashboard_user", "DashDB_2026!", "dashboard_prod");

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("
        INSERT INTO update_requests (employee_name, employee_id, reason, details)
        VALUES (?, ?, ?, ?)
    ");

    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("ssss", $name, $id, $reason, $details);

    if (!$stmt->execute()) {
        die("Execute failed: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();

    // Store sanitized copy for success page display
    $_SESSION['data_request'] = [
        'name'   => htmlspecialchars($name),
        'id'     => htmlspecialchars($id),
        'reason' => htmlspecialchars($reason),
        'details'=> htmlspecialchars($details)
    ];

    header("Location: Frequest_success.php");
    exit();

} else {
    echo "Invalid request.";
}
?>
