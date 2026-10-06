<?php
declare(strict_types=1);

$db = Supabase::user();
$user = Auth::user();
$isResident = Auth::role() === 'resident';
$resident = $isResident ? Auth::resident() : null;
$errors = [];
$old = $_POST;
$section = post('_section');

$canEditResident = $resident && in_array($resident['verification_status'], ['unverified', 'rejected'], true);

if (is_post()) {
    $v = new Validator($_POST);
    if ($section === 'account') {
        $name = $v->text('full_name', 'Display name', true, 2, 120);
        $phone = $v->phone('contact_no');
        if (!$v->fails()) {
            try {
                $db->update('profiles', [['id', 'eq.' . $user['id']]], ['full_name' => $name, 'contact_no' => $phone]);
                flash_success('Account details saved.');
                redirect('/profile');
            } catch (Throwable $e) {
                $errors['_form'] = db_error($e);
            }
        } else {
            $errors = $v->errors();
        }
    } elseif ($section === 'password') {
        $password = $v->password('new_password', 'new_password_confirm', 'New password');
        if (!$v->fails()) {
            try {
                $db->updatePassword((string) $password);
                flash_success('Password changed.');
                redirect('/profile');
            } catch (SupabaseException $e) {
                $errors['new_password'] = str_contains(strtolower($e->getMessage()), 'password') ? $e->getMessage() : db_error($e);
            }
        } else {
            $errors = $v->errors();
        }
    } elseif ($section === 'resident' && $canEditResident) {
        $puroks = $db->select('puroks', [['select', 'id'], ['is_active', 'eq.true']])['rows'];
        $data = [
            'first_name'     => $v->name('first_name', 'First name'),
            'middle_name'    => $v->name('middle_name', 'Middle name', false),
            'last_name'      => $v->name('last_name', 'Last name'),
            'suffix'         => $v->text('suffix', 'Suffix', false, 1, 10),
            'birth_date'     => $v->date('birth_date', 'Birth date', true, today_local(), '1900-01-02'),
            'sex'            => $v->in('sex', 'sex', ['male', 'female']),
            'civil_status'   => $v->in('civil_status', 'civil status', array_keys(CIVIL_STATUSES)),
            'purok_id'       => $v->in('purok_id', 'purok', array_map(static fn ($p) => (string) $p['id'], $puroks)),
            'street_address' => $v->text('street_address', 'House no. and street', true, 3, 200),
            'resident_since' => $v->date('resident_since', 'Living here since', true, today_local()),
            'contact_no'     => $v->phone('contact_no_r', 'Mobile number', true),
            'occupation'     => $v->text('occupation', 'Occupation', false, 0, 100),
            'is_registered_voter' => $v->bool('is_registered_voter'),
        ];
        if (($data['resident_since'] ?? '') !== '' && ($data['birth_date'] ?? '') !== '' && $data['resident_since'] < $data['birth_date']) {
            $v->error('resident_since', 'Living here since cannot be before your birth date.');
        }
        if (!$v->fails()) {
            $data['purok_id'] = (int) $data['purok_id'];
            try {
                $db->update('residents', [['id', 'eq.' . $resident['id']]], $data);
                flash_success('Resident details saved.');
                redirect('/profile');
            } catch (Throwable $e) {
                $errors['_form'] = db_error($e);
            }
        } else {
            $errors = $v->errors();
        }
    } elseif ($section === 'verification' && $resident) {
        $idType = $v->integer('id_type_id', 'ID type', true, 1, 32767);
        $idNumber = $v->text('id_number', 'ID number', true, 3, 40);
        $front = Upload::check($_FILES['id_front'] ?? null, 'Front of the ID', true);
        $back = Upload::check($_FILES['id_back'] ?? null, 'Back of the ID', false);
        if (is_string($front)) {
            $v->error('id_front', $front);
        }
        if (is_string($back)) {
            $v->error('id_back', $back);
        }
        if (!$v->fails()) {
            $paths = [];
            try {
                $paths[] = $frontPath = Upload::store($db, 'verification-ids', $resident['id'], $front);
                $backPath = is_array($back) ? ($paths[] = Upload::store($db, 'verification-ids', $resident['id'], $back)) : null;
                $db->rpc('submit_verification', [
                    'p_id_type_id'  => $idType,
                    'p_id_number'   => $idNumber,
                    'p_front_path'  => $frontPath,
                    'p_back_path'   => $backPath,
                ]);
                flash_success('ID submitted. The Secretary will review it, usually within one working day.');
                redirect('/profile');
            } catch (Throwable $e) {
                try {
                    $db->removeObjects('verification-ids', $paths);
                } catch (Throwable) {
                }
                $errors['_verify'] = db_error($e);
            }
        } else {
            $errors = $v->errors();
        }
    }
}

