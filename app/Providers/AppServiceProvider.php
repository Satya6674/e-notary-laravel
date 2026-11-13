<?php

namespace App\Providers;

use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\DeliveryLog;
use App\Services\SmsService;
use App\Support\Platform;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Mail\RedactingLogTransport;
use Illuminate\Log\LogManager;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\LogTransport;
use Psr\Log\LoggerInterface;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every log mail transport, however it is reached (MAIL_MAILER=log, or the failover mailer
        // falling back to the log), redacts codes, tokens and link signatures before writing.
        $this->app->afterResolving('mail.manager', function (MailManager $manager) {
            $manager->extend('log', function (array $config) {
                $logger = $this->app->make(LoggerInterface::class);

                if ($logger instanceof LogManager) {
                    $logger = $logger->channel($config['channel'] ?? config('mail.log_channel'));
                }

                return new RedactingLogTransport(new LogTransport($logger));
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One password policy for every new or changed password (App\Support\PasswordPolicy).
        \Illuminate\Validation\Rules\Password::defaults(fn () => \App\Support\PasswordPolicy::rule());

        // Served over HTTPS (APP_URL https://, usually behind a proxy): every generated URL is https,
        // even if the proxy talks plain HTTP to PHP. Local http:// development is unaffected.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        $this->enforceSafePreviewDefault();
        $this->configureRateLimiting();
        $this->configureAuditLogging();
        $this->configureDeliveryTracking();

        Paginator::useBootstrapFive();

        // Before the migration there is no table to look in, so the page simply does not exist.
        Route::bind('recovery', function (string $value) {
            abort_unless(User::accountRecoverySupported(), 404);

            return AccountRecoveryRequest::findOrFail($value);
        });

        // Stored times stay in UTC; $date->ist() formats one for people to read.
        Carbon::macro('ist', fn (string $format = 'd M Y, H:i') => $this->copy()->setTimezone(Platform::DISPLAY_TIMEZONE)->format($format));
    }

    /**
     * Safe Preview is the default. Unless LIVE_MAIL_ENABLED=true (or Local Gmail started this process),
     * a real transport such as MAIL_MAILER=smtp is replaced by the log before any mail is built, so a
     * changed MAIL_MAILER alone can never send a real email.
     */
    private function enforceSafePreviewDefault(): void
    {
        if (config('mail.live_enabled') !== true && config('mail.default') !== 'log'
            && Platform::realMailTransport() && ! Platform::localGmailRequested()) {
            config(['mail.default' => 'log']);
        }
    }

    /** Stores what is known about each email, and stops mail outside the live-test allowlist. */
    private function configureDeliveryTracking(): void
    {
        Event::listen(JobQueued::class, [DeliveryLog::class, 'queued']);
        Event::listen(NotificationSending::class, [DeliveryLog::class, 'sending']);
        Event::listen(NotificationSent::class, [DeliveryLog::class, 'sent']);
        Event::listen(JobFailed::class, [DeliveryLog::class, 'failed']);
        Event::listen(JobExceptionOccurred::class, [DeliveryLog::class, 'retrying']);
        // Returns nothing on purpose: false from a Looping listener pauses the worker.
        Event::listen(Looping::class, function () {
            Cache::add(Platform::WORKER_HEARTBEAT, true, 90);
        });
        Event::listen(Verified::class, fn (Verified $e) => app(DeliveryLog::class)->confirm('mail', 'verify_email', $e->user->getAuthIdentifier()));

        Event::listen(MessageSending::class, function (MessageSending $e) {
            // Redaction for the log mailer is done by RedactingLogTransport, on a copy, so the
            // message itself is left intact for any other transport.
            $notificationId = $e->data['__laravel_notification_id'] ?? null;

            // The emergency stop (enotary:mail-stop on) refuses everything. Live Mail: a missing setting
            // stops everything; a notification class not on Platform::LIVE_MAIL_NOTIFICATIONS stays
            // blocked. Checked here, for each message, before the transport connects.
            if ($reason = Platform::liveMailRefusal($e->data['__laravel_notification'] ?? null)) {
                DeliveryLog::refuse($notificationId, $reason);
                Log::warning('Mail stopped by the Live Mail rules', ['reason' => $reason]);

                return false;
            }

            // Every envelope recipient, so a copy cannot reach an address the list would refuse.
            foreach ([...$e->message->getTo(), ...$e->message->getCc(), ...$e->message->getBcc()] as $address) {
                if (! DeliveryLog::allowed('mail', $address->getAddress())) {
                    DeliveryLog::refuse($notificationId, 'recipient_not_allowlisted');
                    Log::warning('Mail stopped by the outbound allowlist', ['to' => DeliveryLog::maskEmail($address->getAddress())]);

                    return false;
                }
            }

            return null;
        });
    }

    /** Authentication events, wherever they are raised from. Domain actions log themselves. */
    private function configureAuditLogging(): void
    {
        Event::listen(Login::class, fn (Login $e) => AuditLog::record('auth.login', $e->user, [], $e->user->getAuthIdentifier()));
        Event::listen(Logout::class, fn (Logout $e) => $e->user && AuditLog::record('auth.logout', $e->user, [], $e->user->getAuthIdentifier()));
        // $e->user is the account being tried, not a proven actor, so it stays the subject only.
        Event::listen(Failed::class, fn (Failed $e) => AuditLog::record('auth.login_failed', $e->user));
        Event::listen(Registered::class, fn (Registered $e) => AuditLog::record('auth.registered', $e->user, ['role' => $e->user->role], $e->user->getAuthIdentifier()));
        Event::listen(Verified::class, fn (Verified $e) => AuditLog::record('auth.email_verified', $e->user, [], $e->user->getAuthIdentifier()));
        Event::listen(PasswordReset::class, function (PasswordReset $e) {
            AuditLog::record('auth.password_reset', $e->user, [], $e->user->getAuthIdentifier());

            // A reset means the old password may be known, so a change begun with it must not finish.
            if (Platform::schemaReady() && $e->user->pending_mobile) {
                $e->user->forceFill(['pending_mobile' => null, 'pending_mobile_requested_at' => null])->save();
            }

            $e->user->notify(new SecurityAlertNotification('password_changed'));
        });
    }

    /**
     * Named limiters, one bucket per feature.
     *
     * Laravel's plain "throttle:N,M" keys every guest request by IP only, so
     * all throttled routes used to share ONE counter: ten failed logins also
     * blocked registration and password reset. Each limiter below has its own
     * key prefix; the limits themselves are unchanged from the previous values.
     */
    /** The account a login attempt is aimed at, written one way only. */
    public static function loginIdentity(mixed $input): string
    {
        $value = strtolower(trim(is_string($input) ? $input : ''));

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email:'.$value;
        }

        return 'mobile:'.(SmsService::normalizeMobile($value) ?? $value);
    }

    private function configureRateLimiting(): void
    {
        $byIp   = fn (string $name) => fn (Request $r) => $name.'|'.$r->ip();
        $byUser = fn (string $name) => fn (Request $r) => $name.'|'.($r->user()?->getAuthIdentifier() ?? $r->ip());

        // The second limit stops one account being guessed from many addresses. It is keyed on the
        // identifier as LoginController matches it, so "+91 98765 43210", "098765-43210" and
        // "9876543210" (or an email in any case) share one counter.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by(($byIp)('login')($r)),
            Limit::perMinutes(15, 20)->by('login-account|'.sha1(self::loginIdentity($r->input('mobile')))),
        ]);
        // Sign-up answers "already taken" for a registered email or mobile, so attempts are also capped
        // per day: a script cannot test long lists of addresses or numbers from one IP.
        RateLimiter::for('register', fn (Request $r) => [
            Limit::perMinute(5)->by(($byIp)('register')($r)),
            Limit::perDay((int) config('accounts.register_daily_per_ip', 20))->by(($byIp)('register-day')($r)),
        ]);
        RateLimiter::for('contact', fn (Request $r) => Limit::perMinutes(10, 3)->by(($byIp)('contact')($r)));
        RateLimiter::for('password-request', fn (Request $r) => Limit::perMinute(5)->by(($byIp)('password-request')($r)));
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(5)->by(($byIp)('password-reset')($r)));
        RateLimiter::for('otp-verify', fn (Request $r) => Limit::perMinute(10)->by(($byUser)('otp-verify')($r)));
        RateLimiter::for('verification', fn (Request $r) => Limit::perMinute(6)->by(($byUser)('verification')($r)));
        RateLimiter::for('mobile-change', fn (Request $r) => Limit::perMinutes(10, 5)->by(($byUser)('mobile-change')($r)));
        RateLimiter::for('otp-send', fn (Request $r) => Limit::perMinute(6)->by(($byUser)('otp-send')($r)));
        RateLimiter::for('upload', fn (Request $r) => Limit::perMinutes(10, 20)->by(($byUser)('upload')($r)));
        RateLimiter::for('verify-public', fn (Request $r) => Limit::perMinute(20)->by(($byIp)('verify-public')($r)));
        RateLimiter::for('portal-write', fn (Request $r) => Limit::perMinute(20)->by(($byUser)('portal-write')($r)));
        RateLimiter::for('pdf', fn (Request $r) => Limit::perMinute(60)->by(($byUser)('pdf')($r)));
        // Admin state changes that had no limit before (applications, documents, messages, settings).
        RateLimiter::for('admin-write', fn (Request $r) => Limit::perMinute(60)->by(($byUser)('admin-write')($r)));
    }
}
