<?php
// db_config.php
// Central database connection — include this in any PHP file that needs DB access

$host     = 'db_prod';
$dbname   = 'dashboard_prod';
$username = 'dashboard_user';       // change to your MySQL username
$password = 'DashDB_2026!';           // change to your MySQL password

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}
?>
