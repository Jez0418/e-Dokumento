<?php
declare(strict_types=1);

$db = Supabase::user();
$id = q('id');
$editing = $id !== '' && preg_match('/^\d{1,5}$/', $id);
$type = $editing ? $db->first('document_types', [['id', 'eq.' . $id], ['select', '*,document_type_requirements(requirement_id,is_mandatory,sort_order)']]) : null;
if ($editing && !$type) {
    abort(404);
}
$requirements = $db->select('requirements', [['select', 'id,name,accepts_upload,is_active'], ['order', 'name.asc']])['rows'];
$current = [];
foreach ($type['document_type_requirements'] ?? [] as $l) {
    $current[(string) $l['requirement_id']] = $l;
}
$errors = [];
$old = is_post() ? $_POST : ($type ?? ['fee' => '0.00', 'processing_days' => '1', 'max_copies' => '3', 'min_residency_months' => '0', 'requires_captain_approval' => true, 'template_key' => 'generic', 'is_active' => true]);

if (is_post()) {
    $v = new Validator($_POST);
    $code = strtoupper($v->text('code', 'Code', true, 2, 10) ?? '');
    if ($code !== '' && !preg_match('/^[A-Z]{2,10}$/', $code)) {
        $v->error('code', 'Code must be 2 to 10 capital letters, like BC.');
    }
    $validity = $v->integer('validity_days', 'Validity', false, 1, 3650);
    $data = [
        'code'                      => $code,
        'name'                      => $v->text('name', 'Name', true, 3, 100),
        'description'               => $v->text('description', 'Description', false, 0, 500),
        'fee'                       => $v->decimal('fee', 'Fee', true, 0, 100000),
        'processing_days'           => $v->integer('processing_days', 'Processing days', true, 0, 30),
        'validity_days'             => $validity,
        'requires_captain_approval' => $v->bool('requires_captain_approval'),
        'min_residency_months'      => $v->integer('min_residency_months', 'Minimum residency', true, 0, 600),
        'once_per_lifetime'         => $v->bool('once_per_lifetime'),
        'max_copies'                => $v->integer('max_copies', 'Maximum copies', true, 1, 10),
        'template_key'              => $v->in('template_key', 'print template', array_keys(TEMPLATE_KEYS)),
        'is_active'                 => $v->bool('is_active'),
    ];
    $picked = [];
    $validReq = array_map(static fn ($r) => (string) $r['id'], $requirements);
    foreach ((array) ($_POST['req'] ?? []) as $rid => $on) {
        if (in_array((string) $rid, $validReq, true) && $on === '1') {
            $picked[(string) $rid] = [
                'is_mandatory' => isset($_POST['mandatory'][$rid]),
                'sort_order'   => max(0, min(99, (int) ($_POST['order'][$rid] ?? 0))),
            ];
        }
    }
    if (!$v->fails()) {
        try {
            if ($editing) {
                $db->update('document_types', [['id', 'eq.' . $id]], $data);
                $typeId = (int) $id;
            } else {
                $typeId = (int) ($db->insert('document_types', $data)[0]['id'] ?? 0);
            }
            // Sync the many-to-many requirement links
            $keep = array_keys($picked);
            $db->delete('document_type_requirements', $keep
                ? [['document_type_id', 'eq.' . $typeId], ['requirement_id', 'not.in.(' . implode(',', array_map('intval', $keep)) . ')']]
                : [['document_type_id', 'eq.' . $typeId]]);
            if ($picked) {
                $rows = [];
                foreach ($picked as $rid => $p) {
                    $rows[] = ['document_type_id' => $typeId, 'requirement_id' => (int) $rid] + $p;
                }
                $db->upsert('document_type_requirements', $rows, 'document_type_id,requirement_id');
            }
            flash_success($editing ? 'Document type saved.' : 'Document type added.');
            redirect('/document-types');
        } catch (Throwable $e) {
            $errors['_form'] = db_error($e);
        }
    } else {
        $errors = $v->errors();
    }
}

