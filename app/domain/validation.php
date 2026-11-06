<?php
/**
 * app/domain/validation.php
 *
 * Rules for the form fields. Step 4 adds the SUPPLIER part;
 * Step 7 adds the evaluator part to this same file.
 *
 * Every function returns an array of error messages keyed by field:
 *   ['bid_ref_no' => 'Enter ...', 'items.0.specifications' => 'Enter ...']
 * An empty array means "no problems".
 */

/** Is this text a real calendar date written as YYYY-MM-DD? */
function is_valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

/** Does a procurement mode with this id exist and is it active? */
function procurement_mode_exists(int $id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM procurement_modes WHERE id = :id AND active = 1 LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Check the supplier-owned part.
 *
 * $forSubmit = false  "Save draft": only shape problems are errors (a date that
 *                     is not a date, text that is too long). Empty is fine.
 * $forSubmit = true   "Submit to MMS": everything must be filled in.
 */
function validate_supplier_part(array $header, array $items, bool $forSubmit): array
{
    $errors = [];

    // ---- Header ------------------------------------------------------------
    $labels = [
        'bid_ref_no'     => ['Bid/Reference No.', 100],
        'bidder'         => ['Bidder', 190],
        'header_item_no' => ['Item No.', 50],
    ];
    foreach ($labels as $name => [$label, $max]) {
        $value = (string) ($header[$name] ?? '');
        if ($forSubmit && $value === '') {
            $errors[$name] = "Enter the $label.";
        } elseif (mb_strlen($value) > $max) {
            $errors[$name] = "$label can have at most $max characters.";
        }
    }

    foreach (['opening_date' => 'Date of Opening', 'eval_date' => 'Date of Evaluation'] as $name => $label) {
        $value = (string) ($header[$name] ?? '');
        if ($value === '') {
            if ($forSubmit) {
                $errors[$name] = "Enter the $label.";
            }
        } elseif (!is_valid_date($value)) {
            $errors[$name] = "$label is not a valid date.";
        }
    }

    $mode = (string) ($header['mode_id'] ?? '');
    if ($mode === '') {
        if ($forSubmit) {
            $errors['mode_id'] = 'Choose the Mode of Procurement.';
        }
    } elseif (!ctype_digit($mode) || !procurement_mode_exists((int) $mode)) {
        $errors['mode_id'] = 'Choose a Mode of Procurement from the list.';
    }

    // ---- Section A rows ----------------------------------------------------
    if ($forSubmit && count($items) === 0) {
        $errors['items'] = 'Add at least one item to Section A.';
    }
    if (count($items) > MAX_FORM_ITEMS) {
        $errors['items'] = 'A form can have at most ' . MAX_FORM_ITEMS . ' items.';
    }

    $itemRules = [
        'item_no'        => ['Item No.', 20],
        'specifications' => ['Technical Specifications', 5000],
        'bidder_spec'    => ["Bidder's Specification/Brand", 5000],
    ];
    foreach ($items as $i => $row) {
        foreach ($itemRules as $name => [$label, $max]) {
            $value = (string) ($row[$name] ?? '');
            if ($forSubmit && $value === '') {
                $errors["items.$i.$name"] = "Enter the $label.";
            } elseif (mb_strlen($value) > $max) {
                $errors["items.$i.$name"] = "$label can have at most $max characters.";
            }
        }
    }

    return $errors;
}
