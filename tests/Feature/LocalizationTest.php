<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Analytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Module 10: English, Hindi and Punjabi, kept complete and switched per person. */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'public'): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => "Lang {$n}", 'email' => "lang{$n}@example.test", 'mobile' => '95600'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now()]);
        $user->forceFill(['mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null])->save();

        return $user;
    }

    private function translations(string $locale): array
    {
        $data = json_decode(File::get(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }

    public function test_hindi_and_punjabi_have_the_same_keys_and_no_empty_or_untranslated_values(): void
    {
        $hi = $this->translations('hi');
        $pa = $this->translations('pa');

        $this->assertSame([], array_values(array_diff(array_keys($hi), array_keys($pa))), 'in hi.json but not pa.json');
        $this->assertSame([], array_values(array_diff(array_keys($pa), array_keys($hi))), 'in pa.json but not hi.json');

        foreach (['hi' => $hi, 'pa' => $pa] as $locale => $data) {
            foreach ($data as $key => $value) {
                $this->assertIsString($value, "{$locale}: {$key}");
                $this->assertNotSame('', trim($value), "{$locale}: {$key} is empty");
                // Every placeholder in the English key must survive in the translation.
                preg_match_all('/:[a-z_]+/', $key, $placeholders);
                foreach ($placeholders[0] as $placeholder) {
                    $this->assertStringContainsString($placeholder, $value, "{$locale}: {$key} lost {$placeholder}");
                }
            }
        }

        // The pages written today are really translated, in the right script.
        foreach (['Reports & Analytics', 'Your Consent', 'Priority', 'Rejected Notaries'] as $key) {
            $this->assertMatchesRegularExpression('/\p{Devanagari}/u', $hi[$key], "hi: {$key}");
            $this->assertMatchesRegularExpression('/\p{Gurmukhi}/u', $pa[$key], "pa: {$key}");
        }
    }

    public function test_every_literal_key_in_views_and_application_code_is_translated(): void
    {
        $keys = [];

        foreach ([resource_path('views'), app_path()] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                preg_match_all("~__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*[,)]~", $file->getContents(), $m);
                foreach ($m[1] as $key) {
                    $keys[str_replace("\\'", "'", $key)] = $file->getFilename();
                }
            }
        }

        // Labels passed to __() as variables: sidebar entries and group names.
        $sidebar = File::get(resource_path('views/partials/sidebar.blade.php'));
        preg_match_all("~'fa-[a-z0-9\-]+', '([^']+)'~", $sidebar, $m);
        preg_match_all("~^\s*'([A-Z][A-Za-z ]+)' => \[~m", $sidebar, $groups);
        foreach ([...$m[1], ...$groups[1]] as $key) {
            $keys[$key] = 'sidebar';
        }

        foreach (config('privacy.retention') as $record => $rule) {
            $keys[$record] = $keys[$rule] = 'config/privacy.php';
        }

        $this->assertGreaterThan(800, count($keys), 'the scan found the keys');

        foreach (['hi', 'pa'] as $locale) {
            $missing = array_diff_key($keys, $this->translations($locale));
            $this->assertSame([], $missing, "{$locale}.json is missing keys");
        }
    }

    public function test_switching_language_is_remembered_and_new_pages_render_in_each_script(): void
    {
        $admin = $this->user('admin');
        $citizen = $this->user();

        $this->actingAs($admin)->post(route('locale.update'), ['locale' => 'hi'])->assertRedirect();
        $this->assertSame('hi', $admin->fresh()->locale);
        $this->actingAs($admin)->get(route('admin.analytics.index'))->assertOk()
            ->assertSee('रिपोर्ट और विश्लेषण')->assertSee('कुल नागरिक')->assertSee('lang="hi"', false);

        $this->actingAs($admin)->post(route('locale.update'), ['locale' => 'pa']);
        $this->actingAs($admin)->get(route('admin.analytics.index'))->assertOk()->assertSee('ਰਿਪੋਰਟਾਂ ਅਤੇ ਵਿਸ਼ਲੇਸ਼ਣ');
        $this->actingAs($admin)->get(route('support.index'))->assertOk()->assertSee('ਤਰਜੀਹ');

        $this->actingAs($citizen)->withSession(['locale' => 'hi'])->get(route('privacy-center.index'))->assertOk()->assertSee('आपकी सहमति');

        $this->actingAs($admin)->post(route('locale.update'), ['locale' => 'en']);
        $this->actingAs($admin)->get(route('admin.analytics.index'))->assertOk()->assertSee('Reports &amp; Analytics', false);
    }

    public function test_an_unknown_language_falls_back_to_english(): void
    {
        $this->withSession(['locale' => 'xx'])->get(route('home'))->assertOk();
        $this->assertSame('en', app()->getLocale());

        $this->post(route('locale.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }

    public function test_validation_messages_and_flash_messages_are_translated(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary');

        $this->actingAs($admin)->withSession(['locale' => 'hi'])->from(route('admin.notaries.index'))
            ->post(route('admin.notaries.reject', $notary), ['reason' => 'Licence not readable here.'])
            ->assertRedirect();
        $this->actingAs($admin)->withSession(['locale' => 'hi'])->get(route('admin.notaries.index'))
            ->assertSee('नोटरी स्वीकृति वापस ली गई।');

        app()->setLocale('pa');
        $this->assertSame('ਨੋਟਰੀ ਅਰਜ਼ੀ ਅਸਵੀਕਾਰ ਕੀਤੀ ਗਈ।', __('Notary application rejected.'));
        $this->assertNotSame('The :attribute field is required.', trans('validation.required'), 'Punjabi validation messages exist');
    }

    public function test_chart_month_names_follow_the_language(): void
    {
        $analytics = app(Analytics::class);
        [$start, $end] = $analytics->range('2026-10-01', '2026-10-31');

        app()->setLocale('hi');
        $this->assertStringContainsString('अक्टू', $analytics->period($start, $end)['registrations']['monthly'][0]['label']);

        app()->setLocale('en');
        $this->assertSame('Oct 2026', $analytics->period($start, $end)['registrations']['monthly'][0]['label']);
    }
}
