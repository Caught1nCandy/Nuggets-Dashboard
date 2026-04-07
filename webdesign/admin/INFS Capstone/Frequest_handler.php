<?php
session_start();
require_once __DIR__ . '/config/api_config.php';
require_once __DIR__ . '/db_config.php';

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

    $new_request_id = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    // --- Check if processing is paused ---
    $paused_row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'processing_paused'")->fetch();
    $is_paused  = $paused_row && $paused_row['setting_value'] === '1';

    // --- Fire webhook only if not paused ---
    if (!$is_paused && function_exists('curl_init')) {
        $webhook_url = 'http://' . OPENCLAW_TAILSCALE_IP . ':18791/webhook/new-request';
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
