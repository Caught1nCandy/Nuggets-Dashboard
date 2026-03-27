<?php
session_start();
if (!isset($_SESSION['authorized']) || !isset($_SESSION['is_sysadmin']) || !$_SESSION['is_sysadmin']) {
    session_destroy();
    header("Location: FEDEXHR.php");
    exit();
}

require_once 'db_config.php';

// Handle approve/deny actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action']      ?? '';
    $proposal_id = $_POST['proposal_id'] ?? '';
    $request_id  = $_POST['request_id']  ?? '';

    if ($action === 'approve' && $proposal_id && $request_id) {
        // Get proposal details
        $stmt = $pdo->prepare("SELECT * FROM proposed_changes WHERE proposal_id = ?");
        $stmt->execute([$proposal_id]);
        $proposal = $stmt->fetch();

        if ($proposal) {
            try {
                $pdo->beginTransaction();

                // Apply the change to the actual table
                $sql = "UPDATE {$proposal['table_name']} SET {$proposal['column_name']} = ? WHERE employee_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$proposal['new_value'], $proposal['employee_id']]);

                // Log the change
                $stmt = $pdo->prepare("
                    INSERT INTO change_log (request_id, proposal_id, employee_id, table_name, column_name, old_value, new_value, applied_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $request_id,
                    $proposal_id,
                    $proposal['employee_id'],
                    $proposal['table_name'],
                    $proposal['column_name'],
                    $proposal['old_value'],
                    $proposal['new_value'],
                    $_SESSION['employee_id']
                ]);

                // Mark proposal as approved
                $stmt = $pdo->prepare("UPDATE proposed_changes SET status = 'approved', reviewed_at = NOW() WHERE proposal_id = ?");
                $stmt->execute([$proposal_id]);

                // Mark request as completed
                $stmt = $pdo->prepare("UPDATE update_requests SET status = 'completed', processed_at = NOW() WHERE id = ?");
                $stmt->execute([$request_id]);

                $pdo->commit();
                $success = "Change approved and applied successfully.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to apply change: " . $e->getMessage();
            }
        }
    } elseif ($action === 'deny' && $proposal_id && $request_id) {
        $stmt = $pdo->prepare("UPDATE proposed_changes SET status = 'denied', reviewed_at = NOW() WHERE proposal_id = ?");
        $stmt->execute([$proposal_id]);
        $stmt = $pdo->prepare("UPDATE update_requests SET status = 'denied', processed_at = NOW() WHERE id = ?");
        $stmt->execute([$request_id]);
        $success = "Request denied.";
    }
}

