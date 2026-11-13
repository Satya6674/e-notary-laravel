<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            // Windows has no pcntl, so queue:work --timeout does nothing there. This is what
            // stops a hung connection outliving the job's retry_after and sending twice.
            'timeout' => env('MAIL_TIMEOUT'),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | E-Notary addresses
    |--------------------------------------------------------------------------
    |
    | admin_address   - receives a copy of contact-form messages (optional).
    | support_address - shown in email footers when set (optional).
    |
    */

    'admin_address' => env('MAIL_ADMIN_ADDRESS'),

    // The most contact-form acknowledgements the whole site sends in 24 hours (on top of one per
    // address per day and the per-IP form throttle). 0 sends none. See ContactController.
    'contact_ack_daily_limit' => (int) env('CONTACT_ACK_DAILY_LIMIT', 100),
    'admin_new_citizens' => (bool) env('MAIL_NOTIFY_ADMIN_NEW_CITIZENS', false),

    // local_gmail is set only by scripts/Start-Local-Gmail.ps1. See Platform::mailMode().
    'mode' => env('MAIL_MODE'),

    // Live Mail: real email through the configured transport (Gmail SMTP). Off by default, and while
    // it is off every real transport is replaced by the log at boot (Safe Preview). When it is on,
    // only the message types in Platform::LIVE_MAIL_NOTIFICATIONS may leave, only to the addresses in
    // OUTBOUND_ALLOW_MAIL, and nothing leaves if a required setting is missing. See Platform::liveMailProblem().
    'live_enabled' => (bool) env('LIVE_MAIL_ENABLED', false),

    // "allowlist" (default): Live Mail reaches only OUTBOUND_ALLOW_MAIL. "any": every legitimate
    // recipient, which is still only the person each event concerns. Anything else sends nothing.
    'live_recipients' => env('LIVE_MAIL_RECIPIENTS', 'allowlist'),

    // A local copy may send real mail from a permanent database (enotary_local) only with this set,
    // and only while APP_ENV=local. Production never honours it.
    'live_allow_permanent_database' => (bool) env('LIVE_MAIL_ALLOW_PERMANENT_DATABASE', false),

    // Local Gmail sends nothing unless the process is connected to this database. Deliberately
    // not an env() setting, so no environment variable can point Local Gmail at real accounts.
    'local_gmail_database' => \App\Support\Platform::LOCAL_GMAIL_DATABASE,
    'support_address' => env('MAIL_SUPPORT_ADDRESS'),

];
