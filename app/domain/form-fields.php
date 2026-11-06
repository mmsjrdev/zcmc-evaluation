<?php
/**
 * app/domain/form-fields.php
 *
 * WHO OWNS WHICH FIELD of the evaluation form. This is the only list.
 * When the hospital changes a rule (for example "the evaluator fills in the
 * Date of Evaluation"), change the owner here and nothing else.
 *
 * The server uses it on every POST: filter_post() keeps ONLY the fields the
 * current role may write at the current status and throws the rest away.
 * Hiding a field in the page is not security; this is.
 *
 * Owners: supplier | head_nurse | evaluator
 * Scope:  header = one value per form, item = one value per Section A row.
 */

/** How many Section A rows one form may have. */
const MAX_FORM_ITEMS = 50;

/** Every field: name => [owner, scope]. */
function form_field_map(): array
{
    return [
        // ---- Header (supplier) -------------------------------------------
        'bid_ref_no'     => ['supplier', 'header'],   // Bid/Reference No.
        'mode_id'        => ['supplier', 'header'],   // Mode of Procurement
        'opening_date'   => ['supplier', 'header'],   // Date of Opening
        'bidder'         => ['supplier', 'header'],   // Bidder (business name)
        'eval_date'      => ['supplier', 'header'],   // Date of Evaluation (as in the photo; see guide Section 12, item 2)
        'header_item_no' => ['supplier', 'header'],   // "Item No." in the header

        // ---- Section A, columns 1 to 3 (supplier) ------------------------
        'item_no'        => ['supplier', 'item'],
        'specifications' => ['supplier', 'item'],     // Technical Specifications
        'bidder_spec'    => ['supplier', 'item'],     // Bidder's Specification/Brand

        // ---- Routing (Head Nurse) ----------------------------------------
        'department_id'  => ['head_nurse', 'header'],

        // ---- Section A, Findings and Grounds (evaluator) -----------------
        'finding'        => ['evaluator', 'item'],
        'grounds'        => ['evaluator', 'item'],

        // ---- Section B and Section C (evaluator) -------------------------
        'failed_quality' => ['evaluator', 'header'],
        'b_params'       => ['evaluator', 'header'],
        'b_details'      => ['evaluator', 'header'],
        'others_checked' => ['evaluator', 'header'],
        'c_params'       => ['evaluator', 'header'],
        'c_details'      => ['evaluator', 'header'],
    ];
}

/** Who owns this field? Returns null for a name that is not a form field. */
function field_owner(string $name): ?string
{
    return form_field_map()[$name][0] ?? null;
}

/**
 * The fields a role may WRITE while the form has this status.
 *  supplier     in supplier_draft and returned_supplier
 *  head_nurse   in with_head_nurse (department only)
 *  evaluator    in pending_evaluation and returned_evaluator
 * Everybody else, and every other status: nothing.
 * (An evaluator's rule "only before the first signature" is checked by
 * can_edit_evaluator_part(); this function only looks at role and status.)
 */
function fields_for(string $role, string $status): array
{
    $ownerOfRole = [
        'supplier'   => ['supplier',   ['supplier_draft', 'returned_supplier']],
        'head_nurse' => ['head_nurse', ['with_head_nurse']],
        'evaluator'  => ['evaluator',  ['pending_evaluation', 'returned_evaluator']],
        'head'       => ['evaluator',  ['pending_evaluation', 'returned_evaluator']],  // a head who evaluates
    ];

    if (!isset($ownerOfRole[$role])) {
        return [];
    }
    [$owner, $statuses] = $ownerOfRole[$role];
    if (!in_array($status, $statuses, true)) {
        return [];
    }

    $names = [];
    foreach (form_field_map() as $name => [$fieldOwner, $scope]) {
        if ($fieldOwner === $owner) {
            $names[] = $name;
        }
    }
    return $names;
}

/**
 * Takes the raw $_POST and returns ONLY what this role may write at this status.
 *
 * Result: header fields at the top level (as trimmed strings), and the Section A
 * rows under 'items' (a list; each row has only the item fields allowed).
 * Anything else that was posted (a hand-made 'finding', 'department_id', ...)
 * is silently dropped.
 */
function filter_post(string $role, string $status, array $post): array
{
    $allowed = fields_for($role, $status);
    $map     = form_field_map();
    $result  = [];

    // Header fields.
    foreach ($allowed as $name) {
        if ($map[$name][1] !== 'header') {
            continue;
        }
        $value = $post[$name] ?? '';
        if (is_array($value)) {
            // b_params and c_params are lists of ticked boxes.
            $result[$name] = array_values(array_filter(array_map(
                static fn($v) => is_string($v) ? trim($v) : '',
                $value
            ), static fn($v) => $v !== ''));
        } else {
            $result[$name] = trim((string) $value);
        }
    }

    // Section A rows.
    $itemFields = array_values(array_filter($allowed, static fn($n) => $map[$n][1] === 'item'));
    if ($itemFields !== []) {
        $rows = [];
        $posted = $post['items'] ?? [];
        if (is_array($posted)) {
            foreach (array_slice(array_values($posted), 0, MAX_FORM_ITEMS) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $clean = [];
                foreach ($itemFields as $name) {
                    $v = $row[$name] ?? '';
                    $clean[$name] = is_string($v) ? trim($v) : '';
                }
                $rows[] = $clean;
            }
        }
        $result['items'] = $rows;
    }

    return $result;
}
