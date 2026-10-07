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
foreach ($db->select('system_settings', [['select', 'key,value,updated_at']])['rows'] as $row) {
    $current[$row['key']] = is_scalar($row['value']) ? (string) $row['value'] : '';
}
$errors = [];
$old = is_post() ? $_POST : $current;

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
<?php layout_end(); ?>
