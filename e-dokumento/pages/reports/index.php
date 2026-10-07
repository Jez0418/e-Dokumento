<?php
declare(strict_types=1);

require_once BASE_PATH . '/includes/reports.php';

$role = Auth::role();
$catalog = array_filter(report_catalog(), static fn ($r) => in_array($role, $r['roles'], true));
$key = array_key_exists(q('report'), $catalog) ? q('report') : (string) array_key_first($catalog);
$def = $catalog[$key];
$f = report_filters();
$db = Supabase::user();

try {
    $data = run_report($key, $f);
} catch (Throwable $e) {
    $data = ['columns' => [], 'rows' => [], 'totals' => [], 'formats' => [], 'truncated' => false];
    flash_error(db_error($e));
}
$types = in_array($key, ['register', 'issuance', 'processing'], true) ? $db->select('document_types', [['select', 'id,name'], ['order', 'name.asc']])['rows'] : [];
$puroks = $key === 'register' ? $db->select('puroks', [['select', 'id,name'], ['order', 'name.asc']])['rows'] : [];
$params = array_filter(['report' => $key] + $f, static fn ($v) => $v !== '' && $v !== null);

layout_start('Reports', 'reports');
page_header('Reports', 'Every figure is read live from the database for the filters you choose.');
?>
<nav class="status-tabs no-print" aria-label="Reports">
  <?php foreach ($catalog as $k => $r): ?><a href="?report=<?= e($k) ?>" class="<?= $k === $key ? 'active' : '' ?>"><?= e($r['title']) ?></a><?php endforeach; ?>
</nav>

<form class="filter-bar no-print" method="get">
  <input type="hidden" name="report" value="<?= e($key) ?>">
  <?php if ($key !== 'residents'): ?>
    <div><label class="form-label small" for="from">From</label><input id="from" name="from" type="date" class="form-control" value="<?= e($f['from']) ?>"></div>
    <div><label class="form-label small" for="to">To</label><input id="to" name="to" type="date" class="form-control" value="<?= e($f['to']) ?>"></div>
  <?php endif; ?>
  <?php if ($types): ?><div><label class="form-label small" for="type">Document</label><select id="type" name="type" class="form-select"><?= options(pluck($types), $f['type'], 'All') ?></select></div><?php endif; ?>
  <?php if ($key === 'register'): ?>
    <div><label class="form-label small" for="status">Status</label><select id="status" name="status" class="form-select"><?= options(REQUEST_STATUSES, $f['status'], 'Any') ?></select></div>
    <div><label class="form-label small" for="purok">Purok</label><select id="purok" name="purok" class="form-select"><?= options(pluck($puroks), $f['purok'], 'All') ?></select></div>
    <div><label class="form-label small" for="channel">Channel</label><select id="channel" name="channel" class="form-select"><?= options(['online' => 'Online', 'walk_in' => 'Walk-in'], $f['channel'], 'Any') ?></select></div>
  <?php elseif ($key === 'collections'): ?>
    <div><label class="form-label small" for="method">Method</label><select id="method" name="method" class="form-select"><?= options(PAYMENT_METHODS, $f['method'], 'Any') ?></select></div>
    <div><label class="form-label small" for="pstatus">Status</label><select id="pstatus" name="pstatus" class="form-select"><?= options(['posted' => 'Posted', 'voided' => 'Voided'], $f['pstatus'], 'Any') ?></select></div>
  <?php elseif ($key === 'activity'): ?>
    <div><label class="form-label small" for="action">Action</label><input id="action" name="action" class="form-control" value="<?= e($f['action']) ?>" placeholder="e.g. payment_post" maxlength="40"></div>
  <?php endif; ?>
  <div class="filter-actions">
    <button class="btn btn-primary" type="submit">Run report</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('reports/export', $params)) ?>"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>
    <button class="btn btn-outline-secondary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
  </div>
</form>

<article class="panel report-sheet">
  <header class="report-head">
    <div>
      <p class="report-org">Barangay <?= e(barangay_name()) ?>, <?= e(setting('city_municipality')) ?></p>
      <h2><?= e($def['title']) ?></h2>
      <p class="text-secondary mb-0"><?= e($def['lead']) ?><?= $key !== 'residents' ? ' ' . e(fmt_date($f['from'])) . ' to ' . e(fmt_date($f['to'])) . '.' : '' ?></p>
    </div>
    <p class="small text-secondary mb-0">Generated <?= e(fmt_datetime(date('c'))) ?> by <?= e(Auth::user()['full_name']) ?></p>
  </header>

  <?php if ($data['totals']): ?>
    <dl class="report-totals"><?php foreach ($data['totals'] as $label => $value): ?><div><dt><?= e($label) ?></dt><dd class="num"><?= e($value) ?></dd></div><?php endforeach; ?></dl>
  <?php endif; ?>
  <?php if ($data['truncated']): ?><div class="alert alert-warning">Showing the first 1,000 rows. Narrow the date range, or export to CSV for up to 5,000.</div><?php endif; ?>

  <?php if (!$data['rows']): ?>
    <?= empty_state('Nothing in this period', 'Try a wider date range or remove a filter.', '', '', 'bar-chart-line') ?>
  <?php else: ?>
    <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
    <div class="table-responsive"><table class="table data-table report-table">
      <thead><tr><?php foreach ($data['columns'] as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($data['rows'] as $row): ?>
          <tr><?php foreach ($data['columns'] as $col => $label): $fmt = $data['formats'][$col] ?? ''; ?>
            <td class="<?= in_array($fmt, ['money', 'int', 'decimal'], true) ? 'text-end num' : ($fmt === 'mono' ? 'mono' : '') ?>"><?= e(report_cell($row[$col] ?? null, $fmt)) ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <?php if (!empty($data['daily'])): ?>
    <h3 class="h6 mt-4">Daily totals</h3>
    <table class="table table-sm w-auto"><thead><tr><th scope="col">Day</th><th scope="col" class="text-end">Collected</th></tr></thead><tbody>
      <?php foreach ($data['daily'] as $day => $sum): ?><tr><td><?= e(fmt_date($day, 'D, M j')) ?></td><td class="text-end num"><?= e(money($sum)) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</article>
<?php layout_end(); ?>
