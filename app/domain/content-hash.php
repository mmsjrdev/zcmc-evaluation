<?php
/**
 * app/domain/content-hash.php
 *
 * ONE function that fingerprints the whole form (Step 9). It is used when a
 * signature is saved, and verify.php (Step 10) will call the very same function
 * to check that nothing changed after signing.
 *
 * (supplier_hash() for the supplier-owned fields stays in app/domain/hashing.php.
 *  You may move form_content_hash() into hashing.php later; nothing else changes.)
 */

/**
 * SHA-256 of a canonical JSON text of the form as it is stored RIGHT NOW:
 *   - header fields, reference number, version
 *   - Section A items in form order (supplier columns + finding and grounds)
 *   - Sections B and C
 * Signatures are never part of it. Same data in = same hash out.
 */
function form_content_hash(int $evaluationId): string
{
    $stmt = db()->prepare(
        'SELECT ref_no, version, form_no, revision_no, bid_ref_no, mode_id, opening_date,
                header_item_no, bidder, eval_date,
                failed_quality, b_params, b_details, others_checked, c_params, c_details
           FROM evaluations
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $evaluationId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Form not found for hashing.');
    }

    $itemStmt = db()->prepare(
        'SELECT item_no, specifications, bidder_spec, finding, grounds
           FROM evaluation_items
          WHERE evaluation_id = :id
          ORDER BY sort_order, id'
    );
    $itemStmt->execute([':id' => $evaluationId]);

    // Every value becomes a plain string (NULL = ''), and the keys are written
    // in a fixed order, so the JSON text is always the same for the same data.
    $text = static function ($value): string {
        return $value === null ? '' : (string) $value;
    };

    $items = [];
    foreach ($itemStmt->fetchAll() as $it) {
        $items[] = [
            'item_no'        => $text($it['item_no']),
            'specifications' => $text($it['specifications']),
            'bidder_spec'    => $text($it['bidder_spec']),
            'finding'        => $text($it['finding']),
            'grounds'        => $text($it['grounds']),
        ];
    }

    $canonical = [
        'ref_no'         => $text($row['ref_no']),
        'version'        => (int) $row['version'],
        'form_no'        => $text($row['form_no']),
        'revision_no'    => $text($row['revision_no']),
        'bid_ref_no'     => $text($row['bid_ref_no']),
        'mode_id'        => $text($row['mode_id']),
        'opening_date'   => $text($row['opening_date']),
        'header_item_no' => $text($row['header_item_no']),
        'bidder'         => $text($row['bidder']),
        'eval_date'      => $text($row['eval_date']),
        'items'          => $items,
        'failed_quality' => (int) $row['failed_quality'],
        'b_params'       => $text($row['b_params']),
        'b_details'      => $text($row['b_details']),
        'others_checked' => (int) $row['others_checked'],
        'c_params'       => $text($row['c_params']),
        'c_details'      => $text($row['c_details']),
    ];

    return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}