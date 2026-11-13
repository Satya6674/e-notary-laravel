<?php

/*
| Accounts that no other administrator can delete, deactivate or edit through the application:
| the owner's permanent Admin. Only the account itself may change its own details. Matching is
| by email, without case. Set PROTECTED_ACCOUNTS (comma-separated) to the permanent Admin's
| address; nothing is protected when it is empty. Entries are validated by
| App\Support\ProtectedAccounts: invalid ones are dropped and reported by enotary:deploy-check.
*/

$protectedAccounts = \App\Support\ProtectedAccounts::parse((string) env('PROTECTED_ACCOUNTS', ''));

return [
    'protected' => $protectedAccounts['valid'],
    'protected_invalid' => $protectedAccounts['invalid'],

    // Citizens: is the mobile step (SMS code) part of sign-up? Off: a Citizen's registration is
    // complete once the email code is verified; the number is stored but not proven. There is no SMS
    // in this deployment, so requiring it would leave every new Citizen stuck. Notary approval and the
    // Admin rules are not affected. The older automated tests switch it on to keep covering that path.
    'citizen_mobile_verification' => (bool) env('CITIZEN_MOBILE_VERIFICATION_REQUIRED', false),

    // Admin step-up: sensitive Admin actions (editing an account's password, email or role,
    // deactivating, deleting, restoring, replacing an email, deciding a recovery or a Notary
    // application) need the Admin's password again once this many minutes have passed since the
    // last confirmation. Clamped to 1-60.
    'admin_reauth_minutes' => max(1, min(60, (int) env('ADMIN_REAUTH_MINUTES', 10))),

    // Admin two-factor sign-in (TOTP authenticator app plus one-time recovery codes). An Admin who
    // has turned it on must pass it at every sign-in. When required, an Admin who has not set it up
    // can only reach the setup page. Off by default locally so the permanent Admin is never locked
    // out by an update; the production template sets ADMIN_MFA_REQUIRED=true.
    'admin_mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', false),

    // Daily ceilings for emailed codes (registration, Forgot Password, mobile fallback), on top of the
    // 60-second cooldown and 5-per-hour limit. Site-wide is kept below the mail account's daily
    // sending quota (a personal Gmail account allows about 500 messages a day, all mail included).
    'email_code_daily_site' => (int) env('EMAIL_CODE_DAILY_SITE', 300),
    'email_code_daily_per_account' => (int) env('EMAIL_CODE_DAILY_PER_ACCOUNT', 10),
    'email_code_daily_per_ip' => (int) env('EMAIL_CODE_DAILY_PER_IP', 30),

    // Sign-up attempts per IP per day (on top of 5 per minute). Limits testing lists of addresses.
    'register_daily_per_ip' => (int) env('REGISTER_DAILY_PER_IP', 20),
];
