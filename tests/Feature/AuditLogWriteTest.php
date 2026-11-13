<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * AuditLog::record() and AuditLog::recordOrFail() each write exactly one row per call, and a
 * failure never turns into a second attempt. Every record here is synthetic.
 */
class AuditLogWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $subject;

    private int $reported = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = User::create([
            'name' => 'Audit Subject', 'email' => 'audit.subject@example.test', 'mobile' => '9477700001',
            'password' => Hash::make('demo-pass-123'), 'role' => 'public', 'email_verified_at' => now(),
        ]);

        // Counts what record() reports instead of throwing.
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->reportable(function (RuntimeException $e) {
                if ($e->getMessage() === 'Simulated audit failure.') {
                    $this->reported++;
                }

                return false;
            });
    }

    private function rows(string $action): int
    {
        return DB::table('audit_logs')->where('action', $action)->count();
    }

    public function test_record_writes_exactly_one_row_per_call(): void
    {
        AuditLog::record('test.one', $this->subject, ['a' => 1]);
        $this->assertSame(1, $this->rows('test.one'));

        AuditLog::record('test.one', $this->subject, ['a' => 2]);
        $this->assertSame(2, $this->rows('test.one'), 'Each call adds one row, no more.');

        $row = AuditLog::where('action', 'test.one')->orderBy('id')->first();
        $this->assertSame(['a' => 1], $row->meta);
        $this->assertSame('User', $row->model_type);
        $this->assertSame($this->subject->id, $row->model_id);
    }

    public function test_record_or_fail_writes_exactly_one_row_and_returns_it(): void
    {
        $entry = AuditLog::recordOrFail('test.two', $this->subject, ['b' => 1], $this->subject->id);

        $this->assertSame(1, $this->rows('test.two'));
        $this->assertTrue($entry->exists);
        $this->assertSame($this->subject->id, $entry->user_id);
        $this->assertSame($entry->id, AuditLog::where('action', 'test.two')->sole()->id);
    }

    public function test_record_or_fail_throws_and_writes_nothing_when_the_insert_fails(): void
    {
        AuditLog::creating(function (AuditLog $entry) {
            if ($entry->action === 'test.three') {
                throw new RuntimeException('Simulated audit failure.');
            }
        });

        try {
            AuditLog::recordOrFail('test.three', $this->subject);
            $this->fail('recordOrFail must throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated audit failure.', $e->getMessage());
        }

        $this->assertSame(0, $this->rows('test.three'));
    }

    public function test_record_reports_a_failed_insert_once_writes_nothing_and_does_not_throw(): void
    {
        AuditLog::creating(function (AuditLog $entry) {
            if ($entry->action === 'test.four') {
                throw new RuntimeException('Simulated audit failure.');
            }
        });

        AuditLog::record('test.four', $this->subject);

        $this->assertSame(0, $this->rows('test.four'));
        $this->assertSame(1, $this->reported, 'Reported once, not retried.');
    }

    public function test_a_failure_after_the_row_is_written_never_produces_a_second_row(): void
    {
        // The row is inserted, then something after the insert fails.
        AuditLog::created(function (AuditLog $entry) {
            if (in_array($entry->action, ['test.five', 'test.six'], true)) {
                throw new RuntimeException('Simulated audit failure.');
            }
        });

        AuditLog::record('test.five', $this->subject);
        $this->assertSame(1, $this->rows('test.five'), 'record() wrote one row and did not try again.');
        $this->assertSame(1, $this->reported);

        try {
            AuditLog::recordOrFail('test.six', $this->subject);
            $this->fail('recordOrFail must throw.');
        } catch (RuntimeException) {
        }

        // Outside a transaction the inserted row stays; it is still exactly one.
        $this->assertSame(1, $this->rows('test.six'));
    }

    public function test_the_database_refuses_sql_changes_to_audit_entries(): void
    {
        // Migration 000023: append-only at the database level, not only in the model.
        $entry = AuditLog::recordOrFail('test.sql', $this->subject, ['a' => 1], $this->subject->id);

        foreach ([
            fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'test.changed']),
            fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['meta' => null]),
            fn () => DB::table('audit_logs')->where('id', $entry->id)->delete(),
        ] as $statement) {
            try {
                $statement();
                $this->fail('The database should refuse this.');
            } catch (\Illuminate\Database\QueryException) {
            }
        }

        $this->assertSame('test.sql', DB::table('audit_logs')->where('id', $entry->id)->value('action'));
        $this->assertSame(1, $this->rows('test.sql'));
    }

    private function newUser(string $email, string $mobile): User
    {
        return User::create([
            'name' => 'Audit '.$mobile, 'email' => $email, 'mobile' => $mobile,
            'password' => Hash::make('demo-pass-123'), 'role' => 'public', 'email_verified_at' => now(),
        ]);
    }

    private function deleteRefused(User $user): bool
    {
        try {
            DB::table('users')->where('id', $user->id)->delete();

            return false;
        } catch (\Illuminate\Database\QueryException) {
            return true;
        }
    }

    public function test_a_user_referenced_by_audit_history_cannot_be_hard_deleted(): void
    {
        // Migration 000024. The application never hard-deletes users (it archives them), so
        // these are direct database deletes, as from phpMyAdmin or a script.

        // As the actor: the foreign key is RESTRICT, no longer SET NULL.
        $actor = $this->newUser('audit.actor@example.test', '9477700002');
        $asActor = AuditLog::recordOrFail('test.actor', null, ['a' => 1], $actor->id);
        $this->assertTrue($this->deleteRefused($actor));
        $this->assertSame($actor->id, DB::table('audit_logs')->where('id', $asActor->id)->value('user_id'), 'Attribution intact.');

        // As the subject only (no actor): refused by the users trigger.
        $subject = $this->newUser('audit.subject.only@example.test', '9477700003');
        $asSubject = AuditLog::recordOrFail('test.subject', $subject, ['a' => 1], null);
        $this->assertTrue($this->deleteRefused($subject));
        $this->assertSame($subject->id, (int) DB::table('audit_logs')->where('id', $asSubject->id)->value('model_id'));

        $this->assertNotNull($actor->fresh());
        $this->assertNotNull($subject->fresh());
    }

    public function test_a_user_with_no_audit_history_can_still_be_removed(): void
    {
        // The rule is exact: only rows the history refers to are kept.
        $plain = $this->newUser('audit.plain@example.test', '9477700004');

        $this->assertFalse($this->deleteRefused($plain));
        $this->assertNull(User::find($plain->id));
    }

    public function test_record_or_fail_inside_a_transaction_rolls_back_with_it(): void
    {
        AuditLog::creating(function (AuditLog $entry) {
            if ($entry->action === 'test.seven') {
                throw new RuntimeException('Simulated audit failure.');
            }
        });

        try {
            DB::transaction(function () {
                $this->subject->forceFill(['name' => 'Changed In Transaction'])->save();
                AuditLog::recordOrFail('test.seven', $this->subject);
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('Audit Subject', $this->subject->fresh()->name);
        $this->assertSame(0, $this->rows('test.seven'));
    }
}
