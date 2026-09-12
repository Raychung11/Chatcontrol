<?php
require_once __DIR__ . '/../inc/auth.php';

$user = require_login();

if (!is_post()) {
    http_response_code(405);
    exit('Method Not Allowed');
}
csrf_check();

$action = (string)($_POST['action'] ?? '');

if ($action === 'start') {
    if (!is_platform_admin()) {
        http_response_code(403);
        exit('Platform admin access only.');
    }
    if (is_impersonating()) {
        redirect('/dashboard.php');
    }
    $targetCompanyId = (int)($_POST['company_id'] ?? 0);
    if ($targetCompanyId <= 0) {
        http_response_code(400);
        exit('Missing company_id.');
    }

    // Verify the company exists and is active.
    $stmt = aiserve_db()->prepare('SELECT id FROM companies WHERE id = ? AND status = "active" LIMIT 1');
    $stmt->execute([$targetCompanyId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        exit('Workspace not found.');
    }
    start_impersonation($targetCompanyId);
    redirect('/dashboard.php');
}

if ($action === 'stop') {
    stop_impersonation();
    redirect('/admin/workspaces.php');
}

http_response_code(400);
exit('Unknown action.');
