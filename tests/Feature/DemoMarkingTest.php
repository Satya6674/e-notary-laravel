<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Presentation notices (owner decision 2026-11-13, replacing the site-wide DEMO banner of 2026-11-11):
 *   - "Independent Prototype | For Evaluation Only" appears once, as the last line of the signed-in
 *     Citizen Portal footer, and nowhere else;
 *   - generated PDFs keep a small legal-validity notice;
 *   - the sample-document warnings, the declaration and the no-index signals stay.
 */
class DemoMarkingTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_MARK = 'DEMO / NOT LEGALLY VALID';

    private function person(string $role, array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => 'Person', 'email' => "marking{$n}@example.test", 'mobile' => '90000001'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'), 'role' => $role,
        ], $extra));
    }

    private function citizen(): User
    {
        return $this->person('public', ['email_verified_at' => now(), 'mobile_verified_at' => now()]);
    }

    public function test_the_prototype_notice_is_the_last_line_of_the_citizen_portal_footer_once(): void
    {
        $citizen = $this->citizen();
        $line = '<p class="en-prototype-line">'.Platform::PROTOTYPE_NOTICE.'</p>';

        foreach (['/dashboard', '/user/upload'] as $path) {
            $html = $this->actingAs($citizen)->get($path)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, Platform::PROTOTYPE_NOTICE), $path);
            $this->assertMatchesRegularExpression('#'.preg_quote($line, '#').'\s*</footer>#', $html, $path);
            $this->assertStringNotContainsString(self::OLD_MARK, $html, $path);
        }
    }

    public function test_the_prototype_notice_is_nowhere_else(): void
    {
        $pages = [
            [null, '/', 200],
            [null, '/login', 200],
            [null, '/register', 200],
            [null, '/missing-page-for-the-notice', 404],
            [$this->person('admin'), '/admin/dashboard', 200],
            [$this->person('notary', ['notary_approved_at' => now(), 'email_verified_at' => now()]), '/notary/dashboard', 200],
            // A Citizen on the public site (home page): the public footer, not the portal footer.
            // (A Citizen's own error pages render inside the Citizen Portal shell and keep its footer.)
            [$this->citizen(), '/', 200],
        ];

        foreach ($pages as [$user, $path, $status]) {
            $html = ($user ? $this->actingAs($user) : $this)->get($path)->assertStatus($status)->getContent();

            $this->assertStringNotContainsString(Platform::PROTOTYPE_NOTICE, $html, $path);
            $this->assertStringNotContainsString(self::OLD_MARK, $html, $path);
            $this->assertStringNotContainsString('en-demo-banner', $html, $path);
            auth()->logout();
        }

        $this->assertStringNotContainsString(Platform::PROTOTYPE_NOTICE, view('errors.admin-mfa-unavailable')->render());
    }

    public function test_noindex_stays_on_role_areas_and_error_pages(): void
    {
        $pages = [
            [null, '/missing-page-for-the-notice', 404],
            [$this->person('admin'), '/admin/dashboard', 200],
            [$this->person('notary', ['notary_approved_at' => now(), 'email_verified_at' => now()]), '/notary/dashboard', 200],
            [$this->citizen(), '/dashboard', 200],
        ];

        foreach ($pages as [$user, $path, $status]) {
            $response = ($user ? $this->actingAs($user) : $this)->get($path)->assertStatus($status);

            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $response->getContent(), $path);
            // An unknown address never reaches the web middleware; the page's meta tag covers it,
            // and the web server adds the header to every response in the deployment.
            if ($status !== 404) {
                $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $path);
            }
            auth()->logout();
        }

        $this->assertStringContainsString('noindex, nofollow', view('errors.admin-mfa-unavailable')->render());
    }

    public function test_the_notice_stays_in_exact_english_in_every_language(): void
    {
        $citizen = $this->citizen();

        foreach (['hi', 'pa'] as $locale) {
            $this->actingAs($citizen)->withSession(['locale' => $locale])->get('/dashboard')->assertOk()
                ->assertSee(Platform::PROTOTYPE_NOTICE);
        }
    }

    public function test_sample_document_warnings_and_the_declaration_stay(): void
    {
        $views = [
            'user/upload.blade.php' => ['Please use sample documents only. Do not upload real identity or legal documents.', 'Sample documents only.'],
            'user/services/apply.blade.php' => ['Please use sample documents only. Do not upload real identity or legal documents.'],
            'user/services/review.blade.php' => ['these are sample documents only'],
        ];

        foreach ($views as $view => $phrases) {
            $source = file_get_contents(resource_path('views/'.$view));
            foreach ($phrases as $phrase) {
                $this->assertStringContainsString($phrase, $source, $view);
            }
        }

        $this->actingAs($this->citizen())->get('/user/upload')->assertOk()
            ->assertSee('Please use sample documents only. Do not upload real identity or legal documents.');
    }

    public function test_generated_pdfs_keep_a_small_legal_validity_notice(): void
    {
        $layout = file_get_contents(resource_path('views/pdf/layout.blade.php'));
        $this->assertStringContainsString('Platform::PDF_VALIDITY_NOTICE', $layout);
        $this->assertStringContainsString("@include('partials.disclosure')", $layout);

        $this->assertStringContainsString('NOT LEGALLY VALID. NOT A NOTARISED DOCUMENT.', file_get_contents(resource_path('views/pdf/certificate.blade.php')));
        $this->assertStringContainsString('SAMPLE ONLY. NOT LEGALLY VALID.', file_get_contents(resource_path('views/pdf/sample.blade.php')));
    }

    public function test_robots_txt_disallows_everything(): void
    {
        $this->assertSame("User-agent: *\nDisallow: /\n", str_replace("\r\n", "\n", file_get_contents(public_path('robots.txt'))));
    }
}