$title = $editing ? 'Edit ' . $type['name'] : 'Add document type';
layout_start($title, 'document-types');
page_header($title, 'Fees are in Philippine pesos per copy. Set them to match your barangay revenue ordinance.');
$isChecked = static function (string $key) use ($old): bool {
    return !empty($old[$key]) && $old[$key] !== '0' && $old[$key] !== false;
};
?>
<?= form_errors($errors) ?>
<form method="post" novalidate class="needs-validation">
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <section class="panel">
        <div class="row g-3">
          <div class="col-md-3"><label class="form-label" for="code">Code</label><input class="form-control mono<?= invalid($errors, 'code') ?>" id="code" name="code" value="<?= e(old($old, 'code')) ?>" required maxlength="10" pattern="[A-Za-z]{2,10}"><?= field_error($errors, 'code') ?></div>
          <div class="col-md-9"><label class="form-label" for="name">Name</label><input class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" value="<?= e(old($old, 'name')) ?>" required maxlength="100"><?= field_error($errors, 'name') ?></div>
          <div class="col-12"><label class="form-label" for="description">Description shown to residents</label><textarea class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" rows="2" maxlength="500"><?= e(old($old, 'description')) ?></textarea><?= field_error($errors, 'description') ?></div>
          <div class="col-md-4"><label class="form-label" for="fee">Fee per copy (₱)</label><input class="form-control num<?= invalid($errors, 'fee') ?>" id="fee" name="fee" value="<?= e(old($old, 'fee')) ?>" required inputmode="decimal"><?= field_error($errors, 'fee') ?></div>
          <div class="col-md-4"><label class="form-label" for="processing_days">Processing days</label><input class="form-control<?= invalid($errors, 'processing_days') ?>" id="processing_days" name="processing_days" type="number" min="0" max="30" value="<?= e(old($old, 'processing_days')) ?>" required><?= field_error($errors, 'processing_days') ?></div>
          <div class="col-md-4"><label class="form-label" for="validity_days">Valid for (days) <span class="optional">blank = no expiry</span></label><input class="form-control<?= invalid($errors, 'validity_days') ?>" id="validity_days" name="validity_days" type="number" min="1" max="3650" value="<?= e(old($old, 'validity_days')) ?>"><?= field_error($errors, 'validity_days') ?></div>
          <div class="col-md-4"><label class="form-label" for="min_residency_months">Minimum residency (months)</label><input class="form-control<?= invalid($errors, 'min_residency_months') ?>" id="min_residency_months" name="min_residency_months" type="number" min="0" max="600" value="<?= e(old($old, 'min_residency_months')) ?>" required><?= field_error($errors, 'min_residency_months') ?></div>
          <div class="col-md-4"><label class="form-label" for="max_copies">Maximum copies</label><input class="form-control<?= invalid($errors, 'max_copies') ?>" id="max_copies" name="max_copies" type="number" min="1" max="10" value="<?= e(old($old, 'max_copies')) ?>" required><?= field_error($errors, 'max_copies') ?></div>
          <div class="col-md-4"><label class="form-label" for="template_key">Print template</label><select class="form-select<?= invalid($errors, 'template_key') ?>" id="template_key" name="template_key" required><?= options(TEMPLATE_KEYS, old($old, 'template_key')) ?></select><?= field_error($errors, 'template_key') ?></div>
          <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="requires_captain_approval" name="requires_captain_approval" value="1"<?= chk($isChecked('requires_captain_approval')) ?>><label class="form-check-label" for="requires_captain_approval">Needs the Punong Barangay's approval before release</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="once_per_lifetime" name="once_per_lifetime" value="1"<?= chk($isChecked('once_per_lifetime')) ?>><label class="form-check-label" for="once_per_lifetime">Can be issued only once per resident</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"<?= chk($isChecked('is_active')) ?>><label class="form-check-label" for="is_active">Residents can request this document</label></div>
          </div>
        </div>
      </section>
    </div>
    <div class="col-lg-5">
      <section class="panel">
        <div class="panel-head"><h2>Requirements</h2><a href="/requirements" class="small">Manage list</a></div>
        <?php if (!$requirements): ?><p class="text-secondary mb-0">Add requirements first.</p><?php endif; ?>
        <ul class="req-pick">
          <?php foreach ($requirements as $rq):
              $rid = (string) $rq['id'];
              $on = is_post() ? (($_POST['req'][$rid] ?? '') === '1') : isset($current[$rid]);
              $mand = is_post() ? isset($_POST['mandatory'][$rid]) : (bool) ($current[$rid]['is_mandatory'] ?? true);
              $ord = is_post() ? (int) ($_POST['order'][$rid] ?? 0) : (int) ($current[$rid]['sort_order'] ?? 0);
              if (!$rq['is_active'] && !$on) { continue; } ?>
            <li>
              <div class="form-check">
                <input type="hidden" name="req[<?= e($rid) ?>]" value="0">
                <input class="form-check-input" type="checkbox" id="req-<?= e($rid) ?>" name="req[<?= e($rid) ?>]" value="1"<?= chk($on) ?>>
                <label class="form-check-label" for="req-<?= e($rid) ?>"><?= e($rq['name']) ?><?= $rq['accepts_upload'] ? '' : ' <span class="optional">in person</span>' ?></label>
              </div>
              <div class="req-pick-opts">
                <label class="form-check-label small"><input class="form-check-input me-1" type="checkbox" name="mandatory[<?= e($rid) ?>]" value="1"<?= chk($mand) ?>>Required</label>
                <label class="small">Order <input class="form-control form-control-sm d-inline-block w-auto" type="number" name="order[<?= e($rid) ?>]" value="<?= $ord ?>" min="0" max="99"></label>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>
  </div>
  <div class="d-flex gap-2 mt-3"><button class="btn btn-primary" type="submit" data-loading-text="Saving…"><?= $editing ? 'Save changes' : 'Add document type' ?></button><a class="btn btn-outline-secondary" href="/document-types">Cancel</a></div>
</form>
<?php layout_end(); ?>
