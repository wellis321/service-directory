<?php
declare(strict_types=1);
/**
 * Router for PHP’s built-in server (.htaccess is ignored there).
 *
 *   php -S localhost:8080 -t . index.php
 *
 * Then set SITE_URL in .env to match (e.g. http://localhost:8080).
 * Use a free HTTP port: MAMP MySQL often uses 8889, so pick 8080 or 8888 for PHP -S.
 *
 * On Apache with mod_rewrite, /.htaccess sends traffic to public/; this file is not used for /.
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = __DIR__;

$path = $root . $uri;
if ($uri !== '/' && $uri !== '/index.php' && is_file($path)) {
    return false;
}

if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    require $root . '/public/index.php';
    return true;
}

if ($uri === '/councils' || $uri === '/councils/') {
    require $root . '/public/councils-map.php';
    return true;
}

if ($uri === '/insights' || $uri === '/insights/') {
    require $root . '/public/insights.php';
    return true;
}

if ($uri === '/news' || $uri === '/news/') {
    require $root . '/public/news.php';
    return true;
}

if ($uri === '/complaints' || $uri === '/complaints/') {
    require $root . '/public/complaints.php';
    return true;
}

if ($uri === '/complaints-metrics' || $uri === '/complaints-metrics/') {
    require $root . '/public/complaints-metrics.php';
    return true;
}

if (preg_match('#^/service/([A-Za-z0-9]+)/[^/]+/?$#', $uri, $m)) {
    $_GET['cs'] = $m[1];
    require $root . '/public/service.php';
    return true;
}

if (preg_match('#^/provider/([A-Za-z0-9]+)/[^/]+/?$#', $uri, $m)) {
    $_GET['sp'] = $m[1];
    require $root . '/public/provider.php';
    return true;
}

if (str_starts_with($uri, '/provider/claim.php')) {
    require $root . '/portal/claim.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "Not found: {$uri}\n";

return true;
