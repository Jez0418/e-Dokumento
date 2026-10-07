<?php
declare(strict_types=1);

// Supabase connection settings. Values come from Vercel Environment Variables
// (or .env locally). Never put keys in this file.
return [
    'url'             => rtrim((string) Env::get('SUPABASE_URL', ''), '/'),
    // Publishable key (sb_publishable_...) or the legacy anon key. Sent as the
    // apikey header; every user call also carries the user's own access token.
    'publishable_key' => (string) Env::get('SUPABASE_PUBLISHABLE_KEY', ''),
    // Secret key (sb_secret_...) or the legacy service_role key. Server-only;
    // used solely to create and ban staff accounts through the Auth admin API.
    'secret_key'      => (string) Env::get('SUPABASE_SECRET_KEY', ''),
];
