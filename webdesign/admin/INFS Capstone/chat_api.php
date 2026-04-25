<?php
// ============================================================
// chat_api.php — AI Chat API Endpoint
// Receives a question from the chat widget, verifies the
// user's role against the DB, sends to OpenClaw, returns
// the response as JSON.
// ============================================================
session_start();

header('Content-Type: application/json');

// ── Auth check ────────────────────────────────────────────────
if (!isset($_SESSION['authorized']) || !$_SESSION['authorized']) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authorized']);
    exit();
}

// ── Only accept POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// ── Parse request body ────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$question = trim($body['question'] ?? '');

// ── Handle clear ──────────────────────────────────────────────
if ($question === '__clear__') {
    $_SESSION['chat_history'] = [];
    echo json_encode(['ok' => true]);
    exit();
}

// ── Validate question ─────────────────────────────────────────
if (empty($question)) {
    http_response_code(400);
    echo json_encode(['error' => 'No question provided']);
    exit();
}

if (strlen($question) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Question too long (max 1000 characters)']);
    exit();
}

// ── Verify role against DB (don't trust session blindly) ──────
require_once __DIR__ . '/db_config.php';

$stmt = $pdo->prepare("
    SELECT w.employee_id, w.first_name, w.last_name, w.role,
           w.manager_id, w.director_id, w.vp_id, w.svp_id,
           w.org_id, w.location_id
    FROM workforce w
    WHERE w.employee_id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['employee_id']]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Sysadmin bypass — not in workforce table
// True sysadmin = is_sysadmin flag AND employee_id is '0'
// If is_sysadmin but employee_id is not '0', we're impersonating — use the impersonated role
$is_sysadmin = isset($_SESSION['is_sysadmin']) && $_SESSION['is_sysadmin'] && $_SESSION['employee_id'] === '0';

if (!$employee && !$is_sysadmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Employee not found']);
    exit();
}

// Build verified user context
if ($is_sysadmin) {
    $userContext = [
        'employee_id' => '0',
        'first_name'  => 'Sys',
        'last_name'   => 'Admin',
        'role'        => 'sysadmin',
        'manager_id'  => null,
        'director_id' => null,
        'vp_id'       => null,
        'svp_id'      => null,
    ];
} else {
    $userContext = $employee;
    // Confirm role matches session (detect tampering)
    if (strtolower($employee['role']) !== strtolower($_SESSION['role'] ?? '')) {
        // Role mismatch — use DB value (more trustworthy)
        $userContext['role'] = strtolower($employee['role']);
    }
}

// ── Initialize chat history in session ───────────────────────
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// ── Build system prompt with user context ─────────────────────
$systemPrompt = "You are CandyClaw, the AI assistant for the FedEx Nuggets HR Workforce Dashboard.

The person asking this question is:
- Name: {$userContext['first_name']} {$userContext['last_name']}
- Employee ID: {$userContext['employee_id']}
- Role: {$userContext['role']}
- Manager ID: " . ($userContext['manager_id'] ?? 'N/A') . "
- Director ID: " . ($userContext['director_id'] ?? 'N/A') . "
- VP ID: " . ($userContext['vp_id'] ?? 'N/A') . "
- SVP ID: " . ($userContext['svp_id'] ?? 'N/A') . "

PERMISSION RULES — STRICTLY ENFORCE:
- employee: Can see own info + coworkers under same manager (name and role ONLY for peers)
- manager: Full detail on direct reports. Name/role only for peers.
- director: Full detail on everyone in their chain. Name/role only for peers outside chain.
- vp, svp, sysadmin: Full detail on ALL employees.

You have access to the database at db_prod. Use the exec tool to run SQL queries:
mariadb -h db_prod -u dashboard_user -pDashDB_2026! dashboard_prod -sN -e \"YOUR SQL HERE\"

Answer questions in plain English. Be concise. If you don't know something or can't find it in the database, say so explicitly. Never share data the user is not permitted to see based on their role above.";

// ── Build messages array with history ─────────────────────────
$messages = [];

// Add chat history
foreach ($_SESSION['chat_history'] as $entry) {
    $messages[] = ['role' => 'user',      'content' => $entry['question']];
    $messages[] = ['role' => 'assistant', 'content' => $entry['answer']];
}

// Add current question
$messages[] = ['role' => 'user', 'content' => $question];

// ── Call OpenClaw API ─────────────────────────────────────────
// OpenClaw gateway is at 127.0.0.1:18789 inside the Pi network
// We reach it via its REST API
require_once __DIR__ . '/config/api_config.php';

$openclaw_url = 'http://openclaw:18789/v1/chat/completions';

$allMessages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    $messages
);
$payload = json_encode([
    'model'    => 'openclaw/default',
    'messages' => $allMessages,
    'stream'   => false,
    'user'     => $_SESSION['employee_id'],
]);

$ch = curl_init($openclaw_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . OPENCLAW_API_KEY,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60); // AI can take a moment
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Could not reach AI. Please try again.']);
    exit();
}

$data   = json_decode($response, true);
$answer = $data['message']['content'] ?? $data['content'] ?? 'No response received.';

// ── Save to session chat history ──────────────────────────────
$_SESSION['chat_history'][] = [
    'question'  => $question,
    'answer'    => $answer,
    'timestamp' => date('H:i'),
];

// Keep last 20 exchanges max to avoid session bloat
if (count($_SESSION['chat_history']) > 20) {
    $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -20);
}

// ── Return response ───────────────────────────────────────────
echo json_encode([
    'answer'    => $answer,
    'timestamp' => date('H:i'),
]);
?>
