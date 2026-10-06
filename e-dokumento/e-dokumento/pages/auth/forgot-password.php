<?php
declare(strict_types=1);

$errors = [];
$sent = false;
if (is_post()) {
    $v = new Validator($_POST);
    $email = $v->email('email');
    if (!$v->fails()) {
        try {
            Supabase::anon()->recover((string) $email, app_url('reset-password'));
        } catch (SupabaseException $e) {
            // Same response either way, so the page never reveals which emails have accounts.
            error_log('recover: ' . $e->getMessage());
        }
        $sent = true;
    } else {
        $errors = $v->errors();
    }
}

guest_start('Reset your password', 'guest auth');
?>
<div class="auth-layout">
  <?php require __DIR__ . '/_aside.php'; ?>
  <main class="auth-main" id="main">
    <div class="auth-card">
      <?php if ($sent): ?>
        <h2>Check your email</h2>
        <p>If an account uses that address, a password reset link is on its way. The link works once and expires after an hour.</p>
        <a class="btn btn-primary" href="/login">Back to sign in</a>
      <?php else: ?>
        <h2>Reset your password</h2>
        <p class="text-secondary">Enter the email you signed up with. We'll send a link to set a new password.</p>
        <form method="post" novalidate class="needs-validation">
          <?= csrf_field() ?>
          <div class="mb-4">
            <label class="form-label" for="email">Email</label>
            <input class="form-control<?= invalid($errors, 'email') ?>" id="email" name="email" type="email" required maxlength="254" autocomplete="email" autofocus>
            <?= field_error($errors, 'email') ?>
          </div>
          <button class="btn btn-primary w-100" type="submit" data-loading-text="Sending…">Send reset link</button>
        </form>
        <p class="auth-switch"><a href="/login">Back to sign in</a></p>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php guest_end(); ?>
