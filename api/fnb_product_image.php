<?php
/**
 * Serve an F&B product image inline.
 *   /api/fnb_product_image.php?product_id=42&v=1721234567
 *
 * Files live at uploads/fnb/<company_id>/<product_id>.<ext>. The
 * uploads/.htaccess forces Content-Disposition: attachment on direct
 * access, so we read + emit the bytes through this endpoint instead
 * with the right Content-Type. Public — a product image is inherently
 * public branding shown in customer menus.
 */

require_once __DIR__ . '/../inc/helpers.php';

$productId = (int)($_GET['product_id'] ?? 0);
if ($productId <= 0) { http_response_code(400); exit; }

$db = aiserve_db();
$s = $db->prepare('SELECT company_id, image_ext, updated_at FROM fnb_products WHERE id = ? LIMIT 1');
$s->execute([$productId]);
$row = $s->fetch();
if (!$row || empty($row['image_ext'])) { http_response_code(404); exit; }

$path = __DIR__ . '/../uploads/fnb/' . (int)$row['company_id'] . '/' . $productId . '.' . $row['image_ext'];
if (!is_file($path)) { http_response_code(404); exit; }

$mimeMap = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . ($mimeMap[$row['image_ext']] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($path));
readfile($path);
