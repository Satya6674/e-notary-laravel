<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * The owner-approved policy: a notary decides only assigned applications, an
 * admin decides or overrides any application, and every decision has a reason.
 */
class DecisionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    private function user(string $role = 'public', array $extra = []): User
    {
        static $i = 300;
        $i++;

        return User::create(array_merge([
            'name' => "Person {$i}", 'email' => "p{$i}@example.test", 'mobile' => '9100000'.$i,
            'password' => Hash::make('password'), 'role' => $role, 'email_verified_at' => now(),
            'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ], $extra));
    }

    private function application(User $owner, array $extra = []): NotaryRequest
    {
        return NotaryRequest::create(array_merge([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $owner->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending',
        ], $extra));
    }

    /**
     * Audit B-3: the permission was checked on the page's copy of the application, before the
     * row lock. An Admin who reassigned it in that gap was overruled by the old Notary.
     */
    public function test_a_notary_cannot_decide_an_application_reassigned_after_it_was_loaded(): void
    {
        $old = $this->user('notary');
        $new = $this->user('notary');
        $application = $this->application($this->user(), ['status' => 'assigned', 'notary_id' => $old->id]);
        $loaded = NotaryRequest::findOrFail($application->id);

        NotaryRequest::whereKey($application->id)->update(['notary_id' => $new->id]); // the Admin's reassignment commits

        $decisions = app(\App\Services\ApplicationDecisionService::class);
        $this->assertFalse($decisions->decide($old, $loaded, 'approve', 'Reviewed carefully'));
        $this->assertSame('This application is no longer assigned to you. Nothing was changed.', $decisions->refusal);

        $fresh = $application->fresh();
        $this->assertSame('assigned', $fresh->status);
        $this->assertSame($new->id, (int) $fresh->notary_id);
        $this->assertNull($fresh->decided_by);
        Notification::assertNothingSent();

        // The Notary it now belongs to can still decide it.
        $this->assertTrue($decisions->decide($new, $fresh, 'approve', 'Reviewed carefully'));
        $this->assertSame('approved', $application->fresh()->status);
    }

    /** The same gap, when the Admin withdraws the Notary's approval or deactivates them. */
    public function test_a_notary_whose_approval_ends_after_loading_cannot_decide(): void
    {
        foreach (['notary_approved_at' => null, 'deactivated_at' => now()] as $column => $value) {
            $notary = $this->user('notary');
            $application = $this->application($this->user(), ['status' => 'assigned', 'notary_id' => $notary->id]);
            $loaded = NotaryRequest::findOrFail($application->id);

            User::whereKey($notary->id)->update([$column => $value]);

            $decisions = app(\App\Services\ApplicationDecisionService::class);
            $this->assertFalse($decisions->decide($notary, $loaded, 'reject', 'Reviewed carefully'), $column);
            $this->assertSame('assigned', $application->fresh()->status, $column);
        }
    }

    public function test_assigned_notary_can_approve_reject_and_request_correction(): void
    {
        $notary = $this->user('notary');

        foreach (['approve' => 'approved', 'reject' => 'rejected', 'correct' => 'correction_requested'] as $decision => $status) {
            $application = $this->application($this->user(), ['status' => 'assigned', 'notary_id' => $notary->id]);

            $this->actingAs($notary)->post("/notary/request/{$application->id}/{$decision}", ['reason' => 'Reviewed carefully'])
                ->assertRedirect(route('notary.requests'));

            $fresh = $application->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertSame('Reviewed carefully', $fresh->decision_remarks);
            $this->assertSame($notary->id, (int) $fresh->decided_by);
        }
    }

    public function test_notary_must_give_remarks(): void
    {
        $notary = $this->user('notary');
        $application = $this->application($this->user(), ['status' => 'assigned', 'notary_id' => $notary->id]);

        $this->actingAs($notary)->post("/notary/request/{$application->id}/approve", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($notary)->post("/notary/request/{$application->id}/approve")->assertSessionHasErrors('reason');

        $this->assertSame('assigned', $application->fresh()->status);
    }

    public function test_notary_cannot_see_or_decide_an_unassigned_or_foreign_application(): void
    {
        $notary = $this->user('notary');
        $unassigned = $this->application($this->user());
        $foreign = $this->application($this->user(), ['status' => 'assigned', 'notary_id' => $this->user('notary')->id]);

        foreach ([$unassigned, $foreign] as $application) {
            $before = $application->status;

            $this->actingAs($notary)->get("/notary/request/{$application->id}")->assertNotFound();

            foreach (['approve', 'reject', 'correct', 'complete'] as $decision) {
                $this->actingAs($notary)->post("/notary/request/{$application->id}/{$decision}", ['reason' => 'No access'])->assertNotFound();
            }

            $this->assertSame($before, $application->fresh()->status);
        }

        $this->assertSame(0, AuditLog::where('action', 'like', 'request.%')->count());
    }

    public function test_unapproved_notary_is_refused_even_when_assigned(): void
    {
        $notary = $this->user('notary', ['notary_approved_at' => null]);
        $application = $this->application($this->user(), ['status' => 'assigned', 'notary_id' => $notary->id]);

        $this->actingAs($notary)->post("/notary/request/{$application->id}/approve", ['reason' => 'Attempt'])->assertForbidden();
    }

    public function test_admin_can_decide_any_application_and_a_first_decision_is_not_an_override(): void
    {
        $admin = $this->user('admin');
        $application = $this->application($this->user());

        $this->actingAs($admin)->post("/admin/requests/{$application->id}/approve", ['reason' => 'Admin review'])->assertRedirect();

        $this->assertSame('approved', $application->fresh()->status);

        $meta = AuditLog::where('action', 'request.approved')->firstOrFail()->meta;
        $this->assertFalse($meta['override']);
    }

    public function test_admin_can_override_a_notary_decision_and_it_is_recorded_as_one(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary');
        $application = $this->application($this->user(), ['status' => 'assigned', 'notary_id' => $notary->id]);

        $this->actingAs($notary)->post("/notary/request/{$application->id}/reject", ['reason' => 'Unclear scan']);
        $this->actingAs($admin)->post("/admin/requests/{$application->id}/approve", ['reason' => 'Scan is readable'])->assertRedirect();

        $fresh = $application->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->rejection_reason);

        $log = AuditLog::where('action', 'request.approved')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($admin->id, $log->meta['actor_id']);
        $this->assertSame('admin', $log->meta['actor_role']);
        $this->assertSame($application->id, $log->meta['application_id']);
        $this->assertSame('rejected', $log->meta['previous_status']);
        $this->assertSame('approved', $log->meta['new_status']);
        $this->assertSame('Scan is readable', $log->meta['reason']);
        $this->assertTrue($log->meta['override']);
        $this->assertNotNull($log->created_at);
    }

    public function test_admin_reason_is_mandatory_for_a_decision_and_an_override(): void
    {
        $admin = $this->user('admin');
        $decided = $this->application($this->user(), ['status' => 'rejected']);

        foreach (['approve', 'reject', 'correct', 'complete'] as $decision) {
            $this->actingAs($admin)->post("/admin/requests/{$decided->id}/{$decision}")->assertSessionHasErrors('reason');
            $this->actingAs($admin)->post("/admin/requests/{$decided->id}/{$decision}", ['reason' => '   '])->assertSessionHasErrors('reason');
        }

        $this->assertSame('rejected', $decided->fresh()->status);
    }

    public function test_overriding_a_completed_application_withdraws_its_certificate(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary');
        $application = $this->application($this->user(), ['status' => 'approved', 'notary_id' => $notary->id]);
        Storage::fake('local');
        Storage::disk('local')->put('documents/x.pdf', 'content');
        $document = Document::create(['request_id' => $application->id, 'title' => 'Doc', 'original_file' => 'documents/x.pdf', 'file_hash' => hash('sha256', 'content')]);

        $this->actingAs($notary)->post("/notary/request/{$application->id}/complete", ['reason' => 'Review finished']);

        $code = $document->fresh()->verification_code;
        $this->assertNotNull($code);
        $this->get("/verify/{$code}")->assertOk()->assertSee('This record exists and is current.');

        $this->actingAs($admin)->post("/admin/requests/{$application->id}/reject", ['reason' => 'Issued in error']);

        $this->get("/verify/{$code}")->assertOk()->assertSee('This record has been revoked.');
    }

    public function test_audit_history_cannot_be_changed_or_deleted_and_outlives_the_actor(): void
    {
        $admin = $this->user('admin');
        $application = $this->application($this->user());

        $this->actingAs($admin)->post("/admin/requests/{$application->id}/reject", ['reason' => 'Original reason']);
        $log = AuditLog::where('action', 'request.rejected')->firstOrFail();

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('An audit entry was changed.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('An audit entry was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame('request.rejected', $log->fresh()->action);

        // An administrator's row cannot be removed from the database at all (migration 000023),
        // so the history keeps its actor, by foreign key and by the recorded identity.
        $this->user('admin');
        try {
            $admin->delete();
            $this->fail('A former administrator row was deleted.');
        } catch (\Illuminate\Database\QueryException) {
        }
        $this->assertNotNull($admin->fresh());
        $this->assertSame($admin->id, $log->fresh()->user_id);
        $this->assertSame($admin->id, $log->fresh()->meta['actor_id']);
        $this->assertSame('Original reason', $log->fresh()->meta['reason']);
    }

    public function test_history_lists_each_change_in_order(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary');
        $application = $this->application($this->user());

        $this->actingAs($admin)->post("/admin/requests/{$application->id}/assign", ['notary_id' => $notary->id]);
        $this->actingAs($notary)->post("/notary/request/{$application->id}/correct", ['reason' => 'Page missing']);

        $this->assertSame(['request.assigned', 'request.correction_requested'], $application->history()->pluck('action')->all());
    }

    public function test_direct_urls_are_refused_for_every_unauthorized_role(): void
    {
        $citizen = $this->user();
        $other = $this->user();
        $notary = $this->user('notary');
        $application = $this->application($citizen, ['status' => 'assigned', 'notary_id' => $notary->id]);

        $adminUrls = ['approve', 'reject', 'correct', 'complete'];

        // Guests are sent to the login page, and JSON clients get 401.
        foreach ($adminUrls as $decision) {
            $this->post("/admin/requests/{$application->id}/{$decision}", ['reason' => 'Guest'])->assertRedirect(route('login'));
            $this->postJson("/notary/request/{$application->id}/{$decision}", ['reason' => 'Guest'])->assertUnauthorized();
        }

        // Citizens and notaries cannot reach admin decisions; citizens cannot reach notary decisions.
        foreach ($adminUrls as $decision) {
            $this->actingAs($citizen)->post("/admin/requests/{$application->id}/{$decision}", ['reason' => 'Citizen'])->assertForbidden();
            $this->actingAs($notary)->post("/admin/requests/{$application->id}/{$decision}", ['reason' => 'Notary'])->assertForbidden();
            $this->actingAs($citizen)->post("/notary/request/{$application->id}/{$decision}", ['reason' => 'Citizen'])->assertForbidden();
        }

        // Another citizen cannot view it, by page or by certificate.
        $this->actingAs($other)->get("/user/requests/{$application->id}")->assertForbidden();
        $application->update(['status' => 'completed']);
        $this->actingAs($other)->get("/applications/{$application->id}/certificate")->assertForbidden();

        $this->assertSame('completed', $application->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'like', 'request.%')->count());
    }

    public function test_a_notary_cannot_be_assigned_their_own_application(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary');
        $application = $this->application($notary);

        $this->actingAs($admin)->post("/admin/requests/{$application->id}/assign", ['notary_id' => $notary->id])
            ->assertSessionHasErrors('notary_id');

        $this->assertNull($application->fresh()->notary_id);
    }

    public function test_citizen_correction_returns_the_application_to_the_same_notary(): void
    {
        $citizen = $this->user();
        $notary = $this->user('notary');
        $application = $this->application($citizen, ['status' => 'correction_requested', 'notary_id' => $notary->id, 'decision_remarks' => 'Page missing']);

        $this->actingAs($this->user())->post("/user/requests/{$application->id}/resubmit", ['note' => 'Added page'])->assertForbidden();
        $this->actingAs($citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Added page'])->assertRedirect();

        $this->assertSame('assigned', $application->fresh()->status);
        $this->actingAs($citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Again'])->assertForbidden();
    }
}
