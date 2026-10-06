<?php
declare(strict_types=1);

/**
 * Report definitions shared by the on-screen report and the CSV export, so the
 * two can never disagree. Every report reads Supabase with the user's token.
 */
function report_catalog(): array
{
    return [
        'register'   => ['title' => 'Request register', 'roles' => ['admin', 'captain', 'secretary'],
                         'lead' => 'Every request filed in the period, with its current status.'],
        'collections'=> ['title' => 'Collections', 'roles' => ['admin', 'captain', 'treasurer'],
                         'lead' => 'Official receipts recorded in the period, with daily totals.'],
        'issuance'   => ['title' => 'Issuance summary', 'roles' => ['admin', 'captain', 'secretary'],
                         'lead' => 'Documents issued per type, and the fees they brought in.'],
        'processing' => ['title' => 'Processing time', 'roles' => ['admin', 'captain', 'secretary'],
                         'lead' => 'How long released requests took, against each type\'s target.'],
        'residents'  => ['title' => 'Residents by purok', 'roles' => ['admin', 'captain', 'secretary'],
                         'lead' => 'Active residents per purok, verified residents, voters and online accounts.'],
        'activity'   => ['title' => 'Activity', 'roles' => ['admin', 'captain'],
                         'lead' => 'Audit log entries in the period.'],
    ];
}

function report_filters(): array
{
    $today = today_local();
    $first = substr($today, 0, 8) . '01';
    $from = is_date(q('from')) ? q('from') : $first;
    $to = is_date(q('to')) ? q('to') : $today;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [
        'from'    => $from,
        'to'      => $to,
        'status'  => array_key_exists(q('status'), REQUEST_STATUSES) ? q('status') : '',
        'type'    => q_int('type') ?: '',
        'purok'   => q_int('purok') ?: '',
        'channel' => in_array(q('channel'), ['online', 'walk_in'], true) ? q('channel') : '',
        'method'  => array_key_exists(q('method'), PAYMENT_METHODS) ? q('method') : '',
        'pstatus' => in_array(q('pstatus'), ['posted', 'voided'], true) ? q('pstatus') : '',
        'action'  => preg_match('/^[a-z_]{2,40}$/', q('action')) ? q('action') : '',
    ];
}

