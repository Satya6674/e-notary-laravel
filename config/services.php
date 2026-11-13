<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'msg91' => [
        // MSG91_ENABLED=false removes the key, so no SMS can be attempted. Previews and tests
        // use this: an empty MSG91_AUTH_KEY does not work, because PowerShell deletes
        // empty variables and the real key would then load from .env.
        'auth_key' => env('MSG91_ENABLED', true) ? env('MSG91_AUTH_KEY') : null,
        'template_id' => env('MSG91_TEMPLATE_ID'),
        'sender_id' => env('MSG91_SENDER_ID'),
        // Ceiling on paid SMS across the whole site, per day.
        'daily_limit' => (int) env('MSG91_DAILY_LIMIT', 300),
        // Minutes a code stays valid. The MSG91 template tells people 10 minutes, so these must match.
        'otp_ttl' => (int) env('OTP_TTL_MINUTES', 10),
        // Asks MSG91 about the account before a paid send, as its OTP API can report
        // success for a key whose IP is refused, and then nothing is delivered.
        'preflight' => env('MSG91_PREFLIGHT', true),
        // Set by a start script for an approved live test: the most SMS one approval may
        // send, counted under its id. A real send is refused while this or the SMS allowlist is unset.
        'send_budget' => env('MSG91_SEND_BUDGET'),
        // Real SMS replaces the local simulation only when the owner sets this after DLT approval
        // and the IDs below are present. See SmsService::realSmsReady().
        'production_ready' => (bool) env('MSG91_PRODUCTION_READY', false),
        // While real SMS is not ready, the mobile step sends its code to the verified email instead and
        // records an email fallback, never SMS proof. Set MOBILE_EMAIL_FALLBACK=false to keep the SMS path
        // (the automated tests and Start-Otp-Mock.ps1 do). See VerificationStatus::mode().
        'email_fallback' => (bool) env('MOBILE_EMAIL_FALLBACK', true),
        'dlt_pe_id' => env('MSG91_DLT_PE_ID'),
        'dlt_template_id' => env('MSG91_DLT_TEMPLATE_ID'),
        // Read only by the test suite, which fakes MSG91. See SmsService::limitsInPlace().
        'enforce_limits' => env('MSG91_ENFORCE_LIMITS', true),
        // Start-Otp-Mock.ps1 only: a stand-in on this computer. Ignored unless it is 127.0.0.1 and not in production.
        'endpoint' => env('MSG91_ENDPOINT'),
        'budget_id' => env('MSG91_BUDGET_ID', 'default'),
    ],

    // When set, real messages go only to these comma-separated recipients. Unset means no
    // limit for mail. Real SMS is refused while its list is unset.
    'outbound' => [
        'mail' => env('OUTBOUND_ALLOW_MAIL'),
        'sms' => env('OUTBOUND_ALLOW_SMS'),
        // Local Gmail only: approved addresses that belong to an Admin account, or to an account in
        // a permanent database, and that the owner confirmed for this run. Set by
        // Start-Local-Gmail.ps1 -ConfirmRecipient, never in .env.
        'mail_confirmed' => env('OUTBOUND_CONFIRM_MAIL'),
    ],

];
