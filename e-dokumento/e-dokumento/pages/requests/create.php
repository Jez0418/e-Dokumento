<?php
declare(strict_types=1);

$db = Supabase::user();
$role = Auth::role();
$isSecretary = $role === 'secretary';
$errors = [];
$old = $_POST;

// Who the request is for -------------------------------------------------
$resident = null;
if ($isSecretary) {
    $rid = is_post() ? post('resident_id') : q('resident_id');
    if (is_uuid($rid)) {
        $resident = $db->first('residents', [['id', 'eq.' . $rid], ['select', '*,puroks(name)']]);
    }
} else {
    $resident = Auth::resident();
}

// Catalogue -------------------------------------------------------------
$types = $db->select('document_types', [
    ['select', 'id,code,name,description,fee,processing_days,validity_days,requires_captain_approval,min_residency_months,once_per_lifetime,max_copies,template_key,document_type_requirements(requirement_id,is_mandatory,sort_order,requirements(id,name,description,accepts_upload,is_active))'],
    ['is_active', 'eq.true'],
    ['order', 'name.asc'],
])['rows'];
$typesById = [];
foreach ($types as &$t) {
    $reqs = $t['document_type_requirements'] ?? [];
    usort($reqs, static fn ($a, $b) => ($a['sort_order'] <=> $b['sort_order']));
    $t['reqs'] = array_values(array_filter(array_map(static function ($x) {
        $rq = one($x['requirements']);
        return $rq ? $rq + ['is_mandatory' => (bool) $x['is_mandatory']] : null;
    }, $reqs)));
    $typesById[(string) $t['id']] = $t;
}
unset($t);
$purposes = $db->select('purposes', [['select', 'id,name'], ['is_active', 'eq.true'], ['order', 'name.asc']])['rows'];

// Submit -------------------------------------------------------------------
if (is_post() && $resident) {
    $v = new Validator($_POST);
    $typeId = $v->in('document_type_id', 'a document', array_keys($typesById));
    $purposeId = $v->in('purpose_id', 'a purpose', array_map(static fn ($p) => (string) $p['id'], $purposes));
    $details = $v->text('purpose_details', 'Purpose details', false, 0, 255);
    $type = $typeId ? $typesById[$typeId] : null;
    $copies = $v->integer('copies', 'Copies', true, 1, (int) ($type['max_copies'] ?? 1));
    $extra = [];
    if ($type && $type['template_key'] === 'business') {
        $extra['business_name'] = $v->text('business_name', 'Business name', true, 2, 120);
        $extra['business_address'] = $v->text('business_address', 'Business address', true, 3, 200);
        $extra['business_nature'] = $v->text('business_nature', 'Nature of business', false, 0, 120);
    }
    $waived = $isSecretary && $v->bool('fee_waived');
    $waiverReason = $waived ? $v->text('waiver_reason', 'Waiver reason', true, 10, 500) : null;

    // Files per requirement
    $files = Upload::group('req_file');
    $checked = [];
    if ($type) {
        foreach ($type['reqs'] as $rq) {
            if (!$rq['accepts_upload']) {
                continue;
            }
            $required = $rq['is_mandatory'] && !$isSecretary; // the Secretary sees originals at the counter
            $res = Upload::check($files[(string) $rq['id']] ?? null, $rq['name'], $required);
            if (is_string($res)) {
                $v->error('req_file_' . $rq['id'], $res);
            } elseif (is_array($res)) {
                $checked[(string) $rq['id']] = $res;
            }
        }
    }

    if (!$v->fails()) {
        $uploaded = [];
        $attachments = [];
        try {
            foreach ($checked as $reqId => $file) {
                $path = Upload::store($db, 'request-files', $resident['id'], $file);
                $uploaded[] = $path;
                $attachments[] = ['requirement_id' => (int) $reqId, 'path' => $path, 'name' => $file['name'], 'mime' => $file['mime'], 'size' => $file['size']];
            }
            $res = $db->rpc('submit_request', [
                'p_resident_id'      => $resident['id'],
                'p_document_type_id' => (int) $typeId,
                'p_purpose_id'       => (int) $purposeId,
                'p_purpose_details'  => $details,
                'p_copies'           => $copies,
                'p_extra'            => (object) array_filter($extra, static fn ($x) => $x !== null && $x !== ''),
                'p_attachments'      => $attachments,
                'p_fee_waived'       => $waived,
                'p_waiver_reason'    => $waiverReason,
            ]);
            flash_success('Request ' . ($res['control_no'] ?? '') . ' submitted. Keep this control number.');
            redirect(url('requests/view', ['id' => $res['id'], 'new' => 1]));
        } catch (Throwable $e) {
            try {
                $db->removeObjects('request-files', $uploaded);
            } catch (Throwable) {
            }
            $errors['_form'] = db_error($e);
        }
    } else {
        $errors = $v->errors();
    }
}

