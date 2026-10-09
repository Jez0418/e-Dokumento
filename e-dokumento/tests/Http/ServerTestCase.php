<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Starts the app under PHP's built-in server (router.php) for HTTP smoke tests.
 * Supabase points at a closed port, so no request leaves the machine.
 */
abstract class ServerTestCase extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    private static string $log = '';
    protected static int $port = 8099;

    public static function setUpBeforeClass(): void
    {
        self::$port = (int) (getenv('TEST_HTTP_PORT') ?: 8099);
        if (self::listening()) {
            self::fail('Port 127.0.0.1:' . self::$port . ' is already in use. Stop that process or set TEST_HTTP_PORT.');
        }

        self::$log = (string) tempnam(sys_get_temp_dir(), 'edk-server');
        // Merge with the parent env: on Windows a child without SystemRoot cannot open sockets.
        $env = array_merge(getenv(), TEST_ENV);
        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$log, 'a'], 2 => ['file', self::$log, 'a']],
            $pipes,
            BASE_PATH,
            $env
        );
        if (!is_resource(self::$process)) {
            self::fail('Test server did not start on 127.0.0.1:' . self::$port);
        }

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && proc_get_status(self::$process)['running']) {
            if (self::listening()) {
                return;
            }
            usleep(100_000);
        }
        $output = trim((string) @file_get_contents(self::$log));
        self::stopServer();
        self::fail('Test server did not start on 127.0.0.1:' . self::$port . ($output !== '' ? "\n" . $output : ''));
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        self::$process = null;
        if (self::$log !== '') {
            @unlink(self::$log);
            self::$log = '';
        }
    }

    private static function listening(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    protected static function http(string $method, string $path, array $form = [], array $cookies = []): array
    {
        $headers = [];
        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headers),
            'content'         => http_build_query($form),
            'follow_location' => 0,
            'ignore_errors'   => true,
            'timeout'         => 20,
        ]]);

        $body = @file_get_contents('http://127.0.0.1:' . self::$port . $path, false, $context);
        if ($body === false) {
            self::fail("{$method} {$path}: no response from the test server");
        }
        $raw = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;

        $status = 0;
        $parsed = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $parsed = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($name))] = trim($value);
            }
        }
        return ['status' => $status, 'headers' => $parsed, 'body' => $body];
    }
}
