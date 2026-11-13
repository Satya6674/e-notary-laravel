<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\SupportTicket;
use App\Models\User;

/**
 * Admin dashboard: live counts and the most recent activity.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $byStatus = NotaryRequest::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.dashboard', [
            'totalUsers'       => User::where('role', 'public')->notDeleted()->count(),
            'totalNotaries'    => User::where('role', 'notary')->notDeleted()->count(),
            'pendingNotaries'  => User::where('role', 'notary')->notDeleted()->whereNull('notary_approved_at')->count(),
            'totalRequests'    => $byStatus->sum(),
            'byStatus'         => $byStatus,
            'pendingRequests'  => (int) ($byStatus[NotaryRequest::STATUS_PENDING] ?? 0),
            'totalDocuments'   => Document::count(),
            'unreadMessages'   => ContactMessage::where('is_read', false)->count(),
            'openTickets'      => SupportTicket::where('status', '!=', 'closed')->count(),
            'openAppointments' => Appointment::whereIn('status', ['requested', 'confirmed'])->count(),
            'recentRequests'   => NotaryRequest::with(['user', 'notary'])->latest()->take(6)->get(),
            'recentAudit'      => AuditLog::with('user')->latest('id')->take(6)->get(),
        ]);
    }
}
