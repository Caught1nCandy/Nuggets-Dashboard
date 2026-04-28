<?php
session_start();
if (!isset($_SESSION['authorized']) || !isset($_SESSION['is_sysadmin']) || !$_SESSION['is_sysadmin']) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}

require_once 'db_config.php';
require_once __DIR__ . '/config/api_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action']      ?? '';
    $proposal_id = $_POST['proposal_id'] ?? '';
    $request_id  = $_POST['request_id']  ?? '';
    $log_id      = $_POST['log_id']      ?? '';

    // ── Pause/Play toggle ────────────────────────────────────
    if ($action === 'pause') {
        $pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'processing_paused'");
        $success = "Processing paused. New requests will queue up until you resume.";

    } elseif ($action === 'play') {
        $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'processing_paused'");
        
        // Immediately fire the webhook to process queued requests
        if (function_exists('curl_init')) {
            $webhook_url = 'http://' . OPENCLAW_TAILSCALE_IP . ':18791/webhook/new-request';
            $ch = curl_init($webhook_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['event' => 'manual_trigger']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_exec($ch);
            curl_close($ch);
        }
        $success = "Processing resumed! Queued requests are now being processed.";

    // ── Approve ──────────────────────────────────────────────
    } elseif ($action === 'approve' && $proposal_id && $request_id) {
        $stmt = $pdo->prepare("SELECT * FROM proposed_changes WHERE proposal_id = ?");
        $stmt->execute([$proposal_id]);
        $proposal = $stmt->fetch();

        if ($proposal) {
            try {
                $pdo->beginTransaction();

                $sql = "UPDATE {$proposal['table_name']} SET {$proposal['column_name']} = ? WHERE employee_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$proposal['new_value'], $proposal['employee_id']]);

                $stmt = $pdo->prepare("
                    INSERT INTO change_log (request_id, proposal_id, employee_id, table_name, column_name, old_value, new_value, applied_by, is_revert, reverted_from_log_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)
                ");
                $stmt->execute([
                    $request_id, $proposal_id, $proposal['employee_id'],
                    $proposal['table_name'], $proposal['column_name'],
                    $proposal['old_value'], $proposal['new_value'],
                    $_SESSION['employee_id']
                ]);

                $stmt = $pdo->prepare("UPDATE proposed_changes SET status = 'approved', reviewed_at = NOW() WHERE proposal_id = ?");
                $stmt->execute([$proposal_id]);

                $stmt = $pdo->prepare("SELECT COUNT(*) as remaining FROM proposed_changes WHERE request_id = ? AND status = 'pending_approval'");
                $stmt->execute([$request_id]);
                if ($stmt->fetch()['remaining'] == 0) {
                    $stmt = $pdo->prepare("UPDATE update_requests SET status = 'completed', processed_at = NOW() WHERE id = ?");
                    $stmt->execute([$request_id]);
                }

                $pdo->commit();
                $success = "Change approved and applied.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed: " . $e->getMessage();
            }
        }

// ── Deny ─────────────────────────────────────────────────
    } elseif ($action === 'deny' && $proposal_id && $request_id) {
        $stmt = $pdo->prepare("UPDATE proposed_changes SET status = 'denied', reviewed_at = NOW() WHERE proposal_id = ?");
        $stmt->execute([$proposal_id]);

        // Log the denial to change_log for record keeping
        $stmt2 = $pdo->prepare("SELECT * FROM proposed_changes WHERE proposal_id = ?");
        $stmt2->execute([$proposal_id]);
        $denied = $stmt2->fetch();
        if ($denied) {
            $stmt3 = $pdo->prepare("
                INSERT INTO change_log (request_id, proposal_id, employee_id, table_name, column_name, old_value, new_value, applied_by, is_revert, reverted_from_log_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)
            ");
            $stmt3->execute([
                $request_id, $proposal_id, $denied['employee_id'],
                $denied['table_name'], $denied['column_name'],
                $denied['old_value'], $denied['new_value'],
                $_SESSION['employee_id']
            ]);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) as remaining FROM proposed_changes WHERE request_id = ? AND status = 'pending_approval'");
        $stmt->execute([$request_id]);
        if ($stmt->fetch()['remaining'] == 0) {
            $stmt = $pdo->prepare("UPDATE update_requests SET status = 'completed', processed_at = NOW() WHERE id = ?");
            $stmt->execute([$request_id]);
        }
        $success = "Proposal denied.";
    // ── Revert ───────────────────────────────────────────────
    } elseif ($action === 'revert' && $log_id) {
        $stmt = $pdo->prepare("SELECT * FROM change_log WHERE log_id = ?");
        $stmt->execute([$log_id]);
        $log = $stmt->fetch();

        if ($log) {
            try {
                $pdo->beginTransaction();

                $sql = "UPDATE {$log['table_name']} SET {$log['column_name']} = ? WHERE employee_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$log['old_value'], $log['employee_id']]);

                $stmt = $pdo->prepare("
                    INSERT INTO change_log (request_id, proposal_id, employee_id, table_name, column_name, old_value, new_value, applied_by, is_revert, reverted_from_log_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([
                    $log['request_id'], $log['proposal_id'], $log['employee_id'],
                    $log['table_name'], $log['column_name'],
                    $log['new_value'], $log['old_value'],
                    $_SESSION['employee_id'], $log_id
                ]);

                $pdo->commit();
                $success = "Change reverted successfully.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Revert failed: " . $e->getMessage();
            }
        }
    }
}

// Get current pause state
$paused_row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'processing_paused'")->fetch();
$is_paused  = $paused_row && $paused_row['setting_value'] === '1';

// Fetch pending proposals grouped by request
$stmt = $pdo->query("
    SELECT 
        ur.id AS request_id, ur.employee_name, ur.employee_id, ur.reason,
        ur.details, ur.submitted_at, ur.status AS request_status,
        pc.proposal_id, pc.table_name, pc.column_name, pc.old_value,
        pc.new_value, pc.confidence, pc.notes
    FROM update_requests ur
    LEFT JOIN proposed_changes pc ON pc.request_id = ur.id AND pc.status = 'pending_approval'
    WHERE ur.status IN ('pending', 'proposed', 'flagged')
    ORDER BY ur.submitted_at ASC, pc.proposal_id ASC
");
$rows = $stmt->fetchAll();

$grouped = [];
foreach ($rows as $row) {
    $rid = $row['request_id'];
    if (!isset($grouped[$rid])) {
        $grouped[$rid] = [
            'request_id'     => $row['request_id'],
            'employee_name'  => $row['employee_name'],
            'employee_id'    => $row['employee_id'],
            'reason'         => $row['reason'],
            'details'        => $row['details'],
            'submitted_at'   => $row['submitted_at'],
            'request_status' => $row['request_status'],
            'proposals'      => []
        ];
    }
    if ($row['proposal_id']) {
        $grouped[$rid]['proposals'][] = $row;
    }
}

$proposed = array_filter($grouped, fn($r) => !empty($r['proposals']));
$flagged  = array_filter($grouped, fn($r) => $r['request_status'] === 'flagged' && empty($r['proposals']));
$pending  = array_filter($grouped, fn($r) => $r['request_status'] === 'pending'  && empty($r['proposals']));

// Fetch change history
$history = $pdo->query("
    SELECT 
        cl.log_id, cl.employee_id, cl.table_name, cl.column_name,
        cl.old_value, cl.new_value, cl.applied_at, cl.applied_by,
        cl.request_id, cl.is_revert, cl.reverted_from_log_id,
        ur.employee_name, ur.reason
    FROM change_log cl
    LEFT JOIN update_requests ur ON ur.id = cl.request_id
    ORDER BY cl.applied_at DESC
    LIMIT 50
")->fetchAll();

$reverted_ids = [];
foreach ($history as $h) {
    if ($h['is_revert'] && $h['reverted_from_log_id']) {
        $reverted_ids[] = $h['reverted_from_log_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Request Approval — Workforce Dashboard</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap');
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --purple: #4D148C; --orange: #FF6200; }
    html, body {
      min-height: 100vh;
      font-family: 'Open Sans', sans-serif;
      display: flex;
      flex-direction: column;
    }

    body::before {
        content: "";
        position: fixed;
        inset: 0;
        background-image: url('fimg/tarmac.jpg');
        background-size: cover;
        background-position: center;
        background-attachment: fixed;
        opacity: 0.5;
        z-index: -1;
    }
      
    .page-content { max-width: 1200px; margin: 40px auto; padding: 0 20px; width: 100%; }
    .section-title { color: white; font-size: 20px; font-weight: 700; margin: 30px 0 12px; text-shadow: 0 1px 4px rgba(0,0,0,0.75); }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
    .alert-success { background: #d4edda; color: #155724; }
    .alert-error   { background: #f8d7da; color: #721c24; }
    .card { background: white; border-radius: 12px; padding: 20px 24px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
    .card-header .employee-info h3 { font-size: 16px; font-weight: 700; color: var(--purple); }
    .card-header .employee-info span { font-size: 13px; color: #666; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
    .badge-high     { background: #d4edda; color: #155724; }
    .badge-medium   { background: #fff3cd; color: #856404; }
    .badge-low      { background: #f8d7da; color: #721c24; }
    .badge-flagged  { background: #f8d7da; color: #721c24; }
    .badge-pending  { background: #e2e3e5; color: #383d41; }
    .badge-reverted { background: #fff3cd; color: #856404; }
    .badge-revert-entry { background: #e8f4fd; color: #0c5460; }
    .reason-tag { display: inline-block; background: var(--purple); color: white; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; margin-bottom: 10px; }
    .details-text { font-size: 14px; color: #444; margin-bottom: 14px; line-height: 1.5; background: #f8f9fa; padding: 10px 14px; border-radius: 6px; border-left: 3px solid var(--orange); }
    .proposals-list { display: flex; flex-direction: column; gap: 10px; }
    .change-proposal { background: #f0f0f8; border-radius: 8px; padding: 14px; }
    .change-proposal h4 { font-size: 13px; font-weight: 700; color: var(--purple); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .change-row { display: flex; gap: 12px; align-items: center; font-size: 13px; flex-wrap: wrap; }
    .field-name { font-weight: 700; color: #333; min-width: 120px; }
    .old-val { background: #fde8e8; padding: 3px 8px; border-radius: 4px; color: #900; text-decoration: line-through; }
    .arrow { color: #666; font-size: 16px; }
    .new-val { background: #e8fde8; padding: 3px 8px; border-radius: 4px; color: #090; font-weight: 700; }
    .notes-text { font-size: 12px; color: #666; margin-top: 8px; font-style: italic; }
    .proposal-actions { display: flex; gap: 8px; margin-top: 10px; }
    .btn { padding: 6px 16px; border: none; border-radius: 6px; font-weight: 700; font-family: 'Open Sans', sans-serif; cursor: pointer; font-size: 13px; transition: opacity 0.15s; }
    .btn:hover { opacity: 0.85; }
    .btn-approve { background: #28a745; color: white; }
    .btn-deny    { background: #dc3545; color: white; }
    .btn-revert  { background: #fd7e14; color: white; }
    .btn-pause   { background: #dc3545; color: white; padding: 10px 24px; font-size: 15px; }
    .btn-play    { background: #28a745; color: white; padding: 10px 24px; font-size: 15px; }
    .submitted-at { font-size: 12px; color: #999; margin-top: 12px; }
    .empty-state { text-align: center; color: rgba(0,0,0,0.7); padding: 20px; font-size: 14px; }
    .history-table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; text-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .history-table th { background: var(--purple); color: white; padding: 10px 14px; text-align: left; font-size: 13px; }
    .history-table td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid #f0f0f0; color: #333; vertical-align: middle; }
    .history-table tr:last-child td { border-bottom: none; }
    .history-table tr:hover td { background: #f8f8ff; }
    .history-table tr.is-revert-row td { background: #f0f8ff; }
    .revert-val { color: #900; text-decoration: line-through; margin-right: 4px; }
    .applied-val { color: #090; font-weight: 700; }
    .log-id-tag { color: #999; font-size: 11px; }

    /* Pause/Play banner */
    .processing-banner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 20px;
      border-radius: 12px;
      margin-bottom: 10px;
      font-weight: 600;
      font-size: 15px;
    }
    .processing-banner.paused {
      background: #fff3cd;
      color: #856404;
      border: 2px solid #ffc107;
    }
    .processing-banner.active {
      background: #d4edda;
      color: #155724;
      border: 2px solid #28a745;
    }
    .banner-left { display: flex; align-items: center; gap: 10px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/impersonation_banner.php'; ?>
<?php $activePage = 'approval'; include __DIR__ . '/navbar.php'; ?>

<div class="page-content">

  <?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- PAUSE / PLAY BANNER -->
  <div class="processing-banner <?= $is_paused ? 'paused' : 'active' ?>">
    <div class="banner-left">
      <?php if ($is_paused): ?>
        ⏸ AI Processing is <strong>PAUSED</strong> — new requests are queuing up and will be processed when you resume.
      <?php else: ?>
        ▶ AI Processing is <strong>ACTIVE</strong> — new requests are processed automatically.
      <?php endif; ?>
    </div>
    <form method="POST">
      <?php if ($is_paused): ?>
        <button type="submit" name="action" value="play" class="btn btn-play">▶ Resume Processing</button>
      <?php else: ?>
        <button type="submit" name="action" value="pause" class="btn btn-pause">⏸ Pause Processing</button>
      <?php endif; ?>
    </form>
  </div>

  <!-- AWAITING APPROVAL -->
  <div class="section-title">⏳ Awaiting Your Approval (<?= count($proposed) ?>)</div>
  <?php if (empty($proposed)): ?>
    <div class="empty-state">No proposed changes waiting for approval.</div>
  <?php else: ?>
    <?php foreach ($proposed as $r): ?>
    <div class="card">
      <div class="card-header">
        <div class="employee-info">
          <h3><?= htmlspecialchars($r['employee_name']) ?> <span style="color:#999">#<?= htmlspecialchars($r['employee_id']) ?></span></h3>
          <span>Request #<?= $r['request_id'] ?> — <?= count($r['proposals']) ?> proposed change(s)</span>
        </div>
      </div>
      <span class="reason-tag"><?= htmlspecialchars($r['reason']) ?></span>
      <div class="details-text">"<?= htmlspecialchars($r['details']) ?>"</div>
      <div class="proposals-list">
        <?php foreach ($r['proposals'] as $p): ?>
        <div class="change-proposal">
          <h4>
            Proposed Change
            <span class="badge badge-<?= $p['confidence'] ?>"><?= ucfirst($p['confidence']) ?> confidence</span>
          </h4>
          <div class="change-row">
            <span class="field-name"><?= htmlspecialchars($p['table_name']) ?>.<?= htmlspecialchars($p['column_name']) ?></span>
            <span class="old-val"><?= htmlspecialchars($p['old_value'] ?? 'null') ?></span>
            <span class="arrow">→</span>
            <span class="new-val"><?= htmlspecialchars($p['new_value']) ?></span>
          </div>
          <?php if ($p['notes']): ?>
            <div class="notes-text">Note: <?= htmlspecialchars($p['notes']) ?></div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="proposal_id" value="<?= $p['proposal_id'] ?>">
            <input type="hidden" name="request_id"  value="<?= $r['request_id'] ?>">
            <div class="proposal-actions">
              <button type="submit" name="action" value="approve" class="btn btn-approve">✓ Approve</button>
              <button type="submit" name="action" value="deny"    class="btn btn-deny">✗ Deny</button>
            </div>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="submitted-at">Submitted <?= $r['submitted_at'] ?></div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- FLAGGED -->
  <div class="section-title">🚩 Flagged — Too Ambiguous (<?= count($flagged) ?>)</div>
  <?php if (empty($flagged)): ?>
    <div class="empty-state">No flagged requests.</div>
  <?php else: ?>
    <?php foreach ($flagged as $r): ?>
    <div class="card">
      <div class="card-header">
        <div class="employee-info">
          <h3><?= htmlspecialchars($r['employee_name']) ?> <span style="color:#999">#<?= htmlspecialchars($r['employee_id']) ?></span></h3>
          <span>Request #<?= $r['request_id'] ?></span>
        </div>
        <span class="badge badge-flagged">Flagged</span>
      </div>
      <span class="reason-tag"><?= htmlspecialchars($r['reason']) ?></span>
      <div class="details-text">"<?= htmlspecialchars($r['details']) ?>"</div>
      <div class="submitted-at">Submitted <?= $r['submitted_at'] ?></div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- PENDING -->
  <div class="section-title">🕐 Pending Processing (<?= count($pending) ?>)</div>
  <?php if (empty($pending)): ?>
    <div class="empty-state">No pending requests.</div>
  <?php else: ?>
    <?php foreach ($pending as $r): ?>
    <div class="card">
      <div class="card-header">
        <div class="employee-info">
          <h3><?= htmlspecialchars($r['employee_name']) ?> <span style="color:#999">#<?= htmlspecialchars($r['employee_id']) ?></span></h3>
          <span>Request #<?= $r['request_id'] ?></span>
        </div>
        <span class="badge badge-pending">Pending</span>
      </div>
      <span class="reason-tag"><?= htmlspecialchars($r['reason']) ?></span>
      <div class="details-text">"<?= htmlspecialchars($r['details']) ?>"</div>
      <div class="submitted-at">Submitted <?= $r['submitted_at'] ?></div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- CHANGE HISTORY -->
  <div class="section-title">📋 Change History (<?= count($history) ?>)</div>
  <?php if (empty($history)): ?>
    <div class="empty-state">No changes have been applied yet.</div>
  <?php else: ?>
    <table class="history-table">
      <thead>
        <tr>
          <th>Log #</th>
          <th>Employee</th>
          <th>Field</th>
          <th>Change</th>
          <th>Reason</th>
          <th>Applied At</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <?php $already_reverted = in_array($h['log_id'], $reverted_ids); ?>
        <tr class="<?= $h['is_revert'] ? 'is-revert-row' : '' ?>">
          <td><span class="log-id-tag">#<?= $h['log_id'] ?></span></td>
          <td>
            <?= htmlspecialchars($h['employee_name'] ?? 'Unknown') ?>
            <br><span style="color:#999;font-size:11px;">#<?= htmlspecialchars($h['employee_id']) ?></span>
          </td>
          <td><strong><?= htmlspecialchars($h['table_name']) ?></strong>.<?= htmlspecialchars($h['column_name']) ?></td>
          <td>
            <span class="revert-val"><?= htmlspecialchars($h['old_value'] ?? 'null') ?></span>
            →
            <span class="applied-val"><?= htmlspecialchars($h['new_value'] ?? 'null') ?></span>
          </td>
          <td>
            <?php if ($h['is_revert']): ?>
              <span class="badge badge-revert-entry">↩ Revert of log #<?= $h['reverted_from_log_id'] ?></span>
            <?php else: ?>
              <?= htmlspecialchars($h['reason'] ?? '—') ?>
            <?php endif; ?>
          </td>
          <td><?= $h['applied_at'] ?></td>
          <td>
            <?php if ($h['is_revert']): ?>
              <span style="color:#999;font-size:12px;">—</span>
            <?php elseif ($already_reverted): ?>
              <span class="badge badge-reverted">Reverted</span>
            <?php elseif ($h['old_value'] !== null): ?>
              <form method="POST" onsubmit="return confirm('Revert this change? This will restore the previous value.');">
                <input type="hidden" name="log_id" value="<?= $h['log_id'] ?>">
                <button type="submit" name="action" value="revert" class="btn btn-revert">↩ Revert</button>
              </form>
            <?php else: ?>
              <span style="color:#999;font-size:12px;">Cannot revert</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

</div>
    <?php include __DIR__ . '/chat_widget.php'; ?>
</body>
</html>
