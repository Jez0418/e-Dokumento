<?php
declare(strict_types=1);

/**
 * Stateless sessions for serverless PHP: the Supabase access and refresh tokens
 * live in HttpOnly cookies. Each request reads the caller's profile with their
 * own token, so PostgREST verifies the JWT and RLS applies.
 */
final class Auth
{
    private const ACCESS = 'edk_at';
    private const REFRESH = 'edk_rt';

    private static bool $loaded = false;
    private static ?array $user = null;
    private static ?string $token = null;
    private static bool $residentLoaded = false;
    private static ?array $resident = null;

    public static function token(): ?string
    {
        self::user();
        return self::$token;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;

        $access = self::cookie(self::ACCESS);
        $refresh = self::cookie(self::REFRESH);
        if ($access === null && $refresh === null) {
            return null;
        }

        $claims = $access ? self::claims($access) : null;
        if ($claims === null || (int) ($claims['exp'] ?? 0) < time() + 30) {
            if ($refresh === null) {
                self::clear();
                return null;
            }
            try {
                $session = Supabase::anon()->refreshSession($refresh);
            } catch (Throwable) {
                self::clear();
                return null;
            }
            self::store($session);
            $access = (string) $session['access_token'];
            $claims = self::claims($access);
            if ($claims === null) {
                self::clear();
                return null;
            }
        }

        self::$token = $access;
        try {
            $row = (new Supabase($access))->first('profiles', [
                ['id', 'eq.' . $claims['sub']],
                ['select', 'id,email,full_name,contact_no,status,role_id,last_login_at,roles(code,name)'],
            ]);
        } catch (SupabaseException $e) {
            if (in_array($e->status, [401, 403], true)) {
                self::$token = null;
                self::clear();
                return null;
            }
            throw $e;
        }
        if ($row === null || $row['status'] !== 'active') {
            self::$token = null;
            self::clear();
            return null;
        }
        $role = one($row['roles'] ?? null);
        $row['role'] = $role['code'] ?? 'resident';
        $row['role_name'] = $role['name'] ?? 'Resident';
        return self::$user = $row;
    }

    public static function id(): ?string
    {
        return self::user()['id'] ?? null;
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    /** The resident record linked to the signed-in account, if any. */
    public static function resident(): ?array
    {
        if (self::$residentLoaded) {
            return self::$resident;
        }
        self::$residentLoaded = true;
        if (!self::id()) {
            return null;
        }
        self::$resident = Supabase::user()->first('residents', [
            ['profile_id', 'eq.' . self::id()],
            ['select', '*,puroks(name)'],
        ]);
        return self::$resident;
    }

    /** @return array{0:bool,1:?string} */
    public static function attempt(string $email, string $password): array
    {
        try {
            $session = Supabase::anon()->signInWithPassword($email, $password);
        } catch (SupabaseException $e) {
            $code = strtolower((string) $e->errorCode);
            $msg = strtolower($e->getMessage());
            if ($code === 'email_not_confirmed' || str_contains($msg, 'not confirmed')) {
                return [false, 'Confirm your email address first. Check your inbox for the link.'];
            }
            if ($e->status === 429 || str_contains($code, 'rate')) {
                return [false, 'Too many attempts. Wait a minute, then try again.'];
            }
            if (in_array($e->status, [400, 401, 422], true)) {
                return [false, 'Incorrect email or password.'];
            }
            return [false, 'Sign-in is unavailable right now. Try again in a moment.'];
        }
        self::store($session);
        self::$loaded = false;
        self::$user = null;
        $user = self::user();
        if ($user === null) {
            return [false, 'This account is inactive. Contact the barangay administrator.'];
        }
        Audit::event('login', 'auth', $user['id']);
        return [true, null];
    }

    public static function logout(): void
    {
        if (self::token()) {
            Audit::event('logout', 'auth', self::id());
            try {
                Supabase::user()->signOut();
            } catch (Throwable) {
                // Token may already be expired; cookies are cleared either way.
            }
        }
        self::clear();
        self::$user = null;
        self::$token = null;
    }

    public static function requireLogin(): void
    {
        if (self::user() === null) {
            redirect(url('login', ['next' => current_path()]));
        }
    }

    /** Store a session returned by Supabase Auth. */
    public static function store(array $session): void
    {
        $access = (string) ($session['access_token'] ?? '');
        $refresh = (string) ($session['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            return;
        }
        $expiresIn = (int) ($session['expires_in'] ?? 3600);
        self::setCookie(self::ACCESS, $access, time() + max(60, $expiresIn));
        self::setCookie(self::REFRESH, $refresh, time() + 60 * 60 * 24 * 30);
    }

    private static function clear(): void
    {
        self::setCookie(self::ACCESS, '', time() - 3600);
        self::setCookie(self::REFRESH, '', time() - 3600);
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($value === '') {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $value;
        }
    }

    private static function cookie(string $name): ?string
    {
        $v = $_COOKIE[$name] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Reads (does not verify) JWT claims; PostgREST verifies the signature on every query. */
    private static function claims(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $data = $payload !== false ? json_decode($payload, true) : null;
        return is_array($data) && isset($data['sub'], $data['exp']) ? $data : null;
    }
}
