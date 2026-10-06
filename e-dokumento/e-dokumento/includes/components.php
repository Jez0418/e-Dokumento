<?php
declare(strict_types=1);

const REQUEST_STATUSES = [
    'pending'           => 'Pending',
    'under_review'      => 'Under review',
    'for_payment'       => 'For payment',
    'processing'        => 'Processing',
    'for_approval'      => 'For approval',
    'ready_for_release' => 'Ready for release',
    'released'          => 'Released',
    'rejected'          => 'Rejected',
    'cancelled'         => 'Cancelled',
];

const VERIFICATION_STATUSES = [
    'unverified' => 'Not verified',
    'pending'    => 'Under review',
    'verified'   => 'Verified',
    'rejected'   => 'Not approved',
];

const RESIDENT_STATUSES = ['active' => 'Active', 'moved_out' => 'Moved out', 'deceased' => 'Deceased', 'inactive' => 'Inactive'];
const CIVIL_STATUSES = ['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'separated' => 'Separated', 'annulled' => 'Annulled'];
const PAYMENT_METHODS = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer'];
const OFFICIAL_POSITIONS = [
    'punong_barangay' => 'Punong Barangay',
    'kagawad'         => 'Barangay Kagawad',
    'secretary'       => 'Barangay Secretary',
    'treasurer'       => 'Barangay Treasurer',
    'sk_chairperson'  => 'SK Chairperson',
];
const TEMPLATE_KEYS = [
    'clearance' => 'Barangay clearance',
    'residency' => 'Certificate of residency',
    'indigency' => 'Certificate of indigency',
    'business'  => 'Business clearance',
    'jobseeker' => 'First time jobseeker (RA 11261)',
    'generic'   => 'General certification',
];

function status_label(string $status): string
{
    return REQUEST_STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function status_badge(string $status): string
{
    return '<span class="status status-' . e($status) . '"><span class="status-dot" aria-hidden="true"></span>' . e(status_label($status)) . '</span>';
}

function verification_badge(string $status): string
{
    $map = ['unverified' => 'cancelled', 'pending' => 'under_review', 'verified' => 'released', 'rejected' => 'rejected'];
    return '<span class="status status-' . e($map[$status] ?? 'pending') . '"><span class="status-dot" aria-hidden="true"></span>'
        . e(VERIFICATION_STATUSES[$status] ?? $status) . '</span>';
}

function simple_badge(string $text, string $tone = 'neutral'): string
{
    return '<span class="tag tag-' . e($tone) . '">' . e($text) . '</span>';
}

/** Inline form-level error summary (field errors render beside their inputs). */
function form_errors(array $errors): string
{
    if ($errors === []) {
        return '';
    }
    $fieldErrors = array_diff_key($errors, ['_form' => true]);
    $html = '<div class="alert alert-danger form-summary" role="alert">';
    if (isset($errors['_form'])) {
        $html .= '<strong>' . e($errors['_form']) . '</strong>';
    }
    if ($fieldErrors !== []) {
        $count = count($fieldErrors);
        $html .= (isset($errors['_form']) ? '<div class="mt-2">' : '<div>') . '<strong>Fix ' . ($count === 1 ? 'this field' : 'these ' . $count . ' fields') . ' and submit again:</strong><ul>';
        foreach ($fieldErrors as $message) {
            $html .= '<li>' . e((string) $message) . '</li>';
        }
        $html .= '</ul></div>';
    }
    return $html . '</div>';
}

function empty_state(string $title, string $text, string $actionHref = '', string $actionLabel = '', string $icon = 'inbox'): string
{
    $html = '<div class="empty-state"><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i><h2>' . e($title) . '</h2><p>' . e($text) . '</p>';
    if ($actionHref !== '') {
        $html .= '<a class="btn btn-primary" href="' . e($actionHref) . '">' . e($actionLabel) . '</a>';
    }
    return $html . '</div>';
}

/** Page window for list screens. @return array{page:int,per:int,offset:int} */
function paging(int $per = 15): array
{
    $page = max(1, q_int('page', 1));
    return ['page' => $page, 'per' => $per, 'offset' => ($page - 1) * $per];
}

function pagination(?int $total, int $page, int $per): string
{
    if ($total === null || $total <= $per) {
        return $total !== null ? '<p class="list-count">' . number_format($total) . ' ' . ($total === 1 ? 'record' : 'records') . '</p>' : '';
    }
    $pages = (int) ceil($total / $per);
    $page = min($page, $pages);
    $params = $_GET;
    $link = static function (int $p) use ($params): string {
        $params['page'] = $p;
        return '?' . http_build_query($params);
    };
    $from = ($page - 1) * $per + 1;
    $to = min($total, $page * $per);
    $html = '<nav class="pager" aria-label="Pages"><p class="list-count">' . number_format($from) . '–' . number_format($to) . ' of ' . number_format($total) . '</p><ul class="pagination pagination-sm mb-0">';
    $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . e($link(max(1, $page - 1))) . '" aria-label="Previous page">‹</a></li>';
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    for ($p = $start; $p <= $end; $p++) {
        $html .= '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . e($link($p)) . '"' . ($p === $page ? ' aria-current="page"' : '') . '>' . $p . '</a></li>';
    }
    $html .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="' . e($link(min($pages, $page + 1))) . '" aria-label="Next page">›</a></li>';
    return $html . '</ul></nav>';
}

/** Validated sort from the query string. @return array{0:string,1:string} [column, asc|desc] */
function sorting(array $allowed, string $default, string $defaultDir = 'desc'): array
{
    $sort = q('sort', $default);
    $dir = q('dir', $defaultDir) === 'asc' ? 'asc' : 'desc';
    return [in_array($sort, $allowed, true) ? $sort : $default, $dir];
}

function sort_link(string $label, string $column, string $current, string $dir): string
{
    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = ($current === $column && $dir === 'asc') ? 'desc' : 'asc';
    unset($params['page']);
    $icon = $current === $column ? ($dir === 'asc' ? ' <i class="bi bi-caret-up-fill" aria-hidden="true"></i>' : ' <i class="bi bi-caret-down-fill" aria-hidden="true"></i>') : '';
    $aria = $current === $column ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
    return '<a class="sort-link" href="?' . e(http_build_query($params)) . '"' . $aria . '>' . e($label) . $icon . '</a>';
}

/** A small POST form button that asks for confirmation (and optionally a reason). */
function action_button(string $action, array $fields, string $label, string $class, string $confirm = '', bool $needsReason = false, string $icon = ''): string
{
    $html = '<form method="post" action="' . e($action) . '" class="d-inline"'
        . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '')
        . ($needsReason ? ' data-confirm-reason="1"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    if ($needsReason) {
        $html .= '<input type="hidden" name="reason" value="">';
    }
    $html .= '<button type="submit" class="btn ' . e($class) . '">' . ($icon !== '' ? '<i class="bi bi-' . e($icon) . ' me-1" aria-hidden="true"></i>' : '') . e($label) . '</button></form>';
    return $html;
}

/** The manila claim stub that carries a control number. */
function claim_stub(string $controlNo, string $documentType, string $status, string $note = ''): string
{
    return '<div class="claim-stub"><div class="stub-body"><span class="stub-label">Control number</span>'
        . '<span class="stub-number">' . e($controlNo) . '</span><span class="stub-doc">' . e($documentType) . '</span></div>'
        . '<div class="stub-side">' . status_badge($status) . ($note !== '' ? '<p>' . e($note) . '</p>' : '') . '</div></div>';
}

function options(array $map, mixed $selected, string $placeholder = ''): string
{
    $html = $placeholder !== '' ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($map as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . sel($value, $selected) . '>' . e($label) . '</option>';
    }
    return $html;
}

/** id => name map from rows. */
function pluck(array $rows, string $label = 'name', string $key = 'id'): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r[$key]] = (string) $r[$label];
    }
    return $out;
}
