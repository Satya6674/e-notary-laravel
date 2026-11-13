<?php

namespace App\Support;

use App\Services\SmsService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Facts about this installation that several layers need. */
class Platform
{
    public const LOCALES = ['en' => 'English', 'hi' => 'हिन्दी', 'pa' => 'ਪੰਜਾਬੀ'];

    /** Stored times are UTC. Everything shown to a person is in this zone. */
    public const DISPLAY_TIMEZONE = 'Asia/Kolkata';

    /** A running queue worker renews this, so its absence means queued mail is not moving. */
    public const WORKER_HEARTBEAT = 'queue:worker-heartbeat';

    /** The scheduler renews this each minute, so its absence means reminders and cleanup are not running. */
    public const SCHEDULER_HEARTBEAT = 'scheduler:heartbeat';

    /**
     * Printed in the footer of every page of every generated PDF. A PDF can be shared on its own, away
     * from the website, so it must say what it is not. Kept in English on purpose.
     */
    public const PDF_VALIDITY_NOTICE = 'Not legally valid. Not a notarised document.';

    /**
     * Shown once, at the very bottom of the signed-in Citizen Portal footer, and nowhere else
     * (owner decision 2026-11-13). Exact English text in every language.
     */
    public const PROTOTYPE_NOTICE = 'Independent Prototype | For Evaluation Only';

    private static ?bool $schemaReady = null;

    /**
     * True once every portal migration has run. Both markers are checked because
     * they come from the last migration of each set.
     */
    public static function schemaReady(): bool
    {
        return self::$schemaReady ??= Schema::hasColumn('users', 'deactivated_at')
            && Schema::hasTable('service_drafts') && Schema::hasTable('security_incidents')
            && Schema::hasColumn('documents', 'superseded_at') && Schema::hasColumn('users', 'pending_mobile');
    }

    /**
     * safe_preview: mail goes to a log. live_test: real mail, but only to allowlisted
     * recipients. local_gmail: real mail through Gmail to approved local test accounts,
     * with nothing sent if that list is empty. live: real mail to each person's own address.
     * misconfigured: Live Mail is on but a required setting is missing, so nothing is sent.
     *
     * A log mailer always wins, so a preview never becomes a real-mail mode, and
     * production ignores MAIL_MODE, so a local setting cannot leak into it. A real transport
     * with Live Mail off never gets here: AppServiceProvider swaps it for the log at boot.
     * With Live Mail on, a real transport always needs the allowlist, so it is never "live".
     */
    public static function mailMode(): string
    {
        return match (true) {
            config('mail.default') === 'log' => 'safe_preview',
            self::localGmailRequested() => 'local_gmail',
            self::liveMailApplies() && self::liveMailProblem() !== null => 'misconfigured',
            filled(config('services.outbound.mail')) => 'live_test',
            default => 'live',
        };
    }

    /**
     * The messages Live Mail may send: every business notification of the application (approved
     * 2026-11-08). Fixed in code, so no environment variable can widen it, and a new notification class
     * stays blocked until it is added here on purpose. Each one is sent only when its own event happens,
     * to the person that event concerns; nothing is sent because Live Mail was switched on.
     *
     * Deliberately NOT here, so they stay blocked even in Live Mail:
     *  - MobileOtpTestNotification: the development email bridge, never a real flow.
     *  - plain mailables such as enotary:mail-test's TestMail: not a business event.
     *
     * ContactAcknowledgement (owner-approved 2026-11-11) goes to an address a visitor typed, so it is
     * fixed text that echoes nothing the visitor wrote, and ContactController sends it only through
     * its abuse limits: one per address per day, a site-wide daily cap, and the per-IP form throttle.
     */
    public const LIVE_MAIL_NOTIFICATIONS = [
        // Account and sign-in
        \App\Notifications\VerifyEmailNotification::class,
        \App\Notifications\PasswordResetCodeNotification::class,
        \App\Notifications\ResetPasswordNotification::class,
        \App\Notifications\SecurityAlertNotification::class,
        \App\Notifications\MobileEmailFallbackCodeNotification::class,
        // Deletion, restoration, recovery and lost-mailbox replacement
        \App\Notifications\AccountDeletedNotification::class,
        \App\Notifications\AccountRestoredNotification::class,
        \App\Notifications\AccountRecoveryCodeNotification::class,
        \App\Notifications\AccountRecoveryNotification::class,
        \App\Notifications\EmailReplacementLinkNotification::class,
        \App\Notifications\EmailReplacementAlertNotification::class,
        // Applications, appointments, support tickets and staff notices
        \App\Notifications\NotaryRequestNotification::class,
        \App\Notifications\PortalNotification::class,
        // The contact form: the Admin's copy, and the visitor's acknowledgement (see above)
        \App\Notifications\ContactMessageReceived::class,
        \App\Notifications\ContactAcknowledgement::class,
    ];

