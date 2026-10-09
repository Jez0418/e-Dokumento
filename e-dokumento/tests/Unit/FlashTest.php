<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FlashTest extends TestCase
{
    protected function setUp(): void
    {
        $_COOKIE = [];
    }

    public function test_pull_returns_message_once(): void
    {
        Flash::add('success', 'Saved.');

        $this->assertSame([['type' => 'success', 'message' => 'Saved.']], Flash::pull());
        $this->assertSame([], Flash::pull());
    }

    public function test_long_message_is_cut_to_300_characters(): void
    {
        Flash::add('error', str_repeat('x', 400));

        $this->assertSame(300, mb_strlen(Flash::pull()[0]['message']));
    }

    public function test_keeps_only_last_four_messages(): void
    {
        foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $m) {
            Flash::add('success', $m);
        }

        $this->assertSame(['m2', 'm3', 'm4', 'm5'], array_column(Flash::pull(), 'message'));
    }

    public function test_tampered_cookie_is_ignored(): void
    {
        $json = json_encode([['type' => 'success', 'message' => 'Forged.']]);
        $_COOKIE['edk_flash'] = base64_encode($json) . '.' . str_repeat('0', 64);

        $this->assertSame([], Flash::pull());
    }
}
