<?php
declare(strict_types=1);

$code = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', q('code')) ?? '');
$result = null;
$error = null;
if ($code !== '') {
    try {
        $result = Supabase::anon()->rpc('verify_document', ['p_code' => $code]);
    } catch (Throwable $e) {
        $error = db_error($e);
    }
}
$found = is_array($result) && ($result['found'] ?? false);
$state = !$found ? 'missing' : (($result['status'] ?? '') === 'revoked' ? 'revoked' : (($result['expired'] ?? false) ? 'expired' : 'valid'));

guest_start('Check a certificate', 'guest verify');
?>
<main class="verify-page" id="main">
  <a href="/login" class="verify-brand"><img src="/assets/img/logo.svg" alt="" width="32" height="32"> e-Dokumento · Barangay <?= e(barangay_name()) ?></a>
  <h1>Check a barangay certificate</h1>
  <p class="page-lead">Enter the 10-character verification code printed at the bottom of the certificate, next to the QR code.</p>
  <form method="get" class="verify-form" role="search">
    <label class="form-label w-100 mb-0" for="code">Verification code</label>
    <input class="form-control form-control-lg mono" id="code" name="code" value="<?= e($code) ?>" maxlength="10" placeholder="e.g. 4F9A21C07B" autocomplete="off" required pattern="[0-9A-Fa-f]{10}" aria-describedby="code-hint" title="10 characters: digits 0 to 9 and letters A to F">
    <button class="btn btn-primary btn-lg" type="submit">Check certificate</button>
    <p class="form-text" id="code-hint">10 characters: digits 0–9 and letters A–F only. Leave out spaces and dashes.</p>
  </form>

  <?php if ($error): ?>
    <div class="alert alert-danger mt-4" role="alert"><?= e($error) ?></div>
  <?php elseif ($code !== ''): ?>
    <section class="verify-result verify-<?= e($state) ?>" aria-live="polite">
      <?php if ($state === 'missing'): ?>
        <h2><i class="bi bi-x-octagon me-2" aria-hidden="true"></i>No certificate has this code</h2>
        <p>Check the code for typing mistakes. If it still fails, the document was not issued by this system.</p>
      <?php else: ?>
        <h2>
          <?php if ($state === 'valid'): ?><i class="bi bi-patch-check-fill me-2" aria-hidden="true"></i>Genuine and valid
          <?php elseif ($state === 'expired'): ?><i class="bi bi-hourglass-bottom me-2" aria-hidden="true"></i>Genuine, but expired
          <?php else: ?><i class="bi bi-slash-circle me-2" aria-hidden="true"></i>Revoked by the barangay<?php endif; ?>
        </h2>
        <dl class="detail-grid">
          <div><dt>Document</dt><dd><?= e($result['document_type']) ?></dd></div>
          <div><dt>Document no.</dt><dd class="mono"><?= e($result['document_no']) ?></dd></div>
          <div><dt>Issued to</dt><dd><?= e($result['holder']) ?></dd></div>
          <div><dt>Issued on</dt><dd><?= e(fmt_date($result['issued_at'])) ?></dd></div>
          <div><dt>Valid until</dt><dd><?= e($result['valid_until'] ? fmt_date($result['valid_until']) : 'No expiry') ?></dd></div>
          <div><dt>Signed by</dt><dd><?= e($result['signatory']) ?>, Punong Barangay</dd></div>
        </dl>
        <p class="small text-secondary mb-0">Compare these details with the paper copy. A genuine copy also carries the barangay's dry seal and a wet signature.</p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <footer class="verify-foot">
    <p class="mb-1">Questions about a certificate? Visit the barangay hall<?= setting('office_hours') ? ' during office hours: ' . e(setting('office_hours')) : '' ?>.</p>
    <a href="/login">Residents and staff: sign in to e-Dokumento</a>
  </footer>
</main>
<?php guest_end(); ?>
