<?php
/**
 * app/views/partials/mms-ui.php
 *
 * Two small helpers shared by the MMS pages and the MMS dashboard.
 */

/** The coloured status label (same colours as everywhere, from definitions.php). */
function status_pill(string $status): string
{
    return '<span class="inline-block whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset '
        . e(status_classes($status)) . '">' . e(status_label($status)) . '</span>';
}

/**
 * A link to mms/records.php (or another page) that keeps the filters.
 * Empty filters are left out so the address stays short.
 * Escape the result with e() when you print it.
 *
 * Example: mms_records_url($filters, ['page' => 3])
 */
function mms_records_url(array $filters, array $override = [], string $page = 'mms/records.php'): string
{
    $all   = array_merge($filters, $override);
    $query = [];

    foreach (['q', 'status', 'from', 'to', 'department', 'mode', 'stuck', 'page'] as $key) {
        $value = $all[$key] ?? '';
        if ($value === '' || $value === null || $value === 0 || $value === '0') {
            continue;
        }
        if ($key === 'page' && (int) $value <= 1) {
            continue;
        }
        $query[$key] = $value;
    }

    return url($page) . ($query ? '?' . http_build_query($query) : '');
}