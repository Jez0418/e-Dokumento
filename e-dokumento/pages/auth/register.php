<?php
declare(strict_types=1);

$errors = [];
$old = $_POST;
$done = false;

try {
    $puroks = Supabase::anon()->select('puroks', [['select', 'id,name'], ['is_active', 'eq.true'], ['order', 'name.asc']])['rows'];
} catch (Throwable $e) {
    $puroks = [];
    $errors['_form'] = db_error($e);
}
$purokIds = array_map(static fn ($p) => (string) $p['id'], $puroks);

if (is_post()) {
    $v = new Validator($_POST);
    $today = today_local();
    $first = $v->name('first_name', 'First name');
    $middle = $v->name('middle_name', 'Middle name', false);
    $last = $v->name('last_name', 'Last name');
    $suffix = $v->text('suffix', 'Suffix', false, 1, 10);
    $birth = $v->date('birth_date', 'Birth date', true, $today, '1900-01-02');
    $sex = $v->in('sex', 'sex', ['male', 'female']);
    $civil = $v->in('civil_status', 'civil status', array_keys(CIVIL_STATUSES));
    $purok = $v->in('purok_id', 'purok', $purokIds);
    $street = $v->text('street_address', 'House no. and street', true, 3, 200);
    $since = $v->date('resident_since', 'Living here since', true, $today, $birth ?? null);
    $contact = $v->phone('contact_no', 'Mobile number', true);
    $occupation = $v->text('occupation', 'Occupation', false, 0, 100);
    $email = $v->email('email');
    $password = $v->password('password', 'password_confirm');
    if (!$v->bool('consent')) {
        $v->error('consent', 'You need to agree before we can create your account.');
    }

    if (!$v->fails()) {
        $meta = [
            'full_name'      => trim($first . ' ' . $last),
            'first_name'     => $first,
            'middle_name'    => $middle,
            'last_name'      => $last,
            'suffix'         => $suffix,
            'birth_date'     => $birth,
            'sex'            => $sex,
            'civil_status'   => $civil,
            'purok_id'       => (int) $purok,
            'street_address' => $street,
            'resident_since' => $since,
            'contact_no'     => $contact,
            'occupation'     => $occupation,
            'is_registered_voter' => $v->bool('is_registered_voter'),
        ];
        try {
            $result = Supabase::anon()->signUp((string) $email, (string) $password, $meta, app_url('login'));
            if (!empty($result['access_token'])) {
                // Email confirmation is turned off in Supabase: sign straight in.
                Auth::store($result);
                flash_success('Account created. Next, verify your residency.');
                redirect('/profile');
            }
            $done = true;
        } catch (SupabaseException $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'database error')) {
                $errors['_form'] = 'We could not create your resident record. Check your details, or visit the barangay hall if you are already registered there.';
            } elseif (str_contains($msg, 'password')) {
                $errors['password'] = $e->getMessage();
            } elseif ($e->status === 429) {
                $errors['_form'] = 'Too many sign-ups from this connection. Wait a few minutes and try again.';
            } else {
                $errors['_form'] = 'We could not create your account right now. Try again in a moment.';
            }
        }
    } else {
        $errors = $v->errors();
    }
}

