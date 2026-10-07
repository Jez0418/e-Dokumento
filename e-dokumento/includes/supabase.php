<?php
declare(strict_types=1);

final class SupabaseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly mixed $body = null
    ) {
        parent::__construct($message, $status);
    }
}

/**
 * Minimal Supabase client over cURL: Auth (GoTrue), Data API (PostgREST), RPC and Storage.
 *
 * - Supabase::user()  -> publishable key + the signed-in user's JWT (RLS applies as that user)
 * - Supabase::anon()  -> publishable key only (RLS applies as anon)
 * - Supabase::admin() -> secret key, server-only, used for the Auth admin API
 */
final class Supabase
{
    private static ?\CurlHandle $ch = null;
    private string $url;

    public function __construct(private ?string $accessToken = null, private bool $useSecret = false)
    {
        $cfg = config('supabase');
        $this->url = $cfg['url'];
        if ($this->url === '' || $cfg['publishable_key'] === '') {
            throw new RuntimeException('Supabase is not configured. Set SUPABASE_URL and SUPABASE_PUBLISHABLE_KEY.');
        }
        if ($useSecret && $cfg['secret_key'] === '') {
            throw new RuntimeException('SUPABASE_SECRET_KEY is required for this action.');
        }
    }

    public static function anon(): self
    {
        return new self(null, false);
    }

    public static function user(): self
    {
        $token = Auth::token();
        return $token ? new self($token, false) : self::anon();
    }

