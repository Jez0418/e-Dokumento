<?php
declare(strict_types=1);

$db = Supabase::user();

if (is_post()) {
    $vid = post('verification_id');
    $decision = post('decision');
    $reason = post('reason');
    if (!is_uuid($vid) || !in_array($decision, ['approved', 'rejected'], true)) {
        abort(400);
    }
    if ($decision === 'rejected' && (mb_strlen($reason) < 10 || mb_strlen($reason) > 500)) {
        flash_error('Give a reason of 10 to 500 characters so the resident knows what to fix.');
        redirect('/verifications');
    }
    try {
        $db->rpc('review_verification', ['p_verification_id' => $vid, 'p_decision' => $decision, 'p_remarks' => $decision === 'rejected' ? $reason : null]);
        flash_success($decision === 'approved' ? 'Resident verified. They can now request documents online.' : 'Submission rejected. The resident was told why.');
    } catch (Throwable $e) {
        flash_error(db_error($e));
    }
    redirect('/verifications');
}

$status = in_array(q('status'), ['pending', 'approved', 'rejected'], true) ? q('status') : 'pending';
['page' => $page, 'per' => $per, 'offset' => $offset] = paging(12);
$result = $db->select('resident_verifications', [
    ['select', 'id,status,id_number,remarks,submitted_at,reviewed_at,front_image_path,back_image_path,id_types(name),residents(id,first_name,middle_name,last_name,suffix,birth_date,street_address,resident_since,puroks(name))'],
    ['status', 'eq.' . $status],
    ['order', 'submitted_at.' . ($status === 'pending' ? 'asc' : 'desc')],
], true, $offset, $per);

layout_start('Verifications', 'verifications');
page_header('Verifications', 'Compare each ID with the resident record. Approve only when the name and birth date match.');
?>
<nav class="status-tabs" aria-label="Filter by status">
  <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $label): ?>
    <a href="?status=<?= e($k) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!$result['rows']): ?>
  <section class="panel"><?= empty_state($status === 'pending' ? 'No IDs waiting' : 'Nothing here yet', $status === 'pending' ? 'New submissions appear here, oldest first.' : 'Reviewed submissions are listed here.', '', '', 'person-check') ?></section>
<?php else: ?>
  <div class="verify-grid">
    <?php foreach ($result['rows'] as $ver): $res = one($ver['residents']); ?>
      <article class="panel verify-card">
        <header>
          <h2 class="h5 mb-0"><a href="<?= e(url('residents/view', ['id' => $res['id']])) ?>"><?= e(resident_name($res, true)) ?></a></h2>
          <small class="text-secondary">Submitted <?= e(time_ago($ver['submitted_at'])) ?></small>
        </header>
        <dl class="detail-grid detail-grid-1">
          <div><dt>Born</dt><dd><?= e(fmt_date($res['birth_date'])) ?></dd></div>
          <div><dt>Address</dt><dd><?= e($res['street_address']) ?>, <?= e(one($res['puroks'])['name'] ?? '') ?></dd></div>
          <div><dt>Resident since</dt><dd><?= e(fmt_date($res['resident_since'], 'F Y')) ?></dd></div>
          <div><dt>ID</dt><dd><?= e(one($ver['id_types'])['name'] ?? '') ?> <span class="mono"><?= e($ver['id_number']) ?></span></dd></div>
        </dl>
        <p class="mb-3">
          <a class="btn btn-sm btn-outline-primary" href="<?= e(file_link('verification-ids', $ver['front_image_path'])) ?>" target="_blank" rel="noopener"><i class="bi bi-image me-1" aria-hidden="true"></i>Open front</a>
          <?php if ($ver['back_image_path']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(file_link('verification-ids', $ver['back_image_path'])) ?>" target="_blank" rel="noopener">Open back</a><?php endif; ?>
        </p>
        <?php if ($ver['status'] === 'pending'): ?>
          <div class="d-flex gap-2">
            <?= action_button('/verifications', ['verification_id' => $ver['id'], 'decision' => 'approved'], 'Approve', 'btn-primary', 'Approve this resident? They will be able to request documents online.', false, 'check2') ?>
            <?= action_button('/verifications', ['verification_id' => $ver['id'], 'decision' => 'rejected'], 'Reject', 'btn-outline-danger', 'Reject this ID? Tell the resident what to fix.', true, 'x') ?>
          </div>
        <?php elseif ($ver['remarks']): ?>
          <p class="small mb-0"><strong>Reason:</strong> <?= e($ver['remarks']) ?></p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?= pagination($result['total'], $page, $per) ?>
<?php endif; ?>
<?php layout_end(); ?>
