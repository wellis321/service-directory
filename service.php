<?php
declare(strict_types=1);
// Legacy ?cs= URL — canonical profile is /service/{cs}/{slug}
define('ROOT', __DIR__);
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/functions.php';

$cs = strtoupper(trim($_GET['cs'] ?? ''));
if (!preg_match('/^CS\d+$/', $cs)) {
    header('Location: /', true, 302);
    exit;
}
$stmt = db()->prepare('SELECT service_name FROM services WHERE cs_number = ? LIMIT 1');
$stmt->execute([$cs]);
$row = $stmt->fetch();
if (!$row) {
    header('Location: /', true, 302);
    exit;
}
$slug = slug((string) $row['service_name']);
header('Location: /service/' . rawurlencode($cs) . '/' . rawurlencode($slug), true, 301);
exit;
