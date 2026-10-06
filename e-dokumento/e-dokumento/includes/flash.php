<?php
declare(strict_types=1);

/** One-time messages carried across a redirect in a signed cookie (no server session needed). */
final class Flash
{
    private const COOKIE = 'edk_flash';

    public static function add(string $type, string $message): void
    {
        $items = self::read();
        $items[] = ['type' => $type, 'message' => mb_substr($message, 0, 300)];
        $json = json_encode(array_slice($items, -4), JSON_UNESCAPED_UNICODE);
        $value = base64_encode((string) $json) . '.' . hash_hmac('sha256', (string) $json, app_secret());
        setcookie(self::COOKIE, $value, ['expires' => time() + 120, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::COOKIE] = $value;
    }

    public static function pull(): array
    {
        $items = self::read();
        if (isset($_COOKIE[self::COOKIE])) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
            unset($_COOKIE[self::COOKIE]);
        }
        return $items;
    }

    private static function read(): array
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($raw) || !str_contains($raw, '.')) {
            return [];
        }
        [$b64, $sig] = explode('.', $raw, 2);
        $json = base64_decode($b64, true);
        if ($json === false || !hash_equals(hash_hmac('sha256', $json, app_secret()), $sig)) {
            return [];
        }
        $items = json_decode($json, true);
        return is_array($items) ? $items : [];
    }
}

function flash_success(string $message): void
{
    Flash::add('success', $message);
}

function flash_error(string $message): void
{
    Flash::add('error', $message);
}
