<?php
declare(strict_types=1);

$errors = [];
$email = '';

if (is_post()) {
    $v = new Validator($_POST);
    $email = (string) $v->email('email');
    $password = (string) ($_POST['password'] ?? '');
    if ($password === '') {
        $v->error('password', 'Password is required.');
    }
    if (!$v->fails()) {
        [$ok, $message] = Auth::attempt($email, $password);
        if ($ok) {
            flash_success('Signed in. Welcome, ' . (Auth::user()['full_name'] ?? '') . '.');
            redirect(safe_next(q('next')) ?? '/dashboard');
        }
        $errors['_form'] = $message;
    } else {
        $errors = $v->errors();
    }
}

guest_start('Sign in', 'guest auth');
?>
<div class="auth-layout">
  <?php require __DIR__ . '/_aside.php'; ?>
  <main class="auth-main" id="main">
    <div class="auth-card">
      <h1>Sign in</h1>
      <p class="auth-lead">Residents and barangay staff use the same sign-in.</p>
      <div id="hash-message" class="alert d-none" role="status"></div>
      <?php if (isset($errors['_form'])): ?>
        <div class="alert alert-danger" role="alert"><?= e($errors['_form']) ?></div>
      <?php endif; ?>
      <form method="post" novalidate class="needs-validation" action="<?= e(url('login', ['next' => safe_next(q('next'))])) ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="email">Email</label>
          <input class="form-control<?= invalid($errors, 'email') ?>" id="email" name="email" type="email" value="<?= e($email) ?>" required autocomplete="email" maxlength="254" autofocus>
          <?= isset($errors['email']) ? field_error($errors, 'email') : '<div class="invalid-feedback">Enter your email address, for example juan@gmail.com.</div>' ?>
        </div>
        <div class="mb-2">
          <label class="form-label" for="password">Password</label>
          <div class="input-group has-validation">
            <input class="form-control<?= invalid($errors, 'password') ?>" id="password" name="password" type="password" required autocomplete="current-password" maxlength="72">
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
            <?= isset($errors['password']) ? field_error($errors, 'password') : '<div class="invalid-feedback">Enter your password.</div>' ?>
          </div>
        </div>
        <p class="mb-4 text-end"><a href="/forgot-password">Forgot your password?</a></p>
        <button class="btn btn-primary w-100 btn-lg" type="submit" data-loading-text="Signing in…">Sign in</button>
      </form>
      <p class="auth-switch">New resident? <a href="/register">Create an account</a></p>
    </div>
  </main>
  <?php require __DIR__ . '/_foot.php'; ?>
</div>
<?php guest_end(['auth.js']); ?>