/** @return array{columns:array<string,string>, rows:array, totals:array<string,string>, formats:array<string,string>, truncated:bool} */
function run_report(string $key, array $f, int $limit = 1000): array
{
    $db = Supabase::user();
    $start = $f['from'] . 'T00:00:00+08:00';
    $end = $f['to'] . 'T23:59:59+08:00';
    $out = ['columns' => [], 'rows' => [], 'totals' => [], 'formats' => [], 'truncated' => false];

    switch ($key) {
        case 'register':
            $q = [['select', 'control_no,submitted_at,resident_name,purok_name,document_type,purpose,channel,fee_amount,fee_waived,status,released_at'],
                  ['submitted_at', 'gte.' . $start], ['submitted_at', 'lte.' . $end]];
            foreach (['status' => 'status', 'type' => 'document_type_id', 'purok' => 'purok_id', 'channel' => 'channel'] as $fk => $col) {
                if ($f[$fk] !== '') {
                    $q[] = [$col, 'eq.' . $f[$fk]];
                }
            }
            $q[] = ['order', 'submitted_at.asc'];
            $res = $db->select('request_list', $q, true, 0, $limit);
            $out['rows'] = array_map(static fn ($r) => $r + ['status_label' => status_label($r['status']), 'channel_label' => $r['channel'] === 'walk_in' ? 'Walk-in' : 'Online'], $res['rows']);
            $out['columns'] = ['control_no' => 'Control no.', 'submitted_at' => 'Filed', 'resident_name' => 'Resident', 'purok_name' => 'Purok',
                               'document_type' => 'Document', 'purpose' => 'Purpose', 'channel_label' => 'Channel', 'fee_amount' => 'Fee',
                               'status_label' => 'Status', 'released_at' => 'Released'];
            $out['formats'] = ['submitted_at' => 'date', 'released_at' => 'date', 'fee_amount' => 'money', 'control_no' => 'mono'];
            $released = count(array_filter($out['rows'], static fn ($r) => $r['status'] === 'released'));
            $out['totals'] = ['Requests' => number_format((int) ($res['total'] ?? count($out['rows']))), 'Released' => number_format($released),
                              'Fees assessed' => money(array_sum(array_column($out['rows'], 'fee_amount')))];
            $out['truncated'] = ($res['total'] ?? 0) > $limit;
            break;

        case 'collections':
            $q = [['select', 'or_number,paid_at,control_no,resident_name,document_type,method,reference_no,amount,status,received_by_name,void_reason'],
                  ['paid_at', 'gte.' . $start], ['paid_at', 'lte.' . $end]];
            if ($f['method'] !== '') {
                $q[] = ['method', 'eq.' . $f['method']];
            }
            if ($f['pstatus'] !== '') {
                $q[] = ['status', 'eq.' . $f['pstatus']];
            }
            $q[] = ['order', 'paid_at.asc'];
            $res = $db->select('payment_list', $q, true, 0, $limit);
            $out['rows'] = array_map(static fn ($r) => $r + ['method_label' => PAYMENT_METHODS[$r['method']] ?? $r['method'], 'status_label' => $r['status'] === 'posted' ? 'Posted' : 'Voided'], $res['rows']);
            $out['columns'] = ['or_number' => 'OR no.', 'paid_at' => 'Paid', 'control_no' => 'Control no.', 'resident_name' => 'Resident', 'document_type' => 'Document',
                               'method_label' => 'Method', 'reference_no' => 'Reference', 'amount' => 'Amount', 'status_label' => 'Status', 'received_by_name' => 'Received by'];
            $out['formats'] = ['paid_at' => 'datetime', 'amount' => 'money', 'or_number' => 'mono', 'control_no' => 'mono'];
            $posted = array_filter($out['rows'], static fn ($r) => $r['status'] === 'posted');
            $daily = [];
            foreach ($posted as $p) {
                $day = fmt_date($p['paid_at'], 'Y-m-d');
                $daily[$day] = ($daily[$day] ?? 0) + (float) $p['amount'];
            }
            $out['totals'] = ['Receipts posted' => number_format(count($posted)), 'Total collected' => money(array_sum(array_column($posted, 'amount'))),
                              'Voided' => number_format(count($out['rows']) - count($posted)), 'Days with collections' => number_format(count($daily))];
            $out['daily'] = $daily;
            $out['truncated'] = ($res['total'] ?? 0) > $limit;
            break;

        case 'issuance':
            $rows = $db->rpc('report_issuance_summary', ['p_from' => $f['from'], 'p_to' => $f['to'], 'p_type' => $f['type'] !== '' ? (int) $f['type'] : null]) ?: [];
            $out['rows'] = $rows;
            $out['columns'] = ['document_type' => 'Document', 'issued' => 'Issued', 'released' => 'Released', 'revoked' => 'Revoked', 'fees' => 'Fees collected'];
            $out['formats'] = ['fees' => 'money', 'issued' => 'int', 'released' => 'int', 'revoked' => 'int'];
            $out['totals'] = ['Documents issued' => number_format(array_sum(array_column($rows, 'issued'))), 'Fees collected' => money(array_sum(array_column($rows, 'fees')))];
            break;

        case 'processing':
            $rows = $db->rpc('report_processing_time', ['p_from' => $f['from'], 'p_to' => $f['to'], 'p_type' => $f['type'] !== '' ? (int) $f['type'] : null]) ?: [];
            $out['rows'] = array_map(static function ($r) {
                $r['on_target'] = $r['avg_days'] === null ? '—' : ((float) $r['avg_days'] <= (float) $r['target_days'] ? 'Yes' : 'No');
                return $r;
            }, $rows);
            $out['columns'] = ['document_type' => 'Document', 'target_days' => 'Target (days)', 'released' => 'Released', 'avg_days' => 'Average days',
                               'max_days' => 'Longest (days)', 'on_target' => 'On target', 'overdue_open' => 'Open and overdue'];
            $out['formats'] = ['released' => 'int', 'overdue_open' => 'int', 'avg_days' => 'decimal', 'max_days' => 'decimal'];
            $out['totals'] = ['Released in period' => number_format(array_sum(array_column($rows, 'released'))), 'Open and overdue now' => number_format(array_sum(array_column($rows, 'overdue_open')))];
            break;

        case 'residents':
            $rows = $db->rpc('report_residents_by_purok') ?: [];
            $out['rows'] = $rows;
            $out['columns'] = ['purok' => 'Purok', 'total' => 'Active residents', 'verified' => 'Verified', 'voters' => 'Registered voters', 'with_account' => 'With online account'];
            $out['formats'] = ['total' => 'int', 'verified' => 'int', 'voters' => 'int', 'with_account' => 'int'];
            $out['totals'] = ['Active residents' => number_format(array_sum(array_column($rows, 'total'))), 'Verified' => number_format(array_sum(array_column($rows, 'verified')))];
            break;

        case 'activity':
            $q = [['select', 'created_at,action,entity_type,entity_id,ip_address,profiles(full_name)'], ['created_at', 'gte.' . $start], ['created_at', 'lte.' . $end]];
            if ($f['action'] !== '') {
                $q[] = ['action', 'eq.' . $f['action']];
            }
            $q[] = ['order', 'created_at.asc'];
            $res = $db->select('audit_logs', $q, true, 0, $limit);
            $out['rows'] = array_map(static fn ($r) => $r + ['actor' => one($r['profiles'])['full_name'] ?? 'System'], $res['rows']);
            $out['columns'] = ['created_at' => 'When', 'actor' => 'User', 'action' => 'Action', 'entity_type' => 'Record type', 'entity_id' => 'Record id', 'ip_address' => 'IP'];
            $out['formats'] = ['created_at' => 'datetime', 'entity_id' => 'mono'];
            $out['totals'] = ['Entries' => number_format((int) ($res['total'] ?? count($out['rows'])))];
            $out['truncated'] = ($res['total'] ?? 0) > $limit;
            break;
    }
    return $out;
}

function report_cell(mixed $value, string $format, bool $forCsv = false): string
{
    if ($value === null || $value === '') {
        return $forCsv ? '' : '—';
    }
    return match ($format) {
        'money'    => $forCsv ? number_format((float) $value, 2, '.', '') : money($value),
        'date'     => fmt_date((string) $value, $forCsv ? 'Y-m-d' : 'M j, Y'),
        'datetime' => fmt_date((string) $value, $forCsv ? 'Y-m-d H:i' : 'M j, Y g:i A'),
        'int'      => $forCsv ? (string) (int) $value : number_format((int) $value),
        'decimal'  => number_format((float) $value, 1, '.', ''),
        default    => (string) $value,
    };
}
