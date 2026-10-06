<?php
declare(strict_types=1);

$db = Supabase::user();
if (is_post() && post('action') === 'revoke') {
    require_role('secretary', 'captain');
    $docId = post('doc_id');
    $reason = post('reason');
    if (!is_uuid($docId) || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
        flash_error('Give a reason of 10 to 500 characters.');
    } else {
        try {
            $db->rpc('revoke_document', ['p_issued_id' => $docId, 'p_reason' => $reason]);
            flash_success('Document revoked. Public verification now shows it as revoked.');
        } catch (Throwable $e) {
            flash_error(db_error($e));
        }
    }
    redirect('/documents');
}

['page' => $page, 'per' => $per, 'offset' => $offset] = paging(config('app')['per_page']);
[$sort, $dir] = sorting(['issued_at', 'document_no', 'resident_name', 'valid_until'], 'issued_at');
$f = [
    'q'      => search_term(q('q')),
    'status' => in_array(q('status'), ['valid', 'revoked'], true) ? q('status') : '',
    'type'   => q_int('type') ?: '',
    'from'   => is_date(q('from')) ? q('from') : '',
    'to'     => is_date(q('to')) ? q('to') : '',
];
$query = [['select', '*']];
if ($f['q'] !== '') {
    $query[] = ['search_text', 'ilike.*' . mb_strtolower($f['q']) . '*'];
}
if ($f['status'] !== '') {
    $query[] = ['status', 'eq.' . $f['status']];
}
if ($f['type'] !== '') {
    $query[] = ['document_type_id', 'eq.' . $f['type']];
}
if ($f['from'] !== '') {
    $query[] = ['issued_at', 'gte.' . $f['from'] . 'T00:00:00+08:00'];
}
if ($f['to'] !== '') {
    $query[] = ['issued_at', 'lte.' . $f['to'] . 'T23:59:59+08:00'];
}
$query[] = ['order', $sort . '.' . $dir . ',id.asc'];
$result = $db->select('issued_document_list', $query, true, $offset, $per);
$types = $db->select('document_types', [['select', 'id,name'], ['order', 'name.asc']])['rows'];

layout_start('Issued documents', 'documents');
page_header('Issued documents', 'Every certificate the system has numbered, with its public verification code.');
?>
<form class="filter-bar" method="get" role="search">
  <div class="filter-search"><label class="visually-hidden" for="q">Search</label><i class="bi bi-search" aria-hidden="true"></i><input id="q" name="q" type="search" class="form-control" value="<?= e($f['q']) ?>" placeholder="Document no., control no. or name" maxlength="80"></div>
  <div><label class="form-label small" for="type">Document</label><select id="type" name="type" class="form-select"><?= options(pluck($types), $f['type'], 'All') ?></select></div>
  <div><label class="form-label small" for="status">Status</label><select id="status" name="status" class="form-select"><?= options(['valid' => 'Valid', 'revoked' => 'Revoked'], $f['status'], 'Any') ?></select></div>
  <div><label class="form-label small" for="from">Issued from</label><input id="from" name="from" type="date" class="form-control" value="<?= e($f['from']) ?>"></div>
  <div><label class="form-label small" for="to">To</label><input id="to" name="to" type="date" class="form-control" value="<?= e($f['to']) ?>"></div>
  <div class="filter-actions"><button class="btn btn-primary" type="submit">Apply</button><a class="btn btn-link" href="/documents">Clear</a></div>
</form>
<section class="panel p-0">
<?php if (!$result['rows']): ?>
  <?= empty_state('No issued documents', 'Documents are numbered when the Secretary issues them or the Punong Barangay approves them.', '', '', 'patch-check') ?>
<?php else: ?>
  <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
  <div class="table-responsive"><table class="table data-table">
    <thead><tr>
      <th scope="col"><?= sort_link('Document no.', 'document_no', $sort, $dir) ?></th>
      <th scope="col"><?= sort_link('Issued to', 'resident_name', $sort, $dir) ?></th>
      <th scope="col">Document</th>
      <th scope="col"><?= sort_link('Issued', 'issued_at', $sort, $dir) ?></th>
      <th scope="col"><?= sort_link('Valid until', 'valid_until', $sort, $dir) ?></th>
      <th scope="col">Code</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $d): ?>
      <tr>
        <td class="mono"><?= e($d['document_no']) ?><small class="d-block"><a href="<?= e(url('requests/view', ['id' => $d['request_id']])) ?>"><?= e($d['control_no']) ?></a></small></td>
        <td><?= e($d['resident_name']) ?></td>
        <td><?= e($d['document_type']) ?></td>
        <td><?= e(fmt_date($d['issued_at'])) ?></td>
        <td><?= e($d['valid_until'] ? fmt_date($d['valid_until']) : '—') ?></td>
        <td class="mono"><?= e($d['verification_code']) ?></td>
        <td><?= $d['status'] === 'valid' ? simple_badge('Valid', 'success') : simple_badge('Revoked', 'danger') ?><?= (int) $d['print_count'] > 0 ? '<small class="d-block text-secondary">Printed ' . (int) $d['print_count'] . '×</small>' : '' ?></td>
        <td class="text-end text-nowrap"><div class="row-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('documents/print', ['id' => $d['id']])) ?>">Open</a>
          <?php if ($d['status'] === 'valid' && has_role('secretary', 'captain')): ?>
            <?= action_button('/documents', ['action' => 'revoke', 'doc_id' => $d['id']], 'Revoke', 'btn-sm btn-outline-danger', 'Revoke ' . $d['document_no'] . '? Anyone checking its code will see it was revoked.', true) ?>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="panel-foot"><?= pagination($result['total'], $page, $per) ?></div>
<?php endif; ?>
</section>
<?php layout_end(); ?>
