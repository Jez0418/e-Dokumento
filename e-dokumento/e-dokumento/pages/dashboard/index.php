<?php
declare(strict_types=1);

$db = Supabase::user();
$role = Auth::role();
$user = Auth::user();

try {
    $summary = $db->rpc('dashboard_summary');
} catch (Throwable $e) {
    $summary = ['cards' => [], 'by_month' => [], 'by_type' => [], 'daily_collections' => []];
    flash_error(db_error($e));
}
$c = $summary['cards'] ?? [];
$n = static fn (string $k): int => (int) ($c[$k] ?? 0);

try {
    $recent = $db->select('request_list', [
        ['select', 'id,control_no,status,document_type,resident_name,submitted_at,is_overdue,fee_amount'],
        ['order', 'submitted_at.desc'],
    ], false, 0, 8)['rows'];
} catch (Throwable) {
    $recent = [];
}

$greeting = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('G') < 12 ? 'Good morning' : 'Good afternoon';

// Pipeline stages, in the order a request moves through the hall
$stages = [
    ['pending', 'Pending', 'Waiting for the Secretary to open'],
    ['under_review', 'Under review', 'Files being checked'],
    ['for_payment', 'For payment', 'At the Treasurer'],
    ['processing', 'Processing', 'Being prepared'],
    ['for_approval', 'For approval', 'Waiting for the captain'],
    ['ready_for_release', 'Ready', 'Waiting to be claimed'],
];
$focus = match ($role) {
    'secretary' => ['pending', 'under_review', 'processing', 'ready_for_release'],
    'treasurer' => ['for_payment'],
    'captain'   => ['for_approval'],
    default     => [],
};

layout_start('Dashboard', 'dashboard');

if ($role === 'resident'):
    $resident = Auth::resident();
    $vstatus = $resident['verification_status'] ?? 'unverified';
