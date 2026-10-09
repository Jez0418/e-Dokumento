<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/ServerTestCase.php';

final class FrontControllerTest extends ServerTestCase
{
    public function test_unknown_page_is_404(): void
    {
        $r = self::http('GET', '/no-such-page');

        $this->assertSame(404, $r['status']);
        // The error page HTML-escapes the apostrophe.
        $this->assertStringContainsString(htmlspecialchars("This page doesn't exist"), $r['body']);
    }

    public function test_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $r = self::http('GET', '/residents');

        $this->assertSame(303, $r['status']);
        $this->assertSame('/login?next=%2Fresidents', $r['headers']['location'] ?? null);
    }

    public function test_login_page_renders_with_and_without_php_suffix(): void
    {
        $login = self::http('GET', '/login');
        $this->assertSame(200, $login['status']);
        $this->assertSame('no-store, private', $login['headers']['cache-control'] ?? null);

        $this->assertSame(200, self::http('GET', '/login.php')['status']);
    }

    public function test_security_headers(): void
    {
        $h = self::http('GET', '/login')['headers'];

        $this->assertSame('DENY', $h['x-frame-options'] ?? null);
        $this->assertSame('nosniff', $h['x-content-type-options'] ?? null);
        $this->assertStringContainsString("frame-ancestors 'none'", $h['content-security-policy'] ?? '');
    }

    public function test_post_without_csrf_token_is_refused(): void
    {
        $r = self::http('POST', '/login', ['email' => 'ana@example.test', 'password' => 'Secret123']);

        $this->assertSame(419, $r['status']);
        $this->assertStringContainsString('This form expired. Reload the page and try again.', $r['body']);
    }

    public function test_post_with_valid_csrf_token_reaches_sign_in(): void
    {
        $seed = str_repeat('b', 64);
        $r = self::http('POST', '/login', [
            'email'    => 'ana@example.test',
            'password' => 'Secret123',
            '_csrf'    => hash_hmac('sha256', $seed, str_repeat('a', 64)),
        ], ['edk_csrf' => $seed]);

        $this->assertNotSame(419, $r['status']);
        $this->assertStringContainsString('Sign-in is unavailable right now. Try again in a moment.', $r['body']);
    }

    public static function publicPages(): array
    {
        return [['/login'], ['/register'], ['/forgot-password'], ['/verify']];
    }

    #[DataProvider('publicPages')]
    public function test_secret_key_never_reaches_the_browser(string $path): void
    {
        $r = self::http('GET', $path);

        $this->assertSame(200, $r['status'], "GET {$path}");
        $this->assertStringNotContainsString('SENTINEL_DO_NOT_LEAK', $r['body']);
    }
}
