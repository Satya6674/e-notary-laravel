<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Support\Platform;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Appointments, shared by all three roles. Citizens book for their own open
 * applications; the assigned notary or an admin confirms, completes or cancels.
 */
class AppointmentController extends Controller
{
    public const NOTARY_UNAVAILABLE = 'The notary assigned to this application is not available at the moment, so it cannot be booked now. An administrator will assign another notary.';

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Appointment::with(['request', 'user', 'notary']);

        if ($user->isNotary()) {
            $query->where('notary_id', $user->id);
        } elseif (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return view('appointments.index', [
            'appointments' => $query->orderByDesc('scheduled_at')->paginate(15),
            'bookable'     => $user->role === 'public' && Setting::get('appointments_enabled') === '1'
                ? NotaryRequest::where('user_id', $user->id)->whereNotIn('status', ['rejected', 'completed'])->latest()->get()
                : collect(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'public', 403);
        abort_unless(Setting::get('appointments_enabled') === '1', 403, 'Appointments are switched off.');

        $data = $request->validate([
            'request_id'   => ['required', 'integer', Rule::exists('notary_requests', 'id')->where('user_id', $request->user()->id)],
            'scheduled_at' => 'required|date|before:+6 months',
            'mode'         => ['required', Rule::in(Appointment::MODES)],
            'notes'        => 'nullable|string|max:500',
        ]);

        // The form is in Indian time; the database stays in UTC.
        $data['scheduled_at'] = Carbon::parse($data['scheduled_at'], Platform::DISPLAY_TIMEZONE)->utc();

        if ($data['scheduled_at']->isPast()) {
            return back()->withErrors(['scheduled_at' => 'Choose a time in the future.'])->withInput();
        }

        // Decided on locked rows, the application first and then its Notary: the order assignment
        // uses. A deactivation holds the Notary's row, so a booking either finishes first and is
        // counted by it, or waits and then sees the Notary is no longer available. Two bookings
        // for one application queue up instead of both finding it free.
        [$appointment, $notary, $error] = DB::transaction(function () use ($data, $request) {
            $application = NotaryRequest::whereKey($data['request_id'])->lockForUpdate()->firstOrFail();
            $notary = $application->notary_id ? User::whereKey($application->notary_id)->lockForUpdate()->first() : null;

            if ($application->isFinal()) {
                return [null, null, 'This application is closed, so it cannot be booked.'];
            }

            if ($application->appointments()->whereIn('status', ['requested', 'confirmed'])->exists()) {
                return [null, null, 'This application already has an open appointment.'];
            }

            // Never booked with a Notary who cannot act on it. Nothing is moved: an administrator
            // reassigns the application, and the booking can then be made with the new Notary.
            if ($application->notary_id && (! $notary || ! $notary->isApprovedNotary())) {
                return [null, null, self::NOTARY_UNAVAILABLE];
            }

            $appointment = Appointment::create($data + ['user_id' => $request->user()->id, 'notary_id' => $application->notary_id]);

            AuditLog::record('appointment.requested', $appointment, ['application_id' => $application->id]);

            return [$appointment, $notary, null];
        }, 3);

        if ($error) {
            return back()->with('error', $error);
        }

        // After the commit, so no notice describes a booking that was rolled back.
        if ($notary) {
            $this->notify($notary, $appointment, 'New appointment request');
        }

        return back()->with('status', 'Appointment requested.');
    }

    /** Confirm, complete or cancel. Citizens may only cancel their own. */
    public function update(Request $request, Appointment $appointment)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['confirmed', 'completed', 'cancelled'])]]);
        $user = $request->user();

        $isOwner = $user->id === (int) $appointment->user_id;
        $isStaff = $user->isAdmin() || ($user->isApprovedNotary() && $user->id === (int) $appointment->notary_id);

        abort_unless($isStaff || ($isOwner && $data['status'] === 'cancelled'), 403);

        if (! $appointment->isOpen()) {
            return back()->with('error', 'This appointment is already closed.');
        }

        $appointment->update(['status' => $data['status']]);

        AuditLog::record('appointment.'.$data['status'], $appointment, ['application_id' => $appointment->request_id]);

        $recipient = $isOwner ? $appointment->notary : $appointment->user;

        if ($recipient) {
            $this->notify($recipient, $appointment, 'Appointment '.$data['status']);
        }

        return back()->with('status', 'Appointment '.$data['status'].'.');
    }

    private function notify(User $recipient, Appointment $appointment, string $title): void
    {
        $recipient->notify(new PortalNotification(
            $title,
            $appointment->scheduled_at->ist().' IST ('.$appointment->request->request_number.')',
            route('appointments.index')
        ));
    }
}
