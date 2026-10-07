<?php
// Local development only: php -S localhost:8000 router.php
// Serves /assets directly and sends everything else to the front controller,
// mirroring the routes in vercel.json.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/assets/') && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/api/index.php';
