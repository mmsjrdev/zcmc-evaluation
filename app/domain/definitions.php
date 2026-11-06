<?php
/**
 * app/domain/definitions.php
 *
 * Fixed lists that many pages need, kept in ONE place:
 *   - the 11 form statuses (label + badge colour)
 *   - the Definition of Terms printed on the form (pasted in Step 7)
 *
 * To rename a status or change its colour, edit the array below and nothing else.
 * The colour classes are written out in full on purpose: Tailwind only creates
 * CSS for class names it can find as complete words in your files.
 */

/**
 * Every status of a form: [ label shown on screen, Tailwind colour classes ].
 * The ORDER is the normal journey of a form, then the side branches.
 */
function status_definitions(): array
{
    return [
        'supplier_draft'     => ['Draft',                 'bg-slate-100 text-slate-700 ring-slate-300'],
        'submitted'          => ['Submitted to MMS',      'bg-sky-100 text-sky-800 ring-sky-300'],
        'with_head_nurse'    => ['With Head Nurse',       'bg-indigo-100 text-indigo-800 ring-indigo-300'],
        'pending_evaluation' => ['Pending evaluation',    'bg-amber-100 text-amber-800 ring-amber-300'],
        'pending_head'       => ['Pending Department Head', 'bg-orange-100 text-orange-800 ring-orange-300'],
        'pending_conforme'   => ['Pending Conforme',      'bg-violet-100 text-violet-800 ring-violet-300'],
        'completed'          => ['Completed',             'bg-green-100 text-green-800 ring-green-300'],
        'received'           => ['Received',              'bg-emerald-100 text-emerald-900 ring-emerald-400'],
        'returned_supplier'  => ['Returned to supplier',  'bg-rose-100 text-rose-800 ring-rose-300'],
        'returned_evaluator' => ['Returned to evaluator', 'bg-rose-100 text-rose-800 ring-rose-300'],
        'cancelled'          => ['Cancelled',             'bg-zinc-200 text-zinc-700 ring-zinc-300'],
    ];
}

/** The screen label of a status, for example 'with_head_nurse' -> 'With Head Nurse'. */
function status_label(string $status): string
{
    return status_definitions()[$status][0] ?? ucfirst(str_replace('_', ' ', $status));
}

/** The colour classes of a status badge. */
function status_classes(string $status): string
{
    return status_definitions()[$status][1] ?? 'bg-slate-100 text-slate-700 ring-slate-300';
}

/**
 * The 15 "Definition of Terms" from the printed form.
 * Empty until Step 7, where you paste them. Shape of each entry:
 *   [ 'term' => 'Functionality', 'meaning' => 'The text printed on the form.' ]
 */
function definition_of_terms(): array
{
    return [];
}