$puroks = $resident ? $db->select('puroks', [['select', 'id,name'], ['order', 'name.asc']])['rows'] : [];
$idTypes = $resident ? $db->select('id_types', [['select', 'id,name'], ['is_active', 'eq.true'], ['order', 'name.asc']])['rows'] : [];
$verifications = $resident ? $db->select('resident_verifications', [
    ['select', 'id,status,id_number,remarks,submitted_at,reviewed_at,front_image_path,back_image_path,id_types(name)'],
    ['resident_id', 'eq.' . $resident['id']],
    ['order', 'submitted_at.desc'],
])['rows'] : [];

$r = $resident ?? [];
$val = static fn (string $k, string $rk = '') => old($old, $k, (string) ($r[$rk !== '' ? $rk : $k] ?? ''));

layout_start('Profile', 'profile');
page_header('Profile', $isResident ? 'Your account, your resident record, and your verification.' : 'Your account and password.');
?>
<?= isset($errors['_form']) ? '<div class="alert alert-danger" role="alert">' . e($errors['_form']) . '</div>' : '' ?>
<div class="row g-4">
  <div class="col-lg-<?= $isResident ? '7' : '8' ?>">
    <?php if ($resident): ?>
    <section class="panel" id="verification">
      <div class="panel-head">
        <h2>Residency verification</h2>
        <?= verification_badge($resident['verification_status']) ?>
      </div>
      <?php if ($resident['verification_status'] === 'verified'): ?>
        <p class="mb-0">Verified on <?= e(fmt_date($resident['verified_at'])) ?>. You can request documents online. To change your name, birth date or address, visit the barangay hall with your ID.</p>
      <?php elseif ($resident['verification_status'] === 'pending'): ?>
        <p class="mb-0">Your ID is with the Secretary. You'll get a notification when it is reviewed.</p>
      <?php else: ?>
        <?php if ($resident['verification_status'] === 'rejected' && !empty($verifications[0]['remarks'])): ?>
          <div class="notice notice-danger"><i class="bi bi-info-circle" aria-hidden="true"></i><div><strong>Why it was not approved:</strong> <?= e($verifications[0]['remarks']) ?></div></div>
        <?php endif; ?>
        <p>Upload a clear photo or scan of one valid ID. The name and birth date must match your resident record below.</p>
        <?php if (isset($errors['_verify'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['_verify']) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" novalidate class="needs-validation" data-max-total="4000000">
          <?= csrf_field() ?>
          <input type="hidden" name="_section" value="verification">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="id_type_id">ID type</label>
              <select class="form-select<?= invalid($errors, 'id_type_id') ?>" id="id_type_id" name="id_type_id" required>
                <?= options(pluck($idTypes), old($old, 'id_type_id'), 'Choose your ID') ?>
              </select>
              <?= field_error($errors, 'id_type_id') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="id_number">ID number</label>
              <input class="form-control<?= invalid($errors, 'id_number') ?>" id="id_number" name="id_number" value="<?= e(old($old, 'id_number')) ?>" required minlength="3" maxlength="40" autocomplete="off">
              <?= field_error($errors, 'id_number') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="id_front">Front of the ID</label>
              <input class="form-control<?= invalid($errors, 'id_front') ?>" id="id_front" name="id_front" type="file" accept="image/jpeg,image/png,application/pdf" required data-max-bytes="2097152">
              <div class="form-text">JPG, PNG or PDF, up to 2 MB.</div>
              <?= field_error($errors, 'id_front') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="id_back">Back of the ID <span class="optional">optional</span></label>
              <input class="form-control<?= invalid($errors, 'id_back') ?>" id="id_back" name="id_back" type="file" accept="image/jpeg,image/png,application/pdf" data-max-bytes="2097152">
              <?= field_error($errors, 'id_back') ?>
            </div>
          </div>
          <button class="btn btn-primary mt-3" type="submit" data-loading-text="Uploading…">Submit for verification</button>
        </form>
      <?php endif; ?>
      <?php if ($verifications): ?>
        <details class="mt-3">
          <summary>Submission history (<?= count($verifications) ?>)</summary>
          <ul class="history-list mt-2">
            <?php foreach ($verifications as $ver): ?>
              <li><?= verification_badge($ver['status'] === 'approved' ? 'verified' : ($ver['status'] === 'pending' ? 'pending' : 'rejected')) ?>
                <?= e(one($ver['id_types'])['name'] ?? 'ID') ?>, submitted <?= e(fmt_datetime($ver['submitted_at'])) ?>
                · <a href="<?= e(file_link('verification-ids', $ver['front_image_path'])) ?>" target="_blank" rel="noopener">View front</a>
                <?php if ($ver['back_image_path']): ?>· <a href="<?= e(file_link('verification-ids', $ver['back_image_path'])) ?>" target="_blank" rel="noopener">back</a><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Resident record</h2><?php if (!$canEditResident): ?><span class="tag tag-neutral"><i class="bi bi-lock me-1" aria-hidden="true"></i>Locked</span><?php endif; ?></div>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <input type="hidden" name="_section" value="resident">
        <fieldset<?= $canEditResident ? '' : ' disabled' ?>>
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="first_name">First name</label><input class="form-control<?= invalid($errors, 'first_name') ?>" id="first_name" name="first_name" value="<?= e($val('first_name')) ?>" required maxlength="60"><?= field_error($errors, 'first_name') ?></div>
            <div class="col-md-3"><label class="form-label" for="middle_name">Middle name</label><input class="form-control<?= invalid($errors, 'middle_name') ?>" id="middle_name" name="middle_name" value="<?= e($val('middle_name')) ?>" maxlength="60"><?= field_error($errors, 'middle_name') ?></div>
            <div class="col-md-3"><label class="form-label" for="last_name">Last name</label><input class="form-control<?= invalid($errors, 'last_name') ?>" id="last_name" name="last_name" value="<?= e($val('last_name')) ?>" required maxlength="60"><?= field_error($errors, 'last_name') ?></div>
            <div class="col-md-2"><label class="form-label" for="suffix">Suffix</label><input class="form-control" id="suffix" name="suffix" value="<?= e($val('suffix')) ?>" maxlength="10"></div>
            <div class="col-md-4"><label class="form-label" for="birth_date">Birth date</label><input class="form-control<?= invalid($errors, 'birth_date') ?>" id="birth_date" name="birth_date" type="date" value="<?= e($val('birth_date')) ?>" required max="<?= e(today_local()) ?>"><?= field_error($errors, 'birth_date') ?></div>
            <div class="col-md-4"><label class="form-label" for="sex">Sex</label><select class="form-select" id="sex" name="sex" required><?= options(['female' => 'Female', 'male' => 'Male'], $val('sex')) ?></select></div>
            <div class="col-md-4"><label class="form-label" for="civil_status">Civil status</label><select class="form-select" id="civil_status" name="civil_status" required><?= options(CIVIL_STATUSES, $val('civil_status')) ?></select></div>
            <div class="col-md-4"><label class="form-label" for="purok_id">Purok</label><select class="form-select<?= invalid($errors, 'purok_id') ?>" id="purok_id" name="purok_id" required><?= options(pluck($puroks), $val('purok_id')) ?></select><?= field_error($errors, 'purok_id') ?></div>
            <div class="col-md-8"><label class="form-label" for="street_address">House no. and street</label><input class="form-control<?= invalid($errors, 'street_address') ?>" id="street_address" name="street_address" value="<?= e($val('street_address')) ?>" required maxlength="200"><?= field_error($errors, 'street_address') ?></div>
            <div class="col-md-4"><label class="form-label" for="resident_since">Living here since</label><input class="form-control<?= invalid($errors, 'resident_since') ?>" id="resident_since" name="resident_since" type="date" value="<?= e($val('resident_since')) ?>" required max="<?= e(today_local()) ?>"><?= field_error($errors, 'resident_since') ?></div>
            <div class="col-md-4"><label class="form-label" for="contact_no_r">Mobile number</label><input class="form-control<?= invalid($errors, 'contact_no_r') ?>" id="contact_no_r" name="contact_no_r" value="<?= e($val('contact_no_r', 'contact_no')) ?>" required inputmode="tel" pattern="(09|\+639)[0-9]{9}"><?= field_error($errors, 'contact_no_r') ?></div>
            <div class="col-md-4"><label class="form-label" for="occupation">Occupation</label><input class="form-control" id="occupation" name="occupation" value="<?= e($val('occupation')) ?>" maxlength="100"></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="is_registered_voter" name="is_registered_voter" value="1"<?= chk($r['is_registered_voter'] ?? false) ?>><label class="form-check-label" for="is_registered_voter">Registered voter in this barangay</label></div></div>
          </div>
          <?php if ($canEditResident): ?><button class="btn btn-outline-primary mt-3" type="submit">Save resident details</button><?php endif; ?>
        </fieldset>
      </form>
    </section>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-head"><h2>Account</h2><span class="tag tag-neutral"><?= e($user['role_name']) ?></span></div>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <input type="hidden" name="_section" value="account">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="full_name">Display name</label><input class="form-control<?= invalid($errors, 'full_name') ?>" id="full_name" name="full_name" value="<?= e(old($old, 'full_name', (string) $user['full_name'])) ?>" required minlength="2" maxlength="120"><?= field_error($errors, 'full_name') ?></div>
          <div class="col-md-6"><label class="form-label" for="contact_no">Mobile number <span class="optional">optional</span></label><input class="form-control<?= invalid($errors, 'contact_no') ?>" id="contact_no" name="contact_no" value="<?= e(old($old, 'contact_no', (string) ($user['contact_no'] ?? ''))) ?>" inputmode="tel" pattern="(09|\+639)[0-9]{9}"><?= field_error($errors, 'contact_no') ?></div>
          <div class="col-12"><label class="form-label" for="email_ro">Email</label><input class="form-control" id="email_ro" value="<?= e($user['email']) ?>" readonly></div>
        </div>
        <button class="btn btn-outline-primary mt-3" type="submit">Save account</button>
      </form>
    </section>
  </div>

  <div class="col-lg-<?= $isResident ? '5' : '4' ?>">
    <section class="panel">
      <div class="panel-head"><h2>Change password</h2></div>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <input type="hidden" name="_section" value="password">
        <div class="mb-3"><label class="form-label" for="new_password">New password</label><input class="form-control<?= invalid($errors, 'new_password') ?>" id="new_password" name="new_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"><?= field_error($errors, 'new_password') ?></div>
        <div class="mb-3"><label class="form-label" for="new_password_confirm">Repeat new password</label><input class="form-control<?= invalid($errors, 'new_password_confirm') ?>" id="new_password_confirm" name="new_password_confirm" type="password" required maxlength="72" autocomplete="new-password" data-match="new_password"><?= field_error($errors, 'new_password_confirm') ?><div class="invalid-feedback">The passwords do not match.</div></div>
        <button class="btn btn-outline-primary" type="submit">Change password</button>
      </form>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>Sign-in activity</h2></div>
      <p class="mb-0">Last sign-in: <?= e(fmt_datetime($user['last_login_at'] ?? null)) ?></p>
    </section>
  </div>
</div>
<?php layout_end(); ?>
