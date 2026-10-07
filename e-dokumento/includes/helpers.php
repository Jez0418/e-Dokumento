<?php
declare(strict_types=1);

function config(string $name): array
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $cache[$name] = require BASE_PATH . '/config/' . $name . '.php';
    }
    return $cache[$name];
}

function app_secret(): string
{
    $secret = config('app')['secret'];
    if (strlen($secret) < 32) {
        throw new RuntimeException('APP_SECRET must be at least 32 characters. Generate one with: php -r "echo bin2hex(random_bytes(32));"');
    }
    return $secret;
}

function app_url(string $path = ''): string
{
    $base = config('app')['url'];
    if ($base === '') {
        $scheme = is_https() ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/' . ltrim($path, '/');
}

/** HTML-escape for output. Every dynamic value printed in a page goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path, array $query = []): string
{
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '' && $v !== false);
    return '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function back_or(string $fallback): never
{
    redirect(safe_next((string) ($_POST['_back'] ?? '')) ?? $fallback);
}

/** Only allow same-site relative paths as redirect targets. */
function safe_next(?string $next): ?string
{
    if ($next === null || $next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
        return null;
    }
    return $next;
}

function current_path(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function q(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function q_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    return is_string($v) && preg_match('/^\d{1,9}$/', $v) ? (int) $v : $default;
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function is_uuid(?string $v): bool
{
    return is_string($v) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
}

function is_date(?string $v): bool
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y);
}

/** Search text safe to place inside a PostgREST filter value. */
function search_term(string $q): string
{
    $q = preg_replace('/[^\p{L}\p{N}\s\-\'.@]/u', ' ', $q) ?? '';
    return trim(preg_replace('/\s+/', ' ', $q) ?? '');
}

function client_ip(): ?string
{
    $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($fwd !== '') {
        return trim(explode(',', $fwd)[0]);
    }
    return $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
}

function user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function money(mixed $value): string
{
    return '₱' . number_format((float) $value, 2);
}

function local_dt(?string $value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Manila'));
    } catch (Throwable) {
        return null;
    }
}

function fmt_date(?string $value, string $format = 'M j, Y'): string
{
    $d = local_dt($value);
    return $d ? $d->format($format) : '—';
}

function fmt_datetime(?string $value): string
{
    return fmt_date($value, 'M j, Y g:i A');
}

function time_ago(?string $value): string
{
    $d = local_dt($value);
    if (!$d) {
        return '—';
    }
    $diff = time() - $d->getTimestamp();
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $m = intdiv($diff, 60);
        return $m . ' min ago';
    }
    if ($diff < 86400) {
        $h = intdiv($diff, 3600);
        return $h . ($h === 1 ? ' hour ago' : ' hours ago');
    }
    if ($diff < 86400 * 7) {
        $days = intdiv($diff, 86400);
        return $days . ($days === 1 ? ' day ago' : ' days ago');
    }
    return $d->format('M j, Y');
}

function today_local(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

function age_from(?string $birthDate): ?int
{
    if (!is_date($birthDate)) {
        return null;
    }
    return (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable(today_local()))->y;
}

function ordinal(int $n): string
{
    $suffix = 'th';
    if (!in_array($n % 100, [11, 12, 13], true)) {
        $suffix = match ($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
    }
    return $n . $suffix;
}

function resident_name(array $r, bool $lastFirst = false): string
{
    $first = trim((string) ($r['first_name'] ?? ''));
    $middle = trim((string) ($r['middle_name'] ?? ''));
    $last = trim((string) ($r['last_name'] ?? ''));
    $suffix = trim((string) ($r['suffix'] ?? ''));
    if ($lastFirst) {
        return trim($last . ', ' . $first . ($middle !== '' ? ' ' . mb_substr($middle, 0, 1) . '.' : '') . ($suffix !== '' ? ' ' . $suffix : ''));
    }
    return trim(implode(' ', array_filter([$first, $middle !== '' ? mb_substr($middle, 0, 1) . '.' : '', $last, $suffix])));
}

/** PostgREST may return a to-one embed as an object or a one-item list. */
function one(mixed $embed): ?array
{
    if (!is_array($embed) || $embed === []) {
        return null;
    }
    return array_is_list($embed) ? ($embed[0] ?? null) : $embed;
}

function settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $settings = [];
    try {
        foreach (Supabase::user()->select('system_settings', [['select', 'key,value']])['rows'] as $row) {
            $settings[$row['key']] = $row['value'];
        }
    } catch (Throwable $e) {
        error_log('settings: ' . $e->getMessage());
    }
    return $settings;
}

function setting(string $key, string $default = ''): string
{
    $v = settings()[$key] ?? null;
    return is_scalar($v) && (string) $v !== '' ? (string) $v : $default;
}

/** False while the 'require_id_verification' setting is 'off' (testing mode). Defaults to on. */
function id_verification_required(): bool
{
    return setting('require_id_verification', 'on') !== 'off';
}

function barangay_name(): string
{
    return setting('barangay_name', 'Barangay');
}

/** Turns database errors into messages a person can act on. */
function db_error(Throwable $e): string
{
    if ($e instanceof SupabaseException) {
        $code = (string) $e->errorCode;
        $msg = $e->getMessage();
        return match (true) {
            $code === 'P0001' => $msg,
            $code === '23505' => str_contains($msg, 'residents_identity') ? 'A resident with the same name and birth date is already registered.'
                               : (str_contains($msg, 'one_captain') ? 'Only one Punong Barangay can be active at a time. Deactivate the current one first.'
                               : 'This record already exists.'),
            $code === '23503' => 'This record is linked to other records. Deactivate it instead of deleting it.',
            $code === '23514' => 'Some values are outside what the system accepts. Check the form and try again.',
            $code === '42501' || $e->status === 403 => 'You do not have permission to perform this action.',
            $e->status === 401 => 'Your session has ended. Sign in again.',
            $code === 'network' => 'The database service could not be reached. Try again in a moment.',
            default => 'Unable to save. ' . ($e->status >= 500 ? 'Try again in a moment.' : $msg),
        };
    }
    error_log((string) $e);
    return 'Something went wrong. Try again in a moment.';
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $GLOBALS['error_code'] = $code;
    $GLOBALS['error_message'] = $message;
    require BASE_PATH . '/pages/errors/error.php';
    exit;
}

function old(array $old, string $key, string $default = ''): string
{
    $v = $old[$key] ?? $default;
    return is_scalar($v) ? (string) $v : $default;
}

function sel(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function chk(mixed $v): string
{
    return $v ? ' checked' : '';
}

function invalid(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>' : '';
}
