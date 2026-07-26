<?php
/**
 * Serve a workspace's uploaded logo.
 *
 *   /assets/img/company_logo.php?company_id=42
 *
 * Files live at uploads/companies/<company_id>/logo.png (created by the
 * upload flow in admin/settings.php). uploads/.htaccess forces
 * Content-Disposition: attachment on direct access, so we read + emit
 * the bytes through this endpoint with inline headers instead.
 *
 * Public — a workspace logo is inherently public branding shown in
 * their sidebar, on their invoices, etc. If we later wanted to gate
 * this it'd require a login check + workspace scoping.
 *
 * Cache: strong, immutable; callers add ?v=<mtime> so a new upload
 * bumps the URL and browsers refetch.
 */

$companyId = (int)($_GET['company_id'] ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    exit;
}

$path = __DIR__ . '/../../uploads/companies/' . $companyId . '/logo.png';
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($path));
readfile($path);
