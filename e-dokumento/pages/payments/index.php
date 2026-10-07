<?php
declare(strict_types=1);

$db = Supabase::user();
$isTreasurer = has_role('treasurer');
$tab = q('tab', $isTreasurer ? 'queue' : 'history') === 'history' ? 'history' : 'queue';
['page' => $page, 'per' => $per, 'offset' => $offset] = paging(config('app')['per_page']);

$f = [
    'q'      => search_term(q('q')),
    'method' => array_key_exists(q('method'), PAYMENT_METHODS) ? q('method') : '',
    'status' => in_array(q('status'), ['posted', 'voided'], true) ? q('status') : '',
    'from'   => is_date(q('from')) ? q('from') : '',
    'to'     => is_date(q('to')) ? q('to') : '',
];

if ($tab === 'queue') {
    $query = [['select', 'id,control_no,resident_name,document_type,copies,fee_amount,submitted_at,updated_at'], ['status', 'eq.for_payment']];
    if ($f['q'] !== '') {
        $query[] = ['search_text', 'ilike.*' . mb_strtolower($f['q']) . '*'];
    }
    $query[] = ['order', 'updated_at.asc'];
    $result = $db->select('request_list', $query, true, $offset, $per);
} else {
    $query = [['select', '*']];
    if ($f['q'] !== '') {
        $query[] = ['search_text', 'ilike.*' . mb_strtolower($f['q']) . '*'];
    }
    if ($f['method'] !== '') {
        $query[] = ['method', 'eq.' . $f['method']];
    }
    if ($f['status'] !== '') {
        $query[] = ['status', 'eq.' . $f['status']];
    }
    if ($f['from'] !== '') {
        $query[] = ['paid_at', 'gte.' . $f['from'] . 'T00:00:00+08:00'];
    }
    if ($f['to'] !== '') {
        $query[] = ['paid_at', 'lte.' . $f['to'] . 'T23:59:59+08:00'];
    }
    $query[] = ['order', 'paid_at.desc'];
    $result = $db->select('payment_list', $query, true, $offset, $per);
}

layout_start($isTreasurer ? 'Cashiering' : 'Payments', 'payments');
page_header($isTreasurer ? 'Cashiering' : 'Payments', $isTreasurer ? 'Record the official receipt for each request waiting at your window.' : 'Payments recorded by the Treasurer.');
?>
<nav class="status-tabs" aria-label="Views">
  <a href="?tab=queue" class="<?= $tab === 'queue' ? 'active' : '' ?>">Waiting for payment</a>
  <a href="?tab=history" class="<?= $tab === 'history' ? 'active' : '' ?>">Payment history</a>
</nav>

<form class="filter-bar" method="get" role="search">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="filter-search"><label class="visually-hidden" for="q">Search</label><i class="bi bi-search" aria-hidden="true"></i>
    <input id="q" name="q" type="search" class="form-control" value="<?= e($f['q']) ?>" placeholder="<?= $tab === 'queue' ? 'Control number or name' : 'OR, control number or name' ?>" maxlength="80"></div>
  <?php if ($tab === 'history'): ?>
    <div><label class="form-label small" for="method">Method</label><select id="method" name="method" class="form-select"><?= options(PAYMENT_METHODS, $f['method'], 'Any') ?></select></div>
    <div><label class="form-label small" for="status">Status</label><select id="status" name="status" class="form-select"><?= options(['posted' => 'Posted', 'voided' => 'Voided'], $f['status'], 'Any') ?></select></div>
    <div><label class="form-label small" for="from">From</label><input id="from" name="from" type="date" class="form-control" value="<?= e($f['from']) ?>"></div>
    <div><label class="form-label small" for="to">To</label><input id="to" name="to" type="date" class="form-control" value="<?= e($f['to']) ?>"></div>
  <?php endif; ?>
  <div class="filter-actions"><button class="btn btn-primary" type="submit">Apply</button><a class="btn btn-link" href="?tab=<?= e($tab) ?>">Clear</a></div>
</form>

<section class="panel p-0">
<?php if (!$result['rows']): ?>
  <?= empty_state($tab === 'queue' ? 'No one is waiting to pay' : 'No payments found', $tab === 'queue' ? 'Requests appear here when the Secretary sends them for payment.' : 'Adjust the filters or date range.', '', '', 'cash-coin') ?>