    /**
     * True when Live Mail may reach any legitimate recipient (LIVE_MAIL_RECIPIENTS=any): each message
     * still goes only to the person its event concerns. Otherwise only OUTBOUND_ALLOW_MAIL is reached.
     */
    public static function liveRecipientsOpen(): bool
    {
        return config('mail.live_recipients') === 'any';
    }

    /**
     * True when this local copy is allowed to send real mail from a permanent database: the explicit
     * LIVE_MAIL_ALLOW_PERMANENT_DATABASE=true, honoured only while APP_ENV=local.
     */
    public static function permanentDatabaseAllowed(): bool
    {
        return config('mail.live_allow_permanent_database') === true && app()->environment('local');
    }

    /** MAIL_MODE=local_gmail, which production ignores. */
    public static function localGmailRequested(): bool
    {
        return config('mail.mode') === 'local_gmail' && ! app()->isProduction();
    }

    /** True when the default mailer would hand mail to something outside this process (SMTP and the like). */
    public static function realMailTransport(): bool
    {
        $mailer = (string) config('mail.default');

        return ! in_array(config("mail.mailers.{$mailer}.transport"), ['array', 'log'], true);
    }

    /** Live Mail rules govern this process: the flag is on, the transport is real, and Local Gmail is not running. */
    public static function liveMailApplies(): bool
    {
        return config('mail.live_enabled') === true && config('mail.default') !== 'log'
            && self::realMailTransport() && ! self::localGmailRequested();
    }

