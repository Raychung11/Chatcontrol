<?php
/**
 * POST /api/workspace_action.php
 *
 * Platform-admin actions that operate on a whole workspace (company row).
 * Currently supports:
 *   action=archive : sets companies.status = 'inactive'.
 *     Reversible (flip status back in DB). Hides the workspace from the
 *     admin/workspaces.php list, which filters WHERE status='active'.
 *     Data is preserved so we can restore or audit later.
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_login();

if (!is_post()) {
    http_response_code(405);
    exit('Method Not Allowed');
}
csrf_check();

if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}
if (is_impersonating()) {
    // Archiving while impersonating would confuse audit trails and
    // could archive the workspace the operator is currently signed
    // into. Force them to stop impersonating first.
    redirect('/dashboard.php');
}

$action     = (string)($_POST['action'] ?? '');
$companyId  = (int)($_POST['company_id'] ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    exit('Missing company_id.');
}
if ($companyId === (int)$user['company_id']) {
    http_response_code(400);
    exit('You cannot archive your own workspace.');
}

$db = aiserve_db();

if ($action === 'change_plan') {
    // Bump the workspace's plan tier. Used when a customer needs more
    // seats than their current tier allows. Downgrading is allowed too,
    // but blocked if the workspace currently has more active users than
    // the target plan permits (would silently orphan users otherwise).
    $newPlan = (string)($_POST['plan'] ?? '');
    if (!in_array($newPlan, ['starter', 'growth', 'enterprise'], true)) {
        http_response_code(400);
        exit('Invalid plan.');
    }

    $stmt = $db->prepare('SELECT id, name, plan FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    if (!$company) {
        http_response_code(404);
        exit('Workspace not found.');
    }
    if ($company['plan'] === $newPlan) {
        redirect('/admin/workspaces.php');
    }

    // Downgrade guard: refuse if the workspace has more active users
    // than the target plan supports. Operator has to deactivate seats
    // first, else the seat-limit check on the next user create/edit
    // would look consistent while the underlying data is off.
    $active = (int)$db->query(
        "SELECT COUNT(*) FROM users
         WHERE company_id = $companyId AND status = 'active'"
    )->fetchColumn();
    $newLimit = plan_seat_limit($newPlan);
    if ($active > $newLimit) {
        redirect('/admin/workspaces.php?plan_error=' . rawurlencode(
            'Cannot downgrade "' . $company['name'] . '" to ' . ucfirst($newPlan)
            . ': ' . $active . ' active users but plan allows only ' . $newLimit
            . '. Deactivate users first, then retry.'
        ));
    }

    $db->prepare('UPDATE companies SET plan = ? WHERE id = ? LIMIT 1')
       ->execute([$newPlan, $companyId]);

    log_activity(
        $companyId, (int)$user['id'], 'workspace_plan_changed',
        'company', $companyId,
        'from=' . $company['plan'] . ' to=' . $newPlan
    );

    redirect('/admin/workspaces.php?plan_changed=' . rawurlencode(
        $company['name'] . ' → ' . ucfirst($newPlan)
        . ' (seat limit now ' . $newLimit . ')'
    ));
}

if ($action === 'change_broadcast_plan') {
    // Flip a workspace between the free and paid broadcast metering tier.
    // Quota + price are set globally at /admin/pricing.php; this endpoint
    // only decides which of those two limits this workspace lives under.
    $newBcastPlan = (string)($_POST['broadcast_plan'] ?? '');
    if (!in_array($newBcastPlan, ['free', 'paid'], true)) {
        http_response_code(400);
        exit('Invalid broadcast plan.');
    }
    $stmt = $db->prepare('SELECT id, name, broadcast_plan FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    if (!$company) {
        http_response_code(404);
        exit('Workspace not found.');
    }
    if ($company['broadcast_plan'] === $newBcastPlan) {
        redirect('/admin/workspaces.php');
    }
    $db->prepare('UPDATE companies SET broadcast_plan = ? WHERE id = ? LIMIT 1')
       ->execute([$newBcastPlan, $companyId]);
    log_activity(
        $companyId, (int)$user['id'], 'workspace_broadcast_plan_changed',
        'company', $companyId,
        'from=' . ($company['broadcast_plan'] ?? 'free') . ' to=' . $newBcastPlan
    );
    redirect('/admin/workspaces.php?plan_changed=' . rawurlencode(
        $company['name'] . ' broadcast plan → ' . ucfirst($newBcastPlan)
    ));
}

if ($action === 'archive') {
    $stmt = $db->prepare(
        'SELECT id, name, status FROM companies WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    if (!$company) {
        http_response_code(404);
        exit('Workspace not found.');
    }
    if ($company['status'] === 'inactive') {
        redirect('/admin/workspaces.php');
    }

    $up = $db->prepare(
        'UPDATE companies SET status = "inactive" WHERE id = ? LIMIT 1'
    );
    $up->execute([$companyId]);

    log_activity(
        $companyId,
        (int)$user['id'],
        'workspace_archive',
        'company',
        $companyId,
        'Workspace archived by platform admin: ' . $company['name']
    );

    redirect('/admin/workspaces.php?archived=' . $companyId);
}

http_response_code(400);
exit('Unknown action.');