    public static function admin(): self
    {
        return new self(null, true);
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------
    private function headers(array $extra = []): array
    {
        $cfg = config('supabase');
        $key = $this->useSecret ? $cfg['secret_key'] : $cfg['publishable_key'];
        $headers = ['apikey: ' . $key, 'Accept: application/json'];
        // New sb_ keys are not JWTs and must not be sent as Bearer; legacy JWT keys may be.
        $bearer = $this->accessToken ?? (str_starts_with($key, 'eyJ') ? $key : null);
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        return array_merge($headers, $extra);
    }

    /** @return array{status:int, data:mixed, headers:array<string,string>} */
    public function request(string $method, string $path, array $query = [], mixed $body = null,
                            array $headers = [], bool $rawBody = false): array
    {
        $url = $this->url . $path;
        $qs = self::buildQuery($query);
        if ($qs !== '') {
            $url .= '?' . $qs;
        }
        $hdrs = $this->headers($headers);
        // One shared handle per PHP process: curl_reset() keeps the open connection,
        // so later calls skip the DNS lookup and TLS handshake.
        self::$ch ??= curl_init();
        $ch = self::$ch;
        curl_reset($ch);
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 25,
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            if ($rawBody) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            } else {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $hdrs[] = 'Content-Type: application/json';
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            throw new SupabaseException('Could not reach the database service. ' . $err, 0, 'network');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $rawBodyText = substr((string) $raw, $headerSize);
        $parsedHeaders = [];
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        $data = null;
        if ($rawBodyText !== '') {
            $decoded = json_decode($rawBodyText, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : $rawBodyText;
        }
        if ($status >= 400) {
            $message = 'Request failed.';
            $code = null;
            if (is_array($data)) {
                $message = (string) ($data['message'] ?? $data['msg'] ?? $data['error_description'] ?? $data['error'] ?? $message);
                $code = isset($data['code']) ? (string) $data['code'] : (isset($data['error_code']) ? (string) $data['error_code'] : null);
            }
            throw new SupabaseException($message, $status, $code, $data);
        }
        return ['status' => $status, 'data' => $data, 'headers' => $parsedHeaders];
    }

    /** Accepts ['col' => 'eq.1'] or [['col','eq.1'], ['col','neq.2']] so keys may repeat. */
    public static function buildQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $k => $v) {
            if (is_int($k) && is_array($v) && count($v) === 2) {
                [$k, $v] = $v;
            }
            if ($v === null) {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return implode('&', $parts);
    }

    // ------------------------------------------------------------------
    // Data API
    // ------------------------------------------------------------------
    /** @return array{rows:array, total:?int} */
    public function select(string $table, array $query = [], bool $count = false, ?int $offset = null, ?int $limit = null): array
    {
        if ($limit !== null) {
            $query[] = ['limit', (string) $limit];
        }
        if ($offset !== null) {
            $query[] = ['offset', (string) $offset];
        }
        $headers = $count ? ['Prefer: count=exact'] : [];
        $res = $this->request('GET', '/rest/v1/' . $table, $query, null, $headers);
        $total = null;
        if ($count && isset($res['headers']['content-range'])) {
            $parts = explode('/', $res['headers']['content-range']);
            $total = isset($parts[1]) && $parts[1] !== '*' ? (int) $parts[1] : null;
        }
        return ['rows' => is_array($res['data']) ? $res['data'] : [], 'total' => $total];
    }

    public function first(string $table, array $query = []): ?array
    {
        $rows = $this->select($table, $query, false, null, 1)['rows'];
        return $rows[0] ?? null;
    }

    public function count(string $table, array $query = []): int
    {
        $query[] = ['select', 'id'];
        $res = $this->request('HEAD', '/rest/v1/' . $table, $query, null, ['Prefer: count=exact']);
        $parts = explode('/', $res['headers']['content-range'] ?? '*/0');
        return (int) ($parts[1] ?? 0);
    }

    public function insert(string $table, array $data, array $query = []): array
    {
        $res = $this->request('POST', '/rest/v1/' . $table, $query, $data, ['Prefer: return=representation']);
        return is_array($res['data']) ? $res['data'] : [];
    }

    public function upsert(string $table, array $data, string $onConflict): array
    {
        $res = $this->request('POST', '/rest/v1/' . $table, [['on_conflict', $onConflict]], $data,
            ['Prefer: return=representation,resolution=merge-duplicates']);
        return is_array($res['data']) ? $res['data'] : [];
    }

    public function update(string $table, array $filters, array $data): array
    {
        if ($filters === []) {
            throw new LogicException('Refusing to update without a filter.');
        }
        $res = $this->request('PATCH', '/rest/v1/' . $table, $filters, $data, ['Prefer: return=representation']);
        return is_array($res['data']) ? $res['data'] : [];
    }

    public function delete(string $table, array $filters): array
    {
        if ($filters === []) {
            throw new LogicException('Refusing to delete without a filter.');
        }
        $res = $this->request('DELETE', '/rest/v1/' . $table, $filters, null, ['Prefer: return=representation']);
        return is_array($res['data']) ? $res['data'] : [];
    }

    public function rpc(string $function, array $params = []): mixed
    {
        $res = $this->request('POST', '/rest/v1/rpc/' . $function, [], (object) $params);
        return $res['data'];
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------
    public function signInWithPassword(string $email, string $password): array
    {
        return $this->request('POST', '/auth/v1/token', [['grant_type', 'password']],
            ['email' => $email, 'password' => $password])['data'];
    }

    public function refreshSession(string $refreshToken): array
    {
        return $this->request('POST', '/auth/v1/token', [['grant_type', 'refresh_token']],
            ['refresh_token' => $refreshToken])['data'];
    }

    public function signUp(string $email, string $password, array $metadata, string $redirectTo): array
    {
        return $this->request('POST', '/auth/v1/signup', [['redirect_to', $redirectTo]],
            ['email' => $email, 'password' => $password, 'data' => $metadata])['data'] ?? [];
    }

    public function recover(string $email, string $redirectTo): void
    {
        $this->request('POST', '/auth/v1/recover', [['redirect_to', $redirectTo]], ['email' => $email]);
    }

    public function updatePassword(string $password): void
    {
        $this->request('PUT', '/auth/v1/user', [], ['password' => $password]);
    }

    public function signOut(): void
    {
        $this->request('POST', '/auth/v1/logout', [], (object) []);
    }

    public function adminCreateUser(string $email, string $password, array $userMeta, array $appMeta): array
    {
        return $this->request('POST', '/auth/v1/admin/users', [], [
            'email'         => $email,
            'password'      => $password,
            'email_confirm' => true,
            'user_metadata' => $userMeta,
            'app_metadata'  => $appMeta,
        ])['data'];
    }

    public function adminUpdateUser(string $userId, array $attributes): array
    {
        return $this->request('PUT', '/auth/v1/admin/users/' . rawurlencode($userId), [], $attributes)['data'];
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------
    private static function objectPath(string $bucket, string $path): string
    {
        $segments = array_map('rawurlencode', explode('/', $path));
        return rawurlencode($bucket) . '/' . implode('/', $segments);
    }

    public function upload(string $bucket, string $path, string $bytes, string $mime): void
    {
        $this->request('POST', '/storage/v1/object/' . self::objectPath($bucket, $path), [], $bytes,
            ['Content-Type: ' . $mime, 'x-upsert: false', 'Cache-Control: max-age=3600'], true);
    }

    public function signedUrl(string $bucket, string $path, int $expiresIn = 60): string
    {
        $data = $this->request('POST', '/storage/v1/object/sign/' . self::objectPath($bucket, $path), [],
            ['expiresIn' => $expiresIn])['data'];
        $signed = is_array($data) ? (string) ($data['signedURL'] ?? $data['signedUrl'] ?? '') : '';
        if ($signed === '') {
            throw new SupabaseException('Could not open the file.', 500);
        }
        return $this->url . '/storage/v1' . (str_starts_with($signed, '/') ? $signed : '/' . $signed);
    }

    public function removeObjects(string $bucket, array $paths): void
    {
        if ($paths === []) {
            return;
        }
        $this->request('DELETE', '/storage/v1/object/' . rawurlencode($bucket), [], ['prefixes' => array_values($paths)]);
    }
}
