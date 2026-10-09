<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_COOKIE = [];
    }

    public function test_token_is_hmac_of_cookie_seed(): void
    {
        $_COOKIE['edk_csrf'] = str_repeat('a', 64);
        $expected = hash_hmac('sha256', str_repeat('a', 64), str_repeat('a', 64));

        $this->assertSame($expected, Csrf::token());
        $field = Csrf::field();
        $this->assertStringContainsString('name="_csrf"', $field);
        $this->assertStringContainsString($expected, $field);
    }

    public function test_malformed_seed_is_replaced(): void
    {
        $_COOKIE['edk_csrf'] = 'xyz';
        Csrf::token();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $_COOKIE['edk_csrf']);
        $this->assertNotSame('xyz', $_COOKIE['edk_csrf']);
    }
}
