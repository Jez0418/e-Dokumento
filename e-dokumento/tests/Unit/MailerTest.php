<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MAIL_USERNAME');
        putenv('MAIL_APP_PASSWORD');
    }

    private static function message(string $subject = 'Hi', string $body = 'Body', string $to = 'ana@example.com'): string
    {
        return build_message('b@gmail.com', 'Barangay San Isidro e-Dokumento', $to, $subject, $body,
            new DateTimeImmutable('2026-10-09 08:00:00+08:00'));
    }

    /** @return list<string> header lines, still folded */
    private static function headerLines(string $message): array
    {
        return explode("\r\n", explode("\r\n\r\n", $message, 2)[0]);
    }

    private static function decodedSubject(string $message): string
    {
        $headers = explode("\r\n\r\n", $message, 2)[0];
        $unfolded = preg_replace('/\r\n(?=[ \t])/', '', $headers);
        preg_match('/^Subject: (.*)$/m', $unfolded, $m);
        return mb_decode_mimeheader($m[1]);
    }

    public function test_builds_required_headers(): void
    {
        $message = self::message();
        $lines = self::headerLines($message);

        $this->assertContains('Date: Fri, 09 Oct 2026 08:00:00 +0800', $lines);
        $this->assertContains('To: ana@example.com', $lines);
        $this->assertContains('MIME-Version: 1.0', $lines);
        $this->assertContains('Content-Type: text/plain; charset=UTF-8', $lines);
        $this->assertContains('Content-Transfer-Encoding: base64', $lines);
        $this->assertCount(1, preg_grep('/^Message-ID: <[0-9a-f]{32}@gmail\.com>$/', $lines));
        $this->assertCount(1, preg_grep('/^From: .*<b@gmail\.com>$/', $lines));

        $this->assertStringContainsString("\r\n\r\n", $message);
        $this->assertStringEndsWith("\r\n", $message);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $message), 'Every line ends with CRLF');
    }

    public function test_body_is_base64_utf8(): void
    {
        $message = self::message('Hi', "Bayad: ₱50.00\nPeña St.");
        $encoded = str_replace("\r\n", '', explode("\r\n\r\n", $message, 2)[1]);

        $this->assertSame("Bayad: ₱50.00\r\nPeña St.", base64_decode($encoded, true));
    }

    public function test_long_utf8_subject_is_folded_and_round_trips(): void
    {
        $subject = 'REQ-2026-000133: Your Certificate of Indigency for Señor Niño Dela Peña is ready for pickup ₱';
        $message = self::message($subject);

        foreach (self::headerLines($message) as $line) {
            $this->assertLessThanOrEqual(78, strlen($line), "Header line too long: {$line}");
        }
        $this->assertSame($subject, self::decodedSubject($message));
    }

    public function test_subject_newlines_cannot_inject_headers(): void
    {
        $message = self::message("Hi\r\nBcc: evil@x.com");

        $this->assertSame([], preg_grep('/^Bcc:/i', self::headerLines($message)));
        $this->assertSame('Hi  Bcc: evil@x.com', self::decodedSubject($message));
    }

    public function test_non_ascii_sender_name_is_fully_encoded(): void
    {
        $name = 'Barangay Sta. Cruz, Niño e-Dokumento';
        $message = build_message('b@gmail.com', $name, 'ana@example.com', 'Hi', 'Body');
        $headers = explode("\r\n\r\n", $message, 2)[0];
        $unfolded = preg_replace('/\r\n(?=[ \t])/', '', $headers);
        preg_match('/^From: (.*) <b@gmail\.com>\r?$/m', $unfolded, $m);

        $this->assertNotEmpty($m, 'From header keeps the address');
        $this->assertSame(0, preg_match('/(^|\?=)[^=]*,/', $m[1]), 'No bare comma outside an encoded word');
        $this->assertSame($name, mb_decode_mimeheader($m[1]));
        foreach (self::headerLines($message) as $line) {
            $this->assertLessThanOrEqual(78, strlen($line), "Header line too long: {$line}");
        }
    }

    public function test_rejects_recipient_with_newline(): void
    {
        foreach (["ana@example.com\r\nBcc: x@y.z", 'not-an-email'] as $to) {
            try {
                self::message('Hi', 'Body', $to);
                $this->fail("Accepted recipient: {$to}");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid recipient address.', $e->getMessage());
            }
        }
    }

    public function test_mail_configured_needs_username_and_password(): void
    {
        $this->assertFalse(mail_configured());

        putenv('MAIL_USERNAME=b@gmail.com');
        $this->assertFalse(mail_configured());

        putenv('MAIL_USERNAME');
        putenv('MAIL_APP_PASSWORD=abcd efgh ijkl mnop');
        $this->assertFalse(mail_configured());

        putenv('MAIL_USERNAME=b@gmail.com');
        $this->assertTrue(mail_configured());
    }

    public function test_send_mail_refuses_when_not_configured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Email is not configured.');

        send_mail('ana@example.com', 'Hi', 'Body');
    }
}