guest_start('Create an account', 'guest auth');
?>
<div class="auth-layout">
  <?php require __DIR__ . '/_aside.php'; ?>
  <main class="auth-main" id="main">
    <div class="auth-card auth-card-wide">
      <?php if ($done): ?>
        <div class="text-center py-4">
          <i class="bi bi-envelope-check display-5 text-primary" aria-hidden="true"></i>
          <h1 class="mt-3">Check your email</h1>
          <p>If <strong><?= e($email ?? '') ?></strong> can receive mail, a confirmation link is on its way. Open it, then sign in to verify your residency.</p>
          <a class="btn btn-primary" href="/login">Go to sign in</a>
        </div>
      <?php else: ?>
      <h1>Create a resident account</h1>
      <p class="auth-lead">Use your name exactly as it appears on your ID. The Secretary checks it against the ID you upload next.</p>
      <?= form_errors($errors) ?>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <fieldset class="form-section">
          <legend>Your name</legend>
          <div class="row g-3 name-row">
            <div class="col-md-4">
              <label class="form-label" for="first_name">First name</label>
              <input class="form-control<?= invalid($errors, 'first_name') ?>" id="first_name" name="first_name" value="<?= e(old($old, 'first_name')) ?>" required maxlength="60" autocomplete="given-name">
              <?= isset($errors['first_name']) ? field_error($errors, 'first_name') : '<div class="invalid-feedback">Enter your first name as it appears on your ID.</div>' ?>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="middle_name">Middle name <span class="optional">optional</span></label>
              <input class="form-control<?= invalid($errors, 'middle_name') ?>" id="middle_name" name="middle_name" value="<?= e(old($old, 'middle_name')) ?>" maxlength="60" autocomplete="additional-name">
              <?= isset($errors['middle_name']) ? field_error($errors, 'middle_name') : '<div class="invalid-feedback">Use letters only.</div>' ?>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="last_name">Last name</label>
              <input class="form-control<?= invalid($errors, 'last_name') ?>" id="last_name" name="last_name" value="<?= e(old($old, 'last_name')) ?>" required maxlength="60" autocomplete="family-name">
              <?= isset($errors['last_name']) ? field_error($errors, 'last_name') : '<div class="invalid-feedback">Enter your last name as it appears on your ID.</div>' ?>
            </div>
            <div class="col-md-2">
              <label class="form-label" for="suffix">Suffix <span class="optional">optional</span></label>
              <input class="form-control<?= invalid($errors, 'suffix') ?>" id="suffix" name="suffix" value="<?= e(old($old, 'suffix')) ?>" maxlength="10" placeholder="Jr., III">
              <?= field_error($errors, 'suffix') ?>
            </div>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend>About you</legend>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="birth_date">Birth date</label>
              <input class="form-control<?= invalid($errors, 'birth_date') ?>" id="birth_date" name="birth_date" type="date" value="<?= e(old($old, 'birth_date')) ?>" required max="<?= e(today_local()) ?>">
              <?= isset($errors['birth_date']) ? field_error($errors, 'birth_date') : '<div class="invalid-feedback">Enter your birth date. It cannot be in the future.</div>' ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="sex">Sex</label>
              <select class="form-select<?= invalid($errors, 'sex') ?>" id="sex" name="sex" required>
                <?= options(['female' => 'Female', 'male' => 'Male'], old($old, 'sex'), 'Choose') ?>
              </select>
              <?= isset($errors['sex']) ? field_error($errors, 'sex') : '<div class="invalid-feedback">Choose your sex as shown on your ID.</div>' ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="civil_status">Civil status</label>
              <select class="form-select<?= invalid($errors, 'civil_status') ?>" id="civil_status" name="civil_status" required>
                <?= options(CIVIL_STATUSES, old($old, 'civil_status'), 'Choose') ?>
              </select>
              <?= isset($errors['civil_status']) ? field_error($errors, 'civil_status') : '<div class="invalid-feedback">Choose your civil status.</div>' ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="contact_no">Mobile number</label>
              <input class="form-control<?= invalid($errors, 'contact_no') ?>" id="contact_no" name="contact_no" value="<?= e(old($old, 'contact_no')) ?>" required inputmode="tel" pattern="(09|\+639)[0-9]{9}" aria-describedby="contact-hint" placeholder="09171234567" autocomplete="tel">
              <div class="form-text" id="contact-hint">11 digits, starting with 09. The Secretary may call this number.</div>
              <?= isset($errors['contact_no']) ? field_error($errors, 'contact_no') : '<div class="invalid-feedback">Enter an 11-digit mobile number that starts with 09.</div>' ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="occupation">Occupation <span class="optional">optional</span></label>
              <input class="form-control<?= invalid($errors, 'occupation') ?>" id="occupation" name="occupation" value="<?= e(old($old, 'occupation')) ?>" maxlength="100">
              <?= field_error($errors, 'occupation') ?>
            </div>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend>Where you live</legend>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="purok_id">Purok</label>
              <select class="form-select<?= invalid($errors, 'purok_id') ?>" id="purok_id" name="purok_id" required>
                <?= options(pluck($puroks), old($old, 'purok_id'), 'Choose') ?>
              </select>
              <?= isset($errors['purok_id']) ? field_error($errors, 'purok_id') : '<div class="invalid-feedback">Choose your purok.</div>' ?>
            </div>
            <div class="col-md-8">
              <label class="form-label" for="street_address">House no. and street</label>
              <input class="form-control<?= invalid($errors, 'street_address') ?>" id="street_address" name="street_address" value="<?= e(old($old, 'street_address')) ?>" required minlength="3" maxlength="200" autocomplete="street-address">
              <?= isset($errors['street_address']) ? field_error($errors, 'street_address') : '<div class="invalid-feedback">Enter your house number and street, at least 3 characters.</div>' ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="resident_since">Living here since</label>
              <input class="form-control<?= invalid($errors, 'resident_since') ?>" id="resident_since" name="resident_since" type="date" value="<?= e(old($old, 'resident_since')) ?>" required max="<?= e(today_local()) ?>">
              <?= isset($errors['resident_since']) ? field_error($errors, 'resident_since') : '<div class="invalid-feedback">Enter when you started living here. It cannot be in the future.</div>' ?>
            </div>
            <div class="col-md-8 d-flex align-items-end">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="is_registered_voter" name="is_registered_voter" value="1"<?= chk(isset($old['is_registered_voter'])) ?>>
                <label class="form-check-label" for="is_registered_voter">I am a registered voter in this barangay</label>
              </div>
            </div>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend>Sign-in details</legend>
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label" for="email">Email</label>
              <input class="form-control<?= invalid($errors, 'email') ?>" id="email" name="email" type="email" value="<?= e(old($old, 'email')) ?>" required maxlength="254" autocomplete="email">
              <?= isset($errors['email']) ? field_error($errors, 'email') : '<div class="invalid-feedback">Enter a valid email address, for example juan@gmail.com.</div>' ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="password">Password</label>
              <div class="input-group has-validation">
                <input class="form-control<?= invalid($errors, 'password') ?>" id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" data-strength="password-hint" aria-describedby="password-hint">
                <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                <?= isset($errors['password']) ? field_error($errors, 'password') : '<div class="invalid-feedback">Use at least 8 characters with an uppercase letter, a lowercase letter and a number.</div>' ?>
              </div>
              <div class="form-text" id="password-hint">At least 8 characters with an uppercase letter, a lowercase letter and a number.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="password_confirm">Repeat password</label>
              <input class="form-control<?= invalid($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm" type="password" required maxlength="72" autocomplete="new-password" data-match="password">
              <?= isset($errors['password_confirm']) ? field_error($errors, 'password_confirm') : '<div class="invalid-feedback">The passwords do not match.</div>' ?>
            </div>
          </div>
        </fieldset>

        <div class="form-check my-3">
          <input class="form-check-input<?= invalid($errors, 'consent') ?>" type="checkbox" id="consent" name="consent" value="1" required<?= chk(isset($old['consent'])) ?>>
          <label class="form-check-label" for="consent">I allow the barangay to process my personal data to issue documents I request, as provided by the Data Privacy Act of 2012 (RA 10173).</label>
          <?= isset($errors['consent']) ? field_error($errors, 'consent') : '<div class="invalid-feedback">Tick this box to agree. We cannot create your account without it.</div>' ?>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit" data-loading-text="Creating your account…">Create account</button>
      </form>
      <p class="auth-switch">Already registered? <a href="/login">Sign in</a></p>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php guest_end(['auth.js']); ?>
