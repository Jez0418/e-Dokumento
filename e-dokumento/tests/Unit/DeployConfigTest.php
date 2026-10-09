<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Test tooling must never ship to the Vercel function bundle. */
final class DeployConfigTest extends TestCase
{
    public function test_vercel_excludes_test_tooling(): void
    {
        $config = json_decode((string) file_get_contents(BASE_PATH . '/vercel.json'), true, 512, JSON_THROW_ON_ERROR);
        $exclude = $config['functions']['api/index.php']['excludeFiles'];
        $this->assertStringContainsString('tests/**', $exclude);
        $this->assertStringContainsString('supabase/**', $exclude);
    }

    public function test_no_root_composer_json(): void
    {
        $this->assertFileDoesNotExist(BASE_PATH . '/composer.json');
    }
}
