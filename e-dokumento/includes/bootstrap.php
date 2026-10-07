<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/includes/env.php';
Env::load(BASE_PATH . '/.env'); // local development only; Vercel uses real env vars

require BASE_PATH . '/includes/helpers.php';
require BASE_PATH . '/includes/supabase.php';
require BASE_PATH . '/includes/audit.php';
require BASE_PATH . '/includes/auth.php';
require BASE_PATH . '/includes/permissions.php';
require BASE_PATH . '/includes/csrf.php';
require BASE_PATH . '/includes/flash.php';
require BASE_PATH . '/includes/validator.php';
require BASE_PATH . '/includes/upload.php';
require BASE_PATH . '/includes/layout.php';
require BASE_PATH . '/includes/components.php';

date_default_timezone_set('Asia/Manila');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', config('app')['debug'] ? '1' : '0');
ini_set('log_errors', '1');

// Buffer output so pages can still set cookies and redirect after rendering starts.
ob_start();

// Security headers
$supabaseOrigin = config('supabase')['url'];
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' https://cdn.jsdelivr.net; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
    . "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net; "
    . "img-src 'self' data: blob: " . ($supabaseOrigin !== '' ? $supabaseOrigin : '') . "; "
    . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

set_exception_handler(static function (Throwable $e): void {
    error_log('Unhandled: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $GLOBALS['error_detail'] = config('app')['debug'] ? $e->getMessage() : null;
    $isConfig = $e instanceof RuntimeException && (str_contains($e->getMessage(), 'environment variable')
        || str_contains($e->getMessage(), 'not configured') || str_contains($e->getMessage(), 'APP_SECRET'));
    http_response_code(500);
    $GLOBALS['error_code'] = 500;
    $GLOBALS['error_message'] = $isConfig
        ? 'The app is not configured yet: ' . $e->getMessage()
        : 'Something went wrong on our side. Try again in a moment.';
    require BASE_PATH . '/pages/errors/error.php';
});
