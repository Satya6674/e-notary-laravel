<?php

namespace App\Support;

/**
 * Form fields that must never be copied into the session. After an error, Laravel keeps the
 * submitted input in the session (storage/framework/sessions) to refill the form: a code, a
 * reset token or a password kept there would sit on disk in plain text.
 *
 * Used by dontFlash() in bootstrap/app.php for validation errors, and by every controller that
 * calls withInput() itself, which dontFlash() does not cover.
 */
final class SensitiveInput
{
    public const FIELDS = ['otp', 'token', 'password', 'password_confirmation', 'current_password', 'admin_password', 'code', 'step_up_code'];
}
