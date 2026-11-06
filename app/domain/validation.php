<?php
/**
 * app/domain/validation.php
 *
 * Rules for the form fields. Step 4 added the SUPPLIER part;
 * Step 7 adds the EVALUATOR part to this same file.
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

// ===========================================================================
// STEP 7: the EVALUATOR part
// ===========================================================================

/**
 * Parameter codes as a clean list. Accepts a list (from checkboxes) or a
 * comma-separated string (as stored in the database).
 */
function ev_params_to_array($value): array
{
    if (is_string($value)) {
        $value = explode(',', $value);
    }
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $v) {
        if (is_string($v) && trim($v) !== '') {
            $out[] = trim($v);
        }
    }
    return array_values(array_unique($out));
}

/**
 * Check the evaluator-owned part.
 *
 * $data  = ['failed_quality', 'b_params', 'b_details', 'others_checked',
 *           'c_params', 'c_details', 'items' => [ ['finding','grounds'], ... ]]
 * $items = the stored Section A rows (only their number matters here).
 *
 * $forReview = false  "Save draft": only shape problems (a wrong finding value,
 *                     a wrong parameter code, text that is too long).
 * $forReview = true   "Save and review": everything must be complete.
 *
 * Keys: items.N.finding, items.N.grounds, b_params, b_details, c_params,
 *       c_details, items.
 */
function validate_evaluator_part(array $data, array $items, bool $forReview): array
{
    $errors = [];
    $maxText = 5000;
    $minDetails = 20;

    // ---- Section A: findings and grounds ----------------------------------
    $rows = array_values($data['items'] ?? []);
    if (count($rows) !== count($items)) {
        $errors['items'] = 'The number of items does not match the saved form.';
        return $errors;
    }

    foreach (array_values($items) as $i => $stored) {
        $finding = (string) ($rows[$i]['finding'] ?? '');
        $grounds = trim((string) ($rows[$i]['grounds'] ?? ''));

        if ($finding === '') {
            if ($forReview) {
                $errors["items.$i.finding"] = 'Choose Responsive or Non-Responsive.';
            }
        } elseif (!in_array($finding, ['responsive', 'non_responsive'], true)) {
            $errors["items.$i.finding"] = 'Choose Responsive or Non-Responsive.';
        }

        if ($finding === 'non_responsive') {
            if (mb_strlen($grounds) > $maxText) {
                $errors["items.$i.grounds"] = "The ground/s can have at most $maxText characters.";
            } elseif ($forReview && $grounds === '') {
                $errors["items.$i.grounds"] = 'Write the ground/s for a Non-Responsive item.';
            }
        }
    }

    // ---- Section B: Quality Compliance ------------------------------------
    if ((int) ($data['failed_quality'] ?? 0) === 1) {
        $errors += ev_check_box_part(
            ev_params_to_array($data['b_params'] ?? []),
            trim((string) ($data['b_details'] ?? '')),
            quality_parameters(), 'b_params', 'b_details',
            $forReview, $minDetails, $maxText, true
        );
    }

    // ---- Section C: Others -------------------------------------------------
    if ((int) ($data['others_checked'] ?? 0) === 1) {
        $errors += ev_check_box_part(
            ev_params_to_array($data['c_params'] ?? []),
            trim((string) ($data['c_details'] ?? '')),
            others_parameters(), 'c_params', 'c_details',
            $forReview, $minDetails, $maxText, false
        );
    }

    return $errors;
}

/**
 * Rules shared by Section B and Section C once the box is ticked:
 * valid parameter codes, at least one parameter, details of a minimum length.
 * Section B ($rejectLowQuality) also rejects details that only say "Low Quality".
 */
function ev_check_box_part(array $params, string $details, array $allowed, string $paramKey, string $detailKey,
                           bool $forReview, int $minDetails, int $maxText, bool $rejectLowQuality): array
{
    $errors = [];

    foreach ($params as $code) {
        if (!isset($allowed[$code])) {
            $errors[$paramKey] = 'Choose parameters from the list only.';
            break;
        }
    }
    if ($forReview && $params === []) {
        $errors[$paramKey] = 'Tick at least one parameter.';
    }

    if (mb_strlen($details) > $maxText) {
        $errors[$detailKey] = "The details can have at most $maxText characters.";
    } elseif ($forReview) {
        if (mb_strlen($details) < $minDetails) {
            $errors[$detailKey] = $rejectLowQuality
                ? "Write at least $minDetails characters. 'Low Quality' alone is not sufficient. Describe what failed."
                : "Write at least $minDetails characters of details.";
        } elseif ($rejectLowQuality) {
            // Take "low quality" out; enough real words must be left over.
            $rest = preg_replace('/low\s*quality/iu', '', $details);
            $letters = preg_replace('/[^\p{L}\p{N}]/u', '', (string) $rest);
            if (mb_strlen((string) $letters) < 10) {
                $errors[$detailKey] = "'Low Quality' alone is not sufficient. Describe what failed.";
            }
        }
    }

    return $errors;
}