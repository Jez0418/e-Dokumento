<?php
declare(strict_types=1);

$db = Supabase::user();
['page' => $page, 'per' => $per, 'offset' => $offset] = paging(25);
$actions = ['login', 'logout', 'create', 'update', 'delete', 'status_change', 'payment_post', 'payment_void', 'issue', 'approve', 'revoke', 'print', 'export', 'view_file',
            'verification_submit', 'verification_approved', 'verification_rejected', 'attachment_add', 'attachment_accepted', 'attachment_rejected'];
$entities = ['auth', 'profiles', 'residents', 'document_requests', 'payments', 'issued_documents', 'resident_verifications', 'document_types', 'requirements',
             'document_type_requirements', 'puroks', 'purposes', 'id_types', 'barangay_officials', 'system_settings', 'storage', 'reports'];
$staff = $db->select('profiles', [['select', 'id,full_name'], ['role_id', 'lt.5'], ['order', 'full_name.asc']])['rows'];

$f = [
    'action' => in_array(q('action'), $actions, true) ? q('action') : '',
    'entity' => in_array(q('entity'), $entities, true) ? q('entity') : '',
    'actor'  => is_uuid(q('actor')) ? q('actor') : '',
    'from'   => is_date(q('from')) ? q('from') : '',
    'to'     => is_date(q('to')) ? q('to') : '',
    'record' => preg_match('/^[A-Za-z0-9\-]{1,60}$/', q('record')) ? q('record') : '',
];
$query = [['select', 'id,action,entity_type,entity_id,details,ip_address,created_at,actor_id,profiles(full_name,email)']];
foreach (['action' => 'action', 'entity' => 'entity_type', 'actor' => 'actor_id', 'record' => 'entity_id'] as $k => $col) {
    if ($f[$k] !== '') {
        $query[] = [$col, 'eq.' . $f[$k]];
    }
}
if ($f['from'] !== '') {
    $query[] = ['created_at', 'gte.' . $f['from'] . 'T00:00:00+08:00'];
}
if ($f['to'] !== '') {
    $query[] = ['created_at', 'lte.' . $f['to'] . 'T23:59:59+08:00'];
}
$query[] = ['order', 'created_at.desc,id.desc'];
$result = $db->select('audit_logs', $query, true, $offset, $per);

layout_start('Audit log', 'audit');
page_header('Audit log', 'Every sign-in, change, payment and status move, newest first. Entries cannot be edited or deleted.');
?>
<form class="filter-bar" method="get">
  <div><label class="form-label small" for="action">Action</label><select id="action" name="action" class="form-select"><?= options(array_combine($actions, array_map(static fn ($a) => ucfirst(str_replace('_', ' ', $a)), $actions)), $f['action'], 'Any') ?></select></div>
  <div><label class="form-label small" for="entity">Record type</label><select id="entity" name="entity" class="form-select"><?= options(array_combine($entities, array_map(static fn ($a) => str_replace('_', ' ', $a), $entities)), $f['entity'], 'Any') ?></select></div>
  <div><label class="form-label small" for="actor">User</label><select id="actor" name="actor" class="form-select"><?= options(pluck($staff, 'full_name'), $f['actor'], 'Anyone') ?></select></div>
  <div><label class="form-label small" for="record">Record id</label><input id="record" name="record" class="form-control mono" value="<?= e($f['record']) ?>" maxlength="60"></div>
  <div><label class="form-label small" for="from">From</label><input id="from" name="from" type="date" class="form-control" value="<?= e($f['from']) ?>"></div>
  <div><label class="form-label small" for="to">To</label><input id="to" name="to" type="date" class="form-control" value="<?= e($f['to']) ?>"></div>
  <div class="filter-actions"><button class="btn btn-primary" type="submit">Apply</button><a class="btn btn-link" href="/audit">Clear</a></div>
</form>
<section class="panel p-0">
<?php if (!$result['rows']): ?>
  <?= empty_state('No entries match', 'Widen the date range or clear a filter.', '', '', 'journal-text') ?>
<?php else: ?>
  <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
  <div class="table-responsive"><table class="table data-table audit-table">
    <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Details</th></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $a): $who = one($a['profiles']); $details = is_array($a['details']) ? $a['details'] : []; ?>
      <tr>
        <td class="text-nowrap"><?= e(fmt_datetime($a['created_at'])) ?></td>
        <td><?= $who ? e($who['full_name']) . '<small class="d-block text-secondary">' . e($who['email']) . '</small>' : '<span class="text-secondary">System or signup</span>' ?></td>
        <td><span class="tag tag-neutral"><?= e(str_replace('_', ' ', $a['action'])) ?></span></td>
        <td><?= e(str_replace('_', ' ', $a['entity_type'])) ?><?php if ($a['entity_id']): ?><small class="d-block mono text-secondary"><?= e(mb_substr((string) $a['entity_id'], 0, 36)) ?></small><?php endif; ?></td>
        <td>
          <?php if ($details): ?>
            <details><summary><?= e(isset($details['control_no']) ? $details['control_no'] : (isset($details['changes']) ? count($details['changes']) . ' field(s) changed' : 'View')) ?></summary>
              <pre class="json-view"><?= e(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
          <?php else: ?><span class="text-secondary">—</span><?php endif; ?>
          <?php if ($a['ip_address']): ?><small class="d-block text-secondary">IP <?= e($a['ip_address']) ?></small><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="panel-foot"><?= pagination($result['total'], $page, $per) ?></div>
<?php endif; ?>
</section>
<?php layout_end(); ?>
