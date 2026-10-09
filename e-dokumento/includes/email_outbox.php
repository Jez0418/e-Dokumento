<?php
declare(strict_types=1);

/**
 * Sends queued status emails right after a staff action. The database trigger
 * queues them (sql/09_email_outbox.sql); a failed send stays queued for the
 * next staff action, and never affects the action itself.
 */

/** RPCs whose status changes can queue an email. void_payment returns a request to For payment. */
const OUTBOX_TRIGGER_FUNCTIONS = ['transition_request', 'record_payment', 'issue_document', 'void_payment'];

/**
 * Claims and sends one email at a time. No new send starts once $budgetSeconds
 * have passed, so the staff action stays well inside Vercel's 30-second limit.
 *
 * @param callable(string, array): mixed $rpc
 * @param (callable(string, string, string): void)|null $send  defaults to send_mail
 * @return int emails sent
 */
function flush_outbox(callable $rpc, ?callable $send = null, float $budgetSeconds = 12.0): int
{
    $send ??= 'send_mail';
    $start = microtime(true);
    $sent = 0;
    while (microtime(true) - $start < $budgetSeconds) {
        $rows = $rpc('claim_outbox_emails', ['p_limit' => 1]);
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($row)) {
            break;
        }
        try {
            $send((string) $row['to_email'], (string) $row['subject'], (string) $row['body']);
            $rpc('finish_outbox_email', ['p_id' => $row['id'], 'p_ok' => true]);
            $sent++;
        } catch (Throwable $e) {
            $rpc('finish_outbox_email', ['p_id' => $row['id'], 'p_ok' => false, 'p_error' => $e->getMessage()]);
        }
    }
    return $sent;
}

/** Called by Supabase::rpc() after a successful call. Never throws. */
function outbox_after_rpc(string $function, callable $rpc, bool $isStaff, bool $configured, ?callable $send = null): void
{
    if (!in_array($function, OUTBOX_TRIGGER_FUNCTIONS, true) || !$isStaff || !$configured) {
        return;
    }
    try {
        flush_outbox($rpc, $send);
    } catch (Throwable $e) {
        error_log('outbox: ' . $e->getMessage());
    }
}
