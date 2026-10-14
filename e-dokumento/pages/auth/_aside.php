<?php
// Left panel: brand, pitch, the Request > Track > Claim steps, hall photo and visit details.
$hallPhoto = is_file(BASE_PATH . '/assets/img/hall.jpg');
$hallAddress = (string) setting('hall_address', '');
?>
<aside class="auth-aside">
  <div class="auth-brand">
    <img src="/assets/img/logo.svg" alt="" width="44" height="44" class="auth-logo">
    <div>
      <span class="auth-brand-name">e-Dokumento</span>
      <span class="auth-brand-sub">Barangay <?= e(barangay_name()) ?></span>
    </div>
  </div>
  <p class="auth-kicker">Your barangay, connected &amp; accessible</p>
  <div class="auth-pitch">
    <div>
      <p class="auth-title">Request barangay documents without lining up twice.</p>
      <p class="auth-copy">File your request online, track it by control number, and come to the hall only when it is ready to claim.</p>
    </div>
    <ol class="auth-steps" aria-label="How it works">
      <li><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span>Request</span></li>
      <li><i class="bi bi-hourglass-split" aria-hidden="true"></i><span>Track</span></li>
      <li><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i><span>Claim</span></li>
    </ol>
  </div>
  <figure class="auth-photo<?= $hallPhoto ? '' : ' is-empty' ?>">
    <?php if ($hallPhoto): ?>
      <img src="/assets/img/hall.jpg" alt="The entrance arch of Barangay <?= e(barangay_name()) ?>" loading="lazy">
    <?php else: ?>
      <i class="bi bi-bank2" aria-hidden="true"></i>
    <?php endif; ?>
    <figcaption>Less waiting. More time for what matters.</figcaption>
  </figure>
  <dl class="auth-facts">
    <div><dt>Office hours</dt><dd><i class="bi bi-clock me-1" aria-hidden="true"></i><?= e(setting('office_hours', 'Not set')) ?></dd></div>
    <div>
      <dt>Visit us</dt>
      <dd>
        <?= e($hallAddress !== '' ? $hallAddress : '—') ?>
        <?php if ($hallAddress !== ''): ?>
          <a class="auth-map" href="https://www.google.com/maps/search/?api=1&amp;query=<?= e(rawurlencode($hallAddress)) ?>" target="_blank" rel="noopener"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>Open in Google Maps</a>
        <?php endif; ?>
      </dd>
    </div>
  </dl>
</aside>
<p class="auth-badge"><i class="bi bi-shield-check" aria-hidden="true"></i>Resident &amp; staff portal</p>
