<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\ApplicationDecisionService;
use App\Services\NotaryAssignmentService;
use Illuminate\Http\Request;

/**
 * Admin side: list, view and assign applications, and decide or override them.
 *
 * Decisions go through ApplicationDecisionService, which requires a reason and
 * audits the actor, both statuses and whether the decision was an override.
 *
 * Note: the route parameter is named {request}, so the NotaryRequest
 * model is injected as $request in the action methods below.
 */
class RequestController extends Controller
{
    public function __construct(private ApplicationDecisionService $decisions)
    {
    }

    public function index(Request $request)
    {
        $query = NotaryRequest::with(['user', 'notary']);

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('request_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        $status = $request->get('status', 'all');
        if ($status !== 'all' && array_key_exists($status, NotaryRequest::TRANSITIONS)) {
            $query->where('status', $status);
        }

        return view('admin.requests.index', [
            'requests' => $query->latest()->paginate(15)->withQueryString(),
            'search'   => $request->get('search', ''),
            'status'   => $status,
        ]);
    }

    public function show(NotaryRequest $request)
    {
        $request->load(['user', 'notary', 'documents', 'appointments']);

        // Only Notaries who can sign in and work: a deactivated one is never offered.
        $notaries = User::where('role', 'notary')
            ->notDeleted()
            ->whereNull('deactivated_at')
            ->whereNotNull('notary_approved_at')
            ->where('id', '!=', $request->user_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.requests.show', [
            'requestItem' => $request,
            'notaries'    => $notaries,
            'history'     => $request->history(),
            'options'     => array_keys(array_filter(
                ApplicationDecisionService::DECISIONS,
                fn ($status) => $this->decisions->allowed(auth()->user(), $request, $status)
            )),
        ]);
    }

    /** Assign (or re-assign) a pending/assigned request to an approved notary. */
    public function assign(Request $httpRequest, NotaryRequest $request, NotaryAssignmentService $assignments)
    {
        $data = $httpRequest->validate([
            'notary_id' => 'required|integer|exists:users,id',
            // The Notary the page showed (empty for none). Sent by the form so a stale page is refused.
            'expected_notary_id' => 'nullable|integer',
        ]);

        $outcome = $assignments->assign(
            $httpRequest->user(),
            $request->id,
            (int) $data['notary_id'],
            $httpRequest->exists('expected_notary_id'),
            isset($data['expected_notary_id']) ? (int) $data['expected_notary_id'] : null,
        );

        return match ($outcome) {
            NotaryAssignmentService::ASSIGNED => back()->with('status', 'Request assigned to '.User::find($data['notary_id'])->name.'. The notary has been notified.'),
            NotaryAssignmentService::UNCHANGED => back()->with('status', $assignments->message),
            default => $assignments->field
                ? back()->withErrors([$assignments->field => $assignments->message])
                : back()->with('error', $assignments->message),
        };
    }

    public function approve(Request $httpRequest, NotaryRequest $request)
    {
        return $this->decide($httpRequest, $request, 'approve');
    }

    public function reject(Request $httpRequest, NotaryRequest $request)
    {
        return $this->decide($httpRequest, $request, 'reject');
    }

    public function correct(Request $httpRequest, NotaryRequest $request)
    {
        return $this->decide($httpRequest, $request, 'correct');
    }

    public function complete(Request $httpRequest, NotaryRequest $request)
    {
        return $this->decide($httpRequest, $request, 'complete');
    }

    private function decide(Request $httpRequest, NotaryRequest $application, string $decision)
    {
        $data = $httpRequest->validate(['reason' => 'required|string|min:3|max:1000']);

        if (! $this->decisions->decide($httpRequest->user(), $application, $decision, $data['reason'])) {
            return back()->with('error', $this->decisions->refusal ?? "A request that is {$application->status} cannot take that decision.");
        }

        return back()->with('status', "Request {$application->status}. The citizen has been notified.");
    }
}
