<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\ServiceDraft;
use App\Models\User;
use App\Notifications\NotaryRequestNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Rules and steps of the service workflow: what to validate, what is missing, and submission. */
class ServiceApplication
{
    /** Text rules for a service. The current configuration decides, whatever the form sent. */
    public function fieldRules(array $service): array
    {
        $rules = [
            'purpose' => 'required|string|min:3|max:2000',
            'notes'   => 'nullable|string|max:2000',
        ];

        foreach ($service['fields'] as $field) {
            $rules["details.{$field['key']}"] = array_filter([
                $field['required'] ? 'required' : 'nullable',
                $field['type'] === 'date' ? 'date' : 'string',
                $field['type'] === 'date' ? null : 'max:'.$field['max'],
            ]);
        }

        return $rules;
    }

    public function fieldNames(array $service): array
    {
        $names = ['purpose' => __('Purpose'), 'notes' => __('Optional Notes')];

        foreach ($service['fields'] as $field) {
            $names["details.{$field['key']}"] = __($field['label']);
        }

        foreach ($service['documents'] as $document) {
            $names["files.{$document['key']}"] = __($document['name']);
        }

        return $names;
    }

    /** "mimes" checks the file's content, and "extensions" stops a mismatched name. */
    public function fileRules(array $service): array
    {
        $rules = [];

        foreach ($service['documents'] as $document) {
            $formats = implode(',', $document['formats']);
            $rules["files.{$document['key']}"] = ['nullable', 'file', 'max:'.$document['max_kb'], "mimes:{$formats}", "extensions:{$formats}"];
        }

        return $rules;
    }

    /** Required documents the draft does not have, or whose file has gone. */
    public function missingDocuments(array $service, ?ServiceDraft $draft): array
    {
        return array_values(array_filter($service['documents'], function (array $document) use ($draft) {
            if (! $document['required']) {
                return false;
            }

            $file = $draft?->file($document['key']);

            return ! $file || ! Storage::disk(Document::PRIVATE_DISK)->exists($file['path']);
        }));
    }

    /** Required text fields with no saved value, for example after a requirement was added. */
    public function missingFields(array $service, ?ServiceDraft $draft): array
    {
        return array_values(array_filter($service['fields'], fn (array $field) => $field['required'] && blank($draft?->details[$field['key']] ?? null)));
    }

    /** Save the text and any newly chosen files. A replaced file is deleted once its replacement is stored. */
    public function saveDraft(User $user, array $service, array $values, array $uploads): ServiceDraft
    {
        $draft = ServiceDraft::firstOrNew(['user_id' => $user->id]);

        // Moving to another service replaces the draft, as its documents do not apply.
        if ($draft->exists && $draft->service_slug !== $service['slug']) {
            $draft->discard();
            $draft = new ServiceDraft(['user_id' => $user->id]);
        }

        $files = $draft->files ?? [];
        $replaced = [];

        foreach ($service['documents'] as $document) {
            $upload = $uploads[$document['key']] ?? null;

            if (! $upload instanceof UploadedFile) {
                continue;
            }

            if (isset($files[$document['key']])) {
                $replaced[] = $files[$document['key']]['path'];
            }

            $files[$document['key']] = [
                'path' => $upload->store(ServiceDraft::FOLDER.'/'.$user->id, Document::PRIVATE_DISK),
                'name' => $this->safeName($upload->getClientOriginalName()),
                'mime' => $upload->getMimeType(),
                'size' => $upload->getSize(),
                'hash' => hash_file('sha256', $upload->getRealPath()),
            ];
        }

        $draft->fill([
            'service_slug' => $service['slug'],
            'details'      => [
                'purpose' => $values['purpose'],
                'notes'   => $values['notes'] ?? null,
            ] + array_intersect_key($values['details'] ?? [], array_flip(array_column($service['fields'], 'key'))),
            'files'        => $files,
        ])->save();

        Storage::disk(Document::PRIVATE_DISK)->delete($replaced);

        return $draft;
    }

    /**
     * Create the application from a checked draft. A failed step puts the files back; the
     * draft is only removed after everything succeeds. The draft row is locked, so a double
     * submit cannot make two applications share one file: the second finds no draft and
     * returns null.
     */
    public function submit(User $user, array $service, ServiceDraft $draft): ?NotaryRequest
    {
        $disk = Storage::disk(Document::PRIVATE_DISK);
        $moved = [];

        try {
            $application = DB::transaction(function () use ($user, $service, $draft, $disk, &$moved) {
                if (! ServiceDraft::whereKey($draft->id)->lockForUpdate()->exists()) {
                    return null;
                }

                $documents = [];

                foreach ($service['documents'] as $document) {
                    $file = $draft->file($document['key']);

                    // A vanished optional file is simply absent. A required one stops the submission.
                    if (! $file || ! $disk->exists($file['path'])) {
                        if ($document['required']) {
                            throw new RuntimeException('A required draft file has gone.');
                        }

                        continue;
                    }

                    $target = 'documents/'.basename($file['path']);

                    if (! $disk->move($file['path'], $target)) {
                        throw new RuntimeException('A draft file could not be moved.');
                    }

                    $moved[$target] = $file['path'];
                    $documents[] = [$document, $file, $target];
                }

                $application = NotaryRequest::create([
                    'request_number'  => NotaryRequest::generateRequestNumber(),
                    'user_id'         => $user->id,
                    'document_type'   => $service['document_type'],
                    'service_slug'    => $service['slug'],
                    'service_details' => $this->snapshot($service, $draft),
                    'purpose'         => $draft->details['purpose'],
                    'status'          => NotaryRequest::STATUS_PENDING,
                    'remarks'         => $draft->details['notes'] ?? null,
                ]);

                foreach ($documents as [$requirement, $file, $path]) {
                    $record = Document::create([
                        'request_id'      => $application->id,
                        'title'           => $requirement['name'],
                        'requirement_key' => $requirement['key'],
                        'original_file'   => $path,
                        'mime_type'       => $file['mime'],
                        'file_size'       => $file['size'],
                        'file_hash'       => $file['hash'],
                    ]);

                    AuditLog::record('document.uploaded', $record, ['request_id' => $application->id, 'requirement' => $requirement['key']]);
                }

                AuditLog::record('application.submitted', $application, ['service' => $service['slug'], 'documents' => count($documents)]);

                $draft->delete();

                $user->notify(new NotaryRequestNotification($application, 'submitted'));
                app(StaffNotifier::class)->applicationSubmitted($application);

                return $application;
            });
        } catch (\Throwable $e) {
            foreach ($moved as $target => $source) {
                if (! Document::isOnRecord($target)) {
                    $disk->move($target, $source);
                }
            }

            throw $e;
        }

        return $application;
    }

    /** Keeps the wording the citizen saw, so a later configuration change cannot rewrite the record. */
    private function snapshot(array $service, ServiceDraft $draft): array
    {
        return [
            'service' => $service['name'],
            'required_documents' => collect($service['documents'])->where('required', true)->pluck('key')->values()->all(),
            'fields'  => collect($service['fields'])
                ->filter(fn (array $field) => filled($draft->details[$field['key']] ?? null))
                ->map(fn (array $field) => ['label' => $field['label'], 'value' => $draft->details[$field['key']]])
                ->values()->all(),
        ];
    }

    /** For display only: stored files use a random name chosen by the framework. */
    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^\pL\pN ._()-]+/u', '_', basename(str_replace('\\', '/', $name)));

        return Str::limit(trim((string) $clean, ' .') ?: 'document', 120, '');
    }
}
