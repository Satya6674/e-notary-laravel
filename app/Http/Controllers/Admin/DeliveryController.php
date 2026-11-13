<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Support\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Outgoing email status. "Waiting" means a job is queued, and "failed" means every
 * retry ran out. This app cannot see whether a message reached an inbox.
 */
class DeliveryController extends Controller
{
    public function index()
    {
        $failed = DB::table('failed_jobs')->orderByDesc('id')->paginate(15)->through(fn ($job) => (object) [
            'uuid'      => $job->uuid,
            'type'      => $this->type($job->payload),
            'failed_at' => $job->failed_at,
            // Only the first line: the rest of a trace can hold addresses or connection details.
            'error'     => mb_strimwidth(strtok((string) $job->exception, "\n"), 0, 140, '...'),
        ]);

        $oldest = Platform::activeJobs()->min('created_at');

        return view('admin.delivery.index', [
            'waiting' => Platform::activeJobs()->count(),
            'held'    => Platform::heldJobs(),
            'oldest'  => $oldest ? Carbon::createFromTimestamp($oldest)->ist('d M, H:i') : '-',
            'failed'  => $failed,
            'total'   => DB::table('failed_jobs')->count(),
            'history' => MessageDelivery::latest('id')->limit(30)->get(),
        ]);
    }

    public function retry(string $id)
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $id)->exists(), 404);

        Artisan::call('queue:retry', ['id' => [$id]]);

        AuditLog::record('admin.delivery_retried', null, ['job' => $id]);

        return back()->with('status', 'Sent back to the queue. It will run when a queue worker is active.');
    }

    private function type(string $payload): string
    {
        $data = json_decode($payload, true);

        return preg_match('/App\\\\+Notifications\\\\+([A-Za-z]+)/', $data['data']['command'] ?? '', $m)
            ? $m[1]
            : class_basename($data['displayName'] ?? 'Job');
    }
}
