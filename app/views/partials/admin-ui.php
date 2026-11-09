<?php
/**
 * app/views/partials/admin-ui.php
 *
 * Small pieces of screen shared by the admin pages: input styling, coloured
 * pills, department drop-down options and the Previous / Next buttons.
 */

/** CSS class for text boxes and drop-downs (styled in admin_ui_styles). */
function admin_input_class(): string
{
    return 'ui-input';
}

/** CSS class for a field label. */
function admin_label_class(): string
{
    return 'ui-label';
}

/** CSS classes for a button. $variant: primary, secondary or danger. $extra: more classes, e.g. 'ui-btn-sm'. */
function admin_btn(string $variant = 'primary', string $extra = ''): string
{
    $v = in_array($variant, ['primary', 'secondary', 'danger'], true) ? $variant : 'primary';
    return trim('ui-btn ui-btn-' . $v . ' ' . $extra);
}

/** CSS classes for a tab link. */
function admin_tab_class(bool $on): string
{
    return $on ? 'ui-tab ui-tab-on' : 'ui-tab';
}

/** CSS class for a table header row. */
function admin_thead_class(): string
{
    return 'ui-thead';
}

/** CSS class for a table body row. */
function admin_row_class(string $extra = ''): string
{
    return trim('ui-row ' . $extra);
}

/** A small coloured pill with a dot. Tones: green, amber, red, blue, slate. */
function admin_pill(string $text, string $tone = 'slate'): string
{
    $tone = in_array($tone, ['green', 'amber', 'red', 'blue', 'slate'], true) ? $tone : 'slate';
    return '<span class="ui-pill ui-pill-' . $tone . '">' . e($text) . '</span>';
}

/**
 * The look of the admin pages: plain CSS, so it does not depend on the Tailwind build.
 * Echo it once per page, right after the header is loaded.
 */
