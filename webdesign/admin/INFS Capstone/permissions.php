<?php
// ============================================================
// permissions.php — Central access control config
// TO CHANGE WHAT A ROLE CAN SEE: edit this file only.
// ============================================================

$PERMISSIONS = [

    'sysadmin' => [
        'scope'            => 'all',
        'view_fields'      => 'full',
        'see_subordinates' => true,
    ],

    'svp' => [
        'scope'            => 'all',
        'view_fields'      => 'full',
        'see_subordinates' => true,
    ],

    'vp' => [
        'scope'            => 'all',
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
        'scope'            => 'own_chain',  // self + peers under same manager
        'view_fields'      => 'full',       // full for self
        'peer_view_fields' => 'name_only',  // name_only for peers
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

    // Always full access to your own record
    if ($targetId === $myId) return 'full';

    if ($p['scope'] === 'all') return $p['view_fields'];

    // ── Boss visibility: one level above always gets name_only ──
    $stmt = $pdo->prepare("
        SELECT manager_id, director_id, vp_id, svp_id
        FROM workforce WHERE employee_id = ?
    ");
    $stmt->execute([$myId]);
    $me = $stmt->fetch();

    if ($me) {
        if ($targetId === $me['manager_id'])  return 'name_only';
        if ($targetId === $me['director_id']) return 'name_only';
        if ($targetId === $me['vp_id'])       return 'name_only';
        if ($targetId === $me['svp_id'])      return 'name_only';
    }

    // ── Own chain: full access ──
    $chainCol = null;
    if ($myRole === 'svp')      $chainCol = 'svp_id';
    if ($myRole === 'vp')       $chainCol = 'vp_id';
    if ($myRole === 'director') $chainCol = 'director_id';
    if ($myRole === 'manager')  $chainCol = 'manager_id';

    if ($chainCol !== null) {
        $stmt = $pdo->prepare("SELECT employee_id FROM workforce WHERE employee_id = ? AND $chainCol = ?");
        $stmt->execute([$targetId, $myId]);
        if ($stmt->fetch()) return $p['view_fields'];
    }

    // ── Peer checks (name_only) ──

    // Employee: peers under same manager
    if ($myRole === 'employee') {
        $stmt = $pdo->prepare("
            SELECT w.employee_id FROM workforce w
            JOIN workforce me ON me.employee_id = ?
            WHERE w.employee_id = ?
              AND w.manager_id IS NOT NULL
              AND me.manager_id IS NOT NULL
              AND w.manager_id = me.manager_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];
    }

    // Manager: others under same director
    if ($myRole === 'manager') {
        $stmt = $pdo->prepare("
            SELECT w.employee_id FROM workforce w
            JOIN workforce me ON me.employee_id = ?
            WHERE w.employee_id = ?
              AND w.director_id IS NOT NULL
              AND me.director_id IS NOT NULL
              AND w.director_id = me.director_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];
    }

    // Director: other directors + their chain under same VP
    if ($myRole === 'director') {
        // Peer director
        $stmt = $pdo->prepare("
            SELECT w.employee_id FROM workforce w
            JOIN workforce me ON me.employee_id = ?
            WHERE w.employee_id = ?
              AND w.role = 'Director'
              AND w.vp_id IS NOT NULL AND me.vp_id IS NOT NULL
              AND w.vp_id = me.vp_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];

        // Under a peer director
        $stmt = $pdo->prepare("
            SELECT w.employee_id FROM workforce w
            JOIN workforce dir ON dir.employee_id = w.director_id
            JOIN workforce me  ON me.employee_id  = ?
            WHERE w.employee_id = ?
              AND dir.role = 'Director'
              AND dir.vp_id IS NOT NULL AND me.vp_id IS NOT NULL
              AND dir.vp_id = me.vp_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];
    }

    // VP: other VPs + their chain under same SVP
    if ($myRole === 'vp') {
        // Peer VP
        $stmt = $pdo->prepare("
            SELECT w.employee_id FROM workforce w
            JOIN workforce me ON me.employee_id = ?
            WHERE w.employee_id = ?
              AND w.role = 'VP'
              AND w.svp_id IS NOT NULL AND me.svp_id IS NOT NULL
              AND w.svp_id = me.svp_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];

        // Under a peer VP
        $stmt = $pdo->prepare("
            SELECT w.employee_id FROM workforce w
            JOIN workforce vp ON vp.employee_id = w.vp_id
            JOIN workforce me ON me.employee_id  = ?
            WHERE w.employee_id = ?
              AND vp.role = 'VP'
              AND vp.svp_id IS NOT NULL AND me.svp_id IS NOT NULL
              AND vp.svp_id = me.svp_id
        ");
        $stmt->execute([$myId, $targetId]);
        if ($stmt->fetch()) return $p['peer_view_fields'];
    }

    return 'none';
}

// ============================================================
// canSeeSubordinates
// ============================================================
function canSeeSubordinates($myRole) {
    global $PERMISSIONS;
    return $PERMISSIONS[$myRole]['see_subordinates'] ?? false;
}

// ============================================================
// getScopeClause — WHERE clause for search queries
// ============================================================
function getScopeClause($myRole, $myId, $pdo) {
    global $PERMISSIONS;

    $p = $PERMISSIONS[$myRole] ?? $PERMISSIONS['employee'];

    if ($p['scope'] === 'all') {
        return ['sql' => '1=1', 'params' => []];
    }

    // Boss subquery — always included for all roles
    $bossSQL = "(
        w.employee_id = (SELECT manager_id  FROM workforce WHERE employee_id = :boss_self1)
        OR w.employee_id = (SELECT director_id FROM workforce WHERE employee_id = :boss_self2)
        OR w.employee_id = (SELECT vp_id       FROM workforce WHERE employee_id = :boss_self3)
        OR w.employee_id = (SELECT svp_id      FROM workforce WHERE employee_id = :boss_self4)
    )";
    $bossParams = [
        ':boss_self1' => $myId,
        ':boss_self2' => $myId,
        ':boss_self3' => $myId,
        ':boss_self4' => $myId,
    ];

    // Employee: self + peers under same manager + boss
    if ($myRole === 'employee') {
        return [
            'sql' => "(
                w.employee_id = :scope_self
                OR (
                    w.manager_id IS NOT NULL
                    AND w.manager_id = (SELECT manager_id FROM workforce WHERE employee_id = :scope_mgr_lookup)
                )
                OR $bossSQL
            )",
            'params' => array_merge([
                ':scope_self'       => $myId,
                ':scope_mgr_lookup' => $myId,
            ], $bossParams),
        ];
    }

    // Manager: own chain + peers under same director + boss
    if ($myRole === 'manager') {
        return [
            'sql' => "(
                w.employee_id = :scope_self
                OR w.manager_id = :scope_mgr
                OR (
                    w.director_id IS NOT NULL
                    AND w.director_id = (SELECT director_id FROM workforce WHERE employee_id = :scope_dir_lookup)
                )
                OR $bossSQL
            )",
            'params' => array_merge([
                ':scope_self'       => $myId,
                ':scope_mgr'        => $myId,
                ':scope_dir_lookup' => $myId,
            ], $bossParams),
        ];
    }

    // Director: own chain + peer directors + everyone under peer directors + boss
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
                OR $bossSQL
            )",
            'params' => array_merge([
                ':scope_self'       => $myId,
                ':scope_dir'        => $myId,
                ':scope_vp_lookup'  => $myId,
                ':scope_vp_lookup2' => $myId,
                ':scope_vp_lookup3' => $myId,
            ], $bossParams),
        ];
    }

    // VP: own chain + peer VPs + everyone under peer VPs + boss
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
                OR $bossSQL
            )",
            'params' => array_merge([
                ':scope_self'        => $myId,
                ':scope_vp'          => $myId,
                ':scope_svp_lookup'  => $myId,
                ':scope_svp_lookup2' => $myId,
                ':scope_svp_lookup3' => $myId,
            ], $bossParams),
        ];
    }

    // SVP: own chain + boss (SVP likely has no boss but included for consistency)
    return [
        'sql'    => "(w.svp_id = :scope_id OR w.employee_id = :scope_self OR $bossSQL)",
        'params' => array_merge([
            ':scope_id'   => $myId,
            ':scope_self' => $myId,
        ], $bossParams),
    ];
}
?>
