<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailReplacement;
use App\Models\User;
use App\Services\EmailReplacementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Replacing the lost email address of a restored account. See EmailReplacementService. */
class EmailReplacementController extends Controller
{
    public function create(User $user)
    {
        abort_unless(EmailReplacement::supported(), 404);

        return view('admin.users.email-replacement', [
            'user' => $user,
            'replacements' => EmailReplacement::where('user_id', $user->id)->latest('id')->get(),
        ]);
    }

    public function store(Request $request, User $user, EmailReplacementService $service)
    {
        abort_unless(EmailReplacement::supported(), 404);

        $data = $request->validate([
            'new_email' => 'required|email:rfc|max:255',
            'identity_check' => ['required', Rule::in(EmailReplacement::CHECKS)],
            // A short record of the check, never a document or its number.
            'identity_note' => ['required', 'string', 'min:10', 'max:300', 'not_regex:/\d{6,}/'],
            'reason' => 'required|string|min:20|max:500',
            'identity_confirmed' => 'accepted',
            'support_ticket_id' => 'nullable|integer|min:1',
            'contact_message_id' => 'nullable|integer|min:1',
        ], [
            'identity_note.not_regex' => 'Do not record document or ID numbers. Describe the check instead, for example "video call, face matched the profile photo".',
            'identity_confirmed.accepted' => 'Please confirm that you checked this person\'s identity outside the system.',
            'reason.min' => 'Please give a reason of at least 20 characters.',
        ]);

        try {
            $replacement = $service->start($request->user(), $user, $data);
        } catch (AuthorizationException $e) {
            return back()->withInput($request->only('new_email', 'identity_check', 'support_ticket_id', 'contact_message_id'))->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users.email-replacement', $user)->with('status', __('Reference :reference. A confirmation link was sent to the new address and the current address was told. Nothing changes until the link is used.', ['reference' => $replacement->reference]));
    }

    /**
     * The id is resolved here, not by route model binding: before migration 000025 the table does
     * not exist, and binding would query it and fail with a 500 before any check could run.
     */
    public function cancel(Request $request, int $replacement, EmailReplacementService $service)
    {
        abort_unless(EmailReplacement::supported(), 404);
        $replacement = EmailReplacement::findOrFail($replacement);

        $data = $request->validate(['reason' => 'required|string|min:10|max:500']);

        try {
            $service->cancel($request->user(), $replacement, $data['reason']);
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'The replacement was cancelled. Its link no longer works.');
    }
}
