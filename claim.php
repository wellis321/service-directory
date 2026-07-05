<?php
declare(strict_types=1);
// Legacy URL — canonical claim flow is provider/claim.php
$q = isset($_GET['cs']) ? '?cs=' . rawurlencode((string) $_GET['cs']) : '';
header('Location: /provider/claim.php' . $q, true, 301);
exit;
