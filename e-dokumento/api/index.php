<?php
declare(strict_types=1);

/*
 * Single entry point. On Vercel, vercel.json routes every non-asset request
 * here (PHP community runtime). Locally: php -S localhost:8000 router.php
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$routes = require BASE_PATH . '/config/routes.php';

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = '/' . trim(rawurldecode($path), '/');
if (str_ends_with($path, '.php')) {
    $path = substr($path, 0, -4);           // /login.php works the same as /login
}
if ($path === '/index') {
    $path = '/';
}

$route = $routes[$path] ?? null;
if ($route === null) {
    abort(404);
}
[$file, $access] = $route;

if ($access === 'guest' && Auth::user() !== null) {
    redirect('/dashboard');
}
if ($access === 'auth' || is_array($access)) {
    Auth::requireLogin();
}
if (is_array($access)) {
    require_role(...$access);
}
if (is_post()) {
    Csrf::verify();
}

header('Cache-Control: no-store, private');
require BASE_PATH . '/' . $file;
