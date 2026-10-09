<?php
declare(strict_types=1);

$db = Supabase::user();
$role = Auth::role();
$id = q('id');
if (!is_uuid($id)) {
    abort(404);
}

$req = $db->first('document_requests', [
    ['id', 'eq.' . $id],
    ['select', '*,residents(*,puroks(name)),document_types(*,document_type_requirements(requirement_id,is_mandatory,sort_order,requirements(id,name,accepts_upload))),purposes(name),'
             . 'request_attachments(*,requirements(name)),request_status_history(*,profiles(full_name)),payments(*),issued_documents(*)'],
]);
if (!$req) {
    abort(404, 'This request does not exist, or it is not yours to view.');
}

$res = one($req['residents']);
$type = one($req['document_types']);
$purpose = one($req['purposes'])['name'] ?? '';
$history = $req['request_status_history'] ?? [];
usort($history, static fn ($a, $b) => strcmp($a['changed_at'], $b['changed_at']));
$attachments = $req['request_attachments'] ?? [];
usort($attachments, static fn ($a, $b) => strcmp($a['uploaded_at'], $b['uploaded_at']));
$payments = $req['payments'] ?? [];
$posted = array_values(array_filter($payments, static fn ($p) => $p['status'] === 'posted'))[0] ?? null;
$issued = one($req['issued_documents'] ?? null);
$status = $req['status'];

/** Issuing needs an active Punong Barangay record to print as signatory; warn before the click fails. */
$missingSignatory = false;
if (($role === 'secretary' && $status === 'processing') || ($role === 'captain' && $status === 'for_approval')) {
    $missingSignatory = $db->first('barangay_officials', [['position', 'eq.punong_barangay'], ['is_active', 'eq.true'], ['select', 'id']]) === null;
}
$signatoryWarning = '<div class="notice notice-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>'
    . '<strong>No active Punong Barangay is on record.</strong> Documents cannot be issued until the Administrator adds one under Officials, '
    . 'with the position Punong Barangay and the record marked active.</div></div>';
$isOwner = $role === 'resident' && ($res['profile_id'] ?? null) === Auth::id();
$extra = is_array($req['extra_details'] ?? null) ? $req['extra_details'] : [];

// Requirements and the files that satisfy them
$reqList = $type['document_type_requirements'] ?? [];
usort($reqList, static fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);
$byReq = [];
foreach ($attachments as $a) {
    $byReq[(string) ($a['requirement_id'] ?? 'other')][] = $a;
}

$self = url('requests/view', ['id' => $id]);
$act = '/requests/action';
$hidden = ['id' => $id, '_back' => $self];

$note = match ($status) {
    'pending'           => 'Waiting for the Secretary to start the review.',
    'under_review'      => 'The Secretary is checking the files.',
    'for_payment'       => 'Pay ' . money($req['fee_amount']) . ' at the Treasurer\'s window.',
    'processing'        => 'The document is being prepared.',
    'for_approval'      => 'Waiting for the Punong Barangay\'s approval.',
    'ready_for_release' => 'Ready to claim. Bring a valid ID.',
    'released'          => 'Released ' . fmt_date($req['released_at']) . ' to ' . ($req['released_to'] ?? '') . '.',
    'rejected'          => 'Rejected. See the reason below.',
    'cancelled'         => 'Cancelled.',
    default             => '',
};

layout_start($req['control_no'], match (true) {
    $role === 'captain' && $status === 'for_approval'    => 'approvals',
    $role === 'admin' && $status === 'ready_for_release' => 'release',
    default                                              => 'requests',
});
?>
<nav aria-label="Breadcrumb" class="crumbs"><a href="/requests"><?= $role === 'resident' ? 'My requests' : 'Requests' ?></a> <span aria-hidden="true">/</span> <span class="mono"><?= e($req['control_no']) ?></span></nav>

