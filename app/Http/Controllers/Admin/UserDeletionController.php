<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** Deletes and restores accounts, and shows the history. See AccountDeletionService. */
class UserDeletionController extends Controller
{
    public function index()
    {
        abort_unless(User::softDeleteSupported(), 404);

        $deletions = AccountDeletion::with(['deletedBy', 'restoredBy'])->latest('id')->paginate(15);

        return view('admin.users.deleted', [
            'deletions' => $deletions,
            // Deletions whose recovery was refused: restoring one needs an explicit confirmation.
            'rejectedRecovery' => User::accountRecoverySupported()
                ? AccountRecoveryRequest::whereIn('account_deletion_id', $deletions->pluck('id'))->where('status', AccountRecoveryRequest::REJECTED)->pluck('account_deletion_id')->flip()
                : collect(),
        ]);
    }

    public function destroy(Request $request, User $user, AccountDeletionService $service)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:500',
            'confirm' => 'accepted',
        ], [
            'reason.min' => 'Please give a reason of at least 10 characters.',
            'confirm.accepted' => 'Please tick the box to confirm the deletion.',
        ]);

        try {
            $deletion = $service->delete($request->user(), $user, trim($data['reason']));
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }
        catch (Throwable $e) {
            // For example the audit entry could not be written: the transaction rolled back.
            report($e);

            return back()->with('error', 'The deletion could not be saved and recorded, so nothing was changed. Please try again.');
        }

        // The same definition of open work as deactivation and approval withdrawal use.
        $open = $user->isNotary() ? $user->openNotaryWork()['applications'] : 0;

        return redirect()->route('admin.users.deleted')->with('status', $open
            ? __('Account deleted. Reference :reference. :count open applications are still assigned to this notary, so please reassign them.', ['reference' => $deletion->reference, 'count' => $open])
            : __('Account deleted. Reference :reference.', ['reference' => $deletion->reference]));
    }

    public function restore(Request $request, User $user, AccountDeletionService $service)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:500',
            // Required by the service only when a recovery request for this account was rejected.
            'rejection_reviewed' => 'nullable|boolean',
        ], [
            'reason.required' => 'Please give a reason for restoring this account.',
            'reason.min' => 'Please give a reason of at least 10 characters.',
        ]);

        try {
            $deletion = $service->restore($request->user(), $user, trim($data['reason']), (bool) ($data['rejection_reviewed'] ?? false));
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }
        catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The restore could not be saved and recorded, so nothing was changed. Please try again.');
        }

        return redirect()->route('admin.users.deleted')->with('status', __('Account restored. Reference :reference. The person must set a new password from the emailed link and verify their mobile number again. A Notary also needs your approval again.', ['reference' => $deletion->reference]));
    }

    /** A new password link for a plain restore whose link failed, never went or expired. */
    public function resendResetLink(Request $request, AccountDeletion $deletion, AccountDeletionService $service)
    {
        try {
            $queued = $service->resendResetLink($request->user(), $deletion);
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(...($queued
            ? ['status', __('A new password link was queued for the registered address of :reference. Any earlier link no longer works.', ['reference' => $deletion->reference])]
            : ['error', __('A new link was made but could not be queued. This was recorded. Please try again in a minute.')]));
    }

    public function retry(Request $request, AccountDeletion $deletion, string $notice, AccountDeletionService $service)
    {
        $request->merge(['notice' => $notice])->validate(['notice' => ['required', Rule::in([AccountDeletion::NOTICE_DELETION, AccountDeletion::NOTICE_RESTORE])]]);

        return back()->with(...($service->retry($deletion, $notice)
            ? ['status', 'The email has been queued again.']
            : ['error', 'This email cannot be sent again now.']));
    }
}
