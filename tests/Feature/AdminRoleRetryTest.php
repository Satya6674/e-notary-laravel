<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminRoleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use PDOException;
use Tests\TestCase;

/**
 * A deadlock inside the role-change transaction makes Laravel run it again. The second attempt
 * must apply the change again, not just write the audit entries for a change that was rolled
 * back. Laravel retries only an outermost transaction, so this class does not wrap each test in
 * one (DatabaseMigrations, not RefreshDatabase). Every account is synthetic.
 */
class AdminRoleRetryTest extends TestCase
{
    use DatabaseMigrations;

    private function admin(string $email, string $mobile): User
    {
        return User::create([
            'name' => 'Retry '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make('demo-pass-123'),
            'role' => 'admin', 'email_verified_at' => now(),
        ]);
    }

    public function test_a_retried_demotion_is_applied_once_with_exactly_one_set_of_entries(): void
    {
        $actor = $this->admin('retry.one@example.test', '9466600001');
        $target = $this->admin('retry.two@example.test', '9466600002');

        // The first role-change entry hits a deadlock, after the role update has been written.
        $deadlocks = 0;
        AuditLog::creating(function (AuditLog $entry) use (&$deadlocks) {
            if ($entry->action === AdminRoleService::ROLE_CHANGED && $deadlocks === 0) {
                $deadlocks++;
                throw new QueryException('sqlite', 'insert into audit_logs', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        $target->role = 'public';
        app(AdminRoleService::class)->save($actor, $target);

        $this->assertSame(1, $deadlocks, 'The first attempt was rolled back and retried.');
        $this->assertSame('public', $target->fresh()->role, 'The retry applied the change again.');
        $this->assertSame('public', $target->role, 'The caller sees what was stored.');
        $this->assertSame(1, AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->where('model_id', $target->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'admin.user_updated')->where('model_id', $target->id)->count());
    }
}
