<?php
declare(strict_types=1);

$db = Supabase::user();
$errors = [];
$editId = q('edit');
$editing = preg_match('/^\d{1,5}$/', $editId) ? $db->first('barangay_officials', [['id', 'eq.' . $editId]]) : null;
$old = is_post() ? $_POST : ($editing ?? ['is_active' => true]);

if (is_post()) {
    $action = post('action');
    $oid = post('official_id');
    try {
        if ($action === 'save') {
            $v = new Validator($_POST);
            $start = $v->date('term_start', 'Term start');
            $data = [
                'full_name'  => $v->name('full_name', 'Full name', true, 120),
                'position'   => $v->in('position', 'position', array_keys(OFFICIAL_POSITIONS)),
                'committee'  => $v->text('committee', 'Committee', false, 0, 120),
                'term_start' => $start,
                'term_end'   => $v->date('term_end', 'Term end', true, null, $start ? (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d') : null),
                'is_active'  => $v->bool('is_active'),
            ];
            if (!$v->fails()) {
                if (preg_match('/^\d{1,5}$/', $oid)) {
                    $db->update('barangay_officials', [['id', 'eq.' . $oid]], $data);
                } else {
                    $db->insert('barangay_officials', $data);
                }
                flash_success('Official saved.');
                redirect('/officials');
            }
            $errors = $v->errors();
        } elseif ($action === 'toggle' && preg_match('/^\d{1,5}$/', $oid)) {
            $db->update('barangay_officials', [['id', 'eq.' . $oid]], ['is_active' => post('to') === '1']);
            flash_success(post('to') === '1' ? 'Official marked as serving.' : 'Official marked as no longer serving.');
            redirect('/officials');
        }
    } catch (Throwable $e) {
        $errors['_form'] = db_error($e);
    }
}

$rows = $db->select('barangay_officials', [['select', '*'], ['order', 'is_active.desc,position.asc,full_name.asc']])['rows'];
$hasCaptain = (bool) array_filter($rows, static fn ($o) => $o['position'] === 'punong_barangay' && $o['is_active']);

layout_start('Officials', 'officials');
page_header('Barangay officials', 'The active Punong Barangay is printed as the signatory on every certificate.');
?>
<?php if (!$hasCaptain): ?>
  <div class="notice notice-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><strong>No active Punong Barangay.</strong> Documents cannot be issued until one is added.</div></div>
<?php endif; ?>
<?= form_errors(isset($errors['_form']) ? ['_form' => $errors['_form']] : []) ?>
<div class="row g-4">
  <div class="col-lg-8">
    <section class="panel p-0">
      <?php if (!$rows): ?><?= empty_state('No officials yet', 'Add the Punong Barangay first; they sign every certificate.', '', '', 'award') ?><?php else: ?>
      <div class="table-responsive"><table class="table data-table">
        <thead><tr><th scope="col">Name</th><th scope="col">Position</th><th scope="col">Term</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $o): ?>
          <tr class="<?= $o['is_active'] ? '' : 'row-muted' ?>">
            <td><strong><?= e($o['full_name']) ?></strong><?= $o['committee'] ? '<small class="d-block text-secondary">' . e($o['committee']) . '</small>' : '' ?></td>
            <td><?= e(OFFICIAL_POSITIONS[$o['position']] ?? $o['position']) ?></td>
            <td><?= e(fmt_date($o['term_start'], 'M Y')) ?> – <?= e(fmt_date($o['term_end'], 'M Y')) ?></td>
            <td><?= $o['is_active'] ? simple_badge('Serving', 'success') : simple_badge('Former', 'neutral') ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= e($o['id']) ?>">Edit</a>
              <?= action_button('/officials', ['action' => 'toggle', 'official_id' => $o['id'], 'to' => $o['is_active'] ? '0' : '1'], $o['is_active'] ? 'End service' : 'Reinstate', 'btn-sm btn-outline-secondary', $o['is_active'] ? 'Mark ' . $o['full_name'] . ' as no longer serving?' : '') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </div>
  <div class="col-lg-4">
    <section class="panel">
      <div class="panel-head"><h2><?= $editing ? 'Edit official' : 'Add official' ?></h2><?php if ($editing): ?><a href="/officials" class="small">Cancel</a><?php endif; ?></div>
      <form method="post" action="/officials" novalidate class="needs-validation">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="official_id" value="<?= e($editing['id'] ?? '') ?>">
        <div class="mb-3"><label class="form-label" for="full_name">Full name as printed</label><input class="form-control<?= invalid($errors, 'full_name') ?>" id="full_name" name="full_name" value="<?= e(old($old, 'full_name')) ?>" required maxlength="120"><?= field_error($errors, 'full_name') ?></div>
        <div class="mb-3"><label class="form-label" for="position">Position</label><select class="form-select<?= invalid($errors, 'position') ?>" id="position" name="position" required><?= options(OFFICIAL_POSITIONS, old($old, 'position'), 'Choose') ?></select><?= field_error($errors, 'position') ?></div>
        <div class="mb-3"><label class="form-label" for="committee">Committee <span class="optional">kagawads</span></label><input class="form-control" id="committee" name="committee" value="<?= e(old($old, 'committee')) ?>" maxlength="120"></div>
        <div class="row g-2 mb-3">
          <div class="col"><label class="form-label" for="term_start">Term start</label><input class="form-control<?= invalid($errors, 'term_start') ?>" id="term_start" name="term_start" type="date" value="<?= e(old($old, 'term_start')) ?>" required><?= field_error($errors, 'term_start') ?></div>
          <div class="col"><label class="form-label" for="term_end">Term end</label><input class="form-control<?= invalid($errors, 'term_end') ?>" id="term_end" name="term_end" type="date" value="<?= e(old($old, 'term_end')) ?>" required><?= field_error($errors, 'term_end') ?></div>
        </div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"<?= chk(!empty($old['is_active'])) ?>><label class="form-check-label" for="is_active">Currently serving</label></div>
        <button class="btn btn-primary" type="submit">Save official</button>
      </form>
    </section>
  </div>
</div>
<?php layout_end(); ?>
