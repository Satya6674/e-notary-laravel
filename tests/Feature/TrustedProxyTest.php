<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\TrustedProxySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H-2 (pre-deployment audit 2026-11-11): trusted reverse proxies.
 *
 * TRUSTED_PROXIES is read through config/trustedproxy.php, after .env is loaded, so a proxy listed in
 * .env is believed. "none" declares direct TLS (no proxy) explicitly; empty declares nothing. Only
 * X-Forwarded-For and -Proto are believed, and only from a listed proxy; Port, Host, Prefix and RFC 7239
 * Forwarded never. Without a listed proxy (local XAMPP), forwarded headers are ignored.
 * enotary:deploy-check reports undeclared, wildcard-equivalent, broad, placeholder and invalid values.
 *
 * A request probe route shows how Laravel resolved each request. The .env loading order is proven in a
 * child process (tests/Support/proxy-env-probe.php) with a temporary environment folder, because
 * PHPUnit's own environment variables would hide a value read too early.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = '/_trusted-proxy-probe';

    private const FORWARDED = ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        Route::middleware('web')->get(self::PROBE, fn (Request $request) => response()->json([
            'ip'        => $request->ip(),
            'secure'    => $request->secure(),
            'host'      => $request->getHost(),
            'port'      => $request->getPort(),
            'login_url' => url('/login'),
        ]));
    }

    // ---------------------------------------------------------------- helpers

    private function trust(array|string|null $proxies, bool $directTls = false): void
    {
        config(['trustedproxy.proxies' => $proxies, 'trustedproxy.direct_tls' => $directTls]);
    }

    /**
     * Always an absolute URL: the test client otherwise builds it from the URL generator, whose root
     * follows the previous request, so an https request would make the next one genuinely https.
     */
    private function probe(string $remote, array $headers = [], string $scheme = 'http'): TestResponse
    {
        return $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders($headers)->getJson($scheme.'://localhost'.self::PROBE)->assertOk();
    }

    private function assertResolved(TestResponse $response, string $ip, bool $secure): void
    {
        $response->assertJsonPath('ip', $ip)->assertJsonPath('secure', $secure);

        $secure
            ? $response->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            : $response->assertHeaderMissing('Strict-Transport-Security');
    }

    private function contact(string $remote, string $forwardedFor, int $n): TestResponse
    {
        return $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $remote])->withHeaders(['X-Forwarded-For' => $forwardedFor])
            ->post('http://localhost'.route('contact.store', [], false), ['name' => 'Proxy Test', 'email' => "proxy{$n}@example.test", 'subject' => 'General Inquiry', 'message' => 'A message sent through the proxy test.']);
    }

    /** The config file evaluated with TRUSTED_PROXIES set to $raw (process environment restored after). */
    private function configFor(string $raw): array
    {
        $original = [getenv('TRUSTED_PROXIES'), $_ENV['TRUSTED_PROXIES'] ?? null, $_SERVER['TRUSTED_PROXIES'] ?? null];

        try {
            putenv("TRUSTED_PROXIES={$raw}");
            $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $raw;

            return require config_path('trustedproxy.php');
        } finally {
            $original[0] === false ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES='.$original[0]);
            foreach (['_ENV' => 1, '_SERVER' => 2] as $global => $i) {
                if ($original[$i] === null) {
                    unset($GLOBALS[$global]['TRUSTED_PROXIES']);
                } else {
                    $GLOBALS[$global]['TRUSTED_PROXIES'] = $original[$i];
                }
            }
        }
    }

    /** Runs the child probe with a temporary .env and the given environment changes. */
    private function runChildProbe(array $envLines, array $requests, array $env = []): \Illuminate\Contracts\Process\ProcessResult
    {
        $dir = storage_path('framework/testing/proxy-env-'.uniqid());
        File::ensureDirectoryExists($dir);
        File::put($dir.DIRECTORY_SEPARATOR.'.env', implode("\n", $envLines)."\n");

        try {
            // The isolated test environment is inherited (in-memory database, array mail, discard log,
            // test storage and APP_KEY); only the settings this .env must decide are removed.
            return Process::path(base_path())->timeout(120)
                ->env(['APP_ENV' => false, 'APP_URL' => false, 'TRUSTED_PROXIES' => false] + $env)
                ->run([PHP_BINARY, base_path('tests/Support/proxy-env-probe.php'), $dir, json_encode($requests)]);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function childProbe(array $envLines, array $requests): array
    {
        $result = $this->runChildProbe($envLines, $requests);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $report = json_decode($result->output(), true);
        $this->assertIsArray($report, $result->output());

        return $report;
    }

    /** The deploy-check output for an https APP_URL with this proxy configuration. */
    private function deployCheck(array|string|null $proxies, bool $directTls = false, string $url = 'https://enotary.example'): string
    {
        config(['app.url' => $url]);
        $this->trust($proxies, $directTls);
        \Illuminate\Support\Facades\Artisan::call('enotary:deploy-check');

        return \Illuminate\Support\Facades\Artisan::output();
    }

    // ---------------------------------------------------------------- .env loading order (the H-2 defect)

    public function test_a_trusted_proxy_listed_in_the_env_file_is_honoured_in_a_fresh_process(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '8443'];
        $report = $this->childProbe(
            ['APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=https://enotary.example', 'TRUSTED_PROXIES=10.0.0.5'],
            [
                'via_proxy'      => ['url' => 'http://enotary.example/up', 'server' => ['REMOTE_ADDR' => '10.0.0.5'] + $server],
                'direct_client'  => ['url' => 'http://enotary.example/up', 'server' => ['REMOTE_ADDR' => '198.51.100.7'] + $server],
                'forwarded_host' => ['url' => 'http://enotary.example/up', 'server' => ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'evil.example', 'HTTP_X_FORWARDED_PREFIX' => '/evil'] + $server],
                'forged_host'    => ['url' => 'http://evil.example/up', 'server' => ['REMOTE_ADDR' => '198.51.100.7']],
            ]
        );

        $this->assertSame('.env', basename($report['environment_file']), 'the temporary .env, not .env.testing');
        $this->assertSame('production', $report['environment']);
        $this->assertSame('10.0.0.5', $report['config_proxies'], 'the value from the .env file reached the configuration');

        $r = $report['requests'];
        $this->assertSame([200, '203.0.113.9', true, 'https://enotary.example/login'], [$r['via_proxy']['status'], $r['via_proxy']['ip'], $r['via_proxy']['secure'], $r['via_proxy']['login_url']], 'believed from the listed proxy; the forged port 8443 is not');
        $this->assertSame([200, '198.51.100.7', false], [$r['direct_client']['status'], $r['direct_client']['ip'], $r['direct_client']['secure']], 'ignored from anyone else');
        $this->assertSame([200, 'enotary.example', 'https://enotary.example/login'], [$r['forwarded_host']['status'], $r['forwarded_host']['host'], $r['forwarded_host']['login_url']], 'Host, Prefix and Port are not believed even from the proxy');
        $this->assertSame(400, $r['forged_host']['status'], 'TrustHosts still refuses a foreign Host in production');
    }

    public function test_without_trusted_proxies_or_with_none_a_fresh_process_believes_no_forwarded_header(): void
    {
        $request = ['via_proxy' => ['url' => 'http://enotary.example/up', 'server' => ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']]];

        foreach ([[[], null], [['TRUSTED_PROXIES=NoNe'], []]] as [$extra, $configured]) {
            $report = $this->childProbe(array_merge(['APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=https://enotary.example'], $extra), $request);

            $this->assertSame($configured, $report['config_proxies'], json_encode($extra));
            $this->assertSame(['10.0.0.5', false], [$report['requests']['via_proxy']['ip'], $report['requests']['via_proxy']['secure']], json_encode($extra));
        }
    }

    public function test_on_laravel_cloud_none_in_the_env_file_still_trusts_no_proxy_while_empty_follows_laravel(): void
    {
        // A fresh process with LARAVEL_CLOUD=1 (as the platform sets it) and a direct client forging
        // its address and scheme. Laravel trusts the connecting address when the list is null (empty);
        // the explicit "none" is passed as [] and must not be overridden by that platform auto-trust.
        $request = ['direct' => ['url' => 'http://enotary.example/up', 'server' => ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https']]];
        $base = ['APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=https://enotary.example'];

        foreach ([[['TRUSTED_PROXIES=none'], '198.51.100.7', false], [[], '1.2.3.4', true]] as [$extra, $ip, $secure]) {
            $result = $this->runChildProbe(array_merge($base, $extra), $request, ['LARAVEL_CLOUD' => '1']);
            $this->assertTrue($result->successful(), $result->errorOutput());
            $direct = json_decode($result->output(), true)['requests']['direct'];

            $this->assertSame([$ip, $secure], [$direct['ip'], $direct['secure']], 'LARAVEL_CLOUD=1 with '.json_encode($extra));
        }
    }

    public function test_the_child_probe_refuses_any_database_but_the_isolated_test_ones(): void
    {
        // Never a real database name here: if the guard failed, these would still lead nowhere.
        foreach ([
            ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'enotary_guard_probe_not_allowed'],
            ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '192.0.2.1', 'DB_DATABASE' => 'enotary_phpunit'],
            ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => storage_path('framework/testing/not-allowed.sqlite')],
        ] as $env) {
            $result = $this->runChildProbe(['APP_ENV=production', 'APP_URL=https://enotary.example'], ['r' => ['url' => 'http://enotary.example/up', 'server' => ['REMOTE_ADDR' => '10.0.0.5']]], $env);

            $this->assertSame(3, $result->exitCode(), json_encode($env).' '.$result->errorOutput());
            $this->assertStringContainsString('proxy-env-probe refused', $result->errorOutput());
            $this->assertSame('', $result->output(), 'nothing was handled');
        }
        $this->assertFileDoesNotExist(storage_path('framework/testing/not-allowed.sqlite'));
    }

    public function test_bootstrap_never_reads_trusted_proxies_with_env(): void
    {
        foreach (['bootstrap', 'app', 'routes'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                $this->assertStringNotContainsString("env('TRUSTED_PROXIES'", $file->getContents(), $file->getRelativePathname().' must read config(\'trustedproxy.proxies\')');
            }
        }

        $this->assertStringContainsString("env('TRUSTED_PROXIES'", File::get(config_path('trustedproxy.php')));
    }

    // ---------------------------------------------------------------- empty, none, list (configuration)

    public function test_empty_none_and_a_list_are_three_distinct_configurations(): void
    {
        foreach (['' => [null, false], '   ' => [null, false], 'none' => [[], true], ' NoNe ' => [[], true], 'NONE' => [[], true],
            '10.0.0.5' => ['10.0.0.5', false], ' 127.0.0.1,::1 ' => ['127.0.0.1,::1', false], '*' => ['*', false],
            'none,10.0.0.5' => ['none,10.0.0.5', false]] as $raw => [$proxies, $direct]) {
            $config = $this->configFor($raw);

            $this->assertSame($proxies, $config['proxies'], 'proxies for '.var_export($raw, true));
            $this->assertSame($direct, $config['direct_tls'], 'direct_tls for '.var_export($raw, true));
        }
    }

    public function test_the_configuration_serializes_for_config_caching(): void
    {
        // config:cache writes var_export() of every config array; it must hold no closure or object.
        // Checked here without running config:cache, which would write the live bootstrap/cache.
        foreach (['', 'none', '10.0.0.5,2001:db8::/32'] as $raw) {
            $config = $this->configFor($raw);

            array_walk_recursive($config, fn ($value) => $this->assertTrue($value === null || is_scalar($value), var_export($raw, true)));
            $this->assertSame($config, eval('return '.var_export($config, true).';'), var_export($raw, true));
        }
    }

    public function test_none_trusts_no_proxy_at_request_time(): void
    {
        $this->trust([], directTls: true);

        $this->assertResolved($this->probe('10.0.0.5', self::FORWARDED), '10.0.0.5', false);
        $this->assertSame([], Request::getTrustedProxies(), 'an empty list, never the word "none"');
    }

    public function test_explicit_none_is_not_overridden_by_laravel_platform_auto_trust(): void
    {
        // Laravel's TrustProxies trusts the connecting address when the proxy list is null and the host
        // is *.on-forge.com / *.on-vapor.com, or LARAVEL_CLOUD=1. A direct client forges its address and
        // scheme in every case below.
        $cloud = [$_ENV['LARAVEL_CLOUD'] ?? null, $_SERVER['LARAVEL_CLOUD'] ?? null];
        $probe = fn (string $host) => $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(self::FORWARDED)->getJson("http://{$host}".self::PROBE)->assertOk();

        try {
            foreach (['enotary.on-forge.com' => false, 'enotary.on-vapor.com' => false, 'enotary.example' => true] as $host => $setCloud) {
                if ($setCloud) {
                    $_ENV['LARAVEL_CLOUD'] = $_SERVER['LARAVEL_CLOUD'] = '1';
                }

                // none -> []: no proxy trusted, the forged headers are ignored.
                $this->trust([], directTls: true);
                $this->assertResolved($probe($host), '198.51.100.7', false);

                // empty -> null: Laravel's own platform behaviour (documented, not changed here).
                $this->trust(null);
                $this->assertResolved($probe($host), '203.0.113.9', true);

                // A specific list is unaffected by the platform.
                $this->trust('10.0.0.5');
                $this->assertResolved($probe($host), '198.51.100.7', false);

                unset($_ENV['LARAVEL_CLOUD'], $_SERVER['LARAVEL_CLOUD']);
            }
        } finally {
            foreach (['_ENV' => 0, '_SERVER' => 1] as $global => $i) {
                if ($cloud[$i] === null) {
                    unset($GLOBALS[$global]['LARAVEL_CLOUD']);
                } else {
                    $GLOBALS[$global]['LARAVEL_CLOUD'] = $cloud[$i];
                }
            }
        }
    }

    // ---------------------------------------------------------------- trusted and untrusted proxies

    public function test_with_no_trusted_proxy_forwarded_headers_from_any_client_are_ignored(): void
    {
        $this->assertNull(config('trustedproxy.proxies'), 'the default is no proxy');
        $this->assertFalse(config('trustedproxy.direct_tls'));

        foreach (['198.51.100.7', '10.0.0.5', '127.0.0.1'] as $remote) {
            $this->assertResolved($this->probe($remote, self::FORWARDED)->assertJsonPath('login_url', 'http://localhost/login'), $remote, false);
        }
    }

    public function test_a_listed_proxy_is_believed_and_every_other_client_is_not(): void
    {
        $this->trust('10.0.0.5');

        $this->assertResolved($this->probe('10.0.0.5', self::FORWARDED)->assertJsonPath('login_url', 'https://localhost/login'), '203.0.113.9', true);
        $this->assertResolved($this->probe('198.51.100.7', self::FORWARDED)->assertJsonPath('login_url', 'http://localhost/login'), '198.51.100.7', false);
    }

    public function test_cidr_ranges_ipv6_and_comma_separated_lists_are_supported(): void
    {
        $this->trust(' 192.0.2.10 , 10.0.0.0/8 ,2001:db8::/32');

        foreach (['192.0.2.10', '10.20.30.40', '2001:db8::1'] as $proxy) {
            $this->assertResolved($this->probe($proxy, self::FORWARDED), '203.0.113.9', true);
        }

        foreach (['192.0.2.11', '172.16.0.1', '2001:db9::1'] as $outsider) {
            $this->assertResolved($this->probe($outsider, self::FORWARDED), $outsider, false);
        }
    }

    // ---------------------------------------------------------------- header chains

    public function test_the_client_is_the_rightmost_address_a_trusted_proxy_did_not_add(): void
    {
        $this->trust('10.0.0.5');
        // A client-written first entry is ignored: the proxy appended the real address last.
        $this->probe('10.0.0.5', ['X-Forwarded-For' => '6.6.6.6, 203.0.113.9'])->assertJsonPath('ip', '203.0.113.9');

        // Two proxy hops: both listed, so the walk continues past the inner one.
        $this->trust('10.0.0.5,10.0.0.6');
        $this->probe('10.0.0.5', ['X-Forwarded-For' => '6.6.6.6, 203.0.113.9, 10.0.0.6'])->assertJsonPath('ip', '203.0.113.9');
    }

    public function test_a_wildcard_trusts_only_the_directly_connecting_address(): void
    {
        $this->trust('*');

        // Only the immediate peer is trusted, so the client is the last entry it added.
        $this->probe('10.9.9.9', ['X-Forwarded-For' => '6.6.6.6, 203.0.113.9'])->assertJsonPath('ip', '203.0.113.9');

        // Why "*" is never the default and deploy-check warns: a client reaching the app directly is
        // itself "the proxy", and can name any address it likes.
        $this->probe('198.51.100.7', ['X-Forwarded-For' => '1.2.3.4'])->assertJsonPath('ip', '1.2.3.4');
    }

    // ---------------------------------------------------------------- host, prefix, port and header set

    public function test_forwarded_host_and_prefix_are_never_believed_even_from_a_trusted_proxy(): void
    {
        $this->trust('10.0.0.5');

        $this->probe('10.0.0.5', self::FORWARDED + ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Prefix' => '/evil', 'Forwarded' => 'for=6.6.6.6;host=evil.example;proto=https'])
            ->assertJsonPath('host', 'localhost')
            ->assertJsonPath('login_url', 'https://localhost/login')
            ->assertJsonPath('ip', '203.0.113.9');

        $this->assertSame(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO, Request::getTrustedHeaderSet(), 'only For and Proto');
    }

    public function test_a_forged_forwarded_port_cannot_change_links_or_redirects(): void
    {
        // Through a pass-through proxy that is trusted: the client's X-Forwarded-Port is not believed.
        $this->trust('10.0.0.5');
        $this->probe('10.0.0.5', self::FORWARDED + ['X-Forwarded-Port' => '8443'])
            ->assertJsonPath('port', 443)->assertJsonPath('login_url', 'https://localhost/login')->assertJsonPath('secure', true);

        // A guest redirect builds its Location from the request: still the standard port.
        $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders(self::FORWARDED + ['X-Forwarded-Port' => '8443'])
            ->get('http://localhost'.route('dashboard', [], false))->assertRedirect('https://localhost/login');

        // From an untrusted client nothing forwarded is believed at all.
        $this->probe('198.51.100.7', self::FORWARDED + ['X-Forwarded-Port' => '8443'])
            ->assertJsonPath('port', 80)->assertJsonPath('login_url', 'http://localhost/login')->assertJsonPath('secure', false);
        $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeaders(self::FORWARDED + ['X-Forwarded-Port' => '8443'])
            ->get('http://localhost'.route('dashboard', [], false))->assertRedirect('http://localhost/login');
    }

    // ---------------------------------------------------------------- HTTPS and HSTS

    public function test_https_and_hsts_come_only_from_real_tls_or_a_trusted_proxy(): void
    {
        // Apache (XAMPP) serving TLS itself: no proxy, the connection is genuinely secure.
        $this->assertResolved($this->probe('127.0.0.1', [], 'https'), '127.0.0.1', true);

        // A client claiming HTTPS over plain HTTP is not believed.
        $this->assertResolved($this->probe('198.51.100.7', ['X-Forwarded-Proto' => 'https']), '198.51.100.7', false);

        // The proxy that terminated TLS is, on the standard port.
        $this->trust('10.0.0.5');
        $this->assertResolved($this->probe('10.0.0.5', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])->assertJsonPath('port', 443), '203.0.113.9', true);
    }

    // ---------------------------------------------------------------- rate limits and audit

    public function test_rate_limit_buckets_follow_the_real_client_behind_a_trusted_proxy(): void
    {
        $this->trust('10.0.0.5');
        $n = 0;

        // Client A, through the proxy: the contact form allows 3 per 10 minutes.
        for ($i = 0; $i < 3; $i++) {
            $this->contact('10.0.0.5', '203.0.113.1', ++$n)->assertRedirect();
        }
        $this->contact('10.0.0.5', '203.0.113.1', ++$n)->assertStatus(429);

        // Client B, through the same proxy, has its own bucket (before H-2 they shared the proxy's).
        $this->contact('10.0.0.5', '203.0.113.2', ++$n)->assertRedirect();

        // A client that is not the proxy cannot make new buckets by inventing addresses.
        for ($i = 0; $i < 3; $i++) {
            $this->contact('198.51.100.7', "6.6.6.{$i}", ++$n)->assertRedirect();
        }
        $this->contact('198.51.100.7', '6.6.6.99', ++$n)->assertStatus(429);
    }

    public function test_the_audit_log_records_the_real_client_address(): void
    {
        $this->trust('10.0.0.5');
        $user = new User;
        $user->forceFill(['name' => 'Proxy Citizen', 'email' => 'proxy.citizen@example.test', 'mobile' => '9411100001', 'password' => Hash::make('Proxy-demo-pass-1'),
            'role' => 'public', 'email_verified_at' => now(), 'mobile_verified_at' => now()])->save();
        $login = fn (string $remote, string $forwarded) => $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $remote])->withHeaders(['X-Forwarded-For' => $forwarded])
            ->postJson('http://localhost'.route('login.submit', [], false), ['mobile' => 'proxy.citizen@example.test', 'password' => 'Proxy-demo-pass-1'])->assertOk();

        $login('10.0.0.5', '203.0.113.9');
        $this->assertSame('203.0.113.9', AuditLog::where('action', 'auth.login')->latest('id')->value('ip_address'), 'behind the proxy: the client');

        $this->post(route('logout'));
        $this->flushSession();
        auth()->forgetGuards();

        $login('198.51.100.7', '6.6.6.6');
        $this->assertSame('198.51.100.7', AuditLog::where('action', 'auth.login')->latest('id')->value('ip_address'), 'a forged header from a non-proxy is ignored');
    }

    // ---------------------------------------------------------------- local XAMPP

    public function test_local_xampp_without_a_proxy_is_unchanged(): void
    {
        $this->assertResolved($this->probe('127.0.0.1')->assertJsonPath('host', 'localhost')->assertJsonPath('login_url', 'http://localhost/login'), '127.0.0.1', false);
        $this->probe('::1')->assertJsonPath('ip', '::1');
        $this->assertSame([], Request::getTrustedProxies());
    }

    // ---------------------------------------------------------------- deploy-check validation

    public function test_every_entry_is_classified_without_false_positives(): void
    {
        $cases = [
            'valid'       => ['10.0.0.5', '::1', '2001:db8::1', '10.0.0.0/8', '192.0.2.0/24', '2001:db8::/32', '127.0.0.1/32', '::1/128'],
            'wildcard'    => ['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0'],
            'broad'       => ['PRIVATE_SUBNETS', 'private_ranges'],
            'placeholder' => ['<proxy-ip-or-cidr>', '<proxy-ip-or-*>'],
            'invalid'     => ['none', 'remote_addr', 'private_subnets', '10.0.0.256', '1.2.3', 'proxy.internal', '10.0.0.0/33', '2001:db8::/129', '10.0.0.0/x', '10.0.0.0/', 'localhost'],
        ];

        foreach ($cases as $expected => $entries) {
            foreach ($entries as $entry) {
                $this->assertSame($expected, TrustedProxySettings::classify($entry), $entry);
            }
        }

        $report = TrustedProxySettings::inspect(' 10.0.0.5 ,, ::1 , REMOTE_ADDR,10.0.0.0/33,');
        $this->assertSame(['10.0.0.5', '::1', 'REMOTE_ADDR', '10.0.0.0/33'], $report['entries'], 'split, trimmed, empty entries ignored');
        $this->assertSame([['10.0.0.5', '::1'], ['REMOTE_ADDR'], ['10.0.0.0/33']], [$report['valid'], $report['wildcard'], $report['invalid']]);
        $this->assertSame([], TrustedProxySettings::inspect(null)['entries']);
    }

    public function test_the_deploy_check_requires_a_declaration_for_https_and_accepts_none_or_a_list(): void
    {
        $declare = 'Declare how TLS reaches the app';

        $this->assertStringContainsString($declare, $this->deployCheck(null), 'https, nothing declared');
        $this->assertStringNotContainsString($declare, $this->deployCheck([], directTls: true), 'https, TRUSTED_PROXIES=none (direct TLS)');
        $this->assertStringNotContainsString($declare, $this->deployCheck('10.0.0.0/8'), 'https, proxy listed');
        $this->assertStringNotContainsString($declare, $this->deployCheck(null, url: 'http://127.0.0.1:8000'), 'local http: no declaration needed');

        $clean = $this->deployCheck('127.0.0.1,::1,10.0.0.0/8,2001:db8::/32');
        // The "Needed" texts appear only when a row does not pass (the row titles always appear).
        foreach (['trusts any directly connecting client', 'every private-network address is trusted', 'still holds the template placeholder', 'not IP addresses or CIDR ranges'] as $warning) {
            $this->assertStringNotContainsString($warning, $clean, "no false positive ({$warning}) for valid entries");
        }
        $this->assertSame(5, preg_match_all('/\| Trusted proxies [^|]+\| PASS /', $clean), 'all five proxy rows pass');
    }

    public function test_the_deploy_check_flags_wildcard_broad_placeholder_and_invalid_values(): void
    {
        foreach (['*', '**', 'REMOTE_ADDR', '10.0.0.5,REMOTE_ADDR', '0.0.0.0/0', '::/0'] as $value) {
            $this->assertStringContainsString('trusts any directly connecting client', $this->deployCheck($value), $value);
        }

        foreach (['PRIVATE_SUBNETS', 'private_ranges'] as $value) {
            $this->assertStringContainsString('every private-network address is trusted', $this->deployCheck($value), $value);
        }

        $this->assertStringContainsString('still holds the template placeholder <proxy-ip-or-cidr>', $this->deployCheck('<proxy-ip-or-cidr>'));

        // The value actually shipped in deploy/production.env.template is caught if copied unedited.
        preg_match('/^TRUSTED_PROXIES=(.*)$/m', File::get(base_path('deploy/production.env.template')), $m);
        $this->assertSame('placeholder', TrustedProxySettings::classify(trim($m[1] ?? '')), 'template value: '.($m[1] ?? '(missing)'));

        $output = $this->deployCheck('10.0.0.5, 10.0.0.0/33, proxy.internal, none');
        $this->assertStringContainsString('not IP addresses or CIDR ranges (10.0.0.0/33, proxy.internal, none)', $output, '"none" inside a list is not an address');
    }
}
