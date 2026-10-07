<?php
// Shared left panel for sign-in pages: who this hall serves and what you can do here.
?>
<aside class="auth-aside">
  <div class="auth-aside-inner">
    <img src="/assets/img/logo.svg" alt="" width="56" height="56" class="auth-logo">
    <p class="auth-kicker">Barangay <?= e(barangay_name()) ?></p>
    <p class="auth-title">Request barangay documents without lining up twice.</p>
    <p class="auth-copy">File your request online, track it by control number, and come to the hall only when it is ready to claim.</p>
    <dl class="auth-facts">
      <div><dt>Office hours</dt><dd><?= e(setting('office_hours', 'Not set')) ?></dd></div>
      <div><dt>Hall</dt><dd><?= e(setting('hall_address', '—')) ?></dd></div>
    </dl>
    <a class="auth-verify-link" href="/verify"><i class="bi bi-patch-check me-2" aria-hidden="true"></i>Check if a barangay certificate is genuine</a>
  </div>
</aside>
