<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadTest extends TestCase
{
    private array $temp = [];

    protected function setUp(): void
    {
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'edk-up');
        file_put_contents($path, $contents);
        return $this->temp[] = $path;
    }

    public function test_missing_file(): void
    {
        $this->assertSame('Valid ID is required.', Upload::check(null, 'Valid ID', true));
        $this->assertNull(Upload::check(null, 'Valid ID', false));
    }

    public function test_file_over_the_php_limit(): void
    {
        $this->assertSame('Valid ID must be 2 MB or smaller.', Upload::check(['error' => UPLOAD_ERR_INI_SIZE], 'Valid ID', true));
    }

    public function test_file_that_was_not_uploaded(): void
    {
        $file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->tempFile("%PDF-1.7\n"), 'size' => 10, 'name' => 'a.pdf'];

        $this->assertSame('Valid ID could not be uploaded. Try again.', Upload::check($file, 'Valid ID', true));
    }

    public static function signatures(): array
    {
        return [
            'pdf'              => ["%PDF-1.7\n", 'application/pdf'],
            'jpeg'             => ["\xFF\xD8\xFF\xE0", 'image/jpeg'],
            'png'              => ["\x89PNG\r\n\x1A\n", 'image/png'],
            'docx renamed pdf' => ["PK\x03\x04", null],
            'empty'            => ['', null],
        ];
    }

    #[DataProvider('signatures')]
    public function test_sniff_reads_magic_bytes(string $contents, ?string $expected): void
    {
        $sniff = new ReflectionMethod(Upload::class, 'sniff');

        $this->assertSame($expected, $sniff->invoke(null, $this->tempFile($contents)));
    }

    public function test_max_bytes_is_2_mb(): void
    {
        $this->assertSame(2097152, Upload::MAX_BYTES);
    }
}
