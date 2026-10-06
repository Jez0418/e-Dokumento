<?php
declare(strict_types=1);

/**
 * Stateless CSRF: a random seed in an HttpOnly cookie; forms carry
 * HMAC-SHA256(seed, APP_SECRET). A cross-site page can neither read the
 * cookie nor compute the token.
 */
final class Csrf
{
    private const COOKIE = 'edk_csrf';

    private static function seed(): string
    {
        $seed = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($seed) || !preg_match('/^[a-f0-9]{64}$/', $seed)) {
            $seed = bin2hex(random_bytes(32));
            setcookie(self::COOKIE, $seed, [
                'expires'  => time() + 60 * 60 * 24 * 7,
                'path'     => '/',
                'secure'   => is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[self::COOKIE] = $seed;
        }
        return $seed;
    }

    public static function token(): string
    {
        return hash_hmac('sha256', self::seed(), app_secret());
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $seed = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($sent) || !is_string($seed) || $seed === ''
            || !hash_equals(hash_hmac('sha256', $seed, app_secret()), $sent)) {
            abort(419, 'This form expired. Reload the page and try again.');
        }
    }
}

function csrf_field(): string
{
    return Csrf::field();
}
