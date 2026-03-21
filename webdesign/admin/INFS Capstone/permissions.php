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
        'scope'       => 'all',        // who they can search: all | own_chain | self
        'view_fields' => 'full',       // full | name_only
        'see_subordinates' => true,    // show direct reports in modal
    ],

    'svp' => [
        'scope'       => 'own_chain',  // everyone whose svp_id = me
        'view_fields' => 'full',
        'see_subordinates' => true,
    ],

    'vp' => [
        'scope'       => 'own_chain',  // everyone whose vp_id = me
        'view_fields' => 'full',
        'see_subordinates' => true,
    ],

    'director' => [
        'scope'       => 'own_chain',  // everyone whose director_id = me
        'view_fields' => 'full',
        'see_subordinates' => true,
    ],

    'manager' => [
        'scope'            => 'own_chain',   // direct reports = full, peers under same director = name_only
        'view_fields'      => 'full',        // for own direct reports
        'peer_view_fields' => 'name_only',   // for peer employees (different manager, same director)
        'see_subordinates' => true,
    ],

    'employee' => [
        'scope'       => 'self',       // only themselves
        'view_fields' => 'full',       // full view of their own record
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
            // Can only see themselves — already handled above
            return 'none';

        case 'own_chain':
            // Determine which column links this role's chain
            $chainCol = [
                'svp'      => 'svp_id',
                'vp'       => 'vp_id',
                'director' => 'director_id',
                'manager'  => 'manager_id',
            ][$myRole] ?? null;

            if (!$chainCol) return 'none';

            // Is the target directly in my chain?
            $stmt = $pdo->prepare("SELECT employee_id FROM workforce WHERE employee_id = ? AND $chainCol = ?");
            $stmt->execute([$targetId, $myId]);
            if ($stmt->fetch()) return $p['view_fields'];

            // Manager special case: peers under same director get peer_view_fields
            if ($myRole === 'manager' && isset($p['peer_view_fields'])) {
                $stmt = $pdo->prepare("
                    SELECT w.employee_id FROM workforce w
                    JOIN workforce me ON me.employee_id = ?
                    WHERE w.employee_id = ?
                      AND w.director_id = me.director_id
                      AND w.director_id IS NOT NULL
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

            // Managers also see peers under same director (even if name_only)
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

            return [
                'sql'    => "(w.$chainCol = :scope_id OR w.employee_id = :scope_self)",
                'params' => [':scope_id' => $myId, ':scope_self' => $myId],
            ];
    }

    return ['sql' => '1=0', 'params' => []]; // fallback: show nothing
}
?>
