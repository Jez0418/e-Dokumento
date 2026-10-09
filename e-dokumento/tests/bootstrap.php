<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80100) { fwrite(STDERR, 'Tests need PHP 8.1 or newer; found ' . PHP_VERSION . PHP_EOL); exit(1); }

/**
 * Loads the app's includes directly, without includes/bootstrap.php, so tests
 * never read .env, send headers or reach the live Supabase project.
 */
define('BASE_PATH', dirname(__DIR__));

// Test environment. SUPABASE_URL points at a closed port so nothing can reach a real project.
const TEST_ENV = [
    'SUPABASE_URL'             => 'http://127.0.0.1:9',
    'SUPABASE_PUBLISHABLE_KEY' => 'sb_publishable_test',
    'SUPABASE_SECRET_KEY'      => 'sb_secret_SENTINEL_DO_NOT_LEAK',
    'APP_URL'                  => 'http://127.0.0.1',
    'APP_SECRET'               => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'APP_DEBUG'                => 'false',
];
foreach (TEST_ENV as $key => $value) {
    putenv("{$key}={$value}");
}

date_default_timezone_set('Asia/Manila');
mb_internal_encoding('UTF-8');

// Buffer output so setcookie() works under the CLI.
ob_start();

foreach (['env', 'mailer', 'email_outbox', 'helpers', 'supabase', 'auth', 'permissions', 'csrf', 'flash', 'validator', 'upload', 'reports'] as $file) {
    require BASE_PATH . "/includes/{$file}.php";
}