<?php elseif ($tab === 'queue'): ?>
  <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
  <div class="table-responsive"><table class="table data-table">
    <thead><tr><th scope="col">Control no.</th><th scope="col">Resident</th><th scope="col">Document</th><th scope="col" class="text-end">Amount due</th><th scope="col">Waiting since</th><?php if ($isTreasurer): ?><th scope="col"><span class="visually-hidden">Action</span></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $r): ?>
      <tr>
        <td><a class="mono" href="<?= e(url('requests/view', ['id' => $r['id']])) ?>"><?= e($r['control_no']) ?></a></td>
        <td><?= e($r['resident_name']) ?></td>
        <td><?= e($r['document_type']) ?><?= (int) $r['copies'] > 1 ? ' × ' . (int) $r['copies'] : '' ?></td>
        <td class="text-end num fw-bold"><?= e(money($r['fee_amount'])) ?></td>
        <td><?= e(time_ago($r['updated_at'])) ?></td>
        <?php if ($isTreasurer): ?>
        <td class="text-end">
          <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#payModal"
                  data-pay-id="<?= e($r['id']) ?>" data-pay-control="<?= e($r['control_no']) ?>" data-pay-amount="<?= e($r['fee_amount']) ?>" data-pay-name="<?= e($r['resident_name']) ?>">Record payment</button>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php else: ?>
  <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
  <div class="table-responsive"><table class="table data-table">
    <thead><tr><th scope="col">OR no.</th><th scope="col">Control no.</th><th scope="col">Resident</th><th scope="col">Method</th><th scope="col" class="text-end">Amount</th><th scope="col">Paid</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $p): ?>
      <tr class="<?= $p['status'] === 'voided' ? 'row-void' : '' ?>">
        <td class="mono"><?= e($p['or_number']) ?></td>
        <td><a class="mono" href="<?= e(url('requests/view', ['id' => $p['request_id']])) ?>"><?= e($p['control_no']) ?></a></td>
        <td><?= e($p['resident_name']) ?><small class="d-block text-secondary"><?= e($p['document_type']) ?></small></td>
        <td><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference_no'] ? '<small class="d-block text-secondary mono">' . e($p['reference_no']) . '</small>' : '' ?></td>
        <td class="text-end num"><?= e(money($p['amount'])) ?></td>
        <td><?= e(fmt_datetime($p['paid_at'])) ?><small class="d-block text-secondary"><?= e($p['received_by_name'] ?? '') ?></small></td>
        <td><?= $p['status'] === 'posted' ? simple_badge('Posted', 'success') : simple_badge('Voided', 'danger') ?></td>
        <td class="text-end text-nowrap"><div class="row-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('payments/view', ['id' => $p['id']])) ?>">View</a>
          <?php if ($isTreasurer && $p['status'] === 'posted' && $p['request_status'] === 'processing'): ?>
            <?= action_button('/requests/action', ['id' => $p['request_id'], 'action' => 'void_payment', 'payment_id' => $p['id'], '_back' => '/payments?tab=history'], 'Void', 'btn-sm btn-outline-danger', 'Void OR ' . $p['or_number'] . '? The request returns to For payment.', true) ?>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
<?php if ($result['rows']): ?><div class="panel-foot"><?= pagination($result['total'], $page, $per) ?></div><?php endif; ?>
</section>

<?php if ($isTreasurer): ?>
<div class="modal fade" id="payModal" tabindex="-1" aria-labelledby="payTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content needs-validation" method="post" action="/requests/action" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="record_payment">
      <input type="hidden" name="id" id="pay-id">
      <input type="hidden" name="amount" id="pay-amount">
      <input type="hidden" name="_back" value="/payments">
      <div class="modal-header"><h2 class="modal-title h5" id="payTitle">Record payment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="mb-3"><span class="mono" id="pay-control"></span> · <span id="pay-name"></span></p>
        <p class="pay-amount num" id="pay-amount-text"></p>
        <div class="mb-3"><label class="form-label" for="pay-or">OR number</label><input class="form-control mono" id="pay-or" name="or_number" required maxlength="30" pattern="[A-Za-z0-9\-]{1,30}" autocomplete="off"><div class="invalid-feedback">Enter the OR number printed on the receipt.</div></div>
        <div class="mb-3"><label class="form-label" for="method-m">Method</label><select class="form-select" id="method-m" name="method" data-toggle-ref="ref-wrap-m"><?= options(PAYMENT_METHODS, 'cash') ?></select></div>
        <div id="ref-wrap-m" hidden><label class="form-label" for="ref-m">Reference number</label><input class="form-control" id="ref-m" name="reference_no" maxlength="60" autocomplete="off"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit" data-loading-text="Recording…">Record payment</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php layout_end(['payments.js']); ?>
