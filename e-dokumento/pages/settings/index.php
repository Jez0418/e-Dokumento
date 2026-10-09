<?php
declare(strict_types=1);

$db = Supabase::user();
$fields = [
    'barangay_name'     => ['Barangay name', 'Without the word "Barangay", e.g. San Isidro', 2, 80, true],
    'city_municipality' => ['City or municipality', 'As printed on certificates, e.g. City of Urdaneta', 2, 80, true],
    'province'          => ['Province', '', 2, 80, true],
    'region'            => ['Region', '', 0, 80, false],
    'hall_address'      => ['Barangay hall address', '', 0, 200, false],
    'contact_number'    => ['Public contact number', '', 0, 40, false],
    'contact_email'     => ['Public contact email', '', 0, 120, false],
    'office_hours'      => ['Office hours', 'Shown on the sign-in page and to residents', 0, 120, false],
    'control_prefix'    => ['Control number prefix', '2 to 6 capital letters; applies to new requests', 2, 6, true],
];
$current = [];
$emailOn = false;
foreach ($db->select('system_settings', [['select', 'key,value,updated_at']])['rows'] as $row) {
    $current[$row['key']] = is_scalar($row['value']) ? (string) $row['value'] : '';
    if ($row['key'] === 'email_notifications_enabled') {
        $emailOn = $row['value'] === true;
    }
}
$errors = [];
$old = is_post() ? $_POST : $current;
$form = post('form');

if (is_post() && $form === 'email_toggle') {
    $on = isset($_POST['email_notifications_enabled']);
    try {
        $db->upsert('system_settings', [[
            'key' => 'email_notifications_enabled', 'value' => $on,
            'description' => 'Email residents when a request needs them to act: true or false', 'updated_by' => Auth::id(),
        ]], 'key');
        flash_success($on ? 'Email notifications turned on.' : 'Email notifications turned off.');
    } catch (Throwable $e) {
        flash_error(db_error($e));
    }
    redirect('/settings');
}

if (is_post() && $form === 'email_test') {
    $to = (string) (Auth::user()['email'] ?? '');
    try {
        send_mail($to, 'e-Dokumento test email', "This is a test from the Settings page.\nIf you can read this, status emails will work.");
        flash_success("Test email sent to {$to}.");
    } catch (Throwable $e) {
        flash_error('Test email failed: ' . $e->getMessage());
    }
    redirect('/settings');
}

if (is_post() && $form === 'email_retry') {
    $id = post('id');
    if (!is_uuid($id)) {
        flash_error('That email could not be found.');
    } else {
        try {
            $db->rpc('retry_outbox_email', ['p_id' => $id]);
            flash_success('Email queued again. It will be sent after the next staff action.');
        } catch (Throwable $e) {
            flash_error(db_error($e));
        }
    }
    redirect('/settings');
}

if (is_post()) {
    $v = new Validator($_POST);
    $values = [];
    foreach ($fields as $key => [$label, , $min, $max, $required]) {
        $values[$key] = (string) ($key === 'contact_email' ? $v->email($key, $label, false) : $v->text($key, $label, $required, $min, $max));
    }
    $values['control_prefix'] = strtoupper($values['control_prefix']);
    if (!preg_match('/^[A-Z]{2,6}$/', $values['control_prefix'])) {
        $v->error('control_prefix', 'Use 2 to 6 capital letters.');
    }
    if (!$v->fails()) {
        try {
            $rows = [];
            foreach ($values as $key => $val) {
                $rows[] = ['key' => $key, 'value' => $val, 'description' => $fields[$key][0], 'updated_by' => Auth::id()];
            }
            $db->upsert('system_settings', $rows, 'key');
            flash_success('Settings saved.');
            redirect('/settings');
        } catch (Throwable $e) {
            $errors['_form'] = db_error($e);
        }
    } else {
        $errors = $v->errors();
    }
}

// null when sql/09_email_outbox.sql has not been applied: the card explains instead of failing.
$outbox = null;
try {
    $outbox = $db->select('email_outbox', [
        ['select', 'id,to_email,kind,status,attempts,last_error,created_at,document_requests(control_no)'],
        ['order', 'created_at.desc'],
        ['limit', '50'],
    ])['rows'];
} catch (Throwable $e) {
    error_log('settings outbox: ' . $e->getMessage());
}
$mailReady = mail_configured();
$kinds = ['for_payment' => 'For payment', 'ready_for_release' => 'Ready for release', 'rejected' => 'Rejected'];
$tones = ['queued' => 'neutral', 'sending' => 'info', 'sent' => 'success', 'failed' => 'danger'];

