<?php
declare(strict_types=1);

$db = Supabase::user();
$id = q('id');
if (!is_uuid($id)) {
    abort(404);
}

if (is_post() && post('action') === 'print') {
    require_role('secretary');
    try {
        $db->rpc('record_print', ['p_issued_id' => $id]);
        redirect(url('documents/print', ['id' => $id, 'autoprint' => 1]));
    } catch (Throwable $e) {
        flash_error(db_error($e));
        redirect(url('documents/print', ['id' => $id]));
    }
}

$doc = $db->first('issued_documents', [
    ['id', 'eq.' . $id],
    ['select', '*,barangay_officials(full_name,position),document_requests(*,residents(*,puroks(name)),document_types(*),purposes(name),payments(or_number,amount,paid_at,status))'],
]);
if (!$doc) {
    abort(404, 'This document does not exist, or it is not yours to view.');
}
$req = one($doc['document_requests']);
$res = one($req['residents']);
$type = one($req['document_types']);
$purpose = one($req['purposes'])['name'] ?? '';
$signatory = one($doc['barangay_officials']);
$paid = array_values(array_filter($req['payments'] ?? [], static fn ($p) => $p['status'] === 'posted'))[0] ?? null;
$extra = is_array($req['extra_details'] ?? null) ? $req['extra_details'] : [];
$isOfficialCopy = has_role('secretary');
$isRevoked = $doc['status'] === 'revoked';

$name = e(mb_strtoupper(trim($res['first_name'] . ' ' . ($res['middle_name'] ? mb_substr($res['middle_name'], 0, 1) . '. ' : '') . $res['last_name'] . ($res['suffix'] ? ' ' . $res['suffix'] : ''))));
$age = age_from($res['birth_date']);
$civil = e(strtolower(CIVIL_STATUSES[$res['civil_status']] ?? ''));
$brgy = barangay_name();
$place = trim($res['street_address'] . ', ' . (one($res['puroks'])['name'] ?? '') . ', Barangay ' . $brgy . ', ' . setting('city_municipality') . ', ' . setting('province'), ', ');
$issued = local_dt($doc['issued_at']) ?? new DateTimeImmutable();
$sinceDt = new DateTimeImmutable($res['resident_since']);
$months = $sinceDt->diff(new DateTimeImmutable(today_local()));
$residency = $months->y >= 1 ? $months->y . ' year' . ($months->y === 1 ? '' : 's') : $months->m . ' month' . ($months->m === 1 ? '' : 's');

$body = match ($type['template_key']) {
    'clearance' => "This is to certify that <strong>{$name}</strong>, {$age} years old, {$civil}, Filipino, and a bona fide resident of " . e($place) . ", is known to this office to be of good moral character and has no derogatory record on file in this barangay as of this date.",
    'residency' => "This is to certify that <strong>{$name}</strong>, {$age} years old, {$civil}, Filipino, is a bona fide resident of " . e($place) . ", and has resided in this barangay since " . e($sinceDt->format('F Y')) . ".",
    'indigency' => "This is to certify that <strong>{$name}</strong>, {$age} years old, {$civil}, a resident of " . e($place) . ", belongs to an indigent family in this barangay and has no sufficient means to meet the expense being requested for.",
    'business'  => "This is to certify that <strong>{$name}</strong>, a resident of " . e($place) . ", is hereby granted this clearance to operate <strong>" . e(mb_strtoupper((string) ($extra['business_name'] ?? ''))) . "</strong>"
                 . (!empty($extra['business_nature']) ? ', engaged in ' . e($extra['business_nature']) : '') . ", located at " . e((string) ($extra['business_address'] ?? '')) . ", within the territorial jurisdiction of this barangay, pursuant to Section 152(c) of the Local Government Code of 1991.",
    'jobseeker' => "This is to certify that <strong>{$name}</strong>, {$age} years old, a resident of " . e($place) . " for {$residency}, is a qualified availee of Republic Act No. 11261, the First Time Jobseekers Assistance Act. This certification is valid for one (1) year from the date of issuance and may be used only once.",
    default     => "This is to certify that <strong>{$name}</strong>, {$age} years old, {$civil}, is a bona fide resident of " . e($place) . ".",
};
$purposeLine = 'This certification is issued upon the request of the above-named person for <strong>' . e(mb_strtolower($purpose)) . '</strong>'
    . ($req['purpose_details'] ? ' (' . e($req['purpose_details']) . ')' : '') . ' and for whatever legal purpose it may serve.';
