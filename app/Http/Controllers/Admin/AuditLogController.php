<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin side: read-only list of audit log entries.
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = collect();

        if (Schema::hasTable('audit_logs')) {
            $query = AuditLog::with('user');

            // Optional search box: match the action, IP address, or the user's name/email.
            if ($search = $request->get('search')) {
                $query->where(fn ($outer) => $outer->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                })->orWhereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                }));
            }

            $dates = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

            $query->when($dates['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                ->when($dates['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));

            $logs = $query->latest()->paginate(15)->withQueryString();
        }

        return view('admin.audit-logs.index', [
            'logs'   => $logs,
            'search' => $request->get('search', ''),
        ]);
    }
}
