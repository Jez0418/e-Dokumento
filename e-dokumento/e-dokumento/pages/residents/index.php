<?php
declare(strict_types=1);

$db = Supabase::user();
['page' => $page, 'per' => $per, 'offset' => $offset] = paging(config('app')['per_page']);
[$sort, $dir] = sorting(['last_name', 'birth_date', 'resident_since', 'created_at', 'verification_status'], 'last_name', 'asc');

$f = [
    'q'            => search_term(q('q')),
    'purok'        => q_int('purok') ?: '',
    'verification' => array_key_exists(q('verification'), VERIFICATION_STATUSES) ? q('verification') : '',
    'status'       => array_key_exists(q('status'), RESIDENT_STATUSES) ? q('status') : 'active',
    'voter'        => in_array(q('voter'), ['1', '0'], true) ? q('voter') : '',
    'account'      => in_array(q('account'), ['1', '0'], true) ? q('account') : '',
];
if (q('status') === 'all') {
    $f['status'] = '';
}

$query = [['select', 'id,first_name,middle_name,last_name,suffix,birth_date,sex,resident_since,verification_status,status,is_registered_voter,profile_id,created_at,puroks(name)']];
if ($f['q'] !== '') {
    $t = '"*' . $f['q'] . '*"';
    $query[] = ['or', "(first_name.ilike.{$t},last_name.ilike.{$t},middle_name.ilike.{$t},street_address.ilike.{$t})"];
}
if ($f['purok'] !== '') {
    $query[] = ['purok_id', 'eq.' . $f['purok']];
}
if ($f['verification'] !== '') {
    $query[] = ['verification_status', 'eq.' . $f['verification']];
}
if ($f['status'] !== '') {
    $query[] = ['status', 'eq.' . $f['status']];
}
if ($f['voter'] !== '') {
    $query[] = ['is_registered_voter', 'is.' . ($f['voter'] === '1' ? 'true' : 'false')];
}
if ($f['account'] !== '') {
    $query[] = ['profile_id', $f['account'] === '1' ? 'not.is.null' : 'is.null'];
}
$query[] = ['order', $sort . '.' . $dir . ($sort === 'last_name' ? ',first_name.asc' : '') . ',id.asc'];

try {
    $result = $db->select('residents', $query, true, $offset, $per);
} catch (Throwable $e) {
    $result = ['rows' => [], 'total' => 0];
    flash_error(db_error($e));
}
$puroks = $db->select('puroks', [['select', 'id,name'], ['order', 'name.asc']])['rows'];

layout_start('Residents', 'residents');
page_header('Residents', 'The barangay registry. Residents who sign up online appear here automatically.',
    has_role('secretary') ? '<a class="btn btn-primary" href="/residents/form"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add resident</a>' : '');
?>
<form class="filter-bar" method="get" role="search">
  <div class="filter-search">
    <label class="visually-hidden" for="q">Search residents</label>
    <i class="bi bi-search" aria-hidden="true"></i>
    <input id="q" name="q" type="search" class="form-control" value="<?= e($f['q']) ?>" placeholder="Name or street" maxlength="80">
  </div>
  <div><label class="form-label small" for="purok">Purok</label><select id="purok" name="purok" class="form-select"><?= options(pluck($puroks), $f['purok'], 'All') ?></select></div>
  <div><label class="form-label small" for="verification">Verification</label><select id="verification" name="verification" class="form-select"><?= options(VERIFICATION_STATUSES, $f['verification'], 'Any') ?></select></div>
  <div><label class="form-label small" for="status">Registry status</label><select id="status" name="status" class="form-select"><option value="all"<?= sel('', $f['status']) ?>>Any</option><?= options(RESIDENT_STATUSES, $f['status']) ?></select></div>
  <div><label class="form-label small" for="voter">Voter</label><select id="voter" name="voter" class="form-select"><?= options(['1' => 'Registered', '0' => 'Not registered'], $f['voter'], 'Any') ?></select></div>
  <div><label class="form-label small" for="account">Online account</label><select id="account" name="account" class="form-select"><?= options(['1' => 'Has account', '0' => 'Walk-in only'], $f['account'], 'Any') ?></select></div>
  <div class="filter-actions"><button class="btn btn-primary" type="submit">Apply</button><a class="btn btn-link" href="/residents">Clear</a></div>
</form>

<section class="panel p-0">
  <?php if (!$result['rows']): ?>
    <?= empty_state('No residents match', $f['q'] !== '' ? 'Check the spelling, or search by last name only.' : 'Residents appear here when they register online or when the Secretary encodes them.', has_role('secretary') ? '/residents/form' : '', 'Add resident', 'people') ?>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table data-table">
      <thead><tr>
        <th scope="col"><?= sort_link('Name', 'last_name', $sort, $dir) ?></th>
        <th scope="col">Purok</th>
        <th scope="col"><?= sort_link('Born', 'birth_date', $sort, $dir) ?></th>
        <th scope="col"><?= sort_link('Resident since', 'resident_since', $sort, $dir) ?></th>
        <th scope="col"><?= sort_link('Verification', 'verification_status', $sort, $dir) ?></th>
        <th scope="col">Registry</th>
      </tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $r): $href = url('residents/view', ['id' => $r['id']]); ?>
          <tr data-href="<?= e($href) ?>">
            <td><a href="<?= e($href) ?>"><?= e(resident_name($r, true)) ?></a><?= $r['profile_id'] ? ' <i class="bi bi-person-check text-secondary" title="Has an online account" aria-label="Has an online account"></i>' : '' ?></td>
            <td><?= e(one($r['puroks'])['name'] ?? '') ?></td>
            <td><?= e(fmt_date($r['birth_date'])) ?> <small class="text-secondary">(<?= (int) age_from($r['birth_date']) ?>)</small></td>
            <td><?= e(fmt_date($r['resident_since'], 'M Y')) ?></td>
            <td><?= verification_badge($r['verification_status']) ?></td>
            <td><?= e(RESIDENT_STATUSES[$r['status']] ?? $r['status']) ?><?= $r['is_registered_voter'] ? ' <span class="tag tag-neutral">Voter</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="panel-foot"><?= pagination($result['total'], $page, $per) ?></div>
  <?php endif; ?>
</section>
<?php layout_end(); ?>
