<?php
declare(strict_types=1);

// Small lookup lists that share one screen: puroks, request purposes and accepted ID types.
$db = Supabase::user();
$tabs = [
    'puroks'   => ['label' => 'Puroks', 'singular' => 'purok', 'max' => 60, 'desc' => true, 'used' => ['residents', 'purok_id']],
    'purposes' => ['label' => 'Purposes', 'singular' => 'purpose', 'max' => 80, 'desc' => false, 'used' => ['document_requests', 'purpose_id']],
    'id_types' => ['label' => 'Accepted IDs', 'singular' => 'ID type', 'max' => 80, 'desc' => false, 'used' => ['resident_verifications', 'id_type_id']],
];
$tab = array_key_exists(q('tab'), $tabs) ? q('tab') : 'puroks';
$cfg = $tabs[$tab];
$errors = [];
$editId = q('edit');
$editing = preg_match('/^\d{1,5}$/', $editId) ? $db->first($tab, [['id', 'eq.' . $editId]]) : null;
$old = is_post() ? $_POST : ($editing ?? []);
$self = url('reference', ['tab' => $tab]);

if (is_post()) {
    $action = post('action');
    $rid = post('row_id');
    try {
        if ($action === 'save') {
            $v = new Validator($_POST);
            $data = ['name' => $v->text('name', 'Name', true, $tab === 'puroks' ? 1 : 2, $cfg['max'])];
            if ($cfg['desc']) {
                $data['description'] = $v->text('description', 'Description', false, 0, 255);
            }
            if (!$v->fails()) {
                if (preg_match('/^\d{1,5}$/', $rid)) {
                    $db->update($tab, [['id', 'eq.' . $rid]], $data);
                } else {
                    $db->insert($tab, $data);
                }
                flash_success(ucfirst($cfg['singular']) . ' saved.');
                redirect($self);
            }
            $errors = $v->errors();
        } elseif ($action === 'toggle' && preg_match('/^\d{1,5}$/', $rid)) {
            $db->update($tab, [['id', 'eq.' . $rid]], ['is_active' => post('to') === '1']);
            flash_success(ucfirst($cfg['singular']) . (post('to') === '1' ? ' activated.' : ' deactivated.'));
            redirect($self);
        } elseif ($action === 'delete' && preg_match('/^\d{1,5}$/', $rid)) {
            $db->delete($tab, [['id', 'eq.' . $rid]]);
            flash_success(ucfirst($cfg['singular']) . ' deleted.');
            redirect($self);
        }
    } catch (Throwable $e) {
        flash_error(db_error($e));
        redirect($self);
    }
}

$rows = $db->select($tab, [['select', '*'], ['order', 'is_active.desc,name.asc']])['rows'];
[$usedTable, $usedCol] = $cfg['used'];
$usage = [];
foreach ($db->select($usedTable, [['select', $usedCol], ['limit', '10000']])['rows'] as $u) {
    $usage[(string) $u[$usedCol]] = ($usage[(string) $u[$usedCol]] ?? 0) + 1;
}

layout_start('Reference data', 'reference');
page_header('Puroks, purposes and IDs', 'Lists used in forms across the system. Deactivate entries that are in use instead of deleting them.');
?>
<nav class="status-tabs" aria-label="Lists">
  <?php foreach ($tabs as $k => $t): ?><a href="?tab=<?= e($k) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($t['label']) ?></a><?php endforeach; ?>
</nav>
<div class="row g-4">
  <div class="col-lg-8">
    <section class="panel p-0">
      <?php if (!$rows): ?><?= empty_state('Nothing here yet', 'Add the first ' . $cfg['singular'] . ' with the form.', '', '', 'tags') ?><?php else: ?>
      <div class="table-responsive"><table class="table data-table">
        <thead><tr><th scope="col">Name</th><th scope="col">In use</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $n = $usage[(string) $r['id']] ?? 0; ?>
          <tr class="<?= $r['is_active'] ? '' : 'row-muted' ?>">
            <td><strong><?= e($r['name']) ?></strong><?= !empty($r['description']) ? '<small class="d-block text-secondary">' . e($r['description']) . '</small>' : '' ?></td>
            <td><?= $n ?></td>
            <td><?= $r['is_active'] ? simple_badge('Active', 'success') : simple_badge('Inactive', 'neutral') ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('reference', ['tab' => $tab, 'edit' => $r['id']])) ?>">Edit</a>
              <?= action_button($self, ['action' => 'toggle', 'row_id' => $r['id'], 'to' => $r['is_active'] ? '0' : '1'], $r['is_active'] ? 'Deactivate' : 'Activate', 'btn-sm btn-outline-secondary') ?>
              <?php if ($n === 0): ?><?= action_button($self, ['action' => 'delete', 'row_id' => $r['id']], 'Delete', 'btn-sm btn-outline-danger', 'Delete ' . $r['name'] . '?') ?><?php endif; ?>
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
      <div class="panel-head"><h2><?= $editing ? 'Edit ' . e($cfg['singular']) : 'Add ' . e($cfg['singular']) ?></h2><?php if ($editing): ?><a class="small" href="<?= e($self) ?>">Cancel</a><?php endif; ?></div>
      <form method="post" action="<?= e($self) ?>" novalidate class="needs-validation">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="row_id" value="<?= e($editing['id'] ?? '') ?>">
        <div class="mb-3"><label class="form-label" for="name">Name</label><input class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" value="<?= e(old($old, 'name')) ?>" required maxlength="<?= (int) $cfg['max'] ?>"><?= field_error($errors, 'name') ?></div>
        <?php if ($cfg['desc']): ?><div class="mb-3"><label class="form-label" for="description">Description <span class="optional">optional</span></label><input class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" value="<?= e(old($old, 'description')) ?>" maxlength="255" placeholder="Landmarks or streets covered"><?= field_error($errors, 'description') ?></div><?php endif; ?>
        <button class="btn btn-primary" type="submit">Save</button>
      </form>
    </section>
  </div>
</div>
<?php layout_end(); ?>