// Secretary: resident search ------------------------------------------------
$matches = [];
$rq = search_term(q('rq'));
if ($isSecretary && !$resident && $rq !== '') {
    $term = '"*' . $rq . '*"';
    $matches = $db->select('residents', [
        ['select', 'id,first_name,middle_name,last_name,suffix,birth_date,verification_status,status,puroks(name)'],
        ['or', "(first_name.ilike.{$term},last_name.ilike.{$term},middle_name.ilike.{$term})"],
        ['status', 'eq.active'],
        ['order', 'last_name.asc,first_name.asc'],
    ], false, 0, 20)['rows'];
}

layout_start($isSecretary ? 'Walk-in request' : 'Request a document', 'requests-new');

if ($isSecretary):
    page_header('Walk-in request', 'Encode a request for a resident at the counter. Check their ID in person first.');
    if (!$resident): ?>
    <section class="panel">
      <form method="get" class="row g-2 align-items-end" role="search">
        <div class="col-md-8">
          <label class="form-label" for="rq">Find the resident</label>
          <input class="form-control form-control-lg" id="rq" name="rq" value="<?= e($rq) ?>" placeholder="Last name or first name" maxlength="80" autofocus>
        </div>
        <div class="col-md-4 d-flex gap-2">
          <button class="btn btn-primary btn-lg flex-grow-1" type="submit">Search</button>
          <a class="btn btn-outline-primary btn-lg" href="/residents/form?then=request">New resident</a>
        </div>
      </form>
      <?php if ($rq !== ''): ?>
        <?php if (!$matches): ?>
          <p class="mt-3 mb-0">No active resident matches "<?= e($rq) ?>". <a href="/residents/form?then=request">Encode them as a new resident</a>.</p>
        <?php else: ?>
          <ul class="pick-list mt-3">
            <?php foreach ($matches as $m): ?>
              <li>
                <div><strong><?= e(resident_name($m, true)) ?></strong><small class="d-block text-secondary">Born <?= e(fmt_date($m['birth_date'])) ?> · <?= e(one($m['puroks'])['name'] ?? '') ?></small></div>
                <?= verification_badge($m['verification_status']) ?>
                <a class="btn btn-sm btn-primary" href="<?= e(url('requests/new', ['resident_id' => $m['id']])) ?>">Select</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php layout_end(); return; endif;
else:
    page_header('Request a document', 'Choose the document, tell us what it is for, and upload the required files.');
    if (!$resident || $resident['verification_status'] !== 'verified'):
        echo empty_state('Verify your residency first', 'Online requests open once the Secretary approves your ID. It usually takes one working day.', '/profile#verification', 'Go to verification', 'person-vcard');
        layout_end();
        return;
    endif;
