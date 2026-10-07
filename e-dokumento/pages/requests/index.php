<?php
declare(strict_types=1);

$db = Supabase::user();
$role = Auth::role();
$isResident = $role === 'resident';

['page' => $page, 'per' => $per, 'offset' => $offset] = paging(config('app')['per_page']);
[$sort, $dir] = sorting(['submitted_at', 'control_no', 'resident_name', 'document_type', 'status', 'updated_at'], 'submitted_at');

$f = [
    'q'      => search_term(q('q')),
    'status' => array_key_exists(q('status'), REQUEST_STATUSES) ? q('status') : '',
    'type'   => q_int('type') ?: '',
    'purok'  => q_int('purok') ?: '',
    'channel'=> in_array(q('channel'), ['online', 'walk_in'], true) ? q('channel') : '',
    'from'   => is_date(q('from')) ? q('from') : '',
    'to'     => is_date(q('to')) ? q('to') : '',
    'overdue'=> q('overdue') === '1',
    'open'   => q('open') === '1',
];

// Every filter except the status tab; the tab counts are taken over these
$base = [];
if ($f['q'] !== '') {
    $base[] = ['search_text', 'ilike.*' . mb_strtolower($f['q']) . '*'];
}
if ($f['type'] !== '') {
    $base[] = ['document_type_id', 'eq.' . $f['type']];
}
if ($f['purok'] !== '') {
    $base[] = ['purok_id', 'eq.' . $f['purok']];
}
if ($f['channel'] !== '') {
    $base[] = ['channel', 'eq.' . $f['channel']];
}
if ($f['from'] !== '') {
    $base[] = ['submitted_at', 'gte.' . $f['from'] . 'T00:00:00+08:00'];
}
if ($f['to'] !== '') {
    $base[] = ['submitted_at', 'lte.' . $f['to'] . 'T23:59:59+08:00'];
}
if ($f['overdue']) {
    $base[] = ['is_overdue', 'is.true'];
}

$query = array_merge([['select', '*']], $base);
if ($f['status'] !== '') {
    $query[] = ['status', 'eq.' . $f['status']];
}
if ($f['open']) {
    $query[] = ['status', 'not.in.(released,rejected,cancelled)'];
}
$query[] = ['order', $sort . '.' . $dir . ',id.asc'];

try {
    $result = $db->select('request_list', $query, true, $offset, $per);
    $rows = $result['rows'];
    $total = $result['total'];
} catch (Throwable $e) {
    $rows = [];
    $total = 0;
    flash_error(db_error($e));
}

// Counts for the status tabs: one light query over the same filters. Skipped on very large
// result sets, where the tabs simply show no numbers.
$tabCountLimit = 2000;
$tabCounts = null;
try {
    $countResult = $db->select('request_list', array_merge([['select', 'status']], $base, [['order', 'id.asc']]), true, 0, $tabCountLimit);
    if (is_int($countResult['total']) && $countResult['total'] <= $tabCountLimit) {
        $tabCounts = array_count_values(array_column($countResult['rows'], 'status'));
        $tabCounts[''] = $countResult['total'];
    }
} catch (Throwable) {
    $tabCounts = null;
}

$types = $db->select('document_types', [['select', 'id,name'], ['order', 'name.asc']])['rows'];
$puroks = $isResident ? [] : $db->select('puroks', [['select', 'id,name'], ['order', 'name.asc']])['rows'];

$active = $role === 'captain' && $f['status'] === 'for_approval' ? 'approvals' : 'requests';
layout_start($isResident ? 'My requests' : 'Requests', $active);

$action = '';
if ($role === 'resident' && (!id_verification_required() || (Auth::resident()['verification_status'] ?? '') === 'verified')) {
    $action = '<a class="btn btn-primary" href="/requests/new"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Request a document</a>';
} elseif ($role === 'secretary') {
    $action = '<a class="btn btn-primary" href="/requests/new"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Walk-in request</a>';
}
page_header($isResident ? 'My requests' : 'Requests', $isResident ? 'Every document you have requested and where it is now.' : 'Search the queue by control number or resident name.', $action);

