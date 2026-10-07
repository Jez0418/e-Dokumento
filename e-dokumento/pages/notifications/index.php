<?php
declare(strict_types=1);

$db = Supabase::user();
$uid = Auth::id();

if (is_post() && post('action') === 'read_all') {
    try {
        $db->update('notifications', [['recipient_id', 'eq.' . $uid], ['is_read', 'eq.false']], ['is_read' => true]);
        flash_success('All notifications marked as read.');
    } catch (Throwable $e) {
        flash_error(db_error($e));
    }
    redirect('/notifications');
}

// Opening a notification marks it read, then goes to its request
$open = q('open');
if (preg_match('/^\d{1,18}$/', $open)) {
    $n = $db->first('notifications', [['id', 'eq.' . $open], ['recipient_id', 'eq.' . $uid], ['select', 'id,request_id']]);
    if ($n) {
        try {
            $db->update('notifications', [['id', 'eq.' . $n['id']]], ['is_read' => true]);
        } catch (Throwable) {
        }
        redirect($n['request_id'] ? url('requests/view', ['id' => $n['request_id']]) : '/notifications');
    }
}

$unreadOnly = q('show') === 'unread';
['page' => $page, 'per' => $per, 'offset' => $offset] = paging(20);
$query = [['select', 'id,title,message,is_read,created_at,request_id'], ['recipient_id', 'eq.' . $uid]];
if ($unreadOnly) {
    $query[] = ['is_read', 'eq.false'];
}
$query[] = ['order', 'created_at.desc'];
$result = $db->select('notifications', $query, true, $offset, $per);

layout_start('Notifications', 'notifications');
page_header('Notifications', 'Updates about your requests and work assigned to you.',
    '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="read_all"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Mark all as read</button></form>');
?>
<nav class="status-tabs" aria-label="Filter">
  <a href="/notifications" class="<?= $unreadOnly ? '' : 'active' ?>">All</a>
  <a href="/notifications?show=unread" class="<?= $unreadOnly ? 'active' : '' ?>">Unread</a>
</nav>
<section class="panel p-0">
  <?php if (!$result['rows']): ?>
    <?= empty_state($unreadOnly ? 'You are all caught up' : 'No notifications yet', 'Status changes on your requests will show up here.', '', '', 'bell') ?>
  <?php else: ?>
    <ul class="notif-list">
      <?php foreach ($result['rows'] as $n): ?>
        <li class="<?= $n['is_read'] ? '' : 'is-unread' ?>">
          <a href="<?= e(url('notifications', ['open' => $n['id']])) ?>">
            <span class="notif-dot" aria-hidden="true"></span>
            <span class="flex-grow-1"><strong><?= e($n['title']) ?></strong><span class="d-block"><?= e($n['message']) ?></span></span>
            <small class="text-secondary text-nowrap"><?= e(time_ago($n['created_at'])) ?></small>
            <?php if (!$n['is_read']): ?><span class="visually-hidden">Unread</span><?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="panel-foot"><?= pagination($result['total'], $page, $per) ?></div>
  <?php endif; ?>
</section>
<?php layout_end(); ?>
