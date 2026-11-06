<?php
/**
 * app/services/otp.php
 *
 * Asks: "does this action need a one-time password?"
 * While OTP_ENABLED is false in config.php, the answer is always no.
 * The real OTP screens come in Step 15.
 */

function otp_required(string $purpose): bool
{
    return OTP_ENABLED;
}
