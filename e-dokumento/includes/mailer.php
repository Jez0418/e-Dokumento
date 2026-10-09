<?php
declare(strict_types=1);

/**
 * Plain-text email through Gmail SMTP (an app password) over cURL.
 * Settings: MAIL_USERNAME, MAIL_APP_PASSWORD and optionally MAIL_FROM_NAME.
 */

function mail_configured(): bool
{
    return (string) Env::get('MAIL_USERNAME', '') !== '' && (string) Env::get('MAIL_APP_PASSWORD', '') !== '';
}

/** Header-safe text: CR and LF become spaces, so a value can never start a new header. */
function mail_header_text(string $value): string
{
    return str_replace(["\r", "\n"], ' ', $value);
}

/** The full RFC 5322 message, with CRLF line endings. */
function build_message(string $from, string $fromName, string $to, string $subject, string $body, ?DateTimeImmutable $now = null): string
{
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || strpbrk($to, "\r\n<>") !== false) {
        throw new InvalidArgumentException('Invalid recipient address.');
    }
    $now ??= new DateTimeImmutable('now');
    $fromName = trim(mail_header_text($fromName));
    if ($fromName === '') {
        $fromHeader = '<' . $from . '>';
    } elseif (preg_match('/^[\x20-\x7E]*$/', $fromName)) {
        $fromHeader = '"' . addcslashes($fromName, '"\\') . '" <' . $from . '>';
    } else {
        // Encoded words only: a comma or period in the name can never be read as address syntax.
        $words = [];
        for ($i = 0, $n = strlen($fromName); $i < $n; $i += strlen($chunk)) {
            $chunk = mb_strcut($fromName, $i, 45, 'UTF-8');
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        $fromHeader = implode("\r\n ", $words) . "\r\n <" . $from . '>';
    }
    $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';

    $headers = [
        'Date: ' . $now->format('D, d M Y H:i:s O'),
        'From: ' . $fromHeader,
        'To: ' . $to,
        'Subject: ' . mb_encode_mimeheader(mail_header_text($subject), 'UTF-8', 'B', "\r\n", strlen('Subject: ')),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $body = preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;

    return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
}

/** Sends one email. Throws RuntimeException with the cURL error when sending fails. */
function send_mail(string $to, string $subject, string $body): void
{
    if (!mail_configured()) {
        throw new RuntimeException('Email is not configured.');
    }
    $user = (string) Env::get('MAIL_USERNAME');
    $fromName = (string) Env::get('MAIL_FROM_NAME', '');
    if ($fromName === '') {
        $fromName = 'Barangay ' . barangay_name() . ' e-Dokumento';
    }
    $message = build_message($user, $fromName, $to, $subject, $body);

    // Its own handle: never the shared handle in Supabase.
    $ch = curl_init();
    $offset = 0;
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'smtps://smtp.gmail.com:465',
        CURLOPT_USERNAME       => $user,
        CURLOPT_PASSWORD       => (string) Env::get('MAIL_APP_PASSWORD'),
        CURLOPT_MAIL_FROM      => "<{$user}>",
        CURLOPT_MAIL_RCPT      => ["<{$to}>"],
        CURLOPT_UPLOAD         => true,
        CURLOPT_READFUNCTION   => static function ($ch, $fd, int $length) use ($message, &$offset): string {
            $chunk = substr($message, $offset, $length);
            $offset += strlen($chunk);
            return $chunk;
        },
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($ok === false) {
        throw new RuntimeException($error !== '' ? $error : 'The email could not be sent.');
    }
}
