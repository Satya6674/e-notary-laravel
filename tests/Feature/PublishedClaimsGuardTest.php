<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\DemoClaims;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An Admin cannot publish wording that presents the demonstration as legally valid or as backed by,
 * authorised by or affiliated with a government body (owner decision 1): neither in the site notice
 * shown on public pages nor in the service catalogue.
 */
class PublishedClaimsGuardTest extends TestCase
{
    use RefreshDatabase;

    private const NAME_CHANGE = 'name-change-affidavit';

    private function admin(): User
    {
        return User::create(['name' => 'Claims Admin', 'email' => 'claims.admin@example.test', 'mobile' => '9477900001',
            'password' => Hash::make('Claims-admin-1'), 'role' => 'admin']);
    }

    private function saveNotice(string $notice)
    {
        return $this->actingAs($this->admin())->from(route('settings.edit'))
            ->put(route('admin.settings.update'), ['portal_notice' => $notice, 'support_email' => 'support@example.test', 'default_locale' => 'en']);
    }

    /** @return array<string, array{string}> */
    public static function claims(): array
    {
        return [
            'legally valid' => ['All certificates issued here are legally valid.'],
            'spacing and case' => ['Our records are LEGALLY   BINDING.'],
            'markup' => ['Our records are legally<b></b> valid.'],
            'entity' => ['Our records are legally&nbsp;valid.'],
            'zero-width character' => ["Our records are legally\u{200B}valid."],
            'government approved' => ['A Government-approved notary portal.'],
            'authorised by' => ['Authorised by the Government for e-notarisation.'],
            'affiliated' => ['We are affiliated with the Ministry.'],
            'official portal' => ['This is an official government portal.'],
            'gov.in' => ['Also available at enotary.gov.in'],
            'bar council' => ['Registered with the Bar Council.'],
            'question answered yes' => ['Is this legally valid? Yes.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('claims')]
    public function test_a_claim_in_the_site_notice_is_refused_and_nothing_is_saved(string $notice): void
    {
        Setting::put('portal_notice', 'Previous notice.');

        $this->saveNotice($notice)->assertSessionHasErrors('portal_notice');

        $this->assertSame('Previous notice.', Setting::get('portal_notice'));
    }

    /** @return array<string, array{string}> */
    public static function allowed(): array
    {
        return [
            'plain notice' => ['Planned maintenance on Sunday from 10:00 to 11:00.'],
            'the demo mark' => ['DEMO / NOT LEGALLY VALID. Sample records only.'],
            'negated government' => ['E-Notary is not a government service and is not affiliated with any government body.'],
            'negated legal' => ['Certificates here are not legally valid.'],
            'faq style' => ['Is E-Notary a government service? No. It is a demonstration.'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allowed')]
    public function test_honest_notices_are_saved(string $notice): void
    {
        $this->saveNotice($notice)->assertSessionHasNoErrors();

        $this->assertSame($notice === '' ? null : $notice, Setting::get('portal_notice') ?: null);
    }

    public function test_the_service_catalogue_refuses_claims_in_published_text(): void
    {
        $admin = $this->admin();

        foreach (['name', 'summary', 'description', 'instructions'] as $field) {
            $this->actingAs($admin)->from(route('admin.services.index'))
                ->put(route('admin.services.update', self::NAME_CHANGE), [$field => 'A legally valid, government-approved affidavit.'])
                ->assertSessionHasErrors($field);
        }

        $this->actingAs($admin)->from(route('admin.services.index'))
            ->put(route('admin.services.update', self::NAME_CHANGE), ['description' => 'A sample affidavit for trying the workflow. Not legally valid.'])
            ->assertSessionDoesntHaveErrors('description');
    }

    public function test_the_built_in_catalogue_passes_the_check(): void
    {
        $walk = function ($value, string $path) use (&$walk) {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $walk($item, "{$path}.{$key}");
                }
            } elseif (is_string($value)) {
                $this->assertNull(DemoClaims::find($value), $path);
            }
        };

        $walk(config('service_catalogue'), 'service_catalogue');
    }
}
