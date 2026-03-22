<?php
// ============================================================
// permissions.php — Central access control config
// Include this in any PHP file that needs to check permissions.
// TO CHANGE WHAT A ROLE CAN SEE: edit this file only.
// ============================================================

$PERMISSIONS = [

    'sysadmin' => [
        'scope'            => 'all',
        'view_fields'      => 'full',
        'see_subordinates' => true,
    ],

    'svp' => [
        'scope'            => 'own_chain',
        'view_fields'      => 'full',
        'see_subordinates' => true,
    ],

    'vp' => [
        'scope'            => 'own_chain',
        'view_fields'      => 'full',
        'see_subordinates' => true,
    ],

    'director' => [
        'scope'            => 'own_chain',
        'view_fields'      => 'full',
        'peer_view_fields' => 'name_only',
        'see_subordinates' => true,
    ],

    'manager' => [
        'scope'            => 'own_chain',
        'view_fields'      => 'full',
        'peer_view_fields' => 'name_only',
        'see_subordinates' => true,
    ],

    'employee' => [
        'scope'            => 'self',
        'view_fields'      => 'full',
        'see_subordinates' => false,
    ],

];

// ============================================================
// getViewLevel — what can the viewer see about one target?
// Returns: 'full' | 'name_only' | 'none'
// ============================================================
function getViewLevel($myRole, $myId, $targetId, $pdo) {
    global $PERMISSIONS;

    $p = $PERMISSIONS[$myRole] ?? $PERMISSIONS['employee'];

    if ($targetId === $myId) return 'full';

    if ($p['scope'] === 'all') return $p['view_fields'];

    if ($p['scope'] === 'self') return 'none';

    // own_chain scope
    $chainCol = null;
    if ($myRole === 'svp')      $chainCol = 'svp_id';
    if ($myRole === 'vp')       $chainCol = 'vp_id';
    if ($myRole === 'director') $chainCol = 'director_id';
    if ($myRole === 'manager')  $chainCol = 'manager_id';

    if ($chainCol === null) return 'none';

    // Check if target is directly in my chain
    $stmt = $pdo->prepare("SELECT employee_id FROM workforce WHERE employee_id = ? AND $chainCol = ?");
    $stmt->execute([$targetId, $myId]);
    if ($stmt->fetch()) return $p['view_fields'];

    // VP peer check: other VPs + their entire chain under same SVP
    if ($myRole === 'vp') {
        // Peer VP
        $stmt = $pdo->prepare("
            SELECT w.employee_id
            FROM workforce w
            JOIN workforce me ON me.employee_id = ?
            WHERE w.employee_id = ?
              AND w.role = 'VP'
              AND w.svp_id IS NOT NULL
              AND me.svp_id IS NOT NULL
              AND w.svp_id = me.svp_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];

        // Anyone under a peer VP
        $stmt = $pdo->prepare("
            SELECT w.employee_id
            FROM workforce w
            JOIN workforce vp ON vp.employee_id = w.vp_id
            JOIN workforce me ON me.employee_id  = ?
            WHERE w.employee_id = ?
              AND vp.role = 'VP'
              AND vp.svp_id IS NOT NULL
              AND me.svp_id IS NOT NULL
              AND vp.svp_id = me.svp_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];
    }

    // Director peer check: other directors under same VP
    if ($myRole === 'director') {
        $stmt = $pdo->prepare("
            SELECT w.employee_id
            FROM workforce w
            JOIN workforce me ON me.employee_id = ?
            WHERE w.employee_id = ?
              AND w.role = 'Director'
              AND w.vp_id IS NOT NULL
              AND me.vp_id IS NOT NULL
              AND w.vp_id = me.vp_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];

        // Anyone who reports to a peer director also gets name_only
        $stmt = $pdo->prepare("
            SELECT w.employee_id
            FROM workforce w
            JOIN workforce dir ON dir.employee_id = w.director_id
            JOIN workforce me  ON me.employee_id  = ?
            WHERE w.employee_id = ?
              AND dir.role = 'Director'
              AND dir.vp_id IS NOT NULL
              AND me.vp_id IS NOT NULL
              AND dir.vp_id = me.vp_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];
    }

    // Manager peer check: other managers + their employees under same director
    if ($myRole === 'manager') {
        $stmt = $pdo->prepare("
            SELECT w.employee_id
            FROM workforce w
            JOIN workforce me ON me.employee_id = ?
            WHERE w.employee_id = ?
              AND w.director_id IS NOT NULL
              AND me.director_id IS NOT NULL
              AND w.director_id = me.director_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];
    }

    return 'none';
}