function admin_ui_styles(): string
{
    return <<<'CSS'
<style>
:root{--ui-brand:#15803d;--ui-brand-dark:#166534;--ui-ring:rgba(22,163,74,.25)}

/* ---- Buttons ---- */
.ui-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;flex-shrink:0;white-space:nowrap;
  min-height:2.5rem;padding:0 1.1rem;border-radius:.625rem;border:1px solid transparent;font-size:.875rem;font-weight:600;
  line-height:1;cursor:pointer;text-decoration:none;transition:transform .12s ease,box-shadow .15s ease,background-color .15s ease,border-color .15s ease,color .15s ease}
.ui-btn:focus-visible{outline:none;box-shadow:0 0 0 3px var(--ui-ring)}
.ui-btn:active{transform:translateY(1px) scale(.985)}
.ui-btn-disabled,.ui-btn:disabled{opacity:.45;cursor:not-allowed;pointer-events:none}
.ui-btn-sm{min-height:2.125rem;padding:0 .8rem;font-size:.8125rem}
.ui-btn-primary{color:#fff;background:linear-gradient(180deg,#16a34a 0%,var(--ui-brand) 100%);border-color:#14532d;
  box-shadow:0 1px 2px rgba(20,83,45,.35),inset 0 1px 0 rgba(255,255,255,.18)}
.ui-btn-primary:hover{background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);box-shadow:0 4px 12px rgba(22,163,74,.35),inset 0 1px 0 rgba(255,255,255,.22);transform:translateY(-1px)}
.ui-btn-secondary{color:#334155;background:#fff;border-color:#cbd5e1;box-shadow:0 1px 2px rgba(15,23,42,.06)}
.ui-btn-secondary:hover{background:#f1f5f9;border-color:#94a3b8;color:#0f172a}
.ui-btn-danger{color:#b91c1c;background:#fef2f2;border-color:#fecaca}
.ui-btn-danger:hover{color:#fff;background:#dc2626;border-color:#dc2626;box-shadow:0 4px 12px rgba(220,38,38,.3)}
.ui-btn-danger:focus-visible{box-shadow:0 0 0 3px rgba(220,38,38,.25)}

/* ---- Tabs ---- */
.ui-tab{display:inline-flex;align-items:center;min-height:2.5rem;padding:0 1.1rem;border-radius:9999px;border:1px solid #cbd5e1;
  background:#fff;color:#334155;font-size:.875rem;font-weight:500;text-decoration:none;transition:all .15s ease}
.ui-tab:hover{background:#f1f5f9;border-color:#94a3b8}
.ui-tab-on,.ui-tab-on:hover{color:#fff;font-weight:600;background:linear-gradient(180deg,#16a34a,var(--ui-brand));border-color:#14532d;box-shadow:0 2px 8px rgba(22,163,74,.3)}

/* ---- Form fields ---- */
.ui-label{display:block;margin-bottom:.375rem;font-size:.8125rem;font-weight:600;color:#334155}
.ui-input{display:block;width:100%;min-width:0;box-sizing:border-box;min-height:2.5rem;padding:.55rem .8rem;border:1px solid #cbd5e1;border-radius:.625rem;
  background-color:#fff;color:#0f172a;font-size:.875rem;line-height:1.25rem;box-shadow:0 1px 2px rgba(15,23,42,.05);transition:border-color .15s ease,box-shadow .15s ease}
.ui-input::placeholder{color:#94a3b8}
.ui-input:hover{border-color:#94a3b8}
.ui-input:focus{outline:none;border-color:#16a34a;box-shadow:0 0 0 3px var(--ui-ring)}
.ui-inline-form{display:flex;align-items:center;gap:.5rem;min-width:280px}
.ui-inline-form.ui-between{justify-content:space-between}

/* ---- Pills ---- */
.ui-pill{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .7rem;border-radius:9999px;border:1px solid;font-size:.75rem;font-weight:600;line-height:1;white-space:nowrap}
.ui-pill::before{content:"";width:.4rem;height:.4rem;border-radius:9999px;background:currentColor;opacity:.9}
.ui-pill-green{color:#047857;background:#ecfdf5;border-color:#a7f3d0}
.ui-pill-amber{color:#b45309;background:#fffbeb;border-color:#fde68a}
.ui-pill-red{color:#b91c1c;background:#fef2f2;border-color:#fecaca}
.ui-pill-blue{color:#0369a1;background:#f0f9ff;border-color:#bae6fd}
.ui-pill-slate{color:#475569;background:#f1f5f9;border-color:#cbd5e1}

/* ---- Tables ---- */
.ui-table{width:100%;border-collapse:separate;border-spacing:0;text-align:left;font-size:.875rem}
.ui-table thead th{padding:.8rem 1rem;background:linear-gradient(180deg,#f8fafc,#f1f5f9);border-bottom:1px solid #e2e8f0;
  color:#64748b;font-size:.6875rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;white-space:nowrap}
.ui-table tbody td{padding:.9rem 1rem;border-bottom:1px solid #f1f5f9;transition:background-color .12s ease}
.ui-table tbody tr:last-child td{border-bottom:0}
.ui-table tbody tr:hover td{background:#f0fdf4}
.ui-table tbody tr:nth-child(even) td{background-color:#fcfdfe}
.ui-table tbody tr:nth-child(even):hover td{background:#f0fdf4}
.card.ui-table-card{border:1px solid #e2e8f0;border-radius:1rem;box-shadow:0 1px 3px rgba(15,23,42,.06),0 8px 24px -12px rgba(15,23,42,.12)}
</style>
CSS;
}

/** The pill for an account status. */
function admin_user_status_pill(string $status): string
{
    $tones = ['active' => 'green', 'pending' => 'amber', 'disabled' => 'slate', 'rejected' => 'red'];
    return admin_pill(ucfirst($status), $tones[$status] ?? 'slate');
}

/**
 * <option> tags for a department drop-down.
 * $names is [id => name]. $blank (when not '') adds a first "no choice" option.
 */
function admin_department_options(array $names, int $selected = 0, string $blank = ''): string
{
    $html = '';
    if ($blank !== '') {
        $html .= '<option value="0"' . ($selected === 0 ? ' selected' : '') . '>' . e($blank) . '</option>';
    }
    foreach ($names as $id => $name) {
        $html .= '<option value="' . (int) $id . '"' . ((int) $id === $selected ? ' selected' : '') . '>'
              . e($name) . '</option>';
    }
    return $html;
}

/**
 * Previous / Next buttons. $query holds the filters to keep in the links
 * (leave empty ones out), $file is the page, for example 'admin/audit.php'.
 */
function admin_pager(int $page, int $pages, array $query, string $file): void
{
    if ($pages <= 1) {
        return;
    }
    $href = static function (int $p) use ($query, $file): string {
        return url($file . '?' . http_build_query(array_merge($query, ['page' => $p])));
    };
    $on  = admin_btn('secondary');
    $off = 'ui-btn ui-btn-secondary ui-btn-disabled';

    echo '<nav class="mt-4 flex items-center justify-between gap-3" aria-label="Pages">';
    echo $page > 1
        ? '<a class="' . $on . '" href="' . e($href($page - 1)) . '">Previous</a>'
        : '<span class="' . $off . '">Previous</span>';
    echo '<span class="text-sm font-medium text-slate-600">Page ' . $page . ' of ' . $pages . '</span>';
    echo $page < $pages
        ? '<a class="' . $on . '" href="' . e($href($page + 1)) . '">Next</a>'
        : '<span class="' . $off . '">Next</span>';
    echo '</nav>';
}