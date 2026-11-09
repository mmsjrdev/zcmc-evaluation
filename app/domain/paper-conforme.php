<?php
/**
 * app/domain/paper-conforme.php
 *
 * Step 10: Conforme on paper. MMS prints the form, the supplier signs by hand,
 * MMS uploads the scan here. No signature row is created for paper.
 *
 *   conforme_scan_check($_FILES['scan'])        checks the upload, returns 'pdf' | 'jpg' | 'png'
 *   conforme_paper_complete($user, $id, $file)  saves the file and completes the form
 *
 * Files go ONLY into storage/uploads/ under a random name. The original file
 * name is never used.
 */

require_once __DIR__ . '/supplier-conforme.php';

const CONFORME_SCAN_MAX_BYTES = 5242880;   // 5 MB

/**
 * Checks the extension, the REAL file type (finfo) and the size.
 * Returns the safe extension to use. Throws SigningRefused with a plain message.
 */
function conforme_scan_check(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new SigningRefused('Please choose the scanned file first.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new SigningRefused('The file is too large. The limit is 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new SigningRefused('The upload did not finish. Please try again.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new SigningRefused('The upload could not be read. Please try again.');
    }

    $size = (int) filesize($tmp);
    if ($size <= 0) {
        throw new SigningRefused('The file is empty.');
    }
    if ($size > CONFORME_SCAN_MAX_BYTES) {
        throw new SigningRefused('The file is too large. The limit is 5 MB.');
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        throw new SigningRefused('Only PDF, JPG or PNG files are accepted.');
    }

    if (!class_exists('finfo')) {
        throw new SigningRefused('The server cannot check file types, so the upload is closed. Contact the developer.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

    // The real type decides. The extension must agree with it.
    $allowed = [
        'application/pdf' => ['pdf'],
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
    ];
    if (!isset($allowed[$mime]) || !in_array($ext, $allowed[$mime], true)) {
        throw new SigningRefused('The file content does not match a PDF, JPG or PNG. It was not accepted.');
    }

    // An image must also open as an image.
    if ($mime !== 'application/pdf' && @getimagesize($tmp) === false) {
        throw new SigningRefused('The picture could not be read. Please scan it again.');
    }

    return $mime === 'image/jpeg' ? 'jpg' : $allowed[$mime][0];
}

/**
 * MMS uploads the signed paper. The form becomes "completed" with
 * conforme_method = 'paper'. Everything runs in one transaction; if anything
 * fails after the file was saved, the file is removed again.
 */
function conforme_paper_complete(array $user, int $id, array $file): void
{
    // 1. Check the upload itself (nothing is saved yet).
    $ext = conforme_scan_check($file);

    $dir = realpath(__DIR__ . '/../../storage/uploads');
    if ($dir === false || !is_dir($dir) || !is_writable($dir)) {
        throw new SigningRefused('The upload folder is missing or not writable. Contact the developer.');
    }
    $name   = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = $dir . DIRECTORY_SEPARATOR . $name;
    $saved  = false;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        signing_lock($id);

        $form = conforme_load_form($id);
        if (!$form) {
            throw new SigningRefused('This form no longer exists.');
        }
        $title = conforme_form_title($form);

        // Only MMS staff, and only at pending_conforme (the workflow table decides).
        if (!user_is_usable($user)
            || $user['role'] !== 'mm_staff'
            || (string) $form['status'] !== 'pending_conforme'
            || !can_transition($form, 'conforme', $user)) {
            throw new SigningRefused(
                'The Conforme of this form cannot be completed by you right now.',
                [],
                'conforme_paper_refused',
                'Evaluation #' . $id . ': user #' . (int) $user['id'] . ' may not complete the Conforme on paper (status ' . $form['status'] . ').'
            );
        }

        // The earlier signatures and the content must still be intact.
        $problem = conforme_integrity_problem($id, (int) $form['version']);
        if ($problem !== null) {
            throw new SigningRefused(
                'This form cannot be completed because its signatures or its content no longer match.',
                [],
                'conforme_refused_integrity',
                'Evaluation #' . $id . ' (' . $title . '): ' . $problem . '. User #' . (int) $user['id'] . ' tried the Conforme on paper.'
            );
        }

        // Save the file under its random name.
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new SigningRefused('The file could not be saved. Please try again.');
        }
        $saved = true;

        $pdo->prepare("UPDATE evaluations SET conforme_method = 'paper', paper_scan_file = :f WHERE id = :id")
            ->execute([':f' => $name, ':id' => $id]);

        transition($form, 'conforme', $user, 'Conforme signed on paper. Scan uploaded by MMS.');

        notify((int) $form['supplier_id'], 'Your Conforme on paper for form ' . $title . ' was received. The form is complete.', 'supplier/form-view.php?id=' . $id);
        if (!empty($form['evaluator_id'])) {
            notify((int) $form['evaluator_id'], 'The supplier\'s Conforme on paper for form ' . $title . ' was received. The form is complete.', 'common/evaluation-view.php?id=' . $id);
        }

        audit('form_conforme_paper', 'Evaluation #' . $id . ' (' . $title . ') version ' . (int) $form['version'] . ' Conforme on paper, scan ' . $name . ', uploaded by user #' . (int) $user['id'] . '.');

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($saved && is_file($target)) {
            @unlink($target);
        }
        signing_abort($ex);
    }
}