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
