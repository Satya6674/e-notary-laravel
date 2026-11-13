<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\ApplicationDecisionService;
use App\Services\DocumentReports;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic records for the demonstration: php artisan db:seed --class=DemoDataSeeder
 *
 * Every person uses an @enotary-demo.invalid address and an impossible mobile
 * number, every file is marked as a sample, and all of it goes through the real
 * decision service, so the audit history is genuine. Nothing is emailed: mail
 * notifications are suppressed while it runs.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must not be seeded in production.');
        }

        if (User::where('email', 'demo.citizen.a@enotary-demo.invalid')->exists()) {
            $this->command?->warn('Demo data is already present.');

            return;
        }

        Notification::fake();

        $admin = User::where('role', 'admin')->firstOrFail();
        $notary = $this->person('Sample Notary Two', 'demo.notary.b@enotary-demo.invalid', '0000000102', 'notary');
        $citizenA = $this->person('Sample Citizen A', 'demo.citizen.a@enotary-demo.invalid', '0000000103', 'public');
        $citizenB = $this->person('Sample Citizen B', 'demo.citizen.b@enotary-demo.invalid', '0000000104', 'public');

        $this->application($citizenA, 'affidavit', 'Sample affidavit for trying the workflow', null);

        $assigned = $this->application($citizenA, 'rent_agreement', 'Sample rent agreement for trying the workflow', $notary);
        Appointment::create([
            'request_id' => $assigned->id, 'user_id' => $citizenA->id, 'notary_id' => $notary->id,
            'scheduled_at' => now()->addDays(3)->setTime(11, 0), 'mode' => 'video', 'status' => 'requested',
        ]);

        $decide = function (User $actor, NotaryRequest $application, string $decision, string $reason) {
            Auth::setUser($actor);
            app(ApplicationDecisionService::class)->decide($actor, $application, $decision, $reason);
        };

        $correction = $this->application($citizenB, 'declaration', 'Sample declaration for trying the workflow', $notary);
        $decide($notary, $correction, 'correct', 'Sample only: please add the second page.');

        $approved = $this->application($citizenB, 'undertaking', 'Sample undertaking for trying the workflow', $notary);
        $decide($notary, $approved, 'approve', 'Sample only: details are complete.');

        $completed = $this->application($citizenA, 'will', 'Sample will for trying the workflow', $notary);
        $decide($notary, $completed, 'approve', 'Sample only: details are complete.');
        $decide($notary, $completed, 'complete', 'Sample only: review finished.');

        $overridden = $this->application($citizenB, 'other', 'Sample document for trying an admin override', $notary);
        $decide($notary, $overridden, 'reject', 'Sample only: the scan is unclear.');
        $decide($admin, $overridden, 'approve', 'Sample only: the admin found the scan readable.');

        Auth::logout();

        $ticket = SupportTicket::create([
            'ticket_number' => SupportTicket::generateNumber(), 'user_id' => $citizenA->id,
            'subject' => 'Sample question about my application', 'category' => 'application',
        ]);
        $ticket->messages()->create(['user_id' => $citizenA->id, 'body' => 'This is a sample support message.']);
    }

    private function person(string $name, string $email, string $mobile, string $role): User
    {
        // Nobody signs in to these with a known password; they only populate the demo.
        $user = new User;

        $user->forceFill([
            'name' => $name, 'email' => $email, 'mobile' => $mobile, 'role' => $role,
            'password' => Hash::make(Str::random(40)), 'email_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ])->save();

        return $user;
    }

    private function application(User $citizen, string $type, string $purpose, ?User $notary): NotaryRequest
    {
        $application = NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $citizen->id,
            'document_type' => $type, 'purpose' => $purpose, 'status' => NotaryRequest::STATUS_PENDING,
        ]);

        $pdf = app(DocumentReports::class)->sampleDocument($type, $purpose);

        $path = 'documents/demo-'.Str::lower(Str::random(24)).'.pdf';
        Storage::disk(Document::PRIVATE_DISK)->put($path, $pdf);

        Document::create([
            'request_id' => $application->id, 'title' => 'Sample '.str_replace('_', ' ', $type),
            'original_file' => $path, 'mime_type' => 'application/pdf',
            'file_size' => strlen($pdf), 'file_hash' => hash('sha256', $pdf),
        ]);

        if ($notary) {
            $application->transitionTo(NotaryRequest::STATUS_ASSIGNED, ['notary_id' => $notary->id]);
        }

        return $application;
    }
}
