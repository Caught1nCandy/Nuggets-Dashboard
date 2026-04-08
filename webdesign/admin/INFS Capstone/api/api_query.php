<?php
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../config/api_config.php';

header('Content-Type: application/json');

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$api_key = $body['api_key'] ?? '';

if ($api_key !== OPENCLAW_API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$sql = trim($body['sql'] ?? '');

if (empty($sql)) {
    http_response_code(400);
    echo json_encode(['error' => 'sql required']);
    exit;
}

// STRICT read-only enforcement — only SELECT allowed
$first_word = strtoupper(strtok($sql, " \t\n\r"));
if ($first_word !== 'SELECT') {
    http_response_code(403);
    echo json_encode(['error' => 'Only SELECT queries are permitted']);
    exit;
}

// Block anything that looks write-adjacent even inside a SELECT
$blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE', 'LOAD', 'INTO OUTFILE'];
foreach ($blocked as $keyword) {
    if (stripos($sql, $keyword) !== false) {
        http_response_code(403);
        echo json_encode(['error' => "Blocked keyword detected: $keyword"]);
        exit;
    }
}

// Cap result size to avoid huge dumps
$sql = rtrim($sql, ';');
if (stripos($sql, 'LIMIT') === false) {
    $sql .= ' LIMIT 500';
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count'   => count($results),
        'results' => $results
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query error: ' . $e->getMessage()]);
}
