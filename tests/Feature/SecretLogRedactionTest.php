<?php

namespace Tests\Feature;

use App\Mail\RedactingLogTransport;
use App\Models\User;
use App\Notifications\AccountRecoveryCodeNotification;
use App\Notifications\MobileOtpTestNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\LogMailRedactor;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Codes, reset tokens and signed links never reach the application log in a usable form. The
 * log mailer writes into a temporary file here, which is read back. The SMTP test talks to a
 * local catcher on 127.0.0.1 that forwards nothing. No real email or SMS is sent. Every account
 * is synthetic.
 */
class SecretLogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private string $log;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // One file per test: on Windows a file still held open by the last test cannot be reused.
        $this->log = storage_path('framework/testing/secret-log-'.getmypid().'-'.bin2hex(random_bytes(4)).'.log');
        @unlink($this->log);

        config([
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'secret-test',
            'logging.channels.secret-test' => ['driver' => 'single', 'path' => $this->log],
            'services.outbound.mail' => 'secret.person@example.test',
        ]);
        app('mail.manager')->forgetMailers();

        $this->user = User::create([
            'name' => 'Secret Person', 'email' => 'secret.person@example.test', 'mobile' => '9433300001',
            'password' => Hash::make('demo-pass-123'), 'role' => 'public',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (app('log')->channel('secret-test')->getLogger()->getHandlers() as $handler) {
            $handler->close();
        }
        @unlink($this->log);

        parent::tearDown();
    }

    private function written(): string
    {
        return (string) @file_get_contents($this->log);
    }

    private function assertWrittenAndRedacted(string ...$secrets): string
    {
        $written = $this->written();

        $this->assertNotSame('', $written, 'The log mailer wrote the email.');
        $this->assertStringContainsString(LogMailRedactor::MARK, $written);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $written);
            // Quoted-printable can split a long line; check the decoded text too.
            $this->assertStringNotContainsString($secret, quoted_printable_decode($written));
        }

        return $written;
    }

    // ---- Each kind of secret, sent for real through the log mailer

    public function test_a_recovery_code_is_not_written_to_the_log(): void
    {
        $this->user->notify(new AccountRecoveryCodeNotification('482913'));

        $written = $this->assertWrittenAndRedacted('482913');
        $this->assertStringContainsString('account recovery code', $written, 'The rest of the email is still logged.');
    }

    public function test_a_bridge_code_is_not_written_to_the_log(): void
    {
        $this->user->notify(new MobileOtpTestNotification('739104'));

        $this->assertWrittenAndRedacted('739104');
    }

    public function test_a_reset_token_is_not_written_to_the_log(): void
    {
        $token = Password::broker()->createToken($this->user);
        $this->user->notify(new ResetPasswordNotification($token));

        $written = $this->assertWrittenAndRedacted($token, substr($token, 0, 16));
        $this->assertStringContainsString('/reset-password/'.LogMailRedactor::MARK, quoted_printable_decode($written));
        $this->assertDoesNotMatchRegularExpression('#/reset-password/[0-9a-f]{20,}#', quoted_printable_decode($written));
    }

    public function test_a_verification_code_is_not_usable_from_the_log(): void
    {
        $this->user->notify(new VerifyEmailNotification('482913', now()->addMinutes(10)));

        $decoded = quoted_printable_decode($this->assertWrittenAndRedacted());
        $this->assertStringNotContainsString('482913', $decoded);
        $this->assertStringContainsString(LogMailRedactor::MARK, $decoded);
    }

    public function test_the_failover_mailer_falling_back_to_the_log_is_redacted_too(): void
    {
        // An SMTP server that is not there: a port nothing listens on, on this machine only.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) parse_url('tcp://'.stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        config([
            'mail.default' => 'failover',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'encryption' => null, 'username' => null, 'password' => null, 'timeout' => 2],
        ]);
        app('mail.manager')->forgetMailers();

        $this->user->notify(new AccountRecoveryCodeNotification('615027'));

        $this->assertWrittenAndRedacted('615027');
    }

    // ---- Normal delivery is unchanged

    public function test_smtp_delivers_the_working_code_and_link_and_logs_nothing(): void
    {
        $dir = storage_path('framework/testing/smtp-sink-'.getmypid());
        @mkdir($dir, 0777, true);
        array_map('unlink', glob($dir.'/*') ?: []);

        $sink = proc_open([PHP_BINARY, base_path('tests/Support/smtp-sink.php'), $dir, '2'], [0 => ['pipe', 'r'], 1 => ['file', $dir.'/out', 'w'], 2 => ['file', $dir.'/err', 'w']], $pipes);
        fclose($pipes[0]);

        try {
            // Wait for a port number, not just the file. Root cause of an earlier intermittent
            // failure: the file can exist before its content is written, and was read as port 0.
            $deadline = microtime(true) + 20;
            $port = 0;
            while ($port <= 0 && microtime(true) < $deadline) {
                $port = (int) @file_get_contents($dir.'/port');
                $port > 0 || usleep(20000);
            }
            $this->assertGreaterThan(0, $port, 'the local catcher did not start: '.@file_get_contents($dir.'/err'));

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'encryption' => null, 'username' => null, 'password' => null, 'timeout' => 15],
            ]);
            app('mail.manager')->forgetMailers();

            $token = Password::broker()->createToken($this->user);
            $this->user->notify(new AccountRecoveryCodeNotification('904417'));
            $this->user->notify(new ResetPasswordNotification($token));
        } finally {
            proc_close($sink);
        }

        $messages = array_map(fn ($file) => quoted_printable_decode((string) file_get_contents($file)), glob($dir.'/*.eml') ?: []);
        // What the catcher saw, so a failure explains itself.
        $diagnosis = 'files: '.implode(', ', array_map('basename', glob($dir.'/*') ?: [])).'; catcher stderr: '.trim((string) @file_get_contents($dir.'/err'));
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);

        $this->assertCount(2, $messages, 'Both emails reached the local catcher. '.$diagnosis);
        $all = implode("\n", $messages);
        $this->assertStringContainsString('904417', $all, 'The delivered email keeps its code.');
        $this->assertStringContainsString('/reset-password/'.$token, str_replace("=\r\n", '', $all), 'The delivered email keeps a working link.');
        $this->assertStringNotContainsString(LogMailRedactor::MARK, $all);
        $this->assertSame('', $this->written(), 'Nothing was written to the mail log.');
    }

    private function capturingTransport(): TransportInterface
    {
        return new class implements TransportInterface {
            public ?RawMessage $captured = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->captured = $message;

                return null;
            }

            public function __toString(): string
            {
                return 'capture';
            }
        };
    }

    public function test_redaction_works_on_a_copy_and_leaves_the_message_itself_intact(): void
    {
        $inner = $this->capturingTransport();

        $email = (new Email())->from('a@example.test')->to('b@example.test')->subject('Your code')
            ->text('Enter 551199 to continue.')->html('<p>Enter 551199 to continue.</p>');
        // No pattern matches this text: only the registered exact value can remove it.
        LogMailRedactor::remember(['551199']);

        (new RedactingLogTransport($inner))->send($email);

        $this->assertStringContainsString('551199', $email->getTextBody(), 'The original is untouched.');
        $this->assertStringNotContainsString('551199', $inner->captured->getTextBody());
        $this->assertStringNotContainsString('551199', $inner->captured->getHtmlBody());
    }

    public function test_exact_values_are_removed_when_failover_hands_the_log_a_clone(): void
    {
        $failing = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('Simulated SMTP outage.');
            }

            public function __toString(): string
            {
                return 'down';
            }
        };
        $inner = $this->capturingTransport();

        $email = (new Email())->from('a@example.test')->to('b@example.test')->subject('Notice')
            ->text('Enter 662288 to continue.')->html('<p>Enter 662288 to continue.</p>');
        LogMailRedactor::remember(['662288']);

        // Symfony's failover passes each transport a clone of the message.
        (new \Symfony\Component\Mailer\Transport\FailoverTransport([$failing, new RedactingLogTransport($inner)]))->send($email);

        $this->assertNotNull($inner->captured);
        $this->assertNotSame($email, $inner->captured);
        $this->assertStringNotContainsString('662288', $inner->captured->getTextBody());
        $this->assertStringNotContainsString('662288', $inner->captured->getHtmlBody());
    }

    public function test_the_patterns_remove_secrets_and_keep_ordinary_text(): void
    {
        $text = 'Reference DEL-20261103-ABCD1234 on 03 Nov 2026. Mobile 9876543210. '
            .'Your code is 123456. OTP: 5807. '
            .'<a href="https://x.test/email/verify/5/abc?expires=1793&amp;signature=deadbeef0123">verify</a> '
            .'https://x.test/reset-password/abcdef0123456789?email=a%40b.test '
            .'https://x.test/thing?token=secretvalue99&next=1';

        $out = LogMailRedactor::redactText($text);

        foreach (['123456', '5807', 'deadbeef0123', 'abcdef0123456789', 'secretvalue99'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }

        foreach (['DEL-20261103-ABCD1234', '03 Nov 2026', '9876543210', 'expires=1793', 'next=1', 'email=a%40b.test'] as $kept) {
            $this->assertStringContainsString($kept, $out);
        }
    }

    // ---- Stack traces

    public function test_stack_traces_do_not_carry_argument_values(): void
    {
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));

        $thrower = function (string $code) {
            throw new Exception('Simulated failure.');
        };

        try {
            $thrower('774411');
        } catch (Exception $e) {
            $this->assertStringNotContainsString('774411', $e->getTraceAsString());
        }
    }
}
