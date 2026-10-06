<?php
declare(strict_types=1);

$db = Supabase::user();
if (is_post()) {
    $tid = post('type_id');
    if (!preg_match('/^\d{1,5}$/', $tid)) {
        abort(400);
    }
    try {
        if (post('action') === 'toggle') {
            $db->update('document_types', [['id', 'eq.' . $tid]], ['is_active' => post('to') === '1']);
            flash_success(post('to') === '1' ? 'Document type activated.' : 'Document type deactivated. Residents can no longer request it.');
        } elseif (post('action') === 'delete') {
            $db->delete('document_types', [['id', 'eq.' . $tid]]);
            flash_success('Document type deleted.');
        }
    } catch (Throwable $e) {
        flash_error(db_error($e));
    }
    redirect('/document-types');
}

$types = $db->select('document_types', [['select', '*,document_type_requirements(requirement_id)'], ['order', 'is_active.desc,name.asc']])['rows'];
$used = [];
foreach ($db->select('document_requests', [['select', 'document_type_id'], ['limit', '5000']])['rows'] as $r) {
    $used[(string) $r['document_type_id']] = ($used[(string) $r['document_type_id']] ?? 0) + 1;
}

layout_start('Document types', 'document-types');
page_header('Document types', 'What residents can request, its fee, how long it takes, and who signs it.',
    '<a class="btn btn-primary" href="/document-types/form"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add document type</a>');
?>
<section class="panel p-0">
<?php if (!$types): ?>
  <?= empty_state('No document types yet', 'Add the certificates your barangay issues, such as clearances and residency certificates.', '/document-types/form', 'Add document type', 'file-earmark-text') ?>
<?php else: ?>
  <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
  <div class="table-responsive"><table class="table data-table">
    <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col" class="text-end">Fee</th><th scope="col">Ready in</th><th scope="col">Signed by captain</th><th scope="col">Requirements</th><th scope="col">Requests</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($types as $t): $count = $used[(string) $t['id']] ?? 0; ?>
      <tr class="<?= $t['is_active'] ? '' : 'row-muted' ?>">
        <td class="mono"><?= e($t['code']) ?></td>
        <td><strong><?= e($t['name']) ?></strong><?= $t['once_per_lifetime'] ? ' <span class="tag tag-warning">Once only</span>' : '' ?><small class="d-block text-secondary"><?= e(TEMPLATE_KEYS[$t['template_key']] ?? '') ?></small></td>
        <td class="text-end num"><?= (float) $t['fee'] > 0 ? e(money($t['fee'])) : 'Free' ?></td>
        <td><?= (int) $t['processing_days'] ?> day<?= (int) $t['processing_days'] === 1 ? '' : 's' ?></td>
        <td><?= $t['requires_captain_approval'] ? 'Yes' : 'No' ?></td>
        <td><?= count($t['document_type_requirements'] ?? []) ?></td>
        <td><?= $count ?></td>
        <td><?= $t['is_active'] ? simple_badge('Active', 'success') : simple_badge('Inactive', 'neutral') ?></td>
        <td class="text-end text-nowrap"><div class="row-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('document-types/form', ['id' => $t['id']])) ?>">Edit</a>
          <?= action_button('/document-types', ['action' => 'toggle', 'type_id' => $t['id'], 'to' => $t['is_active'] ? '0' : '1'], $t['is_active'] ? 'Deactivate' : 'Activate', 'btn-sm btn-outline-secondary', $t['is_active'] ? 'Deactivate ' . $t['name'] . '? Open requests are not affected.' : '') ?>
          <?php if ($count === 0): ?>
            <?= action_button('/document-types', ['action' => 'delete', 'type_id' => $t['id']], 'Delete', 'btn-sm btn-outline-danger', 'Delete ' . $t['name'] . ' permanently? This cannot be undone.') ?>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</section>
<p class="page-note">Types that have been requested can only be deactivated, so past requests keep their history.</p>
<?php layout_end(); ?>
