<?php
declare(strict_types=1);

$db = Supabase::user();
$id = q('id');
if (!is_uuid($id)) {
    abort(404);
}
$r = $db->first('residents', [['id', 'eq.' . $id], ['select', '*,puroks(name)']]);
if (!$r) {
    abort(404);
}

if (is_post() && post('action') === 'set_status') {
    require_role('secretary');
    $status = post('status');
    if (array_key_exists($status, RESIDENT_STATUSES)) {
        try {
            $db->update('residents', [['id', 'eq.' . $id]], ['status' => $status]);
            flash_success('Registry status changed to ' . RESIDENT_STATUSES[$status] . '.');
        } catch (Throwable $e) {
            flash_error(db_error($e));
        }
    }
    redirect(url('residents/view', ['id' => $id]));
}

$account = $r['profile_id'] ? $db->first('profiles', [['id', 'eq.' . $r['profile_id']], ['select', 'email,status,last_login_at']]) : null;
$verifications = $db->select('resident_verifications', [
    ['select', 'id,status,id_number,remarks,submitted_at,reviewed_at,front_image_path,back_image_path,id_types(name)'],
    ['resident_id', 'eq.' . $id], ['order', 'submitted_at.desc'],
])['rows'];
$requests = $db->select('request_list', [
    ['select', 'id,control_no,document_type,status,submitted_at,fee_amount'],
    ['resident_id', 'eq.' . $id], ['order', 'submitted_at.desc'],
], false, 0, 50)['rows'];

layout_start(resident_name($r), 'residents');
$actions = '';
if (has_role('secretary')) {
    $actions = '<a class="btn btn-outline-primary" href="' . e(url('residents/form', ['id' => $id])) . '"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>';
    if ($r['status'] === 'active') {
        $actions .= ' <a class="btn btn-primary" href="' . e(url('requests/new', ['resident_id' => $id])) . '"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Walk-in request</a>';
    }
}
page_header(resident_name($r), (one($r['puroks'])['name'] ?? '') . ', ' . $r['street_address'], $actions);
?>
<nav aria-label="Breadcrumb" class="crumbs"><a href="/residents">Residents</a> <span aria-hidden="true">/</span> <?= e(resident_name($r)) ?></nav>
<div class="row g-4">
  <div class="col-lg-7">
    <section class="panel">
      <div class="panel-head"><h2>Record</h2><?= verification_badge($r['verification_status']) ?></div>
      <dl class="detail-grid">
        <div><dt>Full name</dt><dd><?= e(trim($r['first_name'] . ' ' . ($r['middle_name'] ?? '') . ' ' . $r['last_name'] . ' ' . ($r['suffix'] ?? ''))) ?></dd></div>
        <div><dt>Born</dt><dd><?= e(fmt_date($r['birth_date'])) ?> (<?= (int) age_from($r['birth_date']) ?> years)</dd></div>
        <div><dt>Sex</dt><dd><?= e(ucfirst($r['sex'])) ?></dd></div>
        <div><dt>Civil status</dt><dd><?= e(CIVIL_STATUSES[$r['civil_status']] ?? '') ?></dd></div>
        <div><dt>Resident since</dt><dd><?= e(fmt_date($r['resident_since'], 'F Y')) ?></dd></div>
        <div><dt>Occupation</dt><dd><?= e($r['occupation'] ?: '—') ?></dd></div>
        <div><dt>Mobile</dt><dd><?= e($r['contact_no'] ?: '—') ?></dd></div>
        <div><dt>Email</dt><dd><?= e($r['email'] ?: '—') ?></dd></div>
        <div><dt>Voter</dt><dd><?= $r['is_registered_voter'] ? 'Registered' : 'Not registered' ?></dd></div>
        <div><dt>Registry status</dt><dd><?= e(RESIDENT_STATUSES[$r['status']] ?? $r['status']) ?></dd></div>
        <div><dt>Online account</dt><dd><?= $account ? e($account['email']) . '<small class="d-block text-secondary">Last sign-in ' . e(fmt_datetime($account['last_login_at'])) . '</small>' : 'None (walk-in only)' ?></dd></div>
        <div><dt>Verified</dt><dd><?= $r['verified_at'] ? e(fmt_datetime($r['verified_at'])) : '—' ?></dd></div>
      </dl>
      <?php if (has_role('secretary')): ?>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end mt-3" data-confirm="Change this resident's registry status?">
          <?= csrf_field() ?><input type="hidden" name="action" value="set_status">
          <div><label class="form-label small" for="status">Registry status</label><select class="form-select form-select-sm" id="status" name="status"><?= options(RESIDENT_STATUSES, $r['status']) ?></select></div>
          <button class="btn btn-sm btn-outline-secondary" type="submit">Update status</button>
        </form>
        <p class="small text-secondary mt-2 mb-0">Residents are never deleted, so their request history stays intact. Mark them as moved out or deceased instead.</p>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Requests</h2><span class="text-secondary small"><?= count($requests) ?></span></div>
      <?php if (!$requests): ?>
        <p class="text-secondary mb-0">No requests yet.</p>
      <?php else: ?>
        <ul class="stub-list">
          <?php foreach ($requests as $q): ?>
            <li><a href="<?= e(url('requests/view', ['id' => $q['id']])) ?>"><span class="mono stub-mini"><?= e($q['control_no']) ?></span><span class="flex-grow-1"><?= e($q['document_type']) ?><small class="d-block text-secondary"><?= e(fmt_date($q['submitted_at'])) ?></small></span><?= status_badge($q['status']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
  <div class="col-lg-5">
    <section class="panel">
      <div class="panel-head"><h2>ID submissions</h2></div>
      <?php if (!$verifications): ?>
        <p class="text-secondary mb-0"><?= $r['profile_id'] ? 'The resident has not uploaded an ID yet.' : 'Walk-in residents are verified in person when the Secretary files their first request.' ?></p>
      <?php else: ?>
        <ul class="history-list">
          <?php foreach ($verifications as $ver): ?>
            <li>
              <strong><?= e(one($ver['id_types'])['name'] ?? 'ID') ?></strong> <span class="mono small"><?= e($ver['id_number']) ?></span>
              <?= simple_badge(ucfirst($ver['status']), ['approved' => 'success', 'rejected' => 'danger'][$ver['status']] ?? 'neutral') ?>
              <small class="d-block text-secondary">Submitted <?= e(fmt_datetime($ver['submitted_at'])) ?></small>
              <a href="<?= e(file_link('verification-ids', $ver['front_image_path'])) ?>" target="_blank" rel="noopener">Front</a>
              <?php if ($ver['back_image_path']): ?> · <a href="<?= e(file_link('verification-ids', $ver['back_image_path'])) ?>" target="_blank" rel="noopener">Back</a><?php endif; ?>
              <?php if ($ver['remarks']): ?><p class="small mb-0"><?= e($ver['remarks']) ?></p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php layout_end(); ?>
