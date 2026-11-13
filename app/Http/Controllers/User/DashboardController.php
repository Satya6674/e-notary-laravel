<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\NotaryRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Public user dashboard: live counts of the user's own applications.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $count = fn (array $statuses) => NotaryRequest::where('user_id', $user->id)->whereIn('status', $statuses)->count();

        return view('user.dashboard', [
            'user'             => $user,
            'totalDocuments'   => Document::whereHas('request', fn ($q) => $q->where('user_id', $user->id))->count(),
            'inReview'         => $count([NotaryRequest::STATUS_PENDING, NotaryRequest::STATUS_ASSIGNED]),
            'needsCorrection'  => $count([NotaryRequest::STATUS_CORRECTION]),
            'approvedRequests' => $count([NotaryRequest::STATUS_APPROVED, NotaryRequest::STATUS_COMPLETED]),
            'rejectedRequests' => $count([NotaryRequest::STATUS_REJECTED]),
            'recentRequests'   => NotaryRequest::with('documents')->where('user_id', $user->id)->latest()->take(5)->get(),
            'upcoming'         => Appointment::with('request')->where('user_id', $user->id)
                ->whereIn('status', ['requested', 'confirmed'])->orderBy('scheduled_at')->take(3)->get(),
        ]);
    }
}
