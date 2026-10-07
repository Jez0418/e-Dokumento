<?php /** @var string $pageTitle */ /** @var string $bodyClass */ ?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($pageTitle) ?> · e-Dokumento</title>
  <script src="/assets/js/theme.js?v=<?= e(asset_ver('js/theme.js')) ?>"></script>
  <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@400;500&family=Geist:wght@400;500;600;700<?= str_contains($bodyClass, 'print-page') ? '&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700' : '' ?>&display=swap">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= e(asset_ver('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php if (empty($user) && !str_contains($bodyClass, 'print-page')): ?>
<button type="button" class="btn btn-icon theme-float no-print" data-theme-toggle aria-label="Switch to light theme">
  <i class="bi bi-sun ti-to-light" aria-hidden="true"></i><i class="bi bi-moon ti-to-dark" aria-hidden="true"></i>
</button>
<?php endif; ?>
