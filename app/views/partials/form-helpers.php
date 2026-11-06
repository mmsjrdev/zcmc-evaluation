<?php
/**
 * app/views/partials/form-helpers.php
 *
 * Small helpers shared by every page that has a form (sign-in pages and the
 * supplier form). Loaded by auth-top.php and header.php.
 */

/** Prints the red error text under a form field (if that field has an error). */
if (!function_exists('field_error')) {
    function field_error(array $errors, string $key): void
    {
        if (!empty($errors[$key])) {
            echo '<p class="mt-1.5 text-sm font-medium text-red-600">' . e($errors[$key]) . '</p>';
        }
    }
}

/** The CSS classes of a text box: red border when the field has an error. */
if (!function_exists('input_class')) {
    function input_class(array $errors, string $key): string
    {
        return empty($errors[$key])
            ? 'input'
            : 'input border-red-500 focus:border-red-500 focus:ring-red-200';
    }
}
