<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EmailOutboxTest extends TestCase
{
    /** @var list<array{0:string,1:array}> */
    private array $calls = [];
    /** @var list<array> rows served one per claim_outbox_emails call */
    private array $queue = [];
    private string $errorLog = '';
    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        // outbox_after_rpc logs what it swallows; keep that out of the test output.
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'edk-log');
        $this->previousErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    private function rpc(): Closure
    {
        return function (string $function, array $params): mixed {
            $this->calls[] = [$function, $params];
            if ($function === 'claim_outbox_emails') {
                $row = array_shift($this->queue);
                return $row === null ? [] : [$row];
            }
            return null;
        };
    }

    private static function row(int $n): array
    {
        return ['id' => "id-{$n}", 'to_email' => "r{$n}@example.test", 'subject' => "Subject {$n}", 'body' => "Body {$n}"];
    }

    public function test_flush_sends_each_claimed_row_and_reports_success(): void
    {
        $this->queue = [self::row(1), self::row(2)];
        $sent = [];
        $send = function (string $to, string $subject, string $body) use (&$sent): void {
            $sent[] = [$to, $subject, $body];
        };

        $this->assertSame(2, flush_outbox($this->rpc(), $send));

        $this->assertSame([
            ['claim_outbox_emails', ['p_limit' => 1]],
            ['finish_outbox_email', ['p_id' => 'id-1', 'p_ok' => true]],
            ['claim_outbox_emails', ['p_limit' => 1]],
            ['finish_outbox_email', ['p_id' => 'id-2', 'p_ok' => true]],
            ['claim_outbox_emails', ['p_limit' => 1]],
        ], $this->calls);
        $this->assertSame([
            ['r1@example.test', 'Subject 1', 'Body 1'],
            ['r2@example.test', 'Subject 2', 'Body 2'],
        ], $sent);
    }

    public function test_flush_reports_failure_with_message(): void
    {
        $this->queue = [self::row(1)];
        $send = static function (): void {
            throw new RuntimeException('535 bad credentials');
        };

        $this->assertSame(0, flush_outbox($this->rpc(), $send));
        $this->assertContains(
            ['finish_outbox_email', ['p_id' => 'id-1', 'p_ok' => false, 'p_error' => '535 bad credentials']],
            $this->calls
        );
    }

    public function test_flush_stops_after_a_failure(): void
    {
        // A failed row goes back to the queue, so the next claim would return it again.
        $rpc = function (string $function, array $params): mixed {
            $this->calls[] = [$function, $params];
            return $function === 'claim_outbox_emails' ? [self::row(1)] : null;
        };
        $sends = 0;
        $send = function () use (&$sends): void {
            $sends++;
            throw new RuntimeException('535 bad credentials');
        };

        flush_outbox($rpc, $send);

        $this->assertSame(1, $sends, 'A failing email is tried once per staff action');
        $this->assertSame(['claim_outbox_emails', 'finish_outbox_email'], array_column($this->calls, 0));
    }

    public function test_delivered_email_is_never_reported_as_failed(): void
    {
        // The email went out, then recording the success failed.
        $rpc = function (string $function, array $params): mixed {
            $this->calls[] = [$function, $params];
            if ($function === 'finish_outbox_email') {
                throw new SupabaseException('network', 0, 'network');
            }
            return [self::row(1)];
        };
        $send = static function (): void {
        };

        try {
            flush_outbox($rpc, $send);
        } catch (SupabaseException) {
            // outbox_after_rpc swallows it; the row stays sending and is reclaimed later.
        }

        $this->assertSame([
            ['claim_outbox_emails', ['p_limit' => 1]],
            ['finish_outbox_email', ['p_id' => 'id-1', 'p_ok' => true]],
        ], $this->calls);
    }

    public function test_flush_stops_starting_sends_after_budget(): void
    {
        $this->queue = [self::row(1), self::row(2), self::row(3)];
        $sends = 0;
        $send = function () use (&$sends): void {
            $sends++;
            usleep(60_000);
        };

        flush_outbox($this->rpc(), $send, 0.05);

        $this->assertSame(1, $sends);
        $claims = array_filter($this->calls, static fn ($c) => $c[0] === 'claim_outbox_emails');
        $this->assertCount(1, $claims, 'No row is claimed once the budget is spent');
    }

    public function test_after_rpc_skips_non_staff_and_other_functions(): void
    {
        $this->queue = [self::row(1)];
        $send = static function (): void {
        };
        outbox_after_rpc('transition_request', $this->rpc(), false, true, $send);
        outbox_after_rpc('claim_outbox_emails', $this->rpc(), true, true, $send);
        outbox_after_rpc('submit_request', $this->rpc(), true, true, $send);
        outbox_after_rpc('issue_document', $this->rpc(), true, false, $send);

        $this->assertSame([], $this->calls);
    }

    public function test_after_rpc_swallows_every_failure(): void
    {
        $failingRpc = static function (): mixed {
            throw new SupabaseException('boom', 500);
        };
        outbox_after_rpc('issue_document', $failingRpc, true, true);

        $this->queue = [self::row(1)];
        $send = static function (): void {
            throw new Error('mail transport crashed');
        };
        outbox_after_rpc('issue_document', $this->rpc(), true, true, $send);

        $this->assertSame(['claim_outbox_emails', 'finish_outbox_email'], array_column($this->calls, 0));
    }

    public function test_trigger_functions(): void
    {
        $this->assertSame(['transition_request', 'record_payment', 'issue_document', 'void_payment'], OUTBOX_TRIGGER_FUNCTIONS);
    }
}
