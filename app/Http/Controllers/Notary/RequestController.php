<?php

namespace App\Http\Controllers\Notary;

use App\Support\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Services\ApplicationDecisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Notary side: dashboard, assigned requests, decisions, profile.
 *
 * All pages are protected by the "notary" middleware (role + admin approval).
 * A notary only ever sees and acts on requests assigned to them (notary_id).
 */
class RequestController extends Controller
{
    public function __construct(private ApplicationDecisionService $decisions)
    {
    }

    public function index()
    {
        $me = auth()->id();
        $mine = fn () => NotaryRequest::where('notary_id', $me);

        return view('notary.dashboard', [
            'pendingCount'    => $mine()->where('status', NotaryRequest::STATUS_ASSIGNED)->count(),
            'approvedCount'   => $mine()->where('status', NotaryRequest::STATUS_APPROVED)->count(),
            'completedCases'  => $mine()->where('status', NotaryRequest::STATUS_COMPLETED)->count(),
            'rejectedCount'   => $mine()->where('status', NotaryRequest::STATUS_REJECTED)->count(),
            'correctionCount' => $mine()->where('status', NotaryRequest::STATUS_CORRECTION)->count(),
            'recentRequests'  => $mine()->with(['user', 'documents'])->latest('updated_at')->take(6)->get(),
            'upcoming'        => Appointment::with('request')->where('notary_id', $me)
                ->whereIn('status', ['requested', 'confirmed'])->orderBy('scheduled_at')->take(5)->get(),
        ]);
    }

    /** Requests assigned to this notary, optionally filtered by status. */
    public function requests(Request $http)
    {
        $status = $http->get('status', NotaryRequest::STATUS_ASSIGNED);

        $query = NotaryRequest::with(['user', 'documents'])->where('notary_id', auth()->id());

        if (array_key_exists($status, NotaryRequest::TRANSITIONS)) {
            $query->where('status', $status);
        }

        return view('notary.requests', [
            'requests' => $query->latest()->paginate(15)->withQueryString(),
            'status'   => $status,
        ]);
    }

    public function show($id)
    {
        $request = $this->mine($id);
        $request->load(['user', 'documents', 'appointments']);

        return view('notary.request-show', [
            'request' => $request,
            'history' => $request->history(),
            'options' => $this->optionsFor($request),
        ]);
    }

    public function approve(Request $http, $id)
    {
        return $this->decide($http, $id, 'approve');
    }

    public function reject(Request $http, $id)
    {
        return $this->decide($http, $id, 'reject');
    }

    public function correct(Request $http, $id)
    {
        return $this->decide($http, $id, 'correct');
    }

    public function complete(Request $http, $id)
    {
        return $this->decide($http, $id, 'complete');
    }

    /** Download a document of a request assigned to this notary. */
    public function download($id, Document $document)
    {
        $request = $this->mine($id);

        if ((int) $document->request_id !== (int) $request->id) {
            abort(404);
        }

        return $document->downloadResponse()
            ?? redirect()->route('notary.request.show', $request->id)->with('error', 'Document file not found.');
    }

    public function profile()
    {
        return view('notary.profile');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => PasswordPolicy::rules(),
        ]);

        if (! Hash::check($request->current_password, auth()->user()->password)) {
            return back()->withErrors(['current_password' => 'Wrong current password']);
        }

        if (auth()->user()->isRetiredPassword($request->password)) {
            return back()->withErrors(['password' => 'Choose a different password. Your previous password cannot be used again.']);
        }

        auth()->user()->update(['password' => Hash::make($request->password)]);
        AuditLog::record('user.password_changed', auth()->user());
        auth()->user()->notify(new \App\Notifications\SecurityAlertNotification('password_changed'));

        return back()->with('success', 'Password updated successfully');
    }

    /** 404 unless the request exists AND is assigned to the logged-in notary. */
    private function mine($id): NotaryRequest
    {
        return NotaryRequest::where('notary_id', auth()->id())->findOrFail($id);
    }

    private function decide(Request $http, $id, string $decision)
    {
        $application = $this->mine($id);

        $http->merge(['reason' => $http->input('reason', $http->input('rejection_reason'))]);
        $data = $http->validate(['reason' => 'required|string|min:3|max:1000']);

        if (! $this->decisions->decide($http->user(), $application, $decision, $data['reason'])) {
            return back()->with('error', $this->decisions->refusal ?? "A request that is {$application->status} cannot take that decision.");
        }

        return redirect()->route('notary.requests')
            ->with('success', "Request {$application->status}. The citizen has been notified.");
    }

    /** @return string[] decisions this notary can make now */
    private function optionsFor(NotaryRequest $application): array
    {
        return array_keys(array_filter(
            ApplicationDecisionService::DECISIONS,
            fn ($status) => $this->decisions->allowed(auth()->user(), $application, $status)
        ));
    }
}