layout_start('Settings', 'settings');
page_header('Settings', 'Barangay details printed on certificates and shown to residents.');
?>
<?= form_errors($errors) ?>
<form method="post" class="panel needs-validation" novalidate>
  <?= csrf_field() ?>
  <div class="row g-3">
    <?php foreach ($fields as $key => [$label, $hint, $min, $max, $required]): ?>
      <div class="col-md-6">
        <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?><?= $required ? '' : ' <span class="optional">optional</span>' ?></label>
        <input class="form-control<?= invalid($errors, $key) ?><?= $key === 'control_prefix' ? ' mono' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e(old($old, $key)) ?>" maxlength="<?= (int) $max ?>"<?= $required ? ' required' : '' ?><?= $key === 'contact_email' ? ' type="email"' : '' ?>>
        <?php if ($hint): ?><div class="form-text"><?= e($hint) ?></div><?php endif; ?>
        <?= field_error($errors, $key) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-primary mt-4" type="submit" data-loading-text="Saving…">Save settings</button>
</form>

<section class="panel mt-4" aria-labelledby="email-heading">
  <div class="panel-head"><h2 id="email-heading">Email notifications</h2></div>
  <?php if ($outbox === null): ?>
    <p class="text-secondary mb-0">Status emails need <code>sql/09_email_outbox.sql</code>. Run it in the Supabase SQL Editor, then reload this page.</p>
  <?php else: ?>
    <?php if ($emailOn && !$mailReady): ?>
      <div class="alert alert-warning" role="alert">Email is switched on, but MAIL_USERNAME and MAIL_APP_PASSWORD are not set in Vercel. Emails will wait in the queue.</div>
    <?php endif; ?>
    <form method="post" action="/settings" class="d-flex flex-wrap align-items-center gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="email_toggle">
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="email_notifications_enabled" name="email_notifications_enabled" value="1"<?= chk($emailOn) ?>>
        <label class="form-check-label" for="email_notifications_enabled">Email residents when a request needs payment, is ready for pickup, or is rejected</label>
      </div>
      <button class="btn btn-primary" type="submit" data-loading-text="Saving…">Save</button>
    </form>
    <form method="post" action="/settings" class="mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="email_test">
      <button class="btn btn-outline-secondary" type="submit" data-loading-text="Sending…"<?= $mailReady ? '' : ' disabled aria-describedby="email-test-hint"' ?>>Send test email</button>
      <?php if (!$mailReady): ?><div class="form-text" id="email-test-hint">Set MAIL_USERNAME and MAIL_APP_PASSWORD first.</div><?php endif; ?>
    </form>
  <?php endif; ?>
</section>

<?php if ($outbox !== null): ?>
<section class="panel p-0 mt-3" aria-label="Latest emails">
  <?php if (!$outbox): ?>
    <?= empty_state('No emails yet', 'Emails appear here once the switch is on and a request needs the resident to act.', '', '', 'envelope') ?>
  <?php else: ?>
    <div class="table-responsive"><table class="table data-table">
      <thead><tr><th scope="col">Created</th><th scope="col">Recipient</th><th scope="col">Request</th><th scope="col">Kind</th><th scope="col">Status</th><th scope="col">Attempts</th><th scope="col">Last error</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($outbox as $o): $req = one($o['document_requests'] ?? null); ?>
        <tr>
          <td class="text-nowrap"><?= e(fmt_datetime($o['created_at'])) ?></td>
          <td><?= e($o['to_email']) ?></td>
          <td class="mono"><?= e($req['control_no'] ?? '—') ?></td>
          <td><?= e($kinds[$o['kind']] ?? $o['kind']) ?></td>
          <td><?= simple_badge(ucfirst((string) $o['status']), $tones[$o['status']] ?? 'neutral') ?></td>
          <td><?= e($o['attempts']) ?></td>
          <td class="small text-secondary"><?= e($o['last_error'] ?? '') ?></td>
          <td><?= $o['status'] === 'failed' ? action_button('/settings', ['form' => 'email_retry', 'id' => $o['id']], 'Retry', 'btn-sm btn-outline-primary') : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php layout_end(); ?>
