<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The one save path for an administrator's edit of an account (Admin > Users > Edit).
 *
 * A change that gives or takes away the Admin role is the evidence that
 * AccountDeletionService::adminEvidence() reads before it releases an identity, so:
 *
 * - the edit and its audit entries are written in one transaction, and a failed audit write
 *   for a role change rolls the whole edit back instead of keeping a change nobody can see;
 * - the acting administrator and the account are read again under row locks (every
 *   administrator too when Admin is involved), so two administrators demoting or editing at
 *   once queue up and the second one decides on what is true now;
 * - the edit is applied to the freshly locked row on every attempt, so a deadlock retry never
 *   writes the audit entries without the change they describe.
 */
class AdminRoleService
{
    public const ROLE_CHANGED = 'admin.role_changed';

    /**
     * Saves the unsaved changes set on $user, and syncs $user with what was stored. Returns the
     * names of the changed fields.
     *
     * @throws AuthorizationException when a check made under the lock refuses; nothing is saved
     */
    public function save(User $actor, User $user): array
    {
        $dirty = $user->getDirty();
        // Field names only; the rotated remember token is bookkeeping, not a change the Admin made.
        $changed = array_values(array_diff(array_keys($dirty), ['remember_token']));
        $from = (string) $user->getOriginal('role');
        $to = (string) $user->role;
        $roleChanged = array_key_exists('role', $dirty);
        $adminChange = $roleChanged && ($from === 'admin' || $to === 'admin');

        $stored = DB::transaction(function () use ($actor, $user, $dirty, $changed, $from, $to, $roleChanged, $adminChange) {
            $row = $this->lockAndCheck($actor, $user, $from, $roleChanged, $adminChange);

            if (! $changed) {
                return $row;
            }

            // The permanent marker, set here as well as by the database triggers, so it does not
            // depend on them alone. Never cleared.
            if ($adminChange && $row->was_admin_at === null && User::adminMarkerSupported()) {
                $dirty['was_admin_at'] = $row->fromDateTime(now());
            }

            // Raw values onto the locked row: the model hooks see the same change as before.
            $row->setRawAttributes(array_merge($row->getAttributes(), $dirty));
            $row->save();

            $meta = ['changed' => $changed, 'role_from' => $from, 'role_to' => $to];

            // Enforced for every Admin edit (a password, email or role change included): if the entry
            // cannot be written the whole edit rolls back. Read later by adminEvidence() for role moves.
            AuditLog::recordOrFail('admin.user_updated', $row, $meta, $actor->id);

            if ($adminChange) {
                AuditLog::recordOrFail(self::ROLE_CHANGED, $row, [
                    'role_from' => $from,
                    'role_to' => $to,
                    'change' => $to === 'admin' ? 'granted' : 'revoked',
                ], $actor->id);
            }

            return $row;
        }, 3);

        $user->setRawAttributes($stored->getAttributes(), true);

        return $changed;
    }

    /** Locks the rows the decision depends on, checks again, and returns the locked account. */
    private function lockAndCheck(User $actor, User $user, string $from, bool $roleChanged, bool $adminChange): User
    {
        // Rows are always taken in id order, so concurrent edits queue up instead of deadlocking.
        User::when($adminChange, fn ($query) => $query->where('role', 'admin'))
            ->orWhereIn('id', [$actor->id, $user->id])
            ->orderBy('id')->lockForUpdate()->get();

        $freshActor = User::whereKey($actor->id)->lockForUpdate()->first();
        $row = User::whereKey($user->id)->lockForUpdate()->first();

        if (! $freshActor || ! $freshActor->isAdmin() || $freshActor->isDeactivated()) {
            throw new AuthorizationException('Only an active administrator can edit accounts. Nothing was changed.');
        }

        if (! $row || $row->isDeleted()) {
            throw new AuthorizationException('Restore this account before editing it.');
        }

        if ($roleChanged && $freshActor->is($row)) {
            throw new AuthorizationException('You cannot change your own role.');
        }

        // Someone else changed the role after this edit began. Decide again on what is true now.
        if ($row->role !== $from) {
            throw new AuthorizationException('This account\'s role was changed by someone else while you were editing. Nothing was changed. Review it and try again.');
        }

        if ($adminChange && $from === 'admin') {
            $othersActive = User::where('role', 'admin')->whereKeyNot($row->id)->whereNull('deactivated_at')
                ->when(User::softDeleteSupported(), fn ($query) => $query->whereNull('deleted_at'))
                ->exists();

            if (! $othersActive) {
                throw new AuthorizationException('The last active administrator cannot lose the Admin role.');
            }
        }

        return $row;
    }
}
