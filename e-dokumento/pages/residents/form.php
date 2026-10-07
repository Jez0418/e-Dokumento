<?php
declare(strict_types=1);

$db = Supabase::user();
$id = q('id');
$editing = is_uuid($id);
$resident = $editing ? $db->first('residents', [['id', 'eq.' . $id]]) : null;
if ($editing && !$resident) {
    abort(404);
}
$then = q('then') === 'request' ? 'request' : '';
$puroks = $db->select('puroks', [['select', 'id,name,is_active'], ['order', 'name.asc']])['rows'];
$errors = [];
$old = is_post() ? $_POST : ($resident ?? []);

if (is_post()) {
    $v = new Validator($_POST);
    $today = today_local();
    $allowedPuroks = array_map(static fn ($p) => (string) $p['id'], array_filter($puroks, static fn ($p) => $p['is_active'] || (string) $p['id'] === (string) ($resident['purok_id'] ?? '')));
    $data = [
        'first_name'          => $v->name('first_name', 'First name'),
        'middle_name'         => $v->name('middle_name', 'Middle name', false),
        'last_name'           => $v->name('last_name', 'Last name'),
        'suffix'              => $v->text('suffix', 'Suffix', false, 1, 10),
        'birth_date'          => $v->date('birth_date', 'Birth date', true, $today, '1900-01-02'),
        'sex'                 => $v->in('sex', 'sex', ['male', 'female']),
        'civil_status'        => $v->in('civil_status', 'civil status', array_keys(CIVIL_STATUSES)),
        'purok_id'            => $v->in('purok_id', 'purok', $allowedPuroks),
        'street_address'      => $v->text('street_address', 'House no. and street', true, 3, 200),
        'resident_since'      => $v->date('resident_since', 'Resident since', true, $today),
        'contact_no'          => $v->phone('contact_no'),
        'email'               => $v->email('email', 'Email', false),
        'occupation'          => $v->text('occupation', 'Occupation', false, 0, 100),
        'is_registered_voter' => $v->bool('is_registered_voter'),
    ];
    if ($editing) {
        $data['status'] = $v->in('status', 'registry status', array_keys(RESIDENT_STATUSES));
    }
    if ($data['birth_date'] && $data['resident_since'] && $data['resident_since'] < $data['birth_date']) {
        $v->error('resident_since', 'Resident since cannot be before the birth date.');
    }
    if (!$v->fails()) {
        $data['purok_id'] = (int) $data['purok_id'];
        try {
            if ($editing) {
                $db->update('residents', [['id', 'eq.' . $id]], $data);
                flash_success('Resident record updated.');
                redirect(url('residents/view', ['id' => $id]));
            }
            $data['created_by'] = Auth::id();
            $row = $db->insert('residents', $data)[0] ?? null;
            flash_success('Resident added to the registry.');
            redirect($then === 'request' && $row ? url('requests/new', ['resident_id' => $row['id']]) : url('residents/view', ['id' => $row['id'] ?? '']));
        } catch (Throwable $e) {
            $errors['_form'] = db_error($e);
        }
    } else {
        $errors = $v->errors();
    }
}

