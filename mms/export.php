<?php
/**
 * mms/export.php
 *
 * Step 11: CSV of the CURRENT filter on mms/records.php (all pages, not just
 * the 20 on screen). UTF-8 with a BOM so Excel opens it correctly.
 *
 * Summary data only: never signature images, never evaluator findings.
 * A cell that starts with = + - @ (or a tab / carriage return) gets an
 * apostrophe in front so Excel cannot run it as a formula.
 *
 * Who: mm_staff and admin. It only reads data, so there is no CSRF token.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/mms-records.php';

$user = require_role(['mm_staff', 'admin']);

$f = mms_records_filters($_GET);

/** Makes one cell safe for Excel. */
function csv_safe($value): string
{
    $text = (string) ($value ?? '');
    if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
        return "'" . $text;
    }
    return $text;
}

$total = mms_records_count($f);
audit('records_export', 'User #' . (int) $user['id'] . ' exported ' . $total . ' form(s) to CSV. Filters: ' . json_encode([
    'q' => $f['q'], 'status' => $f['status'], 'from' => $f['from'], 'to' => $f['to'],
    'department' => $f['department'], 'mode' => $f['mode'], 'stuck' => $f['stuck'],
]));

$stmt = mms_records_statement($f);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="zcmc-records-' . date('Ymd-His') . '.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // the BOM

fputcsv($out, [
    'Reference No.', 'Bid/Reference No.', 'Supplier', 'Bidder', 'Department', 'Mode of procurement',
    'Status', 'Version', 'Opening date', 'Evaluation date', 'Submitted', 'Last status change',
], ',', '"', '');

while ($r = $stmt->fetch()) {
    $supplier = trim((string) ($r['supplier_business'] ?? '')) !== ''
        ? (string) $r['supplier_business']
        : (string) ($r['supplier_name'] ?? '');

    fputcsv($out, [
        csv_safe($r['ref_no'] ?? ''),
        csv_safe($r['bid_ref_no'] ?? ''),
        csv_safe($supplier),
        csv_safe($r['bidder'] ?? ''),
        csv_safe($r['department_name'] ?? ''),
        csv_safe($r['mode_name'] ?? ''),
        csv_safe(status_label((string) $r['status'])),
        (int) $r['version'],
        csv_safe($r['opening_date'] ?? ''),
        csv_safe($r['eval_date'] ?? ''),
        csv_safe($r['submitted_at'] ?? ''),
        csv_safe($r['last_change_at'] ?? ''),
    ], ',', '"', '');
}

fclose($out);
exit;