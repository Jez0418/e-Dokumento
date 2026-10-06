<?php
declare(strict_types=1);

// Live dashboard numbers (polled by assets/js/dashboard.js). RLS shapes the result per role.
try {
    json_response(['ok' => true, 'summary' => Supabase::user()->rpc('dashboard_summary')]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => db_error($e)], 502);
}