// Fetch pending requests with their proposed changes
$stmt = $pdo->query("
    SELECT 
        ur.id AS request_id,
        ur.employee_name,
        ur.employee_id,
        ur.reason,
        ur.details,
        ur.submitted_at,
        ur.status AS request_status,
        pc.proposal_id,
        pc.table_name,
        pc.column_name,
        pc.old_value,
        pc.new_value,
        pc.confidence,
        pc.notes,
        pc.status AS proposal_status
    FROM update_requests ur
    LEFT JOIN proposed_changes pc ON pc.request_id = ur.id AND pc.status = 'pending_approval'
    WHERE ur.status IN ('pending', 'proposed', 'flagged')
    ORDER BY ur.submitted_at ASC
");
$requests = $stmt->fetchAll();

// Separate into categories
$proposed = array_filter($requests, fn($r) => $r['proposal_id'] !== null);
$flagged  = array_filter($requests, fn($r) => $r['request_status'] === 'flagged' && $r['proposal_id'] === null);
$pending  = array_filter($requests, fn($r) => $r['request_status'] === 'pending' && $r['proposal_id'] === null);
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

    :root {
      --purple: #4D148C;
      --orange: #FF6200;
    }

    html, body {
      min-height: 100vh;
      font-family: 'Open Sans', sans-serif;
      background-image: url('fimg/tarmac.jpg');
      background-size: cover;
      background-position: center;
      background-attachment: fixed;
      display: flex;
      flex-direction: column;
    }

    .page-content {
      max-width: 1100px;
      margin: 40px auto;
      padding: 0 20px;
      width: 100%;
    }

    .section-title {
      color: white;
      font-size: 20px;
      font-weight: 700;
      margin: 30px 0 12px;
      text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    }

    .alert {
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-weight: 600;
    }
    .alert-success { background: #d4edda; color: #155724; }
    .alert-error   { background: #f8d7da; color: #721c24; }

    .card {
      background: white;
      border-radius: 12px;
      padding: 20px 24px;
      margin-bottom: 16px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 12px;
    }

    .card-header .employee-info h3 {
      font-size: 16px;
      font-weight: 700;
      color: var(--purple);
    }

    .card-header .employee-info span {
      font-size: 13px;
      color: #666;
    }

    .badge {
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .badge-high     { background: #d4edda; color: #155724; }
    .badge-medium   { background: #fff3cd; color: #856404; }
    .badge-low      { background: #f8d7da; color: #721c24; }
    .badge-flagged  { background: #f8d7da; color: #721c24; }
    .badge-pending  { background: #e2e3e5; color: #383d41; }

    .reason-tag {
      display: inline-block;
      background: var(--purple);
      color: white;
      padding: 3px 10px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 10px;
    }

    .details-text {
      font-size: 14px;
      color: #444;
      margin-bottom: 14px;
      line-height: 1.5;
      background: #f8f9fa;
      padding: 10px 14px;
      border-radius: 6px;
      border-left: 3px solid var(--orange);
    }

    .change-proposal {
      background: #f0f0f8;
      border-radius: 8px;
      padding: 14px;
      margin-bottom: 14px;
    }

    .change-proposal h4 {
      font-size: 13px;
      font-weight: 700;
      color: var(--purple);
      margin-bottom: 8px;
    }

    .change-row {
      display: flex;
      gap: 12px;
      align-items: center;
      font-size: 13px;
    }

    .change-row .field-name {
      font-weight: 700;
      color: #333;
      min-width: 120px;
    }

    .old-val {
      background: #fde8e8;
      padding: 3px 8px;
      border-radius: 4px;
      color: #900;
      text-decoration: line-through;
    }

    .arrow { color: #666; font-size: 16px; }

    .new-val {
      background: #e8fde8;
      padding: 3px 8px;
      border-radius: 4px;
      color: #090;
      font-weight: 700;
    }

    .notes-text {
      font-size: 12px;
      color: #666;
      margin-top: 8px;
      font-style: italic;
    }

    .action-buttons {
      display: flex;
      gap: 10px;
      margin-top: 14px;
    }

    .btn {
      padding: 8px 20px;
      border: none;
      border-radius: 6px;
      font-weight: 700;
      font-family: 'Open Sans', sans-serif;
      cursor: pointer;
      font-size: 14px;
      transition: opacity 0.15s;
    }
    .btn:hover { opacity: 0.85; }
    .btn-approve { background: #28a745; color: white; }
    .btn-deny    { background: #dc3545; color: white; }

    .submitted-at {
      font-size: 12px;
      color: #999;
      margin-top: 8px;
    }

    .empty-state {
      text-align: center;
      color: rgba(255,255,255,0.7);
      padding: 20px;
      font-size: 14px;
    }
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

  <!-- PROPOSED CHANGES AWAITING APPROVAL -->
  <div class="section-title">⏳ Awaiting Your Approval (<?= count($proposed) ?>)</div>
  <?php if (empty($proposed)): ?>
    <div class="empty-state">No proposed changes waiting for approval.</div>
  <?php else: ?>
    <?php foreach ($proposed as $r): ?>
    <div class="card">
      <div class="card-header">
        <div class="employee-info">
          <h3><?= htmlspecialchars($r['employee_name']) ?> <span style="color:#999">#<?= htmlspecialchars($r['employee_id']) ?></span></h3>
          <span>Request #<?= $r['request_id'] ?></span>
        </div>
        <span class="badge badge-<?= $r['confidence'] ?>"><?= ucfirst($r['confidence']) ?> confidence</span>
      </div>

      <span class="reason-tag"><?= htmlspecialchars($r['reason']) ?></span>
      <div class="details-text">"<?= htmlspecialchars($r['details']) ?>"</div>

      <div class="change-proposal">
        <h4>Proposed Change</h4>
        <div class="change-row">
          <span class="field-name"><?= htmlspecialchars($r['table_name']) ?>.<?= htmlspecialchars($r['column_name']) ?></span>
          <span class="old-val"><?= htmlspecialchars($r['old_value'] ?? 'null') ?></span>
          <span class="arrow">→</span>
          <span class="new-val"><?= htmlspecialchars($r['new_value']) ?></span>
        </div>
        <?php if ($r['notes']): ?>
          <div class="notes-text">Note: <?= htmlspecialchars($r['notes']) ?></div>
        <?php endif; ?>
      </div>

      <form method="POST" style="display:inline;">
        <input type="hidden" name="proposal_id" value="<?= $r['proposal_id'] ?>">
        <input type="hidden" name="request_id"  value="<?= $r['request_id'] ?>">
        <div class="action-buttons">
          <button type="submit" name="action" value="approve" class="btn btn-approve">✓ Approve</button>
          <button type="submit" name="action" value="deny"    class="btn btn-deny">✗ Deny</button>
        </div>
      </form>

      <div class="submitted-at">Submitted <?= $r['submitted_at'] ?></div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- FLAGGED — TOO AMBIGUOUS -->
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

  <!-- PENDING — NOT YET PROCESSED BY OPENCLAW -->
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

</div>
</body>
</html>
