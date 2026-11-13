<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\MessageDelivery;
use App\Models\PrivacyRequest;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Support\Platform;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Services\StaffNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/** Security overview, integration checks, permissions, privacy requests and incidents. */
class SecurityController extends Controller
{
    private const PERMISSIONS = [
        'Submit applications'              => ['citizen' => 'Own', 'notary' => 'No', 'admin' => 'No'],
        'View applications'                => ['citizen' => 'Own', 'notary' => 'Assigned', 'admin' => 'All'],
        'Open uploaded documents'          => ['citizen' => 'Own', 'notary' => 'Assigned', 'admin' => 'All'],
        'Record a decision'                => ['citizen' => 'No', 'notary' => 'Assigned', 'admin' => 'All, with reason'],
        'Assign notaries'                  => ['citizen' => 'No', 'notary' => 'No', 'admin' => 'Yes'],
        'Reports'                          => ['citizen' => 'No', 'notary' => 'Assigned', 'admin' => 'All'],
        'Audit log'                        => ['citizen' => 'No', 'notary' => 'No', 'admin' => 'Yes'],
        'Manage users and services'        => ['citizen' => 'No', 'notary' => 'No', 'admin' => 'Yes'],
        'Privacy requests and incidents'   => ['citizen' => 'Own requests', 'notary' => 'Own requests', 'admin' => 'Yes'],
    ];

    public function index(OtpService $otp)
    {
        $oldest = Platform::activeJobs()->min('created_at');
        $since = now()->subDay();

        return view('admin.security.index', [
            'stats' => [
                'failedLogins' => AuditLog::where('action', 'auth.login_failed')->where('created_at', '>=', $since)->count(),
                'deactivated'  => User::whereNotNull('deactivated_at')->count(),
                'openPrivacy'  => PrivacyRequest::whereIn('status', ['open', 'in_progress'])->count(),
                'openIncidents' => SecurityIncident::where('status', '!=', 'resolved')->count(),
            ],
            'integrations' => $this->integrations(),
            'queue' => [
                'waiting' => Platform::activeJobs()->count(),
                'held'    => Platform::heldJobs(),
                'oldest'  => $oldest ? Carbon::createFromTimestamp($oldest)->ist() : null,
                'stuck'   => $oldest && $oldest < now()->subMinutes(15)->timestamp,
                'failed'  => DB::table('failed_jobs')->count(),
                'worker'  => Cache::has(Platform::WORKER_HEARTBEAT),
                'scheduler' => Cache::has(Platform::SCHEDULER_HEARTBEAT),
                'stale'   => MessageDelivery::where('status', MessageDelivery::QUEUED)->where('created_at', '<', now()->subMinutes(15))->count(),
            ],
            'mailMode' => Platform::mailMode(),
            'sms' => ['sentToday' => $otp->sentToday(), 'limit' => (int) config('services.msg91.daily_limit')],
            'documents' => [
                'total'     => Document::count(),
                'hashed'    => Document::whereNotNull('file_hash')->count(),
                'removed'   => Document::onlyTrashed()->count(),
                'downloads' => AuditLog::whereIn('action', ['document.downloaded', 'document.previewed'])->where('created_at', '>=', $since)->count(),
            ],
            'deliveries' => MessageDelivery::latest('id')->limit(15)->get(),
        ]);
    }

    /** Asks each provider for a login or a balance. Nothing is sent to anyone. */
    public function check()
    {
        Cache::forget(SmsService::ACCOUNT_CACHE);
        app(SmsService::class)->accountStatus();

        Cache::put('security:smtp-check', $this->smtpLogin(), now()->addHour());

        AuditLog::record('admin.integrations_checked');

        return back()->with('status', 'Integration check finished. No message was sent.');
    }

    public function permissions()
    {
        return view('admin.security.permissions', [
            'permissions' => self::PERMISSIONS,
            'counts'      => User::selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
        ]);
    }

    public function privacyRequests()
    {
        return view('admin.security.privacy', ['requests' => PrivacyRequest::with(['user', 'resolver'])->latest()->paginate(20)]);
    }

    public function updatePrivacyRequest(Request $request, PrivacyRequest $privacy)
    {
        $data = $request->validate([
            'status'     => ['required', Rule::in(PrivacyRequest::STATUSES)],
            'resolution' => 'required_if:status,completed,declined|nullable|string|max:1000',
        ]);

        $final = in_array($data['status'], ['completed', 'declined'], true);

        $privacy->update($data + ['resolved_by' => $final ? $request->user()->id : null, 'resolved_at' => $final ? now() : null]);

        AuditLog::record('privacy.updated', $privacy, ['status' => $data['status']]);

        return back()->with('status', 'Privacy request updated.');
    }

    public function incidents()
    {
        return view('admin.security.incidents', ['incidents' => SecurityIncident::with('reporter')->latest('detected_at')->paginate(20)]);
    }

    public function storeIncident(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'severity'    => ['required', Rule::in(SecurityIncident::SEVERITIES)],
            'description' => 'required|string|max:2000',
        ]);

        $incident = SecurityIncident::create($data + ['reported_by' => $request->user()->id, 'detected_at' => now()]);

        AuditLog::record('security.incident_opened', $incident, ['severity' => $data['severity']]);
        app(StaffNotifier::class)->incidentOpened($incident, $request->user());

        return back()->with('status', 'Incident recorded.');
    }

    public function updateIncident(Request $request, SecurityIncident $incident)
    {
        $data = $request->validate([
            'status'  => ['required', Rule::in(SecurityIncident::STATUSES)],
            'actions' => 'nullable|string|max:2000',
        ]);

        $incident->update($data + ['resolved_at' => $data['status'] === 'resolved' ? now() : null]);

        AuditLog::record('security.incident_updated', $incident, ['status' => $data['status']]);

        return back()->with('status', 'Incident updated.');
    }

    /** Only states and non-secret names. Keys and passwords are never read into the page. */
    private function integrations(): array
    {
        $smtp = Cache::get('security:smtp-check');

        return [
            ['name' => 'Gmail SMTP', 'configured' => filled(config('mail.mailers.smtp.username')) && filled(config('mail.mailers.smtp.password')), 'state' => $smtp ?? 'not_checked'],
            ['name' => 'MSG91 SMS', 'configured' => SmsService::isConfigured(), 'state' => SmsService::limitsInPlace() ? Cache::get(SmsService::ACCOUNT_CACHE, 'not_checked') : 'locked'],
        ];
    }

    private function smtpLogin(): string
    {
        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $transport->start();
            $transport->stop();

            return 'ok';
        } catch (Throwable $e) {
            return 'failed';
        }
    }
}
