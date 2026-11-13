<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccountRecoveryRefused;
use App\Http\Controllers\Controller;
use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Services\AccountRecoveryService;
use App\Models\User;
use App\Services\DeliveryLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Account Recovery Requests: review, approve or reject. See AccountRecoveryService. */
class AccountRecoveryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(User::accountRecoverySupported(), 404);

        $status = $request->query('status', 'all');

        return view('admin.recovery.index', [
            'requests' => AccountRecoveryRequest::when(in_array($status, [AccountRecoveryRequest::PENDING, AccountRecoveryRequest::UNDER_REVIEW, AccountRecoveryRequest::APPROVED, AccountRecoveryRequest::REJECTED, AccountRecoveryRequest::CANCELLED, AccountRecoveryRequest::EXPIRED], true), fn ($q) => $q->where('status', $status))
                ->with('deletion')->latest('id')->paginate(15)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(AccountRecoveryRequest $recovery, AccountRecoveryService $service)
    {
        $recovery->load(['deletion.deletedBy', 'reviewer']);

        return view('admin.recovery.show', [
            'recovery' => $recovery,
            'history' => AccountDeletion::where('user_id', $recovery->old_user_id)->with('deletedBy')->latest('id')->get(),
            'assessment' => $recovery->isOpen() ? $service->assess($recovery) : null,
            'maskedEmail' => DeliveryLog::maskEmail($recovery->verified_email),
            'canDecide' => Gate::inspect('review', $recovery)->allowed(),
        ]);
    }

    public function review(Request $request, AccountRecoveryRequest $recovery, AccountRecoveryService $service)
    {
        return $this->attempt(fn () => $service->startReview($request->user(), $recovery), 'The request is now under review.');
    }

    public function approve(Request $request, AccountRecoveryRequest $recovery, AccountRecoveryService $service)
    {
        $data = $request->validate([
            'identity_checked' => 'accepted',
            'conflict_confirmed' => 'nullable|boolean',
            'conflict_reason' => 'nullable|string|min:10|max:500',
        ], ['identity_checked.accepted' => 'Please confirm that you checked this person\'s identity.']);

        return $this->attempt(
            fn () => $service->approve($request->user(), $recovery, (bool) ($data['conflict_confirmed'] ?? false), $data['conflict_reason'] ?? null),
            $recovery->deletion->role === 'notary'
                ? 'Recovery approved. The original account was restored and needs a new password. Its Notary access stays closed until you approve it again under Notaries.'
                : 'Recovery approved. The original account was restored and needs a new password.'
        );
    }

    public function reject(Request $request, AccountRecoveryRequest $recovery, AccountRecoveryService $service)
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:500'], ['reason.min' => 'Please give a reason of at least 10 characters.']);

        return $this->attempt(fn () => $service->reject($request->user(), $recovery, trim($data['reason'])), 'The request was rejected.');
    }

    /** Safe way out of a conflict. Nothing is restored or archived, and the person keeps their new account. */
    public function cancel(Request $request, AccountRecoveryRequest $recovery, AccountRecoveryService $service)
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:500'], ['reason.min' => 'Please give a reason of at least 10 characters.']);

        return $this->attempt(fn () => $service->cancelByAdmin($request->user(), $recovery, trim($data['reason'])), 'The request was cancelled.');
    }

    public function retry(Request $request, AccountRecoveryRequest $recovery, string $notice, AccountRecoveryService $service)
    {
        $request->merge(['notice' => $notice])->validate(['notice' => ['required', Rule::in(AccountRecoveryRequest::NOTICES)]]);

        return back()->with(...($service->retry($recovery, $notice)
            ? ['status', 'The email has been queued again.']
            : ['error', 'This email cannot be sent again now.']));
    }

    private function attempt(callable $action, string $done)
    {
        try {
            $action();
        } catch (AuthorizationException|AccountRecoveryRefused $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            // The transaction rolled back. Its message holds the SQL and values, so it stays in the log.
            report($e);

            return back()->with('error', 'That could not be completed, and nothing was changed. Please try again.');
        }

        return back()->with('status', $done);
    }
}
