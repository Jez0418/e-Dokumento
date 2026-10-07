<?php
declare(strict_types=1);

require_once BASE_PATH . '/includes/reports.php';

$role = Auth::role();
$catalog = array_filter(report_catalog(), static fn ($r) => in_array($role, $r['roles'], true));
$key = q('report');
if (!array_key_exists($key, $catalog)) {
    abort(403, 'Your role does not include this report.');
}
$f = report_filters();
try {
    $data = run_report($key, $f, 5000);
} catch (Throwable $e) {
    flash_error(db_error($e));
    redirect(url('reports', ['report' => $key]));
}
Audit::event('export', 'reports', $key, ['from' => $f['from'], 'to' => $f['to'], 'rows' => count($data['rows'])]);

$filename = 'e-dokumento-' . $key . '-' . $f['from'] . '-to-' . $f['to'] . '.csv';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ñ and ₱ correctly
fputcsv($out, array_values($data['columns']));
foreach ($data['rows'] as $row) {
    $line = [];
    foreach ($data['columns'] as $col => $label) {
        $cell = report_cell($row[$col] ?? null, $data['formats'][$col] ?? '', true);
        // Neutralise spreadsheet formulas in user-entered text
        if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true) && !is_numeric($cell)) {
            $cell = "'" . $cell;
        }
        $line[] = $cell;
    }
    fputcsv($out, $line);
}
fclose($out);
exit;
