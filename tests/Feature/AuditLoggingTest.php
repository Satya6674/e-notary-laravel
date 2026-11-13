<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'Sup3r!Secret#Value';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    private function user(string $role = 'public', array $overrides = []): User
    {
        static $i = 700;
        $i++;

        return User::create(array_merge([
            'name' => 'Person', 'email' => "person{$i}@example.test", 'mobile' => '9000000'.$i,
            'password' => Hash::make(self::SECRET), 'role' => $role, 'email_verified_at' => now(),
            'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ], $overrides));
    }

    private function request(User $owner, array $overrides = []): NotaryRequest
    {
        return NotaryRequest::create(array_merge([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $owner->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending',
        ], $overrides));
    }

    public function test_login_failure_and_logout_are_recorded_without_credentials(): void
    {
        $user = $this->user();

        $this->postJson('/custom-login', ['mobile' => $user->email, 'password' => 'wrong-'.self::SECRET])->assertStatus(401);
        $this->postJson('/custom-login', ['mobile' => 'nobody@example.test', 'password' => 'whatever'])->assertStatus(401);
        $this->postJson('/custom-login', ['mobile' => $user->email, 'password' => self::SECRET])->assertOk();
        $this->post('/logout')->assertRedirect();

        // A failed attempt proves nothing about who tried, so it has no actor.
        $failed = AuditLog::where('action', 'auth.login_failed')->get();
        $this->assertCount(2, $failed);
        $this->assertNull($failed[0]->user_id);
        $this->assertSame($user->id, $failed[0]->model_id);
        $this->assertNull($failed[1]->model_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
        $this->assertStringNotContainsString(self::SECRET, AuditLog::all()->toJson());
    }

    public function test_upload_download_and_delete_are_recorded(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        $owner = $this->user();

        $this->actingAs($owner)->post('/user/upload', [
            'title' => 'Doc', 'document_type' => 'will', 'description' => 'x',
            'file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertRedirect();

        $doc = Document::firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.uploaded', 'user_id' => $owner->id, 'model_type' => 'Document', 'model_id' => $doc->id]);

        $this->get("/user/documents/{$doc->id}/download")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.downloaded', 'user_id' => $owner->id, 'model_id' => $doc->id]);

        $this->delete("/user/documents/{$doc->id}")->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.deleted', 'user_id' => $owner->id, 'model_id' => $doc->id]);
    }

    public function test_refused_download_of_someone_elses_document_is_not_recorded_as_a_download(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        Storage::disk(Document::PRIVATE_DISK)->put('documents/a.pdf', 'x');
        $doc = Document::create(['request_id' => $this->request($this->user())->id, 'title' => 'A', 'original_file' => 'documents/a.pdf']);

        $this->actingAs($this->user())->get("/user/documents/{$doc->id}/download")->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'document.downloaded']);
    }

    public function test_each_decision_records_its_actor_and_refused_moves_record_nothing(): void
    {
        $owner = $this->user();
        $admin = $this->user('admin');
        $notary = $this->user('notary');
        $request = $this->request($owner);

        $this->actingAs($admin)->post("/admin/requests/{$request->id}/assign", ['notary_id' => $notary->id])->assertRedirect();
        $log = AuditLog::where('action', 'request.assigned')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('pending', $log->meta['previous_status']);
        $this->assertSame($notary->id, $log->meta['notary_id']);

        $this->actingAs($notary)->post("/notary/request/{$request->id}/approve", ['reason' => 'All in order'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'request.approved', 'user_id' => $notary->id, 'model_id' => $request->id]);

        // A repeated post is refused and leaves no second entry.
        $this->actingAs($notary)->post("/notary/request/{$request->id}/approve", ['reason' => 'Again']);
        $this->assertSame(1, AuditLog::where('action', 'request.approved')->count());
    }

    public function test_reassignment_records_both_notaries(): void
    {
        $admin = $this->user('admin');
        [$first, $second] = [$this->user('notary'), $this->user('notary')];
        $request = $this->request($this->user(), ['status' => 'assigned', 'notary_id' => $first->id]);

        $this->actingAs($admin)->post("/admin/requests/{$request->id}/assign", ['notary_id' => $second->id])->assertRedirect();

        $log = AuditLog::where('action', 'request.reassigned')->firstOrFail();
        $this->assertSame(['from_notary_id' => $first->id, 'notary_id' => $second->id], $log->meta);
    }

    public function test_admin_changes_record_field_names_never_values(): void
    {
        $admin = $this->user('admin');
        $target = $this->user();

        $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'mobile' => $target->mobile, 'role' => 'notary',
            'password' => self::SECRET, 'password_confirmation' => self::SECRET,
        ])->assertRedirect();

        $log = AuditLog::where('action', 'admin.user_updated')->firstOrFail();
        $this->assertEqualsCanonicalizing(['role', 'password'], $log->meta['changed']);
        $this->assertSame('public', $log->meta['role_from']);
        $this->assertSame('notary', $log->meta['role_to']);
        $this->assertStringNotContainsString(self::SECRET, $log->toJson());

        $this->post("/admin/notaries/{$target->id}/approve")->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.notary_approved', 'user_id' => $admin->id, 'model_id' => $target->id]);

        $this->delete("/admin/users/{$target->id}", ['reason' => 'No longer needed'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.user_deactivated', 'user_id' => $admin->id, 'model_id' => $target->id]);
    }

    public function test_entries_keep_their_actor_because_the_acting_user_cannot_be_deleted(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        AuditLog::record('user.profile_updated', $user);

        // Migration 000024: the actor foreign key is RESTRICT, no longer SET NULL. Accounts are
        // archived (deleted_at), never removed, so this only refuses a direct database delete.
        try {
            $user->delete();
            $this->fail('A user referenced by audit history was deleted.');
        } catch (\Illuminate\Database\QueryException) {
        }

        $log = AuditLog::where('action', 'user.profile_updated')->firstOrFail();
        $this->assertNotNull($user->fresh());
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->model_id);
    }

    public function test_admin_page_lists_entries_and_offers_no_way_to_change_them(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);
        AuditLog::record('admin.notary_approved', $admin);

        $this->get('/admin/audit-logs')->assertOk()->assertSee('admin.notary_approved')->assertSee('User #'.$admin->id);

        $this->put('/admin/audit-logs/1')->assertStatus(404);
        $this->delete('/admin/audit-logs/1')->assertStatus(404);
    }

    public function test_a_logging_failure_never_blocks_the_action(): void
    {
        $user = $this->user();
        Schema::drop('audit_logs');

        $this->postJson('/custom-login', ['mobile' => $user->email, 'password' => self::SECRET])
            ->assertOk()->assertJson(['success' => true]);
    }
}