$chips = ['' => 'All'] + REQUEST_STATUSES;
?>
<nav class="status-tabs" aria-label="Filter by status">
  <?php foreach ($chips as $key => $label): ?>
    <?php $p = $_GET; $p['status'] = $key; unset($p['page'], $p['open']); ?>
    <a href="?<?= e(http_build_query(array_filter($p, static fn ($v) => $v !== '' && $v !== null))) ?>" class="<?= $f['status'] === (string) $key && !$f['open'] ? 'active' : '' ?>"<?= $f['status'] === (string) $key ? ' aria-current="true"' : '' ?>><?= e($label) ?><?php if ($tabCounts !== null): ?> <span class="tab-count"><span class="visually-hidden">, </span><?= (int) ($tabCounts[(string) $key] ?? 0) ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<form class="filter-bar" method="get" role="search">
  <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <div class="filter-search">
    <label class="visually-hidden" for="q">Search</label>
    <i class="bi bi-search" aria-hidden="true"></i>
    <input id="q" name="q" type="search" class="form-control" value="<?= e($f['q']) ?>" placeholder="<?= $isResident ? 'Control number' : 'Control number or resident name' ?>" maxlength="80">
  </div>
  <div>
    <label class="form-label small" for="type">Document</label>
    <select id="type" name="type" class="form-select"><?= options(pluck($types), $f['type'], 'All documents') ?></select>
  </div>
  <?php if (!$isResident): ?>
  <div>
    <label class="form-label small" for="purok">Purok</label>
    <select id="purok" name="purok" class="form-select"><?= options(pluck($puroks), $f['purok'], 'All puroks') ?></select>
  </div>
  <div>
    <label class="form-label small" for="channel">Filed</label>
    <select id="channel" name="channel" class="form-select"><?= options(['online' => 'Online', 'walk_in' => 'Walk-in'], $f['channel'], 'Any way') ?></select>
  </div>
  <?php endif; ?>
  <div>
    <label class="form-label small" for="from">From</label>
    <input id="from" name="from" type="date" class="form-control" value="<?= e($f['from']) ?>">
  </div>
  <div>
    <label class="form-label small" for="to">To</label>
    <input id="to" name="to" type="date" class="form-control" value="<?= e($f['to']) ?>">
  </div>
  <?php if (!$isResident): ?>
  <div class="form-check align-self-end mb-2">
    <input class="form-check-input" type="checkbox" id="overdue" name="overdue" value="1"<?= chk($f['overdue']) ?>>
    <label class="form-check-label" for="overdue">Overdue only</label>
  </div>
  <?php endif; ?>
  <div class="filter-actions">
    <button class="btn btn-primary" type="submit">Apply</button>
    <a class="btn btn-link" href="/requests">Clear</a>
  </div>
</form>

<section class="panel p-0">
  <?php if (!$rows): ?>
    <?= empty_state(
        array_filter($f) ? 'No requests match these filters' : 'No requests yet',
        array_filter($f) ? 'Try a different status, document or date range.' : ($isResident ? 'Your requests will appear here with their control numbers.' : 'Online and walk-in requests will appear here.'),
        array_filter($f) ? '/requests' : '',
        'Clear filters',
        'files'
    ) ?>
  <?php else: ?>
  <ul class="req-cards d-md-none" aria-label="Requests">
    <?php foreach ($rows as $r): ?>
      <li><a href="<?= e(url('requests/view', ['id' => $r['id']])) ?>">
        <span class="mono stub-mini"><?= e($r['control_no']) ?></span>
        <span class="req-card-date">Filed <?= e(fmt_date($r['submitted_at'])) ?></span>
        <span class="req-card-doc"><?= e($r['document_type']) ?><?= (int) $r['copies'] > 1 ? ' × ' . (int) $r['copies'] : '' ?></span>
        <span class="req-card-meta"><?= $isResident ? '' : e($r['resident_name']) . ' · ' ?><?= e($r['purpose']) ?> · <?= $r['fee_waived'] ? 'Fee waived' : e(money($r['fee_amount'])) ?><?= $r['channel'] === 'walk_in' ? ' · Walk-in' : '' ?></span>
        <span class="req-card-status"><?= status_badge($r['status']) ?><?= $r['is_overdue'] ? '<span class="tag tag-danger">Overdue</span>' : '' ?></span>
      </a></li>
    <?php endforeach; ?>
  </ul>
  <div class="table-responsive d-none d-md-block">
    <table class="table data-table">
      <thead>
        <tr>
          <th scope="col"><?= sort_link('Control no.', 'control_no', $sort, $dir) ?></th>
          <?php if (!$isResident): ?><th scope="col"><?= sort_link('Resident', 'resident_name', $sort, $dir) ?></th><?php endif; ?>
          <th scope="col"><?= sort_link('Document', 'document_type', $sort, $dir) ?></th>
          <th scope="col">Purpose</th>
          <th scope="col" class="text-end">Fee</th>
          <th scope="col"><?= sort_link('Status', 'status', $sort, $dir) ?></th>
          <th scope="col"><?= sort_link('Filed', 'submitted_at', $sort, $dir) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $href = url('requests/view', ['id' => $r['id']]); ?>
          <tr data-href="<?= e($href) ?>">
            <td><a class="mono" href="<?= e($href) ?>"><?= e($r['control_no']) ?></a><?php if ($r['channel'] === 'walk_in'): ?> <span class="tag tag-neutral">Walk-in</span><?php endif; ?></td>
            <?php if (!$isResident): ?><td><?= e($r['resident_name']) ?><small class="d-block text-secondary"><?= e($r['purok_name']) ?></small></td><?php endif; ?>
            <td><?= e($r['document_type']) ?><?= (int) $r['copies'] > 1 ? ' <small class="text-secondary">× ' . (int) $r['copies'] . '</small>' : '' ?></td>
            <td><?= e($r['purpose']) ?></td>
            <td class="text-end num"><?= $r['fee_waived'] ? '<span class="text-secondary">Waived</span>' : e(money($r['fee_amount'])) ?></td>
            <td><?= status_badge($r['status']) ?><?= $r['is_overdue'] ? ' <span class="tag tag-danger">Overdue</span>' : '' ?></td>
            <td title="<?= e(fmt_datetime($r['submitted_at'])) ?>"><?= e(fmt_date($r['submitted_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="panel-foot"><?= pagination($total, $page, $per) ?></div>
  <?php endif; ?>
</section>
<?php layout_end(); ?>
