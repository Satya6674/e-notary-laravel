<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards the demo scope: no fabricated claims, no fake verification, a visible
 * disclosure on every page, and no public path to uploaded documents.
 */
class DemoIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** Lower-case text that must not appear in any view. */
    private const FORBIDDEN = [
        '123456', 'demo otp', 'openverify', 'verifymodal', 'udin', 'gov. approved', 'gov.in', 'government-backed',
        'government of india', 'ministry', 'digital india', 'aadhaar', 'e-stamp', 'estamp', 'blockchain', 'biometric',
        'legally valid', 'lakh', '500+', '99.9%', 'trusted by', 'most trusted', 'rajesh', 'bar council',
        'enotary india', 'centerlist', 'demo portal', 'e-notary demo',
    ];

    public function test_no_view_contains_a_fabricated_claim_or_fake_verification(): void
    {
        $found = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            // welcome.blade.php is the unused framework page: its SVG paths match digit strings.
            if ($file->getFilename() === 'welcome.blade.php') {
                continue;
            }

            // The required demo warning contains a forbidden phrase; the claim itself must not appear.
            $text = str_replace('not legally valid', '', strtolower($file->getContents()));

            foreach (self::FORBIDDEN as $term) {
                if (str_contains($text, $term)) {
                    $found[] = "{$file->getRelativePathname()}: {$term}";
                }
            }
        }

        $this->assertSame([], $found);
    }

    /** Affirmative claims; each is allowed only when negated earlier in its sentence. */
    private const CLAIMS = [
        '/\blegal(?:ly)?[\s-]*(?:valid|validity|binding|enforceable|recogni[sz]ed)\b/u',
        '/\bgovernment[\s-]*(?:authori[sz]ed|approved|recogni[sz]ed|certified|endorsed|backed|affiliated|licensed|sanctioned)\b/u',
        '/\b(?:authori[sz]ed|approved|recogni[sz]ed|certified|endorsed|licensed|sanctioned|accredited) by (?:the |any |a )?(?:government|ministry|state|court)\b/u',
        '/\baffiliated (?:with|to) (?:the |a |any )?(?:government|ministry)\b/u',
        '/\b(?:official|a) government (?:service|portal|website|body)\b/u',
    ];

    /** The public pages a visitor can open without an account. */
    private const PUBLIC_PAGES = ['/', '/login', '/register', '/forgot-password', '/verify', '/about', '/privacy', '/terms', '/disclaimer'];

    /**
     * What a reader sees, normalised so markup, entities, invisible characters, spacing and case
     * cannot hide a claim. Only the exact required disclaimer is removed before checking.
     *
     * @return list<string> the forbidden terms and unnegated claims found
     */
    private static function violations(string $html): array
    {
        $text = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text);
        $text = mb_strtolower(trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $text)));
        $text = str_replace(['demo / not legally valid', 'not legally valid'], ' ', $text);

        $found = array_values(array_filter(self::FORBIDDEN, fn ($term) => str_contains($text, $term)));
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text);

        foreach ($sentences as $i => $sentence) {
            foreach (self::CLAIMS as $claim) {
                if (! preg_match($claim, $sentence, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $negated = preg_match("/\\b(?:not|no|never|without|neither|nor)\\b|n't/u", substr($sentence, 0, $m[0][1])) === 1;
                $answeredNo = str_ends_with($sentence, '?') && preg_match('/^\W*no\b/u', $sentences[$i + 1] ?? '') === 1;

                if (! $negated && ! $answeredNo) {
                    $found[] = $m[0][0];
                }
            }
        }

        return $found;
    }

    public function test_rendered_home_page_has_no_forbidden_text_and_shows_the_disclosure(): void
    {
        $raw = $this->get('/')->assertOk()->getContent();
        $html = strtolower($raw);

        // The original raw scan, kept: it also covers attribute values and script text, which the
        // reader-text check below strips. Only the exact required disclaimer is removed first.
        $stripped = str_replace('not legally valid', '', $html);
        foreach (self::FORBIDDEN as $term) {
            $this->assertStringNotContainsString($term, $stripped);
        }

        $this->assertSame([], self::violations($raw));
        // The site-wide DEMO banner was retired on 2026-11-13 (see DemoMarkingTest for the Citizen footer line).
        $this->assertStringNotContainsString('en-demo-banner', $raw);

        $this->assertStringContainsString('independent software platform', $html);
        $this->assertStringContainsString('not a government service', $html);
        $this->assertStringContainsString('no legally recognised notarisation', $html);
    }

    public function test_every_public_page_shows_the_disclosure_and_makes_no_claim(): void
    {
        $statement = 'It is not a government service, is not affiliated with any government body, and provides no legally recognised notarisation.';

        foreach (self::PUBLIC_PAGES as $page) {
            $raw = $this->get($page)->assertOk()->getContent();

            $this->assertSame([], self::violations($raw), $page);
            $this->assertStringContainsString($statement, html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), "{$page} lost the non-affiliation disclosure");
        }
    }

    /** @return array<string, array{string, bool}> html, allowed */
    public static function claimSamples(): array
    {
        $mark = '<div class="en-demo-banner" role="note"><strong>DEMO / NOT LEGALLY VALID</strong> <span>E-Notary is a demonstration and is not legally valid.</span></div>';

        return [
            'plain claim' => ['This certificate is legally valid.', false],
            'upper case and spaces' => ['This record is LEGALLY   VALID.', false],
            'split by a tag' => ['This record is legally<br>valid.', false],
            'non-breaking space' => ['This record is legally&nbsp;valid.', false],
            'hyphen' => ['A legally-valid record.', false],
            'zero-width character' => ["A legally\u{200B}valid record.", false],
            'binding' => ['Legally binding notarisation.', false],
            'government authorised' => ['A Government-authorised notary.', false],
            'authorised by the government' => ['Authorized by the Government.', false],
            'affiliated' => ['We are affiliated with the government.', false],
            'question answered yes' => ['Is this legally valid? Yes, it is.', false],
            'mark plus a separate claim' => [$mark.'<p>The certificate is legally valid.</p>', false],
            'existing government term' => ['Approved by the Government of India.', false],
            'the mark alone' => [$mark, true],
            'footer disclosure' => ['It is not a government service, is not affiliated with any government body, and provides no legally recognised notarisation.', true],
            'faq answered no' => ['Is E-Notary a government service? No. E-Notary is an independent software platform.', true],
            'receipt line' => ['This receipt is not proof of notarisation, government approval or a completed service.', true],
            'terms line' => ['It is not a government service and is not endorsed by any government body.', true],
            'pdf notice' => ['Not legally valid. Not a notarised document.', true],
            'pdf notice reworded into a claim' => ['A legally valid notarisation document.', false],
            'citizen footer line' => ['Independent Prototype | For Evaluation Only', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('claimSamples')]
    public function test_the_claim_checker_cannot_be_bypassed(string $html, bool $allowed): void
    {
        $this->assertSame($allowed, self::violations($html) === [], $html);
    }

    public function test_home_page_links_to_the_real_lookup_and_has_no_fake_form(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString(route('verify.index'), $html);
        $this->assertStringNotContainsString('id="otpInput"', $html);
        $this->assertStringNotContainsString('id="mobileInput"', $html);
        $this->assertStringNotContainsString('Document Verified', $html);
    }

    public function test_disclosure_is_on_guest_pages_and_on_each_role_area(): void
    {
        $statement = 'It is not a government service, is not affiliated with any government body, and provides no legally recognised notarisation.';

        foreach (['/', '/login', '/register', '/forgot-password', '/verify', '/privacy', '/terms'] as $page) {
            $this->get($page)->assertOk()->assertSee($statement);
        }

        foreach ([
            ['admin', [], '/admin/dashboard'],
            ['notary', ['notary_approved_at' => now(), 'email_verified_at' => now()], '/notary/dashboard'],
            ['public', ['email_verified_at' => now(), 'mobile_verified_at' => now()], '/dashboard'],
        ] as $i => [$role, $extra, $path]) {
            $user = User::create(array_merge([
                'name' => 'Person', 'email' => "person{$i}@example.test", 'mobile' => '900000000'.$i,
                'password' => Hash::make('password'), 'role' => $role,
            ], $extra));

            $this->actingAs($user)->get($path)->assertOk()->assertSee($statement);
        }
    }

    public function test_no_route_or_link_can_expose_stored_files(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertSame([], config('filesystems.links'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
        $this->get('/storage/documents/anything.pdf')->assertNotFound();
    }

    public function test_the_public_storage_link_does_not_exist(): void
    {
        $this->assertFileDoesNotExist(public_path('storage'));
    }
}
