<?php
declare(strict_types=1);

$db = Supabase::user();
$uid = Auth::id();

if (is_post()) {
    // Mark one (id) or all of the caller's notifications read
    $id = post('id');
    $filters = [['recipient_id', 'eq.' . $uid], ['is_read', 'eq.false']];
    if ($id !== '' && preg_match('/^\d{1,18}$/', $id)) {
        $filters[] = ['id', 'eq.' . $id];
    }
    try {
        $db->update('notifications', $filters, ['is_read' => true]);
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => db_error($e)], 502);
    }
}

try {
    json_response([
        'ok'     => true,
        'unread' => $db->count('notifications', [['recipient_id', 'eq.' . $uid], ['is_read', 'eq.false']]),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => db_error($e)], 502);
}
