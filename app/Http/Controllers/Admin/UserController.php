<?php

namespace App\Http\Controllers\Admin;

use App\Support\PasswordPolicy;
use App\Support\SensitiveInput;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\AdminRoleService;
use App\Services\SmsService;
use App\Services\VerificationLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Admin side: list, view, edit and deactivate user accounts. Deleting an account is in
 * UserDeletionController.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->notDeleted();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $role = $request->get('role', 'all');
        if (in_array($role, ['public', 'notary', 'admin'], true)) {
            $query->where('role', $role);
        }

        $state = $request->get('state', 'all');
        if ($state === 'active') {
            $query->whereNull('deactivated_at');
        } elseif ($state === 'deactivated') {
            $query->whereNotNull('deactivated_at');
        }

        // A Notary's open work, counted for the Deactivate confirmation (nothing moves on its own).
        $query->withCount([
            'assignedRequests as open_applications_count' => fn ($q) => $q->whereIn('status', User::OPEN_NOTARY_STATUSES),
            'notaryAppointments as open_appointments_count' => fn ($q) => $q->whereIn('status', ['requested', 'confirmed']),
        ]);

        return view('admin.users.index', [
            'users'  => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'search' => $request->get('search', ''),
            'role'   => $role,
            'state'  => $state,
        ]);
    }

    public function show(User $user, VerificationLedger $ledger)
    {
        return view('admin.users.show', [
            'user' => $user,
            'verificationHistory' => $ledger->history($user),
            'verificationHistorySupported' => VerificationLedger::supported(),
        ]);
    }

    public function edit(User $user)
    {
        // A deleted account is frozen, so its notices keep going to the same address.
        if ($user->isDeleted()) {
            return redirect()->route('admin.users.deleted')->with('error', 'Restore this account before editing it.');
        }

        if ($user->isProtectedAccount() && ! $user->is(auth()->user())) {
            return redirect()->route('admin.users.show', $user)->with('error', 'This is the permanent administrator account. Only its owner can change it.');
        }

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user, AdminRoleService $roles)
    {
        if ($user->isDeleted()) {
            return redirect()->route('admin.users.deleted')->with('error', 'Restore this account before editing it.');
        }

        if ($user->isProtectedAccount() && ! $user->is($request->user())) {
            return back()->with('error', 'This is the permanent administrator account. Only its owner can change it.');
        }

        $request->merge([
            'mobile' => SmsService::normalizeMobile($request->input('mobile')) ?? $request->input('mobile'),
        ]);

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile'   => ['nullable', 'string', 'digits:10', Rule::unique('users', 'mobile')->ignore($user->id)],
            'role'     => 'required|in:public,notary,admin',
            'password' => PasswordPolicy::rules(required: false),
        ]);

        // An admin cannot remove their own admin role (would lock them out).
        if ($user->id === $request->user()->id && $data['role'] !== 'admin') {
            return back()->withErrors(['role' => 'You cannot change your own role.'])->withInput($request->except(SensitiveInput::FIELDS));
        }

        // The reset goes to the proven mailbox, so the address cannot move until it has been used.
        if ($user->mustResetPassword() && strcasecmp($data['email'], (string) $user->email) !== 0) {
            return back()->withErrors(['email' => 'This recovered account must set a new password from its registered address before that address can change.'])->withInput($request->except(SensitiveInput::FIELDS));
        }

        // A recovered person proves their mailbox to get a password. An administrator cannot skip that.
        if (! empty($data['password']) && ($user->mustResetPassword() || $user->isRetiredPassword($data['password']))) {
            return back()->withErrors(['password' => 'This recovered account must set its own new password from the emailed link.'])->withInput($request->except(SensitiveInput::FIELDS));
        }

        $previousEmail = (string) $user->email;

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->mobile = $data['mobile'] ?? null;
        $user->role = $data['role'];

        $passwordSet = ! empty($data['password']);

        if ($passwordSet) {
            $user->password = Hash::make($data['password']);
            // "Remember me" on other devices ends too; AuthenticateSession ends their sessions.
            $user->setRememberToken(Str::random(60));
        }

        $emailChanged = $user->isDirty('email');
        $roleChanged = $user->isDirty('role');

        // A new address or number has not been proven, whoever entered it.
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $mobileChanged = $user->isDirty('mobile');

        if ($mobileChanged && User::mobileVerificationSupported()) {
            $user->mobile_verified_at = null;
        }

        if ($mobileChanged) {
            $user->pending_mobile = null;
            $user->pending_mobile_requested_at = null;
        }

        if ($user->isDirty('role') && $user->role !== 'notary') {
            $user->notary_approved_at = null;
        }

        // Saved with its audit entry (names of the changed fields only, never their values). A
        // change to or from Admin is locked, checked again and recorded in the same transaction.
        try {
            $roles->save($request->user(), $user);
        } catch (AuthorizationException $e) {
            return back()->withErrors(['role' => $e->getMessage()])->withInput($request->except(SensitiveInput::FIELDS));
        } catch (Throwable $e) {
            // For example the audit entry of a role change could not be written: the transaction
            // rolled back, so the account is exactly as it was.
            report($e);

            return back()->withErrors(['role' => 'The change could not be saved and recorded, so nothing was changed. Please try again.'])->withInput($request->except(SensitiveInput::FIELDS));
        }

        // The person is told what an administrator changed, never the new values. A change of
        // address is announced to the previous address: the one a thief cannot also read.
        $previous = $emailChanged ? Notification::route('mail', $previousEmail) : null;

        if ($emailChanged) {
            $previous->notify(new SecurityAlertNotification('admin_email_changed'));
        }

        if ($passwordSet) {
            ($previous ?? $user)->notify(new SecurityAlertNotification('admin_password_set'));
        }

        if ($roleChanged) {
            ($previous ?? $user)->notify(new SecurityAlertNotification('admin_role_changed'));
        }

        if ($mobileChanged) {
            ($previous ?? $user)->notify(new SecurityAlertNotification('mobile_changed'));
        }

        return redirect()->route('admin.users.index')
            ->with('status', 'User updated successfully.');
    }

    /**
     * Accounts are deactivated, never deleted: deleting one would cascade to the
     * person's applications and documents. A reason is required and audited.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($user->isProtectedAccount()) {
            return back()->with('error', 'This is the permanent administrator account. It cannot be deactivated.');
        }

        if ($user->isDeleted()) {
            return back()->with('error', 'This account is already deleted.');
        }

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:500',
            // The open work the confirmation showed, for a Notary. See below.
            'open_applications' => 'nullable|integer|min:0',
            'open_appointments' => 'nullable|integer|min:0',
        ]);

        $confirmed = $request->filled('open_applications') && $request->filled('open_appointments');

        $actorId = $request->user()->id;

        // Every administrator and both accounts are locked, in id order (the order role changes
        // use too). An assignment or booking in progress holds the Notary's row, so it finishes
        // first and is counted below; one that starts later waits and then sees the deactivation.
        try {
            [$open, $refused, $denied] = DB::transaction(function () use ($user, $data, $confirmed, $actorId) {
            User::where('role', 'admin')->orWhereIn('id', [$actorId, $user->id])->orderBy('id')->lockForUpdate()->get();
            $actor = User::whereKey($actorId)->lockForUpdate()->first();
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            // Checked again on the locked rows: two administrators deactivating each other at once
            // must not both succeed, and the site must keep an active administrator.
            if (! $actor || ! $actor->isAdmin() || $actor->isDeactivated()) {
                return [null, false, 'Only an active administrator can deactivate accounts. Nothing was changed.'];
            }

            if (! $locked || $locked->isDeleted()) {
                return [null, false, 'This account is already deleted.'];
            }

            if ($locked->isAdmin() && ! User::where('role', 'admin')->whereKeyNot($locked->id)->whereNull('deactivated_at')
                ->when(User::softDeleteSupported(), fn ($query) => $query->whereNull('deleted_at'))->exists()) {
                return [null, false, 'The last active administrator cannot be deactivated.'];
            }

            $open = $locked->isNotary() ? $locked->openNotaryWork() : null;

            // A Notary with open work is deactivated only if the administrator confirmed exactly the
            // work that is open now. A stale or missing count changes nothing and is shown again.
            if ($open && ($confirmed
                ? ((int) $data['open_applications'] !== $open['applications'] || (int) $data['open_appointments'] !== $open['appointments'])
                : ($open['applications'] || $open['appointments']))) {
                return [$open, true, null];
            }

            $locked->forceFill(['deactivated_at' => now(), 'remember_token' => Str::random(60)])->save();

            // A temporary access grant ends with the account, so a later reactivation starts clean.
            app(\App\Services\ManualVerificationService::class)->endAccessFor($locked, $actor->id, \App\Services\ManualVerificationService::CAUSE_ACCOUNT_DEACTIVATED);

            // Enforced: if the entry cannot be written the deactivation is rolled back.
            AuditLog::recordOrFail('admin.user_deactivated', $locked, ['role' => $locked->role, 'reason' => $data['reason']] + ($open ? ['open_applications' => $open['applications'], 'open_appointments' => $open['appointments']] : []));

            return [$open, false, null];
            }, 3);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The deactivation could not be saved and recorded, so nothing was changed. Please try again.');
        }

        if (! $denied && ! $refused) {
            $user->refresh()->notify(new SecurityAlertNotification('account_deactivated'));
        }

        if ($denied) {
            return back()->with('error', $denied);
        }

        if ($refused) {
            return back()->with('error', __('This Notary now has :applications open applications and :appointments open appointments, which you have not confirmed. Nothing was changed. Review the work and confirm again.', $open));
        }

        // Open work stays where it is. The administrator decides where each item goes.
        return redirect()->route('admin.users.index')->with('status', $open && ($open['applications'] || $open['appointments'])
            ? __('Account deactivated. :applications open applications and :appointments open appointments are still assigned to this Notary. Reassign them from Applications.', $open)
            : 'Account deactivated.');
    }

    public function reactivate(User $user)
    {
        // Only a restore may bring back a deleted account: it records who did it and tells the person.
        if ($user->isDeleted()) {
            return back()->with('error', 'This account is deleted. Restore it from Deleted Users.');
        }

        if (! $user->isDeactivated()) {
            return back()->with('status', 'This account is already active.');
        }

        // The change and its audit entry together, on the locked row: a failed entry changes nothing.
        try {
            DB::transaction(function () use ($user) {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill(['deactivated_at' => null])->save();
                AuditLog::recordOrFail('admin.user_reactivated', $locked);
            }, 3);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The reactivation could not be saved and recorded, so nothing was changed. Please try again.');
        }

        $user->refresh()->notify(new SecurityAlertNotification('account_reactivated'));

        return back()->with('status', 'Account reactivated.');
    }
}
