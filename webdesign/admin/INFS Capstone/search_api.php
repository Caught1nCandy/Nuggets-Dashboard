<?php
// ============================================================
// permissions.php — Central access control config
// Include this in any PHP file that needs to check permissions.
//
// TO CHANGE WHAT A ROLE CAN SEE: edit this file only.
// Nothing else needs to change.
// ============================================================

$PERMISSIONS = [

    'sysadmin' => [
        'scope'            => 'all',
        'view_fields'      => 'full',
        'see_subordinates' => true,
    ],

    'svp' => [
        'scope'            => 'own_chain',  // everyone whose svp_id = me
        'view_fields'      => 'full',
        'see_subordinates' => true,
    ],

    'vp' => [
        'scope'            => 'own_chain',  // everyone whose vp_id = me
        'view_fields'      => 'full',
        'see_subordinates' => true,
    ],

    'director' => [
        'scope'            => 'own_chain',   // own chain = full, peer directors same VP = name_only
        'view_fields'      => 'full',
        'peer_view_fields' => 'name_only',   // peer directors under same VP
        'see_subordinates' => true,
    ],

    'manager' => [
        'scope'            => 'own_chain',   // own chain = full, peers same director = name_only
        'view_fields'      => 'full',
        'peer_view_fields' => 'name_only',   // peer employees/managers under same director
        'see_subordinates' => true,
    ],

    'employee' => [
        'scope'            => 'self',
        'view_fields'      => 'full',
        'see_subordinates' => false,
    ],

];

// ============================================================
// Helper: what fields is a viewer allowed to see on a target?
// Returns: 'full' | 'name_only' | 'none'
// ============================================================
function getViewLevel($myRole, $myId, $targetId, $pdo) {
    global $PERMISSIONS;

    $p = $PERMISSIONS[$myRole] ?? $PERMISSIONS['employee'];

    // Always full access to your own record
    if ($targetId === $myId) return 'full';

    switch ($p['scope']) {

        case 'all':
            return $p['view_fields'];

        case 'self':
            return 'none';

        case 'own_chain':
            $chainCol = [
                'svp'      => 'svp_id',
                'vp'       => 'vp_id',
                'director' => 'director_id',
                'manager'  => 'manager_id',
            ][$myRole] ?? null;

            if (!$chainCol) return 'none';

            // Is the target directly in my chain? (their chain column points to me)
            $stmt = $pdo->prepare("SELECT employee_id FROM workforce WHERE employee_id = ? AND $chainCol = ?");
            $stmt->execute([$targetId, $myId]);
            if ($stmt->fetch()) return $p['view_fields'];

            // Director peer check: other directors under the same VP → name_only
            if ($myRole === 'director' && isset($p['peer_view_fields'])) {
                $stmt = $pdo->prepare("
                    SELECT w.employee_id
                    FROM workforce w
                    JOIN workforce me ON me.employee_id = ?
                    WHERE w.employee_id = ?
                      AND w.role = 'Director'
                      AND w.vp_id = me.vp_id
                      AND w.vp_id IS NOT NULL
                      AND me.vp_id IS NOT NULL
                ");
                $stmt->execute([$myId, $targetId]);
                if ($stmt->fetch()) return $p['peer_view_fields'];
            }

            // Manager peer check: employees/managers under same director → name_only
            if ($myRole === 'manager' && isset($p['peer_view_fields'])) {
                $stmt = $pdo->prepare("
                    SELECT w.employee_id
                    FROM workforce w
                    JOIN workforce me ON me.employee_id = ?
                    WHERE w.employee_id = ?
                      AND w.director_id = me.director_id
                      AND w.director_id IS NOT NULL
                      AND me.director_id IS NOT NULL
                ");
                $stmt->execute([$myId, $targetId]);
                if ($stmt->fetch()) return $p['peer_view_fields'];
            }

            return 'none';
    }

    return 'none';
}

// ============================================================
// Helper: should this role see the subordinates list at all?
// ============================================================
function canSeeSubordinates($myRole) {
    global $PERMISSIONS;
    return $PERMISSIONS[$myRole]['see_subordinates'] ?? false;
}

// ============================================================
// Helper: build the WHERE clause scope for search_api.php
// Returns ['sql' => string, 'params' => array]
// ============================================================
function getScopeClause($myRole, $myId, $pdo) {
    global $PERMISSIONS;

    $p = $PERMISSIONS[$myRole] ?? $PERMISSIONS['employee'];

    switch ($p['scope']) {

        case 'all':
            return ['sql' => '1=1', 'params' => []];

        case 'self':
            return [
                'sql'    => 'w.employee_id = :scope_self',
                'params' => [':scope_self' => $myId],
            ];

        case 'own_chain':
            $chainCol = [
                'svp'      => 'svp_id',
                'vp'       => 'vp_id',
                'director' => 'director_id',
                'manager'  => 'manager_id',
            ][$myRole] ?? null;

            if (!$chainCol) {
                return ['sql' => 'w.employee_id = :scope_self', 'params' => [':scope_self' => $myId]];
            }

            // Directors: own chain + peer directors under same VP
            if ($myRole === 'director') {
                return [
                    'sql' => "(
                        w.employee_id = :scope_self
                        OR w.director_id = :scope_dir
                        OR (
                            w.role = 'Director'
                            AND w.vp_id = (SELECT vp_id FROM workforce WHERE employee_id = :scope_vp_lookup)
                            AND w.vp_id IS NOT NULL
                        )
                    )",
                    'params' => [
                        ':scope_self'      => $myId,
                        ':scope_dir'       => $myId,
                        ':scope_vp_lookup' => $myId,
                    ],
                ];
            }

            // Managers: own chain + peers under same director
            if ($myRole === 'manager') {
                return [
                    'sql' => "(
                        w.employee_id = :scope_self
                        OR w.manager_id = :scope_mgr
                        OR w.director_id = (
                            SELECT director_id FROM workforce WHERE employee_id = :scope_dir_lookup
                        )
                    )",
                    'params' => [
                        ':scope_self'       => $myId,
                        ':scope_mgr'        => $myId,
                        ':scope_dir_lookup' => $myId,
                    ],
                ];
            }

            // VP / SVP: everyone in their chain + themselves
            return [
                'sql'    => "(w.$chainCol = :scope_id OR w.employee_id = :scope_self)",
                'params' => [':scope_id' => $myId, ':scope_self' => $myId],
            ];
    }

    return ['sql' => '1=0', 'params' => []];
}
?>