$issuedLine = 'Issued this ' . ordinal((int) $issued->format('j')) . ' day of ' . $issued->format('F Y') . ' at Barangay ' . e($brgy) . ', ' . e(setting('city_municipality')) . ', ' . e(setting('province')) . '.';
$verifyUrl = app_url('verify?code=' . $doc['verification_code']);

guest_start($doc['document_no'], 'print-page');
?>
<div class="print-toolbar no-print">
  <a class="btn btn-link" href="<?= e(url('requests/view', ['id' => $req['id']])) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to request</a>
  <?php if ($isOfficialCopy && !$isRevoked): ?>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="print"><button class="btn btn-primary" type="submit"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print official copy</button></form>
    <span class="small text-secondary ms-2">Printed <?= (int) $doc['print_count'] ?> time<?= (int) $doc['print_count'] === 1 ? '' : 's' ?>. Each print is logged.</span>
  <?php endif; ?>
</div>

<article class="certificate<?= $isOfficialCopy ? '' : ' is-reference' ?><?= $isRevoked ? ' is-revoked' : '' ?>" data-autoprint="<?= q('autoprint') === '1' && $isOfficialCopy ? '1' : '0' ?>">
  <?php if (!$isOfficialCopy || $isRevoked): ?>
    <div class="cert-watermark" aria-hidden="true"><?= $isRevoked ? 'Revoked' : 'Reference copy' ?></div>
  <?php endif; ?>
  <header class="cert-head">
    <img src="/assets/img/logo.svg" alt="" class="cert-seal">
    <div>
      <p>Republic of the Philippines</p>
      <p>Province of <?= e(setting('province')) ?></p>
      <p><?= e(setting('city_municipality')) ?></p>
      <p class="cert-brgy">Barangay <?= e($brgy) ?></p>
      <p class="cert-office">Office of the Punong Barangay</p>
    </div>
  </header>

  <h1 class="cert-title"><?= e(mb_strtoupper($type['name'])) ?></h1>
  <p class="cert-salutation">To whom it may concern:</p>
  <p class="cert-body"><?= $body ?></p>
  <p class="cert-body"><?= $purposeLine ?></p>
  <p class="cert-body"><?= $issuedLine ?></p>

  <div class="cert-sign">
    <div class="cert-sign-line"><?= e(mb_strtoupper($signatory['full_name'] ?? '')) ?></div>
    <div>Punong Barangay</div>
  </div>

  <footer class="cert-foot">
    <dl>
      <div><dt>Document no.</dt><dd><?= e($doc['document_no']) ?></dd></div>
      <div><dt>Control no.</dt><dd><?= e($req['control_no']) ?></dd></div>
      <div><dt>Valid until</dt><dd><?= e($doc['valid_until'] ? fmt_date($doc['valid_until'], 'F j, Y') : 'No expiry') ?></dd></div>
      <?php if ($paid): ?><div><dt>OR no. / amount</dt><dd><?= e($paid['or_number']) ?> / <?= e(money($paid['amount'])) ?></dd></div><?php elseif ($req['fee_waived'] || (float) $req['fee_amount'] === 0.0): ?><div><dt>Fee</dt><dd><?= $type['template_key'] === 'jobseeker' ? 'Free under RA 11261' : ($req['fee_waived'] ? 'Waived' : 'None') ?></dd></div><?php endif; ?>
    </dl>
    <div class="cert-verify">
      <div id="qr" class="cert-qr" data-url="<?= e($verifyUrl) ?>" aria-hidden="true"></div>
      <p>Verify at <strong><?= e(preg_replace('#^https?://#', '', app_url('verify'))) ?></strong><br>Code <strong class="mono"><?= e($doc['verification_code']) ?></strong></p>
    </div>
    <p class="cert-note">Not valid without the barangay dry seal and the wet signature of the Punong Barangay.</p>
  </footer>
</article>
<?php guest_end(['https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js', 'print.js']); ?>
