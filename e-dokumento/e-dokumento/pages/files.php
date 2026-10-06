<?php
declare(strict_types=1);

// Opens a private file. The signed URL is requested with the user's own token,
// so Storage RLS decides whether this user may see the file.
$bucket = q('bucket');
$path = q('path');
if (!in_array($bucket, ['verification-ids', 'request-files'], true)
    || !preg_match('#^[0-9a-f\-]{36}/[0-9a-f]{24}\.(pdf|jpg|png)$#', $path)) {
    abort(404);
}
try {
    $signed = Supabase::user()->signedUrl($bucket, $path, 60);
} catch (SupabaseException $e) {
    abort($e->status === 400 || $e->status === 404 ? 404 : 403, 'This file is not available to your account.');
}
if ($bucket === 'verification-ids') {
    Audit::event('view_file', 'storage', $bucket . '/' . $path);
}
header('Location: ' . $signed, true, 302);
exit;
