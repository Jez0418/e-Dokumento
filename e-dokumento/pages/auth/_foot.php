<?php
// Under the card: the public certificate check and a short footer.
?>
<footer class="auth-outro">
  <section class="auth-services" aria-labelledby="auth-services-h">
    <h2 id="auth-services-h">Public services</h2>
    <a class="auth-verify-link" href="/verify"><i class="bi bi-patch-check" aria-hidden="true"></i><span>Verify certificate authenticity</span><i class="bi bi-arrow-up-right ms-auto" aria-hidden="true"></i></a>
  </section>
  <p class="auth-copyright">&copy; <?= (int) date('Y') ?> e-Dokumento &middot; Barangay <?= e(barangay_name()) ?></p>
</footer>
