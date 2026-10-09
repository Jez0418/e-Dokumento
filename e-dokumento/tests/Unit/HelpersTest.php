<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function test_safe_next_keeps_same_site_path(): void
    {
        $this->assertSame('/dashboard', safe_next('/dashboard'));
    }

    public static function unsafeNext(): array
    {
        return [
            'protocol-relative' => ['//evil.com'],
            'absolute'          => ['https://evil.com'],
            'backslash'         => ['/\\evil'],
            'empty'             => [''],
            'null'              => [null],
        ];
    }

    #[DataProvider('unsafeNext')]
    public function test_safe_next_rejects_off_site_targets(?string $next): void
    {
        $this->assertNull(safe_next($next));
    }

    public function test_is_uuid_and_is_date(): void
    {
        $this->assertFalse(is_uuid('not-a-uuid'));
        $this->assertTrue(is_date('2024-02-29'));
        $this->assertFalse(is_date('2023-02-29'));
        $this->assertFalse(is_date('2024-2-1'));
    }

    public function test_search_term_strips_filter_syntax(): void
    {
        $this->assertSame('ana or', search_term('ana,(or)*'));
        $this->assertSame('Peña', search_term('Peña'));
    }

    public function test_url_drops_empty_query_values(): void
    {
        $this->assertSame('/requests/view?id=5', url('requests/view', ['id' => 5, 'x' => '', 'y' => null]));
    }

    public function test_e_and_money(): void
    {
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;', e('<a href="x">'));
        $this->assertSame('₱1,234.50', money(1234.5));
    }

    public static function ordinals(): array
    {
        return [[1, '1st'], [2, '2nd'], [3, '3rd'], [4, '4th'], [11, '11th'], [12, '12th'], [13, '13th'], [21, '21st'], [112, '112th']];
    }

    #[DataProvider('ordinals')]
    public function test_ordinal(int $n, string $expected): void
    {
        $this->assertSame($expected, ordinal($n));
    }

    public function test_resident_name(): void
    {
        $r = ['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.'];
        $this->assertSame('Juan S. Dela Cruz Jr.', resident_name($r));
        $this->assertSame('Dela Cruz, Juan S. Jr.', resident_name($r, true));
    }

    public function test_one_unwraps_embeds(): void
    {
        $this->assertSame(['a' => 1], one([['a' => 1]]));
        $this->assertSame(['a' => 1], one(['a' => 1]));
        $this->assertNull(one([]));
    }

    public function test_report_cell_money(): void
    {
        $this->assertSame('', report_cell(null, 'money', true));
        $this->assertSame('1234.50', report_cell('1234.5', 'money', true));
        $this->assertSame('₱1,234.50', report_cell('1234.5', 'money'));
    }

    public static function dbErrors(): array
    {
        return [
            'duplicate resident' => ['dup residents_identity_uniq', 409, '23505', 'A resident with the same name and birth date is already registered.'],
            'foreign key'        => ['x', 409, '23503', 'This record is linked to other records. Deactivate it instead of deleting it.'],
            'raised rule'        => ['Custom rule.', 400, 'P0001', 'Custom rule.'],
            'expired session'    => ['x', 401, null, 'Your session has ended. Sign in again.'],
            'permission'         => ['x', 403, '42501', 'You do not have permission to perform this action.'],
        ];
    }

    #[DataProvider('dbErrors')]
    public function test_db_error_messages(string $msg, int $status, ?string $code, string $expected): void
    {
        $this->assertSame($expected, db_error(new SupabaseException($msg, $status, $code)));
    }
}
