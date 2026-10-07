<?php
// Office details and the certificate check, shown under the sign-in card.
?>
<footer class="auth-outro">
  <dl class="auth-facts">
    <div><dt>Office hours</dt><dd><?= e(setting('office_hours', 'Not set')) ?></dd></div>
    <div><dt>Hall</dt><dd><?= e(setting('hall_address', '—')) ?></dd></div>
  </dl>
  <a class="auth-verify-link" href="/verify"><i class="bi bi-patch-check me-2" aria-hidden="true"></i>Check if a barangay certificate is genuine</a>
</footer>
