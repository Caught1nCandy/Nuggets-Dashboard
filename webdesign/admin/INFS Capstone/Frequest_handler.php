<?php
session_start();
require_once __DIR__ . '/config/api_config.php';

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

    // Get the new request ID
    $new_request_id = $stmt->insert_id;

    $stmt->close();
    $conn->close();

    // --- Try to ping OpenClaw immediately via Tailscale (fire and forget) ---
    // If VM is off, this times out silently after 3 seconds.
    // OpenClaw will pick it up on next boot via cron job.
    if (function_exists('curl_init')) {
        $webhook_url = 'http://' . OPENCLAW_TAILSCALE_IP . ':' . OPENCLAW_PORT . '/webhook/new-request';
        $payload = json_encode([
            'event'      => 'new_update_request',
            'request_id' => $new_request_id,
            'api_key'    => OPENCLAW_API_KEY
        ]);
        $ch = curl_init($webhook_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
        curl_close($ch);
    }

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
