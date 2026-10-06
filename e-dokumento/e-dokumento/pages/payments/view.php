<?php
declare(strict_types=1);

$id = q('id');
if (!is_uuid($id)) {
    abort(404);
}
$p = Supabase::user()->first('payment_list', [['id', 'eq.' . $id], ['select', '*']]);
if (!$p) {
    abort(404, 'This payment does not exist, or it is not yours to view.');
}

layout_start('Payment ' . $p['or_number'], 'payments');
?>
<nav aria-label="Breadcrumb" class="crumbs no-print"><a href="<?= has_role('resident') ? e(url('requests/view', ['id' => $p['request_id']])) : '/payments?tab=history' ?>"><?= has_role('resident') ? 'Back to request' : 'Payments' ?></a></nav>
<article class="panel receipt">
  <header class="receipt-head">
    <img src="/assets/img/logo.svg" alt="" width="44" height="44">
    <div>
      <h1 class="h5 mb-0">Barangay <?= e(barangay_name()) ?></h1>
      <p class="mb-0 small text-secondary"><?= e(setting('city_municipality')) ?>, <?= e(setting('province')) ?></p>
    </div>
  </header>
  <h2 class="receipt-title">Payment acknowledgement</h2>
  <p class="small text-secondary">This is a system acknowledgement. The official receipt is the printed OR issued by the Treasurer.</p>
  <dl class="detail-grid">
    <div><dt>OR number</dt><dd class="mono"><?= e($p['or_number']) ?></dd></div>
    <div><dt>Status</dt><dd><?= $p['status'] === 'posted' ? 'Posted' : 'Voided: ' . e($p['void_reason']) ?></dd></div>
    <div><dt>Received from</dt><dd><?= e($p['resident_name']) ?></dd></div>
    <div><dt>For</dt><dd><?= e($p['document_type']) ?> (<span class="mono"><?= e($p['control_no']) ?></span>)</dd></div>
    <div><dt>Amount</dt><dd class="num fw-bold"><?= e(money($p['amount'])) ?></dd></div>
    <div><dt>Method</dt><dd><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference_no'] ? ' · ' . e($p['reference_no']) : '' ?></dd></div>
    <div><dt>Date</dt><dd><?= e(fmt_datetime($p['paid_at'])) ?></dd></div>
    <div><dt>Received by</dt><dd><?= e($p['received_by_name'] ?: 'Barangay Treasurer') ?></dd></div>
  </dl>
  <button class="btn btn-outline-primary no-print mt-3" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
</article>
<?php layout_end(); ?>
