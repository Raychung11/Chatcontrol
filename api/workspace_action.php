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

if ($action === 'toggle_fnb') {
    // Flip a workspace's F&B module on/off (none <-> active). Once
    // active, the sidebar shows the Menu link and /admin/fnb_menu.php
    // becomes reachable. Reserved 'paid' state is set manually in DB
    // for now — Layer 3 will surface it in this endpoint too.
    $stmt = $db->prepare('SELECT id, name, fnb_plan FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    if (!$company) { http_response_code(404); exit('Workspace not found.'); }

    $newFnb = $company['fnb_plan'] === 'active' ? 'none' : 'active';
    $db->prepare('UPDATE companies SET fnb_plan = ? WHERE id = ? LIMIT 1')->execute([$newFnb, $companyId]);
    log_activity(
        $companyId, (int)$user['id'], 'workspace_fnb_toggled',
        'company', $companyId,
        'from=' . ($company['fnb_plan'] ?? 'none') . ' to=' . $newFnb
    );
    redirect('/admin/workspaces.php?plan_changed=' . rawurlencode(
        $company['name'] . ' F&B module → ' . ($newFnb === 'active' ? 'enabled' : 'disabled')
    ));
}

if ($action === 'change_broadcast_plan') {
    // Flip a workspace between free / paid / payg. Also accepts an
    // optional billing_cycle=monthly|yearly (only meaningful for paid).
    $newBcastPlan = (string)($_POST['broadcast_plan'] ?? '');
    $newCycle     = (string)($_POST['broadcast_billing_cycle'] ?? 'monthly');
    if (!in_array($newBcastPlan, ['free', 'paid', 'payg'], true)) {
        http_response_code(400);
        exit('Invalid broadcast plan.');
    }
    if (!in_array($newCycle, ['monthly', 'yearly'], true)) $newCycle = 'monthly';

    $stmt = $db->prepare('SELECT id, name, broadcast_plan, broadcast_billing_cycle FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    if (!$company) {
        http_response_code(404);
        exit('Workspace not found.');
    }

    $sameAsBefore = $company['broadcast_plan'] === $newBcastPlan
                 && ($company['broadcast_billing_cycle'] ?? 'monthly') === $newCycle;
    if ($sameAsBefore) redirect('/admin/workspaces.php');

    $db->prepare('UPDATE companies SET broadcast_plan = ?, broadcast_billing_cycle = ? WHERE id = ? LIMIT 1')
       ->execute([$newBcastPlan, $newCycle, $companyId]);
    log_activity(
        $companyId, (int)$user['id'], 'workspace_broadcast_plan_changed',
        'company', $companyId,
        'from=' . ($company['broadcast_plan'] ?? 'free') . '/' . ($company['broadcast_billing_cycle'] ?? 'monthly')
        . ' to=' . $newBcastPlan . '/' . $newCycle
    );
    $label = ucfirst($newBcastPlan) . ($newBcastPlan === 'paid' ? ' (' . $newCycle . ')' : '');
    redirect('/admin/workspaces.php?plan_changed=' . rawurlencode(
        $company['name'] . ' broadcast plan → ' . $label
    ));
}

if ($action === 'change_ai_chatbot') {
    // Update companies.ai_chatbot_plan / ai_chatbot_multiplier /
    // ai_chatbot_monthly_cap via a single field-specific handler so
    // /admin/ai_billing.php can inline-edit each column without three
    // separate endpoints.
    $field  = (string)($_POST['field'] ?? '');
    $value  = trim((string)($_POST['value'] ?? ''));
    $back   = '/admin/ai_billing.php';

    if ($field === 'plan') {
        if (!in_array($value, ['none', 'payg', 'paid'], true)) {
            http_response_code(400); exit('Invalid plan.');
        }
        $db->prepare('UPDATE companies SET ai_chatbot_plan = ? WHERE id = ?')
           ->execute([$value, $companyId]);
    } elseif ($field === 'multiplier') {
        $m = (float)$value;
        if ($m < 1 || $m > 20) {
            http_response_code(400); exit('Multiplier must be between 1 and 20.');
        }
        $db->prepare('UPDATE companies SET ai_chatbot_multiplier = ? WHERE id = ?')
           ->execute([$m, $companyId]);
    } elseif ($field === 'cap') {
        $c = $value === '' ? null : (float)$value;
        if ($c !== null && $c < 0) {
            http_response_code(400); exit('Cap must be positive.');
        }
        $db->prepare('UPDATE companies SET ai_chatbot_monthly_cap = ? WHERE id = ?')
           ->execute([$c, $companyId]);
    } else {
        http_response_code(400); exit('Invalid field.');
    }

    log_activity($companyId, (int)$user['id'], 'ai_chatbot_' . $field . '_change',
        'company', $companyId, $value);
    redirect($back);
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
