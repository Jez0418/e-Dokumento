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

    /** A real RGBA PNG of the given size, built without GD. */
    private static function png(int $w, int $h, int $alpha = 255): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $row = "\x00" . str_repeat("\x10\x20\x40" . chr($alpha), $w);
        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0))
            . $chunk('IDAT', gzcompress(str_repeat($row, $h)))
            . $chunk('IEND', '');
    }

    private static function dataUrl(string $bytes, string $mime = 'image/png'): string
    {
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    public function test_data_url_png_is_accepted(): void
    {
        $info = Upload::fromDataUrl(self::dataUrl(self::png(300, 100)));

        $this->assertIsArray($info);
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame('png', $info['ext']);
        $this->assertSame(self::png(300, 100), file_get_contents($info['tmp']));
        @unlink($info['tmp']);
    }

    public static function badDataUrls(): array
    {
        return [
            'empty'            => ['', 'Draw your signature first.'],
            'not a data url'   => ['hello', 'Draw your signature first.'],
            'jpeg mime'        => [self::dataUrl("\xFF\xD8\xFF\xE0", 'image/jpeg'), 'Signature must be a PNG image.'],
            'bad base64'       => ['data:image/png;base64,@@@', 'Signature must be a PNG image.'],
            'not really a png' => [self::dataUrl('<svg></svg>'), 'Signature must be a PNG image.'],
            'too small'        => [self::dataUrl(self::png(5, 5)), 'Signature is too small. Draw it larger.'],
            'too large'        => [self::dataUrl(self::png(3000, 100)), 'Signature is too large.'],
        ];
    }

    #[DataProvider('badDataUrls')]
    public function test_data_url_problems(string $data, string $expected): void
    {
        $this->assertSame($expected, Upload::fromDataUrl($data));
    }

    public function test_data_url_over_1_mb(): void
    {
        $big = self::png(300, 100) . str_repeat('x', Upload::SIGNATURE_MAX_BYTES);

        $this->assertSame('Signature must be 1 MB or smaller.', Upload::fromDataUrl(self::dataUrl($big)));
    }
}
