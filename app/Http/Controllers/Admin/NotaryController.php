<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\StaffNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin side: list notary accounts and approve or reject them.
 *
 * A notary is "approved" when users.notary_approved_at has a date. Only
 * approved notaries pass the "notary" middleware and can be assigned work.
 * (email_verified_at is the separate, user-driven email verification.)
 *
 * A notary is "rejected" when the latest decision in the audit trail is a rejection and it is
 * not approved now. A rejected account can still be approved later by an administrator.
 */
class NotaryController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'notary')->notDeleted();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $status = $request->get('status', 'all');
        $rejected = User::rejectedNotaries()->select('id');

        if ($status === 'verified') {
            $query->whereNotNull('notary_approved_at');
        } elseif ($status === 'pending') {
            $query->whereNull('notary_approved_at')->whereNotIn('id', $rejected);
        } elseif ($status === 'rejected') {
            $query->whereIn('id', $rejected);
        }

        $notaries = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.notaries.index', [
            'notaries'    => $notaries,
            'rejectedIds' => User::rejectedNotaries()->whereIn('id', $notaries->pluck('id'))->pluck('id')->all(),
            'search'      => $request->get('search', ''),
            'status'      => $status,
        ]);
    }

    public function approve(User $user)
    {
        if ($user->role !== 'notary') {
            return back()->with('error', 'Only notary accounts can be approved.');
        }

        if ($user->isDeleted()) {
            return back()->with('error', 'This account is deleted. Restore it first.');
        }

        // Approval would mean nothing while the account cannot sign in.
        if ($user->isDeactivated()) {
            return back()->with('error', 'This account is deactivated. Reactivate it before approving it.');
        }

        if ($user->notary_approved_at !== null) {
            return back()->with('status', 'This notary is already approved.');
        }

        // On the locked row, with its audit entry (the rejection status is read from the audit trail,
        // so the entry must exist whenever the approval does). Two Admins approving at once: one wins.
        try {
            $approved = DB::transaction(function () use ($user) {
                $locked = User::whereKey($user->id)->lockForUpdate()->first();

                if (! $locked || $locked->notary_approved_at !== null || $locked->isDeactivated() || $locked->isDeleted()) {
                    return false;
                }

                $locked->forceFill(['notary_approved_at' => now()])->save();
                AuditLog::recordOrFail('admin.notary_approved', $locked);

                return true;
            }, 3);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The approval could not be saved and recorded, so nothing was changed. Please try again.');
        }

        if (! $approved) {
            return back()->with('status', 'This notary is already approved, or can no longer be approved.');
        }

        $user->refresh();
        app(StaffNotifier::class)->notaryReviewed($user, true, auth()->user());

        return redirect()->route('admin.notaries.index')
            ->with('status', 'Notary approved successfully.');
    }

    /**
     * Reject a pending application, or withdraw an approval. Either way a reason is required,
     * kept in the audit trail and shown to the Notary.
     */
    public function reject(Request $request, User $user)
    {
        if ($user->role !== 'notary') {
            return back()->with('error', 'Only notary accounts can be rejected.');
        }

        if ($user->isDeleted()) {
            return back()->with('error', 'This account is deleted. Restore it first.');
        }

        $data = $request->validate(['reason' => 'required|string|min:10|max:500']);

        // Decided on the locked row. An assignment or booking in progress holds it too, so it either
        // finishes first and is counted here, or waits and then finds the Notary no longer approved.
        [$wasApproved, $open, $already] = DB::transaction(function () use ($user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wasApproved = $locked->notary_approved_at !== null;

            if (! $wasApproved && $locked->isRejectedNotary()) {
                return [false, null, true];
            }

            // A Notary who is no longer approved cannot open the portal, so their open work would
            // be stranded. It has to be reassigned first (deactivation remains for stopping at once).
            $open = $wasApproved ? $locked->openNotaryWork() : null;

            if ($open && ($open['applications'] || $open['appointments'])) {
                return [$wasApproved, $open, false];
            }

            $locked->forceFill(['notary_approved_at' => null])->save();

            AuditLog::recordOrFail('admin.notary_unapproved', $locked, [
                'reason' => $data['reason'],
                'from'   => $wasApproved ? 'approved' : 'pending',
            ]);

            return [$wasApproved, null, false];
        }, 3);

        if ($already) {
            return back()->with('status', 'This notary application is already rejected.');
        }

        if ($open) {
            return back()->with('error', __('This Notary still has :applications open applications and :appointments open appointments. Nothing was changed. Reassign them first, or deactivate the account to stop access at once.', $open));
        }

        $user->refresh();
        app(StaffNotifier::class)->notaryReviewed($user, false, auth()->user(), $data['reason'], $wasApproved);

        return redirect()->route('admin.notaries.index')
            ->with('status', $wasApproved ? 'Notary approval withdrawn.' : 'Notary application rejected.');
    }
}
