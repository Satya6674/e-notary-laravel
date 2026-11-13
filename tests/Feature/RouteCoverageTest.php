<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Walks every GET route in the application as each role. No page may fail with a
 * server error, and every protected page must refuse the people it should refuse.
 */
class RouteCoverageTest extends TestCase
{
    use RefreshDatabase;

    private array $people = [];

    private array $params = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::PRIVATE_DISK);
        Storage::disk(Document::PRIVATE_DISK)->put('documents/a.pdf', "%PDF-1.4\n%%EOF\n");

        $person = fn (string $role, string $key) => User::create([
            'name' => "Person {$key}", 'email' => "{$key}@example.test", 'mobile' => '94'.str_pad((string) (crc32($key) % 100000000), 8, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'), 'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);

        $this->people = ['public' => $person('public', 'citizen'), 'notary' => $person('notary', 'notary'), 'admin' => $person('admin', 'admin')];

        $application = NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $this->people['public']->id, 'notary_id' => $this->people['notary']->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'completed',
        ]);
        $document = Document::create(['request_id' => $application->id, 'title' => 'T', 'original_file' => 'documents/a.pdf', 'verification_code' => 'ABCDEFGHJKLMNPQR', 'certified_at' => now()]);
        $ticket = SupportTicket::create(['ticket_number' => 'ST-1', 'user_id' => $this->people['public']->id, 'subject' => 'S']);
        $message = $ticket->messages()->create(['user_id' => $this->people['public']->id, 'body' => 'B']);
        Storage::disk(Document::PRIVATE_DISK)->put('support/a.pdf', "%PDF-1.4\n%%EOF\n");
        $attachment = SupportTicketAttachment::create([
            'ticket_id' => $ticket->id, 'message_id' => $message->id, 'user_id' => $this->people['public']->id, 'original_name' => 'a.pdf', 'path' => 'support/a.pdf',
            'mime_type' => 'application/pdf', 'size' => 15, 'sha256' => hash('sha256', "%PDF-1.4\n%%EOF\n"),
        ]);
        Appointment::create(['request_id' => $application->id, 'user_id' => $this->people['public']->id, 'notary_id' => $this->people['notary']->id, 'scheduled_at' => now()->addDay()]);

        $archived = $person('public', 'archived');
        $deletion = AccountDeletion::create(['reference' => 'DEL-1', 'user_id' => $archived->id, 'deleted_by' => $this->people['admin']->id, 'name' => 'Person', 'email' => 'archived@example.test', 'role' => 'public', 'reason' => 'Route coverage', 'deleted_at' => now(), 'identity_released_at' => now()]);
        $archived->forceFill(['email' => 'held@deleted.invalid', 'mobile' => null, 'deleted_at' => now(), 'deactivated_at' => now()])->save();
        $recovery = AccountRecoveryRequest::create(['reference' => 'REC-1', 'account_deletion_id' => $deletion->id, 'old_user_id' => $archived->id, 'requester_id' => $this->people['public']->id, 'applicant_name' => 'Person', 'verified_email' => 'archived@example.test', 'match_type' => 'email', 'email_proof' => 'emailed_code', 'mobile_proof' => 'none', 'environment' => 'local_development', 'open_key' => $deletion->id]);

        $this->params = [
            'recovery' => $recovery->id, 'document' => $document->id, 'notaryRequest' => $application->id, 'request' => $application->id, 'id' => $application->id,
            'user' => $this->people['public']->id, 'ticket' => $ticket->id, 'code' => 'ABCDEFGHJKLMNPQR', 'appointment' => 1, 'slug' => 'rent-agreement', 'key' => 'draft_agreement', 'token' => str_repeat('a', 64), 'hash' => 'hash', 'replacement' => 1, 'attachment' => $attachment->id,
        ];
    }

    /** @return array<string, LaravelRoute> */
    private function getRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $uses = $route->action['uses'] ?? '';
            $ours = isset($route->defaults['view']) || (is_string($uses) && str_starts_with($uses, 'App\\'));

            if ($ours && in_array('GET', $route->methods(), true)) {
                $routes[$route->uri()] = $route;
            }
        }

        return $routes;
    }

    private function url(LaravelRoute $route): string
    {
        return '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $this->params[$m[1]], $route->uri()), '/');
    }

    public function test_the_route_list_is_what_this_test_assumes(): void
    {
        $this->assertGreaterThan(45, count($this->getRoutes()));
    }

    public function test_no_get_route_fails_for_any_role_or_for_a_guest(): void
    {
        $failures = [];

        foreach ([...array_keys($this->people), 'guest'] as $who) {
            foreach ($this->getRoutes() as $route) {
                if ($who !== 'guest') {
                    $this->actingAs($this->people[$who]);
                } else {
                    auth()->guard('web')->logout();
                }

                $status = $this->get($this->url($route))->getStatusCode();

                if ($status >= 500) {
                    $failures[] = "{$who} {$route->uri()} {$status}";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_every_authenticated_route_turns_a_guest_away(): void
    {
        $leaks = [];

        foreach ($this->getRoutes() as $route) {
            if (in_array('auth', $route->gatherMiddleware(), true) && $this->get($this->url($route))->getStatusCode() === 200) {
                $leaks[] = $route->uri();
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_each_portal_area_turns_away_the_other_roles(): void
    {
        $areas = ['admin' => ['admin/'], 'notary' => ['notary/'], 'public' => ['dashboard', 'user/']];
        $leaks = [];

        foreach ($areas as $owner => $prefixes) {
            foreach ($this->people as $role => $person) {
                if ($role === $owner) {
                    continue;
                }

                foreach ($this->getRoutes() as $route) {
                    if (collect($prefixes)->contains(fn ($p) => str_starts_with($route->uri(), $p))) {
                        $status = $this->actingAs($person)->get($this->url($route))->getStatusCode();

                        if ($status === 200) {
                            $leaks[] = "{$role} reached {$route->uri()}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_list_pages_do_not_run_one_query_per_row(): void
    {
        $admin = $this->people['admin'];

        $count = function (string $url) use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get($url)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $pages = ['/admin/requests', '/admin/documents', '/admin/dashboard', '/admin/audit-logs', '/reports', '/appointments', '/support'];
        $before = array_map($count, $pages);

        foreach (range(1, 12) as $i) {
            $citizen = User::create(['name' => "Extra {$i}", 'email' => "extra{$i}@example.test", 'mobile' => '95000001'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'password' => 'x', 'role' => 'public']);
            $application = NotaryRequest::create(['request_number' => "NR-{$i}", 'user_id' => $citizen->id, 'notary_id' => $this->people['notary']->id, 'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending']);
            Document::create(['request_id' => $application->id, 'title' => "T{$i}", 'original_file' => 'documents/a.pdf']);
            Appointment::create(['request_id' => $application->id, 'user_id' => $citizen->id, 'scheduled_at' => now()->addDay()]);
            SupportTicket::create(['ticket_number' => "ST-X{$i}", 'user_id' => $citizen->id, 'subject' => 'S']);
        }

        foreach ($pages as $i => $page) {
            $this->assertLessThanOrEqual($before[$i] + 2, $count($page), "{$page} grew with the number of rows");
        }
    }
}
