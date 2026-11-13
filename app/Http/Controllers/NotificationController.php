<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** The in-app notification list. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return view('notifications.index', ['notifications' => $request->user()->notifications()->paginate(20)]);
    }

    public function read(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = (string) ($notification->data['url'] ?? '');
        $host = parse_url($url, PHP_URL_HOST);
        $internal = $url !== '' && ($host === null ? str_starts_with($url, '/') && ! str_starts_with($url, '//') : in_array($host, [$request->getHost(), parse_url((string) config('app.url'), PHP_URL_HOST)], true));

        return redirect()->to($internal ? $url : route('notifications.index'));
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'All notifications marked as read.');
    }
}