    /**
     * Why Live Mail cannot send anything now, or null when it can. Checked before every message, so
     * a missing setting stops mail instead of producing a half-working message. A short code, never
     * a setting's value.
     */
    public static function liveMailProblem(): ?string
    {
        $mailer = (string) config('mail.default');
        $settings = (array) config("mail.mailers.{$mailer}", []);
        $appUrl = (string) config('app.url');
        $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));

        return match (true) {
            // Not from the permanent databases (enotary_local, enotary), unless this is a local copy
            // explicitly allowed to (LIVE_MAIL_ALLOW_PERMANENT_DATABASE, APP_ENV=local only).
            self::onPermanentDatabase() && ! self::permanentDatabaseAllowed() => 'permanent_database',
            ($settings['transport'] ?? null) === null => 'mailer_not_configured',
            ! in_array(config('mail.live_recipients', 'allowlist'), ['allowlist', 'any'], true) => 'recipient_policy_invalid',
            ! self::liveRecipientsOpen() && self::liveRecipients() === [] => 'recipient_allowlist_missing',
            ($settings['transport'] ?? null) === 'smtp' && (blank($settings['host'] ?? null) || blank($settings['port'] ?? null)
                || blank($settings['username'] ?? null) || blank($settings['password'] ?? null)) => 'smtp_settings_missing',
            ! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) => 'from_address_missing',
            ! filter_var($appUrl, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true) => 'app_url_invalid',
            app()->isProduction() && $scheme !== 'https' => 'https_required',
            // Links in real email must open for the recipient: never a loopback or private address.
            app()->isProduction() && self::privateHost((string) parse_url($appUrl, PHP_URL_HOST)) => 'app_url_not_public',
            default => null,
        };
    }

    /** True when this process is connected to a database that holds real or permanent accounts. */
    public static function onPermanentDatabase(): bool
    {
        return in_array(strtolower((string) DB::connection()->getDatabaseName()), self::PERMANENT_DATABASES, true);
    }

    /** @return list<string> The Live Mail recipient allowlist (OUTBOUND_ALLOW_MAIL), lower-cased. */
    public static function liveRecipients(): array
    {
        return array_values(array_filter(array_map(fn ($a) => strtolower(trim($a)), explode(',', (string) config('services.outbound.mail')))));
    }

    /**
     * Why Live Mail refuses this message, or null when it may go (or when Live Mail rules do not
     * apply to this process). $notification is the notification class Laravel passes with the
     * message; a plain mailable has none and is refused.
     */
    public static function liveMailRefusal(?string $notification): ?string
    {
        // The emergency stop wins over everything, in every process (web and queue worker), and
        // needs no restart: it is a file checked for each message. See enotary:mail-stop.
        if (self::mailStopped()) {
            return 'mail_stopped';
        }

        if (! self::liveMailApplies()) {
            return null;
        }

        if ($problem = self::liveMailProblem()) {
            return 'misconfigured:'.$problem;
        }

        return in_array($notification, self::LIVE_MAIL_NOTIFICATIONS, true) ? null : 'purpose_not_live';
    }

    /** localhost, *.localhost, *.test, loopback or private/reserved IP addresses. */
    public static function privateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }

    /** storage/framework/mail-stopped: while it exists, no email leaves any process of this app. */
    public const MAIL_STOP_FILE = 'framework/mail-stopped';

    public static function mailStopped(): bool
    {
        $file = self::mailStopFile();
        clearstatcache(true, $file);

        return is_file($file);
    }

    /**
     * The stop file's full path. Always storage/framework/mail-stopped in the application; only the
     * test suite points it elsewhere (config mail.stop_file, never read from the environment), so a
     * test run can neither lift a real emergency stop nor pause the live site's mail while it runs.
     */
    public static function mailStopFile(): string
    {
        return (string) (config('mail.stop_file') ?: storage_path(self::MAIL_STOP_FILE));
    }

    /**
     * The one database Local Gmail may run on: created empty by scripts/New-LocalGmailDatabase.ps1,
     * holding only test accounts. Fixed in config/mail.php, never read from the environment.
     */
    public const LOCAL_GMAIL_DATABASE = 'enotary_gmail_test';

    /** Databases holding real or permanent accounts. Local Gmail never runs on one. */
    public const PERMANENT_DATABASES = ['enotary', 'enotary_local'];

    /** True when this process is connected to the disposable Local Gmail database and nothing else. */
    public static function onLocalGmailDatabase(): bool
    {
        $database = (string) DB::connection()->getDatabaseName();

        return $database === (string) config('mail.local_gmail_database', self::LOCAL_GMAIL_DATABASE)
            && ! in_array(strtolower($database), self::PERMANENT_DATABASES, true);
    }

    /** off: no key. mock: a local stand-in, so no phone is reached. live: real MSG91. */
    public static function smsMode(): string
    {
        return match (true) {
            blank(config('services.msg91.auth_key')) => 'off',
            SmsService::endpoint() !== SmsService::ENDPOINT => 'mock',
            default => 'live',
        };
    }

    /**
     * Jobs this installation's worker will process. Local Gmail runs its own queue, so jobs
     * held on another queue must not read as a stopped worker.
     */
    public static function activeJobs(): Builder
    {
        return DB::table('jobs')->where('queue', config('queue.connections.database.queue', 'default'));
    }

    /** Jobs on any other queue, which no worker here will touch. */
    public static function heldJobs(): int
    {
        return DB::table('jobs')->count() - self::activeJobs()->count();
    }

    public static function forgetSchemaState(): void
    {
        self::$schemaReady = null;
    }
}
