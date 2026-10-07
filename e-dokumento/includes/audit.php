<?php
declare(strict_types=1);

/**
 * Events PHP records explicitly (login, logout, print, export, opening files).
 * Creates, updates and status changes are logged inside the database by
 * triggers and workflow functions, so they cannot be skipped by a client.
 */
final class Audit
{
    public static function event(string $action, string $entityType, ?string $entityId = null, array $details = []): void
    {
        try {
            Supabase::user()->rpc('log_event', [
                'p_action'      => $action,
                'p_entity_type' => $entityType,
                'p_entity_id'   => $entityId,
                'p_details'     => (object) $details,
                'p_ip'          => client_ip(),
                'p_user_agent'  => user_agent(),
            ]);
        } catch (Throwable $e) {
            error_log('audit: ' . $e->getMessage());
        }
    }
}
