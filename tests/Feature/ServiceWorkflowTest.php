<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\ServiceDraft;
use App\Models\User;
use App\Services\ServiceApplication;
use App\Services\ServiceCatalogue;
use App\Support\SiteLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const NAME_CHANGE = 'name-change-affidavit';

    private User $citizen;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->citizen = $this->user('public');
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null]);
    }

    private function pdf(string $name = 'sample.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }

    public function test_a_double_submit_cannot_make_two_applications_share_a_file(): void
    {
        $draft = $this->completeDraft();
        $stale = ServiceDraft::findOrFail($draft->id);
        $service = app(ServiceCatalogue::class)->find($draft->service_slug);

        $first = app(ServiceApplication::class)->submit($this->citizen, $service, $draft);
        $second = app(ServiceApplication::class)->submit($this->citizen, $service, $stale);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, NotaryRequest::count());
        $this->assertSame(Document::count(), Document::distinct()->count('original_file'));
    }

    public function test_a_required_file_that_vanished_stops_the_submission(): void
    {
        $draft = $this->completeDraft();
        $service = app(ServiceCatalogue::class)->find($draft->service_slug);
        $file = $draft->file(collect($service['documents'])->firstWhere('required', true)['key']);
        Storage::disk('local')->delete($file['path']);

        try {
            app(ServiceApplication::class)->submit($this->citizen, $service, $draft);
            $this->fail('An application must not be created without its required file.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, NotaryRequest::count());
    }

    public function test_submission_records_what_was_required_so_a_later_edit_cannot_change_it(): void
    {
        $draft = $this->completeDraft();
        $service = app(ServiceCatalogue::class)->find($draft->service_slug);
        $key = collect($service['documents'])->firstWhere('required', true)['key'];

        $this->actingAs($this->citizen)->post(route('user.services.submit', $draft->service_slug), ['declaration' => '1'])->assertRedirect();

        $application = NotaryRequest::firstOrFail();
        $this->assertContains($key, $application->service_details['required_documents']);

        // The catalogue no longer asks for it. This application still does.
        $edited = $service;
        $edited['documents'] = collect($service['documents'])->map(fn ($document) => ['required' => false] + $document)->all();
        $this->mock(ServiceCatalogue::class, fn ($mock) => $mock->shouldReceive('find')->andReturn($edited));

        $this->assertContains($key, $application->fresh()->requiredDocumentKeys());
        $this->assertTrue($application->documents()->where('requirement_key', $key)->firstOrFail()->isRequiredByService());
    }

    public function test_a_fault_after_commit_leaves_the_submitted_files_in_place(): void
    {
        $draft = $this->completeDraft();
        $service = app(ServiceCatalogue::class)->find($draft->service_slug);

        AuditLog::created(function (AuditLog $entry) {
            if ($entry->action === 'application.submitted') {
                DB::afterCommit(fn () => throw new \RuntimeException('Queue down'));
            }
        });

        try {
            app(ServiceApplication::class)->submit($this->citizen, $service, $draft);
        } catch (\RuntimeException) {
        }

        $this->assertGreaterThan(0, Document::count());

        foreach (Document::all() as $document) {
            $this->assertTrue($document->fileExists());
        }
    }

    public function test_a_failed_file_move_leaves_no_application_and_keeps_the_draft(): void
    {
        $draft = $this->completeDraft();
        $service = app(ServiceCatalogue::class)->find($draft->service_slug);
        $file = collect($draft->documents ?? $draft->files ?? [])->first() ?? $draft->file(collect($service['documents'])->firstWhere('required', true)['key']);

        // A folder already at the target makes the move fail.
        Storage::disk('local')->put('documents/'.basename($file['path']).'/blocker', 'x');

        try {
            app(ServiceApplication::class)->submit($this->citizen, $service, $draft);
            $this->fail('A failed move must not succeed quietly.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, NotaryRequest::count());
        $this->assertNotNull(ServiceDraft::find($draft->id));
        $this->assertTrue(Storage::disk('local')->exists($file['path']));
    }

    private function details(): array
    {
        return ['purpose' => 'Sample purpose', 'details' => ['current_name' => 'Asha', 'proposed_name' => 'Asha Rao', 'change_reason' => 'Sample reason']];
    }

    private function savedDraft(array $files = []): ServiceDraft
    {
        $this->actingAs($this->citizen)->post(route('user.services.save', self::NAME_CHANGE), $this->details() + ['files' => $files])
            ->assertRedirect(route('user.services.review', self::NAME_CHANGE));

        return ServiceDraft::where('user_id', $this->citizen->id)->firstOrFail();
    }

    private function completeDraft(): ServiceDraft
    {
        return $this->savedDraft(['draft_affidavit' => $this->pdf(), 'current_name_proof' => $this->pdf('proof.pdf')]);
    }

    public function test_every_surface_uses_the_same_catalogue(): void
    {
        $catalogue = app(ServiceCatalogue::class);
        $name = 'Renamed Rent Service';
        $rent = $catalogue->find('rent-agreement');

        $catalogue->update('rent-agreement', ['name' => $name] + $rent);
        app()->forgetScopedInstances();

        $this->get('/')->assertOk()->assertSee($name);
        $this->get('/services')->assertOk()->assertSee($name);
        $this->get('/services/rent-agreement')->assertOk()->assertSee($name);
        $this->actingAs($this->citizen)->get(route('user.services.index'))->assertOk()->assertSee($name);
    }

    public function test_closed_services_show_status_but_accept_no_application(): void
    {
        $this->get('/services/lease-agreement')->assertOk()->assertSee('Coming Soon')->assertDontSee('Continue to Application');

        $this->actingAs($this->citizen)->get(route('user.services.apply', 'lease-agreement'))->assertNotFound();
        $this->actingAs($this->citizen)->post(route('user.services.save', 'lease-agreement'), $this->details())->assertNotFound();
    }

    public function test_feature_services_cannot_be_applied_for(): void
    {
        $this->actingAs($this->citizen)->get(route('user.services.apply', 'application-tracking'))->assertNotFound();
    }

    public function test_requirements_differ_by_service_and_are_marked_unconfirmed(): void
    {
        $page = $this->actingAs($this->citizen)->get(route('user.services.index', ['service' => self::NAME_CHANGE]));

        $page->assertOk()->assertSee('Document showing the current name')->assertSee('Draft power of attorney')->assertSee('subject to confirmation');

        $catalogue = app(ServiceCatalogue::class);
        $this->assertNotSame(
            array_column($catalogue->find('rent-agreement')['documents'], 'key'),
            array_column($catalogue->find(self::NAME_CHANGE)['documents'], 'key')
        );
    }

    public function test_search_filters_the_service_list(): void
    {
        $this->actingAs($this->citizen)->get(route('user.services.index', ['q' => 'indemnity']))
            ->assertOk()->assertSee('Indemnity Bond')->assertDontSee('Rent Agreement</strong>', false);
    }

    public function test_complete_journey_creates_a_tracked_application(): void
    {
        $admin = $this->user('admin');
        $draft = $this->completeDraft();
        $this->assertCount(2, $draft->files);
        Storage::disk('local')->assertExists($draft->files['draft_affidavit']['path']);

        $this->get(route('user.services.review', self::NAME_CHANGE))->assertOk()->assertSee('Asha Rao')->assertSee('proof.pdf');

        $response = $this->post(route('user.services.submit', self::NAME_CHANGE), ['declaration' => '1']);

        $application = NotaryRequest::where('user_id', $this->citizen->id)->firstOrFail();
        $response->assertRedirect(route('user.requests.show', $application));

        Notification::assertSentTo($admin, \App\Notifications\PortalNotification::class, fn ($n) => $n->body === $application->request_number && ! str_contains(json_encode($n), 'Asha Rao'));

        $this->assertSame(self::NAME_CHANGE, $application->service_slug);
        $this->assertSame('affidavit', $application->document_type);
        $this->assertSame('pending', $application->status);
        $this->assertSame('Name Change Affidavit', $application->service_details['service']);
        $this->assertSame(2, $application->documents()->count());
        $this->assertSame(['current_name_proof', 'draft_affidavit'], $application->documents()->orderBy('requirement_key')->pluck('requirement_key')->all());

        $file = $application->documents()->first()->original_file;
        Storage::disk('local')->assertExists($file);
        $this->assertStringNotContainsString('drafts', $file);
        $this->assertSame([], Storage::disk('local')->files(ServiceDraft::FOLDER.'/'.$this->citizen->id));
        $this->assertDatabaseMissing('service_drafts', ['user_id' => $this->citizen->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.submitted', 'model_id' => $application->id]);

        $this->get(route('user.requests.show', $application))->assertOk()->assertSee('Asha Rao');
    }

    public function test_submission_is_blocked_while_a_mandatory_document_is_missing(): void
    {
        $this->savedDraft(['draft_affidavit' => $this->pdf()]);

        $this->get(route('user.services.review', self::NAME_CHANGE))->assertOk()->assertSee('cannot be submitted yet');

        $this->post(route('user.services.submit', self::NAME_CHANGE), ['declaration' => '1'])
            ->assertRedirect(route('user.services.review', self::NAME_CHANGE));

        $this->assertSame(0, NotaryRequest::count());
    }

    public function test_optional_documents_are_not_needed_and_are_kept_when_given(): void
    {
        $this->savedDraft(['draft_affidavit' => $this->pdf(), 'current_name_proof' => $this->pdf(), 'change_evidence' => $this->pdf()]);

        $this->post(route('user.services.submit', self::NAME_CHANGE), ['declaration' => '1'])->assertRedirect();

        $this->assertSame(3, Document::count());
    }

    public function test_declaration_is_required(): void
    {
        $this->completeDraft();

        $this->post(route('user.services.submit', self::NAME_CHANGE))->assertSessionHasErrors('declaration');

        $this->assertSame(0, NotaryRequest::count());
    }

    public function test_required_text_fields_are_enforced(): void
    {
        $this->actingAs($this->citizen)->post(route('user.services.save', self::NAME_CHANGE), ['purpose' => 'Sample', 'details' => ['current_name' => 'Asha']])
            ->assertSessionHasErrors(['details.proposed_name', 'details.change_reason']);

        $this->assertDatabaseCount('service_drafts', 0);
    }

    public function test_disguised_file_is_rejected_by_its_content(): void
    {
        // A real temporary file: the framework's fake reports a type from the name, not the bytes.
        $path = tempnam(sys_get_temp_dir(), 'fake');
        file_put_contents($path, 'MZ this is a program, not a document');
        $fake = new UploadedFile($path, 'invoice.pdf', null, null, true);

        $this->actingAs($this->citizen)->post(route('user.services.save', self::NAME_CHANGE), $this->details() + ['files' => ['draft_affidavit' => $fake]])
            ->assertSessionHasErrors('files.draft_affidavit');

        $this->assertDatabaseCount('service_drafts', 0);
    }

    public function test_wrong_extension_is_rejected(): void
    {
        $this->actingAs($this->citizen)->post(route('user.services.save', self::NAME_CHANGE), $this->details() + ['files' => ['draft_affidavit' => $this->pdf('sample.exe')]])
            ->assertSessionHasErrors('files.draft_affidavit');
    }

    public function test_oversized_file_is_rejected(): void
    {
        $big = UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf');

        $this->actingAs($this->citizen)->post(route('user.services.save', self::NAME_CHANGE), $this->details() + ['files' => ['draft_affidavit' => $big]])
            ->assertSessionHasErrors('files.draft_affidavit');
    }

    public function test_uploaded_file_name_is_cleaned_and_never_used_for_storage(): void
    {
        $draft = $this->savedDraft(['draft_affidavit' => $this->pdf('..\\..\\evil<script>.pdf')]);
        $file = $draft->files['draft_affidavit'];

        $this->assertStringNotContainsString('<', $file['name']);
        $this->assertStringNotContainsString('..\\', $file['name']);
        $this->assertStringNotContainsString('evil', $file['path']);
        $this->assertStringStartsWith(ServiceDraft::FOLDER.'/'.$this->citizen->id.'/', $file['path']);
    }

    public function test_replacing_and_removing_a_file_deletes_the_old_one(): void
    {
        $old = $this->savedDraft(['draft_affidavit' => $this->pdf()])->files['draft_affidavit']['path'];

        $new = $this->savedDraft(['draft_affidavit' => $this->pdf('new.pdf')])->files['draft_affidavit']['path'];

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($new);

        $this->delete(route('user.services.documents.destroy', [self::NAME_CHANGE, 'draft_affidavit']))->assertRedirect();

        Storage::disk('local')->assertMissing($new);
        $this->assertNull(ServiceDraft::first()->file('draft_affidavit'));
    }

    public function test_moving_to_another_service_discards_the_old_draft_files(): void
    {
        $path = $this->savedDraft(['draft_affidavit' => $this->pdf()])->files['draft_affidavit']['path'];

        $this->post(route('user.services.save', 'declaration'), ['purpose' => 'Sample', 'details' => ['declarant_name' => 'Asha']])->assertRedirect();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame('declaration', ServiceDraft::where('user_id', $this->citizen->id)->value('service_slug'));
    }

    public function test_one_citizen_cannot_reach_another_citizens_draft_or_document(): void
    {
        $this->completeDraft();
        $this->post(route('user.services.submit', self::NAME_CHANGE), ['declaration' => '1']);
        $document = Document::firstOrFail();

        $other = $this->user('public');

        $this->actingAs($other)->get(route('user.documents.download', $document))->assertForbidden();
        $this->actingAs($other)->get(route('documents.preview', $document))->assertForbidden();

        $this->actingAs($this->citizen)->post(route('user.services.save', self::NAME_CHANGE), $this->details());
        $this->actingAs($other)->get(route('user.services.review', self::NAME_CHANGE))->assertRedirect(route('user.services.apply', self::NAME_CHANGE));
        $this->actingAs($other)->delete(route('user.services.documents.destroy', [self::NAME_CHANGE, 'draft_affidavit']))->assertNotFound();
    }

    public function test_requirement_added_while_a_draft_waits_stops_submission(): void
    {
        $this->completeDraft();

        $catalogue = app(ServiceCatalogue::class);
        $service = $catalogue->find(self::NAME_CHANGE);
        $service['documents'][] = ['key' => 'new_item', 'name' => 'New item', 'required' => true, 'formats' => ['pdf'], 'max_kb' => 1024];
        $catalogue->update(self::NAME_CHANGE, $service);
        app()->forgetScopedInstances();

        $this->post(route('user.services.submit', self::NAME_CHANGE), ['declaration' => '1'])->assertSessionHas('error');

        $this->assertSame(0, NotaryRequest::count());
    }

    public function test_stale_draft_command_removes_files_and_keeps_recent_ones(): void
    {
        $path = $this->completeDraft()->files['draft_affidavit']['path'];

        $this->artisan('enotary:clear-stale-drafts')->assertSuccessful();
        Storage::disk('local')->assertExists($path);

        ServiceDraft::query()->update(['updated_at' => now()->subDays(20)]);
        $this->artisan('enotary:clear-stale-drafts')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('service_drafts', 0);
    }

    public function test_notary_and_admin_cannot_use_the_citizen_workflow(): void
    {
        $this->actingAs($this->user('notary'))->get(route('user.services.index'))->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('user.services.index'))->assertForbidden();
        $this->post('/logout');
        $this->get(route('user.services.index'))->assertRedirect(route('login'));
    }

    public function test_staff_see_the_service_details(): void
    {
        $this->completeDraft();
        $this->post(route('user.services.submit', self::NAME_CHANGE), ['declaration' => '1']);
        $application = NotaryRequest::firstOrFail();

        $this->actingAs($this->user('admin'))->get(route('admin.requests.show', $application))->assertOk()->assertSee('Asha Rao')->assertSee('Name Change Affidavit');
    }

    public function test_only_admins_can_manage_services(): void
    {
        $url = route('admin.services.update', 'rent-agreement');

        $this->actingAs($this->citizen)->get(route('admin.services.index'))->assertForbidden();
        $this->actingAs($this->citizen)->put($url, ['name' => 'Taken'])->assertForbidden();
        $this->actingAs($this->user('notary'))->put($url, ['name' => 'Taken'])->assertForbidden();
    }

    private function adminPayload(array $changes = []): array
    {
        $service = app(ServiceCatalogue::class)->find('rent-agreement');

        return $changes + [
            'name' => $service['name'], 'category' => $service['category'], 'summary' => $service['summary'],
            'description' => $service['description'], 'icon' => $service['icon'], 'status' => $service['status'],
            'order' => $service['order'], 'instructions' => $service['instructions'], 'visible' => '1',
            'documents' => array_map(fn ($d) => ['key' => $d['key'], 'name' => $d['name'], 'required' => $d['required'] ? '1' : null, 'formats' => $d['formats'], 'max_mb' => 5], $service['documents']),
            'fields' => array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'required' => $f['required'] ? '1' : null, 'max' => $f['max']], $service['fields']),
        ];
    }

    public function test_admin_changes_reach_validation_and_are_audited(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->put(route('admin.services.update', 'rent-agreement'), $this->adminPayload(['summary' => 'A new summary']))
            ->assertRedirect(route('admin.services.index'));

        app()->forgetScopedInstances();
        $service = app(ServiceCatalogue::class)->find('rent-agreement');

        $this->assertSame('A new summary', $service['summary']);
        $this->assertSame(5120, $service['documents'][0]['max_kb']);

        $entry = AuditLog::where('action', 'admin.service_updated')->firstOrFail();
        $this->assertSame('rent-agreement', $entry->meta['service']);
        $this->assertContains('summary', $entry->meta['changed']);

        $this->post(route('admin.services.reset', 'rent-agreement'))->assertRedirect();
        app()->forgetScopedInstances();
        $this->assertNotSame('A new summary', app(ServiceCatalogue::class)->find('rent-agreement')['summary']);
    }

    public function test_admin_input_is_limited_to_safe_values(): void
    {
        $admin = $this->user('admin');
        $url = route('admin.services.update', 'rent-agreement');

        $this->actingAs($admin)->put($url, $this->adminPayload(['icon' => 'fa-x" onload="alert(1)']))->assertSessionHasErrors('icon');

        $bad = $this->adminPayload();
        $bad['documents'][0]['formats'] = ['exe'];
        $this->put($url, $bad)->assertSessionHasErrors('documents.0.formats.0');

        $big = $this->adminPayload();
        $big['documents'][0]['max_mb'] = 500;
        $this->put($url, $big)->assertSessionHasErrors('documents.0.max_mb');

        $none = $this->adminPayload();
        foreach ($none['documents'] as &$row) {
            $row['required'] = null;
        }
        $this->put($url, $none)->assertSessionHasErrors('documents');
    }

    public function test_hidden_service_disappears_everywhere(): void
    {
        $catalogue = app(ServiceCatalogue::class);
        $catalogue->update('rent-agreement', ['visible' => false] + $catalogue->find('rent-agreement'));
        app()->forgetScopedInstances();

        $this->get('/services/rent-agreement')->assertNotFound();
        $this->get('/services')->assertDontSee('Rent Agreement</h3>', false);
        $this->actingAs($this->citizen)->get(route('user.services.apply', 'rent-agreement'))->assertNotFound();
    }

    public function test_footer_shows_only_working_links_and_no_invented_contacts(): void
    {
        foreach (SiteLinks::footer() as $column) {
            foreach (array_merge($column['links'], $column['categories']) as $link) {
                $this->assertStringNotContainsString('#"', $link['url'].'"');
                $this->assertNotSame('', $link['url']);
            }
        }

        $flat = json_encode(SiteLinks::footer());
        $this->assertStringNotContainsString('Pricing', $flat);
        $this->assertStringNotContainsString('Careers', $flat);
        $this->assertStringNotContainsString('Lease Agreement', $flat);

        $this->assertNull(config('site_links.phone'));
        $this->assertSame([], config('site_links.social'));
    }

    public function test_footer_services_are_four_categories_leading_to_real_filtered_pages(): void
    {
        $column = collect(SiteLinks::footer())->firstWhere('title', 'Our Services');
        $catalogue = app(ServiceCatalogue::class);

        $this->assertSame(
            ['Affidavit Services', 'Agreements & Documents', 'Digital Services', 'Notary Assistance'],
            array_column($column['categories'], 'name')
        );
        $this->assertSame([], $column['links']);
        $this->assertSame(route('services.index'), $column['cta']['url']);

        foreach (['affidavits', 'agreements', 'digital', 'assistance'] as $index => $key) {
            $this->assertSame(route('services.index', ['category' => $key]), $column['categories'][$index]['url']);

            $page = $this->get($column['categories'][$index]['url'])->assertOk();

            foreach ($catalogue->inCategory($key) as $service) {
                $page->assertSee($service['name']);
            }
        }

        // Shorter footer only: the catalogue and portal keep every service.
        $this->assertCount(28, config('service_catalogue.services'));
        $this->get('/services')->assertOk()->assertSee('Marriage-related Affidavit')->assertSee('Lost Document Declaration');
        $this->actingAs($this->citizen)->get(route('user.services.index'))->assertOk()->assertSee('Marriage-related Affidavit');
    }

    /** A crafted query string once gave a 500 ("Illegal offset type"), audit B-5. */
    public function test_a_malformed_or_unknown_category_shows_every_service_instead_of_failing(): void
    {
        $all = app(ServiceCatalogue::class)->inCategory(null)->count();

        foreach (['/services?category[]=x', '/services?category[a]=b', '/services?category=no-such-category', '/services?category='] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertNull($response->viewData('category'), $url);
            $this->assertCount($all, $response->viewData('services'), $url);
        }

        $key = array_key_first(app(ServiceCatalogue::class)->categories());
        $this->assertSame($key, $this->get('/services?category='.$key)->assertOk()->viewData('category'));
    }

    public function test_footer_drops_a_category_with_no_open_services(): void
    {
        $catalogue = app(ServiceCatalogue::class);

        foreach ($catalogue->inCategory('assistance') as $service) {
            $catalogue->update($service['slug'], ['status' => 'unavailable'] + $service);
        }
        app()->forgetScopedInstances();

        $column = collect(SiteLinks::footer())->firstWhere('title', 'Our Services');

        $this->assertNotContains('Notary Assistance', array_column($column['categories'], 'name'));
        $this->assertCount(3, $column['categories']);
    }

    public function test_every_link_on_the_public_pages_resolves(): void
    {
        foreach (['/', '/services', '/services/rent-agreement', '/about', '/disclaimer'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            $this->assertStringNotContainsString('href="#"', $html, $page);

            preg_match_all('/href="(http[^"#]*)/', $html, $matches);

            foreach (array_unique($matches[1]) as $url) {
                if (! str_ends_with($url, '.css')) {
                    $this->assertNotSame(404, $this->get($url)->getStatusCode(), $url);
                }
            }
        }
    }

    /** The header only: the page below it has its own calls to action. */
    private function header(): string
    {
        $html = $this->get('/')->assertOk()->getContent();
        $start = strpos($html, 'en-site-top');

        return substr($html, $start, strpos($html, '</nav>') - $start);
    }

    public function test_guests_see_login_and_create_account_once_in_the_navbar(): void
    {
        $header = $this->header();
        $top = substr($header, 0, strpos($header, 'en-site-nav'));

        $this->assertSame(1, substr_count($header, 'href="'.route('login').'"'));
        $this->assertSame(1, substr_count($header, 'href="'.route('register').'"'));
        $this->assertStringNotContainsString(route('login'), $top);
        $this->assertStringNotContainsString(route('register'), $top);
        $this->assertStringContainsString(route('verify.index'), $top);
        $this->assertStringContainsString('name="locale"', $top);
    }

    public function test_signed_in_users_see_their_dashboard_instead_of_login_links(): void
    {
        foreach ([$this->citizen, $this->user('notary'), $this->user('admin')] as $user) {
            $this->actingAs($user);
            $header = $this->header();

            $this->assertStringNotContainsString(route('login'), $header);
            $this->assertStringNotContainsString(route('register'), $header);
            $this->assertSame(1, substr_count($header, 'href="'.$user->landingUrl().'"'));
        }
    }

    public function test_home_tracking_card_is_labelled_and_holds_no_record_data(): void
    {
        NotaryRequest::create(['user_id' => $this->citizen->id, 'request_number' => 'NR-PRIVATE-1', 'document_type' => 'affidavit', 'purpose' => 'Private purpose']);

        $this->get('/')->assertOk()->assertSee('Illustrative example')->assertDontSee('NR-PRIVATE-1')->assertDontSee($this->citizen->name);
    }

    public function test_before_the_new_migrations_public_pages_work_and_portals_stay_closed(): void
    {
        \Illuminate\Support\Facades\Schema::drop('service_drafts');
        \App\Support\Platform::forgetSchemaState();

        $this->get('/')->assertOk()->assertSee('General Affidavit');
        $this->get('/services')->assertOk();
        $this->get('/services/rent-agreement')->assertOk();
        $this->actingAs($this->citizen)->get(route('user.services.index'))->assertStatus(503);

        \App\Support\Platform::forgetSchemaState();
    }
}