// ============================================================
// canSeeSubordinates — should the modal show direct reports?
// ============================================================
function canSeeSubordinates($myRole) {
    global $PERMISSIONS;
    return $PERMISSIONS[$myRole]['see_subordinates'] ?? false;
}

// ============================================================
// getScopeClause — builds the WHERE clause for search queries
// Returns: ['sql' => string, 'params' => array]
// ============================================================
function getScopeClause($myRole, $myId, $pdo) {
    global $PERMISSIONS;

    $p = $PERMISSIONS[$myRole] ?? $PERMISSIONS['employee'];

    if ($p['scope'] === 'all') {
        return ['sql' => '1=1', 'params' => []];
    }

    if ($p['scope'] === 'self') {
        return ['sql' => 'w.employee_id = :scope_self', 'params' => [':scope_self' => $myId]];
    }

    // own_chain scope
    $chainCol = null;
    if ($myRole === 'svp')      $chainCol = 'svp_id';
    if ($myRole === 'vp')       $chainCol = 'vp_id';
    if ($myRole === 'director') $chainCol = 'director_id';
    if ($myRole === 'manager')  $chainCol = 'manager_id';

    if ($chainCol === null) {
        return ['sql' => 'w.employee_id = :scope_self', 'params' => [':scope_self' => $myId]];
    }

    // Directors: own chain + peer directors + everyone under peer directors
    if ($myRole === 'director') {
        return [
            'sql' => "(
                w.employee_id = :scope_self
                OR w.director_id = :scope_dir
                OR (
                    w.vp_id IS NOT NULL
                    AND w.vp_id = (SELECT vp_id FROM workforce WHERE employee_id = :scope_vp_lookup)
                    AND w.director_id IN (
                        SELECT employee_id FROM workforce
                        WHERE role = 'Director'
                        AND vp_id = (SELECT vp_id FROM workforce WHERE employee_id = :scope_vp_lookup2)
                    )
                )
                OR (
                    w.role = 'Director'
                    AND w.vp_id IS NOT NULL
                    AND w.vp_id = (SELECT vp_id FROM workforce WHERE employee_id = :scope_vp_lookup3)
                )
            )",
            'params' => [
                ':scope_self'       => $myId,
                ':scope_dir'        => $myId,
                ':scope_vp_lookup'  => $myId,
                ':scope_vp_lookup2' => $myId,
                ':scope_vp_lookup3' => $myId,
            ],
        ];
    }

    // Managers: own chain + all people under same director (including other managers and their employees)
    if ($myRole === 'manager') {
        return [
            'sql' => "(
                w.employee_id = :scope_self
                OR w.manager_id = :scope_mgr
                OR (
                    w.director_id IS NOT NULL
                    AND w.director_id = (SELECT director_id FROM workforce WHERE employee_id = :scope_dir_lookup)
                )
            )",
            'params' => [
                ':scope_self'       => $myId,
                ':scope_mgr'        => $myId,
                ':scope_dir_lookup' => $myId,
            ],
        ];
    }

    // VP: own chain + peer VPs under same SVP + everyone under those VPs
    if ($myRole === 'vp') {
        return [
            'sql' => "(
                w.employee_id = :scope_self
                OR w.vp_id = :scope_vp
                OR (
                    w.svp_id IS NOT NULL
                    AND w.svp_id = (SELECT svp_id FROM workforce WHERE employee_id = :scope_svp_lookup)
                    AND w.vp_id IN (
                        SELECT employee_id FROM workforce
                        WHERE role = 'VP'
                        AND svp_id = (SELECT svp_id FROM workforce WHERE employee_id = :scope_svp_lookup2)
                    )
                )
                OR (
                    w.role = 'VP'
                    AND w.svp_id IS NOT NULL
                    AND w.svp_id = (SELECT svp_id FROM workforce WHERE employee_id = :scope_svp_lookup3)
                )
            )",
            'params' => [
                ':scope_self'        => $myId,
                ':scope_vp'          => $myId,
                ':scope_svp_lookup'  => $myId,
                ':scope_svp_lookup2' => $myId,
                ':scope_svp_lookup3' => $myId,
            ],
        ];
    }

    // SVP: everyone in their chain + themselves
    return [
        'sql'    => "(w.$chainCol = :scope_id OR w.employee_id = :scope_self)",
        'params' => [':scope_id' => $myId, ':scope_self' => $myId],
    ];
}
?>
