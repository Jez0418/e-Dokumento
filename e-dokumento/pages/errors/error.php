<?php
// Self-contained so it still renders when configuration itself is the problem.
$code = (int) ($GLOBALS['error_code'] ?? 500);
$message = (string) ($GLOBALS['error_message'] ?? '');
$detail = $GLOBALS['error_detail'] ?? null;
$titles = [
    403 => 'You don\'t have access to this page',
    404 => 'This page doesn\'t exist',
    419 => 'This form expired',
    500 => 'Something went wrong',
];
$title = $titles[$code] ?? 'Something went wrong';
if ($message === '') {
    $message = match ($code) {
        403 => 'Your role does not include this page. If you need it, ask the barangay administrator.',
        404 => 'Check the address, or go back to your dashboard.',
        default => 'Try again in a moment.',
    };
}
$signedIn = isset($_COOKIE['edk_rt']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> · e-Dokumento</title>
  <script src="/assets/js/theme.js?v=<?= htmlspecialchars(substr((string) (getenv('VERCEL_GIT_COMMIT_SHA') ?: getenv('VERCEL_DEPLOYMENT_ID') ?: (int) @filemtime(__DIR__ . '/../../assets/css/app.css')), 0, 12)) ?>"></script>
  <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= htmlspecialchars(substr((string) (getenv('VERCEL_GIT_COMMIT_SHA') ?: getenv('VERCEL_DEPLOYMENT_ID') ?: (int) @filemtime(__DIR__ . '/../../assets/css/app.css')), 0, 12)) ?>">
</head>
<body class="guest">
  <main class="error-page" id="main">
    <p class="error-code"><?= $code ?></p>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
    <?php if ($detail): ?><pre class="error-detail"><?= htmlspecialchars((string) $detail) ?></pre><?php endif; ?>
    <?php if ($code === 419): ?><p>To fix it, go back with your browser's back button, reload the page, and submit the form again.</p><?php endif; ?>
    <div class="error-actions">
      <a class="btn btn-primary" href="<?= $signedIn ? '/dashboard' : '/login' ?>"><?= $signedIn ? 'Go to dashboard' : 'Go to sign in' ?></a>
    </div>
  </main>
</body>
</html>
