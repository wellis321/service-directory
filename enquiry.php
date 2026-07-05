<?php
declare(strict_types=1);
// Legacy POST target — forwards behaviour to match sql/schema enquiries + public/service flow
define('ROOT', __DIR__);
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /', true, 302);
    exit;
}

$cs = strtoupper(trim($_POST['cs'] ?? ''));
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$message = trim($_POST['message'] ?? '');

if (!$cs || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
    header('Location: /', true, 302);
    exit;
}

$service = get_service($cs);
if (!$service) {
    header('Location: /', true, 302);
    exit;
}

$pdo = db();
$pdo->prepare('
    INSERT INTO enquiries (service_id, sender_name, sender_email, sender_phone, message, ip_address)
    VALUES (?, ?, ?, ?, ?, ?)
')->execute([
    (int) $service['id'],
    $name,
    $email,
    $phone,
    $message,
    $_SERVER['REMOTE_ADDR'] ?? '',
]);

$to = $service['enquiry_email'] ?: $service['email'];
if ($to) {
    $body = '<p>New enquiry for <strong>' . h($service['service_name']) . '</strong>:</p>'
        . '<p><strong>From:</strong> ' . h($name) . ' (' . h($email) . ')<br>'
        . ($phone !== '' ? '<strong>Phone:</strong> ' . h($phone) . '<br>' : '')
        . '<strong>Message:</strong><br>' . nl2br(h($message)) . '</p>';
    send_email($to, 'New enquiry: ' . $service['service_name'], $body);
}

$slug = slug((string) $service['service_name']);
header('Location: /service/' . rawurlencode($cs) . '/' . rawurlencode($slug) . '?enquiry_sent=1', true, 303);
exit;
