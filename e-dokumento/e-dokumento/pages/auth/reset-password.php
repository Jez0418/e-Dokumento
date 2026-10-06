<?php
declare(strict_types=1);

// The recovery email links here with the session in the URL fragment
// (#access_token=...&type=recovery). auth.js copies the token into the form.
$errors = [];
if (is_post()) {
    $token = (string) ($_POST['access_token'] ?? '');
    $v = new Validator($_POST);
    $password = $v->password('password', 'password_confirm', 'New password');
    if (!preg_match('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $token)) {
        $v->error('_form', 'This reset link is invalid or has expired. Request a new one.');
    }
    if (!$v->fails()) {
        try {
            (new Supabase($token))->updatePassword((string) $password);
            flash_success('Password updated. Sign in with your new password.');
            redirect('/login');
        } catch (SupabaseException $e) {
            $errors['_form'] = in_array($e->status, [401, 403], true)
                ? 'This reset link is invalid or has expired. Request a new one.'
                : (str_contains(strtolower($e->getMessage()), 'password') ? $e->getMessage() : 'Could not update your password. Try again.');
        }
    } else {
        $errors = $v->errors();
    }
}

guest_start('Set a new password', 'guest auth');
?>
<div class="auth-layout">
  <?php require __DIR__ . '/_aside.php'; ?>
  <main class="auth-main" id="main">
    <div class="auth-card">
      <h1>Set a new password</h1>
      <div id="reset-missing" class="alert alert-warning d-none" role="alert">
        Open this page from the link in your password reset email. <a href="/forgot-password">Request a new link</a>.
      </div>
      <?php if (isset($errors['_form'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
      <form method="post" novalidate class="needs-validation" id="reset-form">
        <?= csrf_field() ?>
        <input type="hidden" name="access_token" id="access_token" value="<?= e((string) ($_POST['access_token'] ?? '')) ?>">
        <div class="mb-3">
          <label class="form-label" for="password">New password</label>
          <input class="form-control<?= invalid($errors, 'password') ?>" id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
          <div class="form-text">At least 8 characters with an uppercase letter, a lowercase letter and a number.</div>
          <?= isset($errors['password']) ? field_error($errors, 'password') : '<div class="invalid-feedback">Use at least 8 characters with an uppercase letter, a lowercase letter and a number.</div>' ?>
        </div>
        <div class="mb-4">
          <label class="form-label" for="password_confirm">Repeat new password</label>
          <input class="form-control<?= invalid($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm" type="password" required maxlength="72" autocomplete="new-password" data-match="password">
          <?= field_error($errors, 'password_confirm') ?>
          <div class="invalid-feedback">The passwords do not match.</div>
        </div>
        <button class="btn btn-primary w-100" type="submit" data-loading-text="Saving…">Save new password</button>
      </form>
    </div>
  </main>
</div>
<?php guest_end(['auth.js']); ?>