?>
  <?php page_header($greeting . ', ' . ($resident['first_name'] ?? $user['full_name']) . '.', 'Here is where your documents stand.',
      $vstatus === 'verified' ? '<a class="btn btn-primary" href="/requests/new"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Request a document</a>' : ''); ?>

  <?php if (!$resident): ?>
    <div class="notice notice-warning">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <div><strong>No resident record is linked to this account.</strong> Visit the barangay hall so the Secretary can link it.</div>
    </div>
  <?php elseif ($vstatus !== 'verified'): ?>
    <div class="notice notice-<?= $vstatus === 'rejected' ? 'danger' : 'info' ?>">
      <i class="bi bi-person-vcard" aria-hidden="true"></i>
      <div>
        <strong><?= e(match ($vstatus) { 'pending' => 'Your ID is being reviewed.', 'rejected' => 'Your ID was not approved.', default => 'Verify your residency to start requesting documents.' }) ?></strong>
        <?= e(match ($vstatus) { 'pending' => 'You will get a notification once the Secretary approves it.', 'rejected' => 'See the reason on your profile and submit a clearer photo.', default => 'Upload a photo of a valid ID. This takes about two minutes.' }) ?>
      </div>
      <?php if ($vstatus !== 'pending'): ?><a class="btn btn-sm btn-primary ms-auto" href="/profile#verification">Verify now</a><?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="stat-strip" aria-label="Summary">
    <div><span class="stat-num" data-card="open"><?= $n('open') ?></span><span class="stat-label">Open requests</span></div>
    <div><span class="stat-num" data-card="ready_for_release"><?= $n('ready_for_release') ?></span><span class="stat-label">Ready to claim</span></div>
    <div><span class="stat-num" data-card="for_payment"><?= $n('for_payment') ?></span><span class="stat-label">Waiting for payment</span></div>
    <div><span class="stat-num" data-card="released_total"><?= $n('released_total') ?></span><span class="stat-label">Released to you</span></div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>My recent requests</h2><a href="/requests">See all</a></div>
    <?php if (!$recent): ?>
      <?= empty_state('No requests yet', $vstatus === 'verified' ? 'Request a clearance, residency certificate or other document. You will get a control number right away.' : 'Once your residency is verified you can request documents here.', $vstatus === 'verified' ? '/requests/new' : '', 'Request a document', 'file-earmark') ?>
    <?php else: ?>
      <ul class="stub-list">
        <?php foreach ($recent as $r): ?>
          <li><a href="<?= e(url('requests/view', ['id' => $r['id']])) ?>">
            <span class="mono stub-mini"><?= e($r['control_no']) ?></span>
            <span class="flex-grow-1"><span class="stub-title"><?= e($r['document_type']) ?></span><small class="d-block text-secondary">Filed <?= e(time_ago($r['submitted_at'])) ?></small></span>
            <?= status_badge($r['status']) ?>
          </a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php else: /* staff */ ?>
  <?php
    $actions = match ($role) {
        'secretary' => '<a class="btn btn-primary" href="/requests/new"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Walk-in request</a>',
        'treasurer' => '<a class="btn btn-primary" href="/payments"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Open cashiering</a>',
        'captain'   => '<a class="btn btn-primary" href="/requests?status=for_approval"><i class="bi bi-pen me-1" aria-hidden="true"></i>Review approvals</a>',
        default     => '<a class="btn btn-outline-primary" href="/reports"><i class="bi bi-bar-chart-line me-1" aria-hidden="true"></i>Reports</a>',
    };
    page_header($greeting . ', ' . strtok((string) $user['full_name'], ' ') . '.', 'Where every request stands today. Counts refresh every minute.', $actions);
  ?>

  <section class="pipeline" aria-label="Requests by stage">
    <?php foreach ($stages as $i => [$key, $label, $hint]): ?>
      <a class="pipe-step<?= in_array($key, $focus, true) ? ' is-focus' : '' ?>" href="<?= e(url('requests', ['status' => $key])) ?>">
        <span class="pipe-no">Step <?= $i + 1 ?></span>
        <span class="pipe-num" data-card="<?= e($key) ?>"><?= $n($key) ?></span>
        <span class="pipe-label"><?= e($label) ?></span>
        <span class="pipe-hint"><?= e($hint) ?></span>
      </a>
    <?php endforeach; ?>
  </section>

  <section class="stat-strip" aria-label="Summary">
    <?php if ($role === 'treasurer'): ?>
      <div><span class="stat-num" data-card="collections_today" data-money="1"><?= e(money($c['collections_today'] ?? 0)) ?></span><span class="stat-label">Collected today</span></div>
      <div><span class="stat-num" data-card="collections_month" data-money="1"><?= e(money($c['collections_month'] ?? 0)) ?></span><span class="stat-label">Collected this month</span></div>
      <div><span class="stat-num" data-card="for_payment"><?= $n('for_payment') ?></span><span class="stat-label">Awaiting payment</span></div>
      <div><span class="stat-num" data-card="voided_month"><?= $n('voided_month') ?></span><span class="stat-label">Voided this month</span></div>
    <?php else: ?>
      <div><span class="stat-num" data-card="residents_verified"><?= $n('residents_verified') ?></span><span class="stat-label">Verified residents</span></div>
      <div><span class="stat-num" data-card="released_month"><?= $n('released_month') ?></span><span class="stat-label">Released this month</span></div>
      <?php if ($role !== 'secretary'): ?>
        <div><span class="stat-num" data-card="collections_month" data-money="1"><?= e(money($c['collections_month'] ?? 0)) ?></span><span class="stat-label">Collected this month</span></div>
      <?php endif; ?>
      <div><span class="stat-num" data-card="open"><?= $n('open') ?></span><span class="stat-label">Open requests</span></div>
    <?php endif; ?>
  </section>

  <div class="dash-grid">
    <section class="panel">
      <div class="panel-head"><h2>Needs attention</h2></div>
      <ul class="attention-list">
        <?php if ($role !== 'treasurer'): ?>
          <li class="<?= $n('overdue') ? 'is-alert' : '' ?>"><a href="<?= e(url('requests', ['overdue' => 1])) ?>"><span data-card="overdue"><?= $n('overdue') ?></span> requests past their processing days</a></li>
        <?php endif; ?>
        <?php if (in_array($role, ['secretary', 'admin', 'captain'], true)): ?>
          <li class="<?= $n('verifications_pending') ? 'is-alert' : '' ?>"><a href="<?= $role === 'secretary' ? '/verifications' : '/residents?verification=pending' ?>"><span data-card="verifications_pending"><?= $n('verifications_pending') ?></span> residents waiting for ID verification</a></li>
          <li><a href="<?= e(url('requests', ['status' => 'under_review'])) ?>"><span data-card="rejected_files"><?= $n('rejected_files') ?></span> requests waiting for a replacement file</a></li>
        <?php endif; ?>
        <?php if (in_array($role, ['treasurer', 'admin', 'captain'], true)): ?>
          <li class="<?= $n('for_payment') ? 'is-alert' : '' ?>"><a href="/payments"><span data-card="for_payment"><?= $n('for_payment') ?></span> requests waiting at the Treasurer</a></li>
        <?php endif; ?>
        <?php if (in_array($role, ['captain', 'admin'], true)): ?>
          <li class="<?= $n('for_approval') ? 'is-alert' : '' ?>"><a href="<?= e(url('requests', ['status' => 'for_approval'])) ?>"><span data-card="for_approval"><?= $n('for_approval') ?></span> documents waiting for the captain's signature</a></li>
        <?php endif; ?>
      </ul>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2><?= $role === 'treasurer' ? 'Daily collections, last 30 days' : 'Requests, last 6 months' ?></h2>
        <span class="text-secondary small" id="dash-updated">Updated <?= e(fmt_date((string) ($summary['generated_at'] ?? ''), 'g:i A')) ?></span>
      </div>
      <div class="chart-box"><canvas id="mainChart" aria-label="<?= $role === 'treasurer' ? 'Daily collections chart. The numbers are in the table below it.' : 'Requests per month chart. The numbers are in the table below it.' ?>" role="img"></canvas></div>
      <details class="chart-data">
        <summary>Show the numbers</summary>
        <div class="table-responsive">
          <table class="table table-sm">
            <?php if ($role === 'treasurer'): ?>
              <thead><tr><th scope="col">Day</th><th scope="col" class="text-end">Collected</th></tr></thead>
              <tbody><?php foreach ($summary['daily_collections'] ?? [] as $row): ?><tr><td><?= e((string) ($row['label'] ?? '')) ?></td><td class="text-end num"><?= e(money($row['total'] ?? 0)) ?></td></tr><?php endforeach; ?></tbody>
            <?php else: ?>
              <thead><tr><th scope="col">Month</th><th scope="col" class="text-end">Filed</th><th scope="col" class="text-end">Released</th><th scope="col" class="text-end">Rejected</th></tr></thead>
              <tbody><?php foreach ($summary['by_month'] ?? [] as $row): ?><tr><td><?= e((string) ($row['label'] ?? '')) ?></td><td class="text-end num"><?= (int) ($row['submitted'] ?? 0) ?></td><td class="text-end num"><?= (int) ($row['released'] ?? 0) ?></td><td class="text-end num"><?= (int) ($row['rejected'] ?? 0) ?></td></tr><?php endforeach; ?></tbody>
            <?php endif; ?>
          </table>
        </div>
      </details>
    </section>

    <?php if ($role !== 'treasurer'): ?>
    <section class="panel">
      <div class="panel-head"><h2>This month by document</h2></div>
      <?php if (empty($summary['by_type'])): ?>
        <p class="text-secondary mb-0">No requests filed this month yet.</p>
      <?php endif; ?>
      <div class="chart-box chart-box-sm<?= empty($summary['by_type']) ? ' d-none' : '' ?>"><canvas id="typeChart" aria-label="Requests by document type this month. The numbers are in the table below it." role="img"></canvas></div>
      <?php if (!empty($summary['by_type'])): ?>
        <details class="chart-data">
          <summary>Show the numbers</summary>
          <table class="table table-sm">
            <thead><tr><th scope="col">Document</th><th scope="col" class="text-end">Requests</th></tr></thead>
            <tbody><?php foreach ($summary['by_type'] as $row): ?><tr><td><?= e((string) ($row['name'] ?? '')) ?></td><td class="text-end num"><?= (int) ($row['total'] ?? 0) ?></td></tr><?php endforeach; ?></tbody>
          </table>
        </details>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel panel-wide">
      <div class="panel-head"><h2>Latest requests</h2><a href="/requests">Open the queue</a></div>
      <?php if (!$recent): ?>
        <?= empty_state('No requests yet', 'Requests filed online or at the counter will appear here.', $role === 'secretary' ? '/requests/new' : '', 'Encode a walk-in request', 'files') ?>
      <?php else: ?>
        <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
        <div class="table-responsive">
          <table class="table data-table">
            <thead><tr><th scope="col">Control no.</th><th scope="col">Resident</th><th scope="col">Document</th><th scope="col">Status</th><th scope="col">Filed</th></tr></thead>
            <tbody>
              <?php foreach ($recent as $r): ?>
                <tr data-href="<?= e(url('requests/view', ['id' => $r['id']])) ?>">
                  <td><a class="mono" href="<?= e(url('requests/view', ['id' => $r['id']])) ?>"><?= e($r['control_no']) ?></a></td>
                  <td><?= e($r['resident_name']) ?></td>
                  <td><?= e($r['document_type']) ?></td>
                  <td><?= status_badge($r['status']) ?><?= $r['is_overdue'] ? ' <span class="tag tag-danger">Overdue</span>' : '' ?></td>
                  <td><?= e(time_ago($r['submitted_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>

<script type="application/json" id="dashboard-data"><?= json_encode(['role' => $role, 'summary' => $summary], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php layout_end($role === 'resident' ? ['dashboard.js'] : ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', 'dashboard.js']); ?>
