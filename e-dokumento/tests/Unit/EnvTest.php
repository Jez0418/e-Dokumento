<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private static string $path;

    public static function setUpBeforeClass(): void
    {
        self::$path = tempnam(sys_get_temp_dir(), 'edk-env');
        file_put_contents(self::$path, implode("\n", ['T1=plain', 'T2="a b"', "T3='c'", '# T4=x', 'NOEQUALS']) . "\n");
        Env::load(self::$path);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$path);
    }

    protected function tearDown(): void
    {
        putenv('T1');
    }

    public function test_reads_plain_and_quoted_values(): void
    {
        $this->assertSame('plain', Env::get('T1'));
        $this->assertSame('a b', Env::get('T2'));
        $this->assertSame('c', Env::get('T3'));
        $this->assertNull(Env::get('T4'));
    }

    public function test_real_environment_wins_over_file(): void
    {
        putenv('T1=fromenv');

        $this->assertSame('fromenv', Env::get('T1'));
    }

    public function test_require_throws_for_missing_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing environment variable NOPE_X');

        Env::require('NOPE_X');
    }
}
