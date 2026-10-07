<?php
declare(strict_types=1);

$db = Supabase::user();
$errors = [];
$editId = q('edit');
$editing = preg_match('/^\d{1,5}$/', $editId) ? $db->first('requirements', [['id', 'eq.' . $editId]]) : null;
$old = is_post() ? $_POST : ($editing ?? ['accepts_upload' => true]);

if (is_post()) {
    $action = post('action');
    $rid = post('requirement_id');
    try {
        if ($action === 'save') {
            $v = new Validator($_POST);
            $data = [
                'name'           => $v->text('name', 'Name', true, 3, 120),
                'description'    => $v->text('description', 'Guidance', false, 0, 500),
                'accepts_upload' => $v->bool('accepts_upload'),
            ];
            if (!$v->fails()) {
                if (preg_match('/^\d{1,5}$/', $rid)) {
                    $db->update('requirements', [['id', 'eq.' . $rid]], $data);
                    flash_success('Requirement saved.');
                } else {
                    $db->insert('requirements', $data + ['is_active' => true]);
                    flash_success('Requirement added.');
                }
                redirect('/requirements');
            }
            $errors = $v->errors();
        } elseif ($action === 'toggle' && preg_match('/^\d{1,5}$/', $rid)) {
            $db->update('requirements', [['id', 'eq.' . $rid]], ['is_active' => post('to') === '1']);
            flash_success(post('to') === '1' ? 'Requirement activated.' : 'Requirement deactivated.');
            redirect('/requirements');
        } elseif ($action === 'delete' && preg_match('/^\d{1,5}$/', $rid)) {
            $db->delete('requirements', [['id', 'eq.' . $rid]]);
            flash_success('Requirement deleted.');
            redirect('/requirements');
        }
    } catch (Throwable $e) {
        flash_error(db_error($e));
        redirect('/requirements');
    }
}

$rows = $db->select('requirements', [['select', '*,document_type_requirements(document_type_id)'], ['order', 'is_active.desc,name.asc']])['rows'];

layout_start('Requirements', 'requirements');
page_header('Requirements', 'Files or checks a document can ask for. Link them to document types on each type\'s form.');
?>
<div class="row g-4">
  <div class="col-lg-8">
    <section class="panel p-0">
      <?php if (!$rows): ?><?= empty_state('No requirements yet', 'Add requirements such as a valid ID or proof of residency.', '', '', 'list-check') ?><?php else: ?>
      <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
      <div class="table-responsive"><table class="table data-table">
        <thead><tr><th scope="col">Requirement</th><th scope="col">How</th><th scope="col">Used by</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $usedBy = count($r['document_type_requirements'] ?? []); ?>
          <tr class="<?= $r['is_active'] ? '' : 'row-muted' ?>">
            <td><strong><?= e($r['name']) ?></strong><?= $r['description'] ? '<small class="d-block text-secondary">' . e($r['description']) . '</small>' : '' ?></td>
            <td><?= $r['accepts_upload'] ? 'Upload' : 'In person' ?></td>
            <td><?= $usedBy ?> type<?= $usedBy === 1 ? '' : 's' ?></td>
            <td><?= $r['is_active'] ? simple_badge('Active', 'success') : simple_badge('Inactive', 'neutral') ?></td>
            <td class="text-end text-nowrap"><div class="row-actions">
              <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= e($r['id']) ?>">Edit</a>
              <?= action_button('/requirements', ['action' => 'toggle', 'requirement_id' => $r['id'], 'to' => $r['is_active'] ? '0' : '1'], $r['is_active'] ? 'Deactivate' : 'Activate', 'btn-sm btn-outline-secondary') ?>
              <?php if ($usedBy === 0): ?><?= action_button('/requirements', ['action' => 'delete', 'requirement_id' => $r['id']], 'Delete', 'btn-sm btn-outline-danger', 'Delete this requirement? Files already uploaded against it keep the link and block deletion.') ?><?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </div>
  <div class="col-lg-4">
    <section class="panel">
      <div class="panel-head"><h2><?= $editing ? 'Edit requirement' : 'Add requirement' ?></h2><?php if ($editing): ?><a href="/requirements" class="small">Cancel</a><?php endif; ?></div>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="requirement_id" value="<?= e($editing['id'] ?? '') ?>">
        <div class="mb-3"><label class="form-label" for="name">Name</label><input class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" value="<?= e(old($old, 'name')) ?>" required minlength="3" maxlength="120"><?= field_error($errors, 'name') ?></div>
        <div class="mb-3"><label class="form-label" for="description">Guidance for residents</label><textarea class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" rows="3" maxlength="500"><?= e(old($old, 'description')) ?></textarea><?= field_error($errors, 'description') ?></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="accepts_upload" name="accepts_upload" value="1"<?= chk(!empty($old['accepts_upload'])) ?>><label class="form-check-label" for="accepts_upload">Residents upload a file for this</label></div>
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save requirement' : 'Add requirement' ?></button>
      </form>
    </section>
  </div>
</div>
<?php layout_end(); ?>
