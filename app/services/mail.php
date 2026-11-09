<?php
/**
 * app/services/mail.php
 *
 * Sends one email through SMTP with PHPMailer (Step 15).
 * PHP mail() is not used because InfinityFree disables it.
 *
 * Every email is sent as a small branded HTML message (ZCMC logo on top,
 * footer at the bottom) with the plain text kept as a fallback.
 * The callers still pass plain text. A paragraph that is only a 6-digit
 * number (the one-time code) is shown big and centered.
 *
 * The logo is the file assets/img/zcmc.png. It is attached inside the email
 * itself, so it shows without "load images" prompts. If the file is missing,
 * the email still works, with a text header instead.
 *
 * mail_send() never throws: it returns ['ok' => bool, 'error' => string].
 */

/** True when every SMTP setting in config/config.php has a value. */
function mail_is_configured(): bool
{
    foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM'] as $name) {
        if (!defined($name) || trim((string) constant($name)) === '') {
            return false;
        }
    }
    return true;
}

/** True when PHPMailer was installed with Composer (vendor/autoload.php exists). */
function mail_library_installed(): bool
{
    return is_file(__DIR__ . '/../../vendor/autoload.php');
}

/** Full path of the logo shown at the top of every email. */
function mail_logo_path(): string
{
    return __DIR__ . '/../../assets/img/zcmc.png';
}

/**
 * Turns the plain-text message into the HTML page of the email.
 * Email programs ignore Tailwind and style sheets, so everything is inline
 * and the layout uses a table (the one thing every mail program understands).
 * $hasLogo = true shows the logo through the attachment named "zcmc-logo".
 */
function mail_build_html(string $subject, string $textBody, bool $hasLogo): string
{
    $brand = '#15803d';   // green of the ZCMC logo. Change this one value to recolor the email.

    // Paragraphs: blank lines separate them. A lone 6-digit number is the one-time code.
    $paragraphs = preg_split('/\n{2,}/', trim(str_replace("\r\n", "\n", $textBody))) ?: [];
    $content    = '';
    foreach ($paragraphs as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (preg_match('/^\d{6}$/', $p)) {
            $content .= '<p style="margin:24px 0;text-align:center;">'
                . '<span style="display:inline-block;padding:14px 28px;font-family:Consolas,Menlo,monospace;'
                . 'font-size:32px;font-weight:bold;letter-spacing:8px;color:' . $brand . ';'
                . 'background:#f0fdf4;border:2px dashed ' . $brand . ';border-radius:12px;">'
                . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</span></p>';
        } else {
            $content .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">'
                . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
    }

    $logo = $hasLogo
        ? '<img src="cid:zcmc-logo" alt="Zamboanga City Medical Center" width="64" height="64" '
          . 'style="display:block;margin:0 auto 10px;border:0;height:64px;width:auto;">'
        : '';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">'
        // Header with the logo
        . '<tr><td align="center" style="padding:24px 24px 18px;border-bottom:4px solid ' . $brand . ';">'
        . $logo
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#0f172a;">Zamboanga City Medical Center</div>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#64748b;margin-top:2px;">Material Management Section &middot; Evaluation Form System</div>'
        . '</td></tr>'
        // Message
        . '<tr><td style="padding:28px 28px 12px;font-family:Arial,Helvetica,sans-serif;">' . $content . '</td></tr>'
        // Footer
        . '<tr><td style="padding:16px 28px 24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#94a3b8;border-top:1px solid #e2e8f0;">'
        . 'This is an automatic message from the ZCMC Evaluation Form System. Please do not reply to it. '
        . 'ZCMC staff will never ask you for your password by email.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Send one email.
 * $textBody is plain text; it is also used to build the HTML version.
 * @return array{ok: bool, error: string}
 */
function mail_send(string $toEmail, string $toName, string $subject, string $textBody): array
{
    if (!mail_is_configured()) {
        return ['ok' => false, 'error' => 'The SMTP settings in config/config.php are not filled in.'];
    }
    if (!mail_library_installed()) {
        return ['ok' => false, 'error' => 'PHPMailer is not installed (vendor/autoload.php is missing).'];
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);   // true = throw exceptions
        $mail->isSMTP();
        $mail->Host       = (string) SMTP_HOST;
        $mail->Port       = (int) SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) SMTP_USER;
        $mail->Password   = (string) SMTP_PASS;
        $mail->SMTPSecure = ((int) SMTP_PORT === 465) ? 'ssl' : 'tls';
        $mail->Timeout    = 15;                            // seconds; a blocked host fails fast
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom((string) SMTP_FROM, 'ZCMC Evaluation System');
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;

        // The logo travels inside the email (no "load images" prompt for the reader).
        $logoPath = mail_logo_path();
        $hasLogo  = is_file($logoPath);
        if ($hasLogo) {
            $mail->addEmbeddedImage($logoPath, 'zcmc-logo', 'zcmc.png', 'base64', 'image/png');
        }

        // HTML version for normal mail apps, plain text for the rest.
        $mail->isHTML(true);
        $mail->Body    = mail_build_html($subject, $textBody, $hasLogo);
        $mail->AltBody = $textBody;

        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $ex) {
        return ['ok' => false, 'error' => $ex->getMessage()];
    }
}