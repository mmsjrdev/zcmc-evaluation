<?php
/**
 * app/views/partials/status-badge.php
 *
 * The small coloured pill that shows a form's status.
 * Usage:  <?= status_badge($row['status']) ?>
 * Labels and colours come from app/domain/definitions.php.
 */

function status_badge(string $status): string
{
    return '<span class="badge ring-1 ring-inset whitespace-nowrap ' . e(status_classes($status)) . '">'
         . e(status_label($status))
         . '</span>';
}