endif;
?>
<?= form_errors($errors) ?>
<form method="post" enctype="multipart/form-data" novalidate class="needs-validation request-form" data-max-total="4000000">
  <?= csrf_field() ?>
  <?php if ($isSecretary): ?><input type="hidden" name="resident_id" value="<?= e($resident['id']) ?>"><?php endif; ?>

  <div class="row g-4">
    <div class="col-xl-8">
      <?php if ($isSecretary): ?>
        <section class="panel">
          <div class="panel-head"><h2>Resident</h2><a href="/requests/new">Change</a></div>
          <p class="mb-0"><strong><?= e(resident_name($resident)) ?></strong>, born <?= e(fmt_date($resident['birth_date'])) ?>, <?= e(one($resident['puroks'])['name'] ?? '') ?>. <?= verification_badge($resident['verification_status']) ?></p>
          <?php if ($resident['verification_status'] !== 'verified'): ?><p class="small text-secondary mt-2 mb-0">Submitting marks this resident as verified by you, so check their ID at the counter first.</p><?php endif; ?>
        </section>
      <?php endif; ?>

      <section class="panel">
        <div class="panel-head"><h2>1. Choose a document</h2></div>
        <?= field_error($errors, 'document_type_id') ?>
        <div class="doc-choices" role="radiogroup" aria-label="Document type">
          <?php foreach ($types as $t): ?>
            <label class="doc-choice">
              <input type="radio" name="document_type_id" value="<?= e($t['id']) ?>" required<?= chk(old($old, 'document_type_id') === (string) $t['id']) ?>
                     data-fee="<?= e($t['fee']) ?>" data-max="<?= e($t['max_copies']) ?>" data-template="<?= e($t['template_key']) ?>">
              <span class="doc-choice-body">
                <span class="doc-choice-name"><?= e($t['name']) ?></span>
                <span class="doc-choice-meta"><?= (float) $t['fee'] > 0 ? e(money($t['fee'])) . ' per copy' : 'Free' ?> · ready in <?= (int) $t['processing_days'] ?: 'same' ?> <?= (int) $t['processing_days'] === 0 ? 'day' : ((int) $t['processing_days'] === 1 ? 'working day' : 'working days') ?></span>
                <?php if ($t['description']): ?><span class="doc-choice-desc"><?= e($t['description']) ?></span><?php endif; ?>
                <?php if ($t['once_per_lifetime']): ?><span class="tag tag-warning mt-1">Issued once only</span><?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>2. Purpose</h2></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="purpose_id">What is it for?</label>
            <select class="form-select<?= invalid($errors, 'purpose_id') ?>" id="purpose_id" name="purpose_id" required><?= options(pluck($purposes), old($old, 'purpose_id'), 'Choose') ?></select>
            <?= field_error($errors, 'purpose_id') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="copies">Copies</label>
            <input class="form-control<?= invalid($errors, 'copies') ?>" id="copies" name="copies" type="number" min="1" max="3" value="<?= e(old($old, 'copies', '1')) ?>" required>
            <?= field_error($errors, 'copies') ?>
          </div>
          <div class="col-12">
            <label class="form-label" for="purpose_details">Details <span class="optional">optional</span></label>
            <input class="form-control<?= invalid($errors, 'purpose_details') ?>" id="purpose_details" name="purpose_details" value="<?= e(old($old, 'purpose_details')) ?>" maxlength="255" placeholder="For example: requirement of ABC Company for job application">
            <?= field_error($errors, 'purpose_details') ?>
          </div>
        </div>
        <div class="business-fields row g-3 mt-1" hidden>
          <div class="col-md-6"><label class="form-label" for="business_name">Business name</label><input class="form-control<?= invalid($errors, 'business_name') ?>" id="business_name" name="business_name" value="<?= e(old($old, 'business_name')) ?>" maxlength="120"><?= field_error($errors, 'business_name') ?></div>
          <div class="col-md-6"><label class="form-label" for="business_nature">Nature of business <span class="optional">optional</span></label><input class="form-control" id="business_nature" name="business_nature" value="<?= e(old($old, 'business_nature')) ?>" maxlength="120" placeholder="Sari-sari store, carinderia…"></div>
          <div class="col-12"><label class="form-label" for="business_address">Business address</label><input class="form-control<?= invalid($errors, 'business_address') ?>" id="business_address" name="business_address" value="<?= e(old($old, 'business_address')) ?>" maxlength="200"><?= field_error($errors, 'business_address') ?></div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>3. Requirements</h2></div>
        <p class="text-secondary choose-first">Choose a document to see what to upload.</p>
        <?php foreach ($types as $t): ?>
          <div class="req-group" data-type="<?= e($t['id']) ?>" hidden>
            <?php if (!$t['reqs']): ?><p class="mb-0">No files needed for this document.</p><?php endif; ?>
            <?php foreach ($t['reqs'] as $rq): $fid = 'req_' . $t['id'] . '_' . $rq['id']; ?>
              <div class="req-item">
                <div class="req-text">
                  <strong><?= e($rq['name']) ?></strong>
                  <?= $rq['is_mandatory'] ? '' : '<span class="optional">optional</span>' ?>
                  <?php if ($rq['description']): ?><small class="d-block text-secondary"><?= e($rq['description']) ?></small><?php endif; ?>
                </div>
                <?php if ($rq['accepts_upload']): ?>
                  <div class="req-input">
                    <label class="visually-hidden" for="<?= e($fid) ?>">Upload <?= e($rq['name']) ?></label>
                    <input class="form-control<?= invalid($errors, 'req_file_' . $rq['id']) ?>" type="file" id="<?= e($fid) ?>" name="req_file[<?= e($rq['id']) ?>]" accept="image/jpeg,image/png,application/pdf" data-max-bytes="2097152"<?= $rq['is_mandatory'] && !$isSecretary ? ' data-required="1"' : '' ?> disabled>
                    <?= field_error($errors, 'req_file_' . $rq['id']) ?>
                  </div>
                <?php else: ?>
                  <span class="tag tag-neutral">Bring in person</span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <p class="form-text mb-0">JPG, PNG or PDF, up to 2 MB each and 4 MB in total.</p>
      </section>

      <?php if ($isSecretary): ?>
      <section class="panel">
        <div class="panel-head"><h2>4. Fee waiver</h2></div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="fee_waived" name="fee_waived" value="1"<?= chk(isset($old['fee_waived'])) ?>>
          <label class="form-check-label" for="fee_waived">Waive the fee for this request</label>
        </div>
        <label class="form-label" for="waiver_reason">Basis for the waiver</label>
        <input class="form-control<?= invalid($errors, 'waiver_reason') ?>" id="waiver_reason" name="waiver_reason" value="<?= e(old($old, 'waiver_reason')) ?>" maxlength="500" placeholder="For example: indigent resident per social worker's assessment">
        <?= field_error($errors, 'waiver_reason') ?>
      </section>
      <?php endif; ?>
    </div>

    <div class="col-xl-4">
      <aside class="panel summary-panel">
        <h2 class="h5">Summary</h2>
        <dl class="summary-list">
          <div><dt>Document</dt><dd id="sum-doc">—</dd></div>
          <div><dt>Copies</dt><dd id="sum-copies">1</dd></div>
          <div><dt>Fee</dt><dd id="sum-fee" class="num">—</dd></div>
        </dl>
        <p class="small text-secondary">Pay at the Treasurer's window when the request reaches <em>For payment</em>. You'll get a notification.</p>
        <button class="btn btn-primary btn-lg w-100" type="submit" data-loading-text="Submitting…">Submit request</button>
      </aside>
    </div>
  </div>
</form>
<?php layout_end(['request-form.js']); ?>
