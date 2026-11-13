<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin side: list contact-form messages, mark them as read, or delete them.
 */
class MessageController extends Controller
{
    public function index(Request $request)
    {
        $messages = collect();

        if (Schema::hasTable('contact_messages')) {
            $query = ContactMessage::query();

            // Optional search box: match name, email, subject or message text.
            if ($search = $request->get('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            }

            // Optional filter: read or unread.
            $status = $request->get('status', 'all');
            if ($status === 'read') {
                $query->where('is_read', true);
            } elseif ($status === 'unread') {
                $query->where('is_read', false);
            }

            $messages = $query->latest()->paginate(15)->withQueryString();
        }

        return view('admin.messages.index', [
            'messages' => $messages,
            'search'   => $request->get('search', ''),
            'status'   => $request->get('status', 'all'),
        ]);
    }

    public function markRead(ContactMessage $message)
    {
        $message->update(['is_read' => true]);

        return redirect()->route('admin.messages.index')
            ->with('status', 'Message marked as read.');
    }

    public function destroy(ContactMessage $message)
    {
        AuditLog::record('admin.message_deleted', $message);
        $message->delete();

        return redirect()->route('admin.messages.index')
            ->with('status', 'Message deleted successfully.');
    }
}
