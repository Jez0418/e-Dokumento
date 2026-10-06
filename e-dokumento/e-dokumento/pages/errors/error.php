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
  <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Next:wght@400;700&display=swap">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="guest">
  <main class="error-page" id="main">
    <p class="error-code"><?= $code ?></p>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
    <?php if ($detail): ?><pre class="error-detail"><?= htmlspecialchars((string) $detail) ?></pre><?php endif; ?>
    <a class="btn btn-primary" href="<?= $signedIn ? '/dashboard' : '/login' ?>"><?= $signedIn ? 'Go to dashboard' : 'Go to sign in' ?></a>
  </main>
</body>
</html>