<?= claim_stub($req['control_no'], ($type['name'] ?? 'Document') . ((int) $req['copies'] > 1 ? ' × ' . (int) $req['copies'] : ''), $status, $note) ?>

<?php if ($isOwner): ?>
  <section class="panel tracker-panel" aria-label="Progress"><?= request_tracker($req, $type, $history) ?></section>
<?php endif; ?>

<?php if ($status === 'rejected' && $req['rejection_reason']): ?>
  <div class="notice notice-danger"><i class="bi bi-x-octagon" aria-hidden="true"></i><div><strong>Reason:</strong> <?= e($req['rejection_reason']) ?></div></div>
<?php endif; ?>

    <?php /* ---------------- Next step for this role ---------------- */ ?>
    <?php
    $panel = '';
    // The Secretary releases at the counter; the Administrator can too, so a
    // claimed document is never stuck when the Secretary is away.
    $releaseForm = '';
    if ($status === 'ready_for_release') {
        $releaseForm = '<form method="post" action="' . e($act) . '" class="row g-2 align-items-end needs-validation" novalidate>' . csrf_field();
        foreach ($hidden + ['action' => 'release'] as $k => $v) {
            $releaseForm .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        $releaseForm .= '<div class="col-md-7"><label class="form-label" for="released_to">Claimed by</label><input class="form-control" id="released_to" name="released_to" required minlength="2" maxlength="120" value="' . e(resident_name($res)) . '"><div class="form-text">Name of the person who received it, after checking their ID.</div></div>';
        $releaseForm .= '<div class="col-md-5"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Mark as released</button></div></form>';
    }
    ob_start();
    if ($role === 'secretary') {
        if ($status === 'pending') {
            echo '<p>Open the review to check the uploaded files.</p>';
            echo action_button($act, $hidden + ['action' => 'start_review'], 'Start review', 'btn-primary', '', false, 'clipboard-check');
        } elseif ($status === 'under_review') {
            $next = (float) $req['fee_amount'] > 0 ? ['to_payment', 'Send for payment', 'cash-coin'] : ['to_processing', 'Move to processing', 'gear'];
            echo '<p>Accept or reject each file below, then move the request on.</p><div class="d-flex flex-wrap gap-2">';
            echo action_button($act, $hidden + ['action' => $next[0]], $next[1], 'btn-primary', '', false, $next[2]);
            echo action_button($act, $hidden + ['action' => 'reject'], 'Reject request', 'btn-outline-danger', 'Reject this request? The resident will see your reason.', true, 'x-circle');
            echo '</div>';
        } elseif ($status === 'processing') {
            if ($missingSignatory) {
                echo $signatoryWarning;
            }
            if ($type['requires_captain_approval']) {
                echo '<p>This document needs the Punong Barangay\'s approval before it can be issued.</p>';
                echo action_button($act, $hidden + ['action' => 'to_approval'], 'Send for approval', 'btn-primary', '', false, 'send');
            } else {
                echo '<p>Issuing assigns a document number and verification code.</p>';
                echo action_button($act, $hidden + ['action' => 'issue'], 'Issue document', 'btn-primary', 'Issue this document now?', false, 'patch-check');
            }
        } elseif ($status === 'ready_for_release') {
            echo $releaseForm;
            if ($issued) {
                echo '<p class="mt-3 mb-0"><a class="btn btn-outline-primary" href="' . e(url('documents/print', ['id' => $issued['id']])) . '"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print certificate</a></p>';
            }
        }
        if (in_array($status, ['pending', 'for_payment'], true) && $req['channel'] === 'walk_in') {
            echo '<div class="mt-3">' . action_button($act, $hidden + ['action' => 'cancel'], 'Cancel walk-in request', 'btn-outline-secondary btn-sm', 'Cancel this walk-in request?') . '</div>';
        }
    } elseif ($role === 'treasurer') {
        if ($status === 'for_payment') {
            echo '<form method="post" action="' . e($act) . '" class="row g-3 needs-validation" novalidate>' . csrf_field();
            foreach ($hidden + ['action' => 'record_payment', 'amount' => $req['fee_amount']] as $k => $v) {
                echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
            }
            echo '<div class="col-md-4"><label class="form-label" for="or_number">OR number</label><input class="form-control mono" id="or_number" name="or_number" required maxlength="30" pattern="[A-Za-z0-9\-]{1,30}" autocomplete="off"></div>';
            echo '<div class="col-md-4"><label class="form-label" for="method">Method</label><select class="form-select" id="method" name="method" required data-toggle-ref="ref-wrap">' . options(PAYMENT_METHODS, 'cash') . '</select></div>';
            echo '<div class="col-md-4"><label class="form-label">Amount</label><p class="form-control-plaintext num fw-bold">' . e(money($req['fee_amount'])) . '</p></div>';
            echo '<div class="col-md-8" id="ref-wrap" hidden><label class="form-label" for="reference_no">Reference number</label><input class="form-control" id="reference_no" name="reference_no" maxlength="60" autocomplete="off"></div>';
            echo '<div class="col-12"><button class="btn btn-primary" type="submit" data-loading-text="Recording…"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Record payment of ' . e(money($req['fee_amount'])) . '</button></div></form>';
        } elseif ($status === 'processing' && $posted) {
            echo '<p>Paid with OR <span class="mono">' . e($posted['or_number']) . '</span>. A payment can be voided while the request is still in Processing.</p>';
            echo action_button($act, ['id' => $id, '_back' => $self, 'action' => 'void_payment', 'payment_id' => $posted['id']], 'Void payment', 'btn-outline-danger', 'Void OR ' . $posted['or_number'] . '? The request returns to For payment.', true, 'arrow-counterclockwise');
        }
    } elseif ($role === 'admin' && $status === 'ready_for_release') {
        echo $releaseForm;
    } elseif ($role === 'captain' && $status === 'for_approval') {
        if ($missingSignatory) {
            echo $signatoryWarning;
        }
        echo '<p>Approving signs off the document under your name and issues it.</p><div class="d-flex flex-wrap gap-2">';
        echo action_button($act, $hidden + ['action' => 'approve'], 'Approve and issue', 'btn-primary', 'Approve and issue this document under your name?', false, 'pen');
        echo action_button($act, $hidden + ['action' => 'reject'], 'Reject', 'btn-outline-danger', 'Reject this request? The resident will see your reason.', true, 'x-circle');
        echo '</div>';
    } elseif ($isOwner) {
        if (in_array($status, ['pending', 'for_payment'], true)) {
            echo '<p>You can cancel while the request is ' . e(strtolower(status_label($status))) . '.</p>';
            echo action_button($act, $hidden + ['action' => 'cancel'], 'Cancel request', 'btn-outline-danger', 'Cancel this request? You can file a new one later.', false, 'x-lg');
        } elseif ($status === 'ready_for_release') {
            echo '<p class="mb-0">Bring a valid ID and your control number <strong class="mono">' . e($req['control_no']) . '</strong> to the barangay hall during office hours: ' . e(setting('office_hours')) . '.</p>';
        }
    }
    $panel = trim((string) ob_get_clean());
    if ($panel !== ''): ?>
      <section class="panel panel-action" aria-labelledby="next-step">
        <h2 id="next-step" class="h5">Next step</h2>
        <?= $panel ?>
      </section>
    <?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">


    <section class="panel">
      <div class="panel-head"><h2>Request details</h2></div>
      <dl class="detail-grid">
        <div><dt>Resident</dt><dd><?php if (is_staff() && $role !== 'treasurer'): ?><a href="<?= e(url('residents/view', ['id' => $res['id']])) ?>"><?= e(resident_name($res)) ?></a><?php else: ?><?= e(resident_name($res)) ?><?php endif; ?></dd></div>
        <div><dt>Purok</dt><dd><?= e(one($res['puroks'])['name'] ?? '') ?></dd></div>
        <div><dt>Document</dt><dd><?= e($type['name'] ?? '') ?></dd></div>
        <div><dt>Copies</dt><dd><?= (int) $req['copies'] ?></dd></div>
        <div><dt>Purpose</dt><dd><?= e($purpose) ?><?= $req['purpose_details'] ? '<small class="d-block text-secondary">' . e($req['purpose_details']) . '</small>' : '' ?></dd></div>
        <div><dt>Fee</dt><dd class="num"><?= $req['fee_waived'] ? 'Waived <small class="d-block text-secondary">' . e($req['waiver_reason']) . '</small>' : e(money($req['fee_amount'])) ?></dd></div>
        <div><dt>Filed</dt><dd><?= e(fmt_datetime($req['submitted_at'])) ?> · <?= $req['channel'] === 'walk_in' ? 'at the counter' : 'online' ?></dd></div>
        <div><dt>Target</dt><dd><?= (int) $type['processing_days'] ?> working day<?= (int) $type['processing_days'] === 1 ? '' : 's' ?></dd></div>
        <?php if (!empty($extra['business_name'])): ?>
          <div><dt>Business</dt><dd><?= e($extra['business_name']) ?><?= !empty($extra['business_nature']) ? ' (' . e($extra['business_nature']) . ')' : '' ?></dd></div>
          <div><dt>Business address</dt><dd><?= e($extra['business_address'] ?? '') ?></dd></div>
        <?php endif; ?>
      </dl>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Requirements and files</h2></div>
      <?php if (!$reqList && !$attachments): ?><p class="mb-0 text-secondary">This document has no requirements.</p><?php endif; ?>
      <ul class="req-review">
        <?php foreach ($reqList as $link): $rq = one($link['requirements']); if (!$rq) { continue; } $files = $byReq[(string) $rq['id']] ?? []; ?>
          <li>
            <div class="req-review-head">
              <strong><?= e($rq['name']) ?></strong>
              <?= $link['is_mandatory'] ? '' : '<span class="optional">optional</span>' ?>
              <?php if (!$rq['accepts_upload']): ?><span class="tag tag-neutral">Checked in person</span><?php endif; ?>
            </div>
            <?php if ($rq['accepts_upload'] && !$files): ?><p class="small text-secondary mb-2">No file uploaded<?= $req['channel'] === 'walk_in' ? ' (walk-in: original seen at the counter)' : '' ?>.</p><?php endif; ?>
            <?php foreach ($files as $a): ?>
              <div class="file-row">
                <a href="<?= e(file_link('request-files', $a['file_path'])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark<?= $a['mime_type'] === 'application/pdf' ? '-pdf' : '-image' ?> me-1" aria-hidden="true"></i><?= e($a['original_name'] ?: 'File') ?></a>
                <small class="text-secondary"><?= e(number_format($a['size_bytes'] / 1024, 0)) ?> KB · <?= e(time_ago($a['uploaded_at'])) ?></small>
                <?= simple_badge(ucfirst($a['review_status']), ['accepted' => 'success', 'rejected' => 'danger'][$a['review_status']] ?? 'neutral') ?>
                <?php if ($role === 'secretary' && in_array($status, ['pending', 'under_review'], true) && $a['review_status'] === 'pending'): ?>
                  <span class="ms-auto d-flex flex-wrap gap-2">
                    <?= action_button($act, $hidden + ['action' => 'accept_file', 'attachment_id' => $a['id']], 'Accept', 'btn-sm btn-outline-success') ?>
                    <?= action_button($act, $hidden + ['action' => 'reject_file', 'attachment_id' => $a['id']], 'Reject', 'btn-sm btn-outline-danger', 'Reject this file? Tell the resident what to fix.', true) ?>
                  </span>
                <?php endif; ?>
              </div>
              <?php if ($a['review_status'] === 'rejected' && $a['review_remarks']): ?><p class="small text-danger fw-semibold mb-1"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>Why it was rejected: <?= e($a['review_remarks']) ?></p><?php endif; ?>
            <?php endforeach; ?>
            <?php
              $needsReplacement = $rq['accepts_upload'] && in_array($status, ['pending', 'under_review'], true)
                  && ($isOwner || $role === 'secretary')
                  && !array_filter($files, static fn ($a) => in_array($a['review_status'], ['pending', 'accepted'], true));
            ?>
            <?php if ($needsReplacement): ?>
              <form method="post" action="<?= e($act) ?>" enctype="multipart/form-data" class="upload-inline needs-validation" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="_back" value="<?= e($self) ?>">
                <input type="hidden" name="action" value="upload_file"><input type="hidden" name="requirement_id" value="<?= e($rq['id']) ?>">
                <label class="visually-hidden" for="up-<?= e($rq['id']) ?>">Upload <?= e($rq['name']) ?></label>
                <input class="form-control form-control-sm" id="up-<?= e($rq['id']) ?>" type="file" name="file" accept="image/jpeg,image/png,application/pdf" required data-max-bytes="2097152">
                <button class="btn btn-sm btn-primary" type="submit" data-loading-text="Uploading…">Upload</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <div class="col-xl-4 order-first order-xl-last">
    <section class="panel">
      <div class="panel-head"><h2>Timeline</h2></div>
      <ol class="timeline">
        <?php foreach ($history as $h): ?>
          <li class="tl-<?= e($h['to_status']) ?>">
            <span class="tl-dot" aria-hidden="true"></span>
            <div>
              <strong><?= e(status_label($h['to_status'])) ?></strong>
              <small class="d-block text-secondary"><?= e(fmt_datetime($h['changed_at'])) ?><?= ($n = one($h['profiles'])['full_name'] ?? null) ? ' · ' . e($n) : '' ?></small>
              <?php if ($h['remarks']): ?><p class="small mb-0"><?= e($h['remarks']) ?></p><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>

    <?php if ($payments): ?>
    <section class="panel">
      <div class="panel-head"><h2>Payment</h2></div>
      <?php foreach ($payments as $p): ?>
        <div class="pay-row<?= $p['status'] === 'voided' ? ' is-void' : '' ?>">
          <div><span class="mono"><?= e($p['or_number']) ?></span> · <?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><small class="d-block text-secondary"><?= e(fmt_datetime($p['paid_at'])) ?></small></div>
          <div class="text-end"><strong class="num"><?= e(money($p['amount'])) ?></strong><?= $p['status'] === 'voided' ? '<small class="d-block text-danger">Voided</small>' : '' ?></div>
        </div>
        <?php if ($p['status'] === 'posted'): ?><a class="small" href="<?= e(url('payments/view', ['id' => $p['id']])) ?>">Payment acknowledgement</a><?php endif; ?>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($issued): ?>
    <section class="panel">
      <div class="panel-head"><h2>Issued document</h2><?= simple_badge($issued['status'] === 'valid' ? 'Valid' : 'Revoked', $issued['status'] === 'valid' ? 'success' : 'danger') ?></div>
      <dl class="detail-grid detail-grid-1">
        <div><dt>Document no.</dt><dd class="mono"><?= e($issued['document_no']) ?></dd></div>
        <div><dt>Verification code</dt><dd class="mono"><?= e($issued['verification_code']) ?></dd></div>
        <div><dt>Valid until</dt><dd><?= e($issued['valid_until'] ? fmt_date($issued['valid_until']) : 'No expiry') ?></dd></div>
      </dl>
      <a class="btn btn-outline-primary btn-sm mt-2" href="<?= e(url('documents/print', ['id' => $issued['id']])) ?>"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i><?= $role === 'secretary' ? 'Open print view' : 'View copy' ?></a>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php layout_end(); ?>