$title = $editing ? 'Edit resident' : 'Add resident';
layout_start($title, 'residents');
page_header($title, $editing ? resident_name($resident) : 'Encode a resident who came to the hall. Check their ID before saving.');
?>
<?= form_errors($errors) ?>
<form method="post" novalidate class="needs-validation panel" action="<?= e(url('residents/form', ['id' => $editing ? $id : null, 'then' => $then])) ?>">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label" for="first_name">First name</label><input class="form-control<?= invalid($errors, 'first_name') ?>" id="first_name" name="first_name" value="<?= e(old($old, 'first_name')) ?>" required maxlength="60"><?= field_error($errors, 'first_name') ?></div>
    <div class="col-md-3"><label class="form-label" for="middle_name">Middle name <span class="optional">optional</span></label><input class="form-control<?= invalid($errors, 'middle_name') ?>" id="middle_name" name="middle_name" value="<?= e(old($old, 'middle_name')) ?>" maxlength="60"><?= field_error($errors, 'middle_name') ?></div>
    <div class="col-md-3"><label class="form-label" for="last_name">Last name</label><input class="form-control<?= invalid($errors, 'last_name') ?>" id="last_name" name="last_name" value="<?= e(old($old, 'last_name')) ?>" required maxlength="60"><?= field_error($errors, 'last_name') ?></div>
    <div class="col-md-2"><label class="form-label" for="suffix">Suffix</label><input class="form-control<?= invalid($errors, 'suffix') ?>" id="suffix" name="suffix" value="<?= e(old($old, 'suffix')) ?>" maxlength="10"><?= field_error($errors, 'suffix') ?></div>
    <div class="col-md-3"><label class="form-label" for="birth_date">Birth date</label><input class="form-control<?= invalid($errors, 'birth_date') ?>" id="birth_date" name="birth_date" type="date" value="<?= e(old($old, 'birth_date')) ?>" required max="<?= e(today_local()) ?>"><?= field_error($errors, 'birth_date') ?></div>
    <div class="col-md-3"><label class="form-label" for="sex">Sex</label><select class="form-select<?= invalid($errors, 'sex') ?>" id="sex" name="sex" required><?= options(['female' => 'Female', 'male' => 'Male'], old($old, 'sex'), 'Choose') ?></select><?= field_error($errors, 'sex') ?></div>
    <div class="col-md-3"><label class="form-label" for="civil_status">Civil status</label><select class="form-select<?= invalid($errors, 'civil_status') ?>" id="civil_status" name="civil_status" required><?= options(CIVIL_STATUSES, old($old, 'civil_status'), 'Choose') ?></select><?= field_error($errors, 'civil_status') ?></div>
    <div class="col-md-3"><label class="form-label" for="occupation">Occupation</label><input class="form-control<?= invalid($errors, 'occupation') ?>" id="occupation" name="occupation" value="<?= e(old($old, 'occupation')) ?>" maxlength="100"><?= field_error($errors, 'occupation') ?></div>
    <div class="col-md-3"><label class="form-label" for="purok_id">Purok</label>
      <select class="form-select<?= invalid($errors, 'purok_id') ?>" id="purok_id" name="purok_id" required>
        <option value="">Choose</option>
        <?php foreach ($puroks as $p): if (!$p['is_active'] && (string) $p['id'] !== old($old, 'purok_id')) { continue; } ?>
          <option value="<?= e($p['id']) ?>"<?= sel($p['id'], old($old, 'purok_id')) ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select><?= field_error($errors, 'purok_id') ?></div>
    <div class="col-md-6"><label class="form-label" for="street_address">House no. and street</label><input class="form-control<?= invalid($errors, 'street_address') ?>" id="street_address" name="street_address" value="<?= e(old($old, 'street_address')) ?>" required maxlength="200"><?= field_error($errors, 'street_address') ?></div>
    <div class="col-md-3"><label class="form-label" for="resident_since">Resident since</label><input class="form-control<?= invalid($errors, 'resident_since') ?>" id="resident_since" name="resident_since" type="date" value="<?= e(old($old, 'resident_since')) ?>" required max="<?= e(today_local()) ?>"><?= field_error($errors, 'resident_since') ?></div>
    <div class="col-md-4"><label class="form-label" for="contact_no">Mobile number <span class="optional">optional</span></label><input class="form-control<?= invalid($errors, 'contact_no') ?>" id="contact_no" name="contact_no" value="<?= e(old($old, 'contact_no')) ?>" inputmode="tel" pattern="(09|\+639)[0-9]{9}"><?= field_error($errors, 'contact_no') ?></div>
    <div class="col-md-5"><label class="form-label" for="email">Email <span class="optional">optional</span></label><input class="form-control<?= invalid($errors, 'email') ?>" id="email" name="email" type="email" value="<?= e(old($old, 'email')) ?>" maxlength="254"><?= field_error($errors, 'email') ?></div>
    <?php if ($editing): ?>
      <div class="col-md-3"><label class="form-label" for="status">Registry status</label><select class="form-select<?= invalid($errors, 'status') ?>" id="status" name="status" required><?= options(RESIDENT_STATUSES, old($old, 'status')) ?></select><?= field_error($errors, 'status') ?></div>
    <?php endif; ?>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="is_registered_voter" name="is_registered_voter" value="1"<?= chk(!empty($old['is_registered_voter'])) ?>><label class="form-check-label" for="is_registered_voter">Registered voter in this barangay</label></div></div>
  </div>
  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary" type="submit" data-loading-text="Saving…"><?= $editing ? 'Save changes' : ($then === 'request' ? 'Save and continue to request' : 'Add resident') ?></button>
    <a class="btn btn-outline-secondary" href="<?= e($editing ? url('residents/view', ['id' => $id]) : '/residents') ?>">Cancel</a>
  </div>
</form>
<?php layout_end(); ?>
