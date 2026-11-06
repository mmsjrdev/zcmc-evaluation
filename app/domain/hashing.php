<?php
/**
 * app/domain/hashing.php
 *
 * Fingerprints of the form content. Step 4 adds the supplier hash; Step 9 adds
 * the whole-form content hash to this same file.
 *
 * A hash is a SHA-256 "fingerprint" of the text. If anyone changes even one
 * letter in the database, the fingerprint no longer matches.
 */

/**
 * SHA-256 of the SUPPLIER-owned fields (header + Section A columns 1 to 3).
 * Stored when the supplier submits. Signing re-calculates it and refuses to
 * continue if it changed.
 *
 * $header : bid_ref_no, mode_id, opening_date, bidder, eval_date, header_item_no
 * $items  : list of rows with item_no, specifications, bidder_spec (in order)
 */
function supplier_hash(array $header, array $items): string
{
    // Same keys, same order, everything as plain text: the same data always
    // gives the same fingerprint, whether it comes from the form or the database.
    $canonical = [
        'bid_ref_no'     => (string) ($header['bid_ref_no'] ?? ''),
        'mode_id'        => (string) ($header['mode_id'] ?? ''),
        'opening_date'   => (string) ($header['opening_date'] ?? ''),
        'bidder'         => (string) ($header['bidder'] ?? ''),
        'eval_date'      => (string) ($header['eval_date'] ?? ''),
        'header_item_no' => (string) ($header['header_item_no'] ?? ''),
        'items'          => [],
    ];
    foreach (array_values($items) as $row) {
        $canonical['items'][] = [
            'item_no'        => (string) ($row['item_no'] ?? ''),
            'specifications' => (string) ($row['specifications'] ?? ''),
            'bidder_spec'    => (string) ($row['bidder_spec'] ?? ''),
        ];
    }

    return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
