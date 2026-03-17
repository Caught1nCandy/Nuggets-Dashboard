<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST['employee_name']);
    $id = trim($_POST['employee_id']);
    $reason = trim($_POST['update_reason']);
    $details = trim($_POST['details']);

   
    if (empty($name) || empty($id) || empty($reason) || empty($details)) {
        die("All fields are required.");
    }

    // store for success page
    $_SESSION['data_request'] = [
        'name' => htmlspecialchars($name),
        'id' => htmlspecialchars($id),
        'reason' => htmlspecialchars($reason),
        'details' => htmlspecialchars($details)
    ];

    /* db insert
    $conn = new mysqli("localhost", "root", "", "database");

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("
        INSERT INTO update_requests (employee_name, employee_id, reason, details)
        VALUES (?, ?, ?, ?)
    );

    $stmt->bind_param("ssss", $name, $id, $reason, $details);

    if (!$stmt->execute()) {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
    */

    header("Location: Frequest_success.php");
    exit();

} else {
    echo "Invalid request.";
}
?>

<!--
sql for later:
CREATE TABLE IF NOT EXISTS update_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_name VARCHAR(100),
  employee_id VARCHAR(50),
  reason VARCHAR(100),
  details TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-->
