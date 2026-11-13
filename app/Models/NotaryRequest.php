<?php

namespace App\Models;

use App\Services\ServiceCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A citizen's application.
 *
 * Normal lifecycle (followed by notaries, enforced by canTransitionTo):
 *
 *   pending              -> assigned
 *   assigned             -> approved | rejected | correction_requested
 *   correction_requested -> pending | assigned (citizen resubmits)
 *   approved             -> completed
 *   rejected, completed are final.
 *
 * Admins may decide outside this lifecycle: ApplicationDecisionService applies
 * those moves through forceStatus(), with a mandatory reason, and audits them.
 */
class NotaryRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_ASSIGNED   = 'assigned';
    public const STATUS_CORRECTION = 'correction_requested';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_REJECTED   = 'rejected';

    public const TRANSITIONS = [
        self::STATUS_PENDING    => [self::STATUS_ASSIGNED],
        self::STATUS_ASSIGNED   => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CORRECTION],
        self::STATUS_CORRECTION => [self::STATUS_PENDING, self::STATUS_ASSIGNED],
        self::STATUS_APPROVED   => [self::STATUS_COMPLETED],
        self::STATUS_REJECTED   => [],
        self::STATUS_COMPLETED  => [],
    ];

    /** A status holding someone's decision: changing it is an override. */
    public const DECIDED_STATUSES = [self::STATUS_CORRECTION, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_COMPLETED];

    public const DOCUMENT_TYPES = ['affidavit', 'power_of_attorney', 'sale_deed', 'rent_agreement', 'will', 'declaration', 'undertaking', 'indemnity_bond', 'other'];

    protected $table = 'notary_requests';

    // Empty guarded list = every column can be mass-assigned with create()/update().
    protected $guarded = [];

    protected $casts = [
        'decided_at'     => 'datetime',
        'scheduled_date' => 'datetime',
        'service_details' => 'array',
    ];

    // The user who submitted this request.
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // The notary assigned to review this request (nullable).
    public function notary()
    {
        return $this->belongsTo(User::class, 'notary_id');
    }

    // The account that made the latest decision.
    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // The uploaded files attached to this request (documents.request_id).
    public function documents()
    {
        return $this->hasMany(Document::class, 'request_id');
    }

    /** Files not replaced by a newer version: what reviews and certificates are about. */
    public function currentDocuments()
    {
        return $this->documents()->whereNull('superseded_at');
    }

    /**
     * Certificates that can be shown right now: only records the public check would
     * confirm, so a page or PDF can never vouch for what /verify refuses.
     */
    public function validCertificates()
    {
        return $this->documents->whereNotNull('verification_code')
            ->each(fn (Document $document) => $document->setRelation('request', $this))
            ->filter(fn (Document $document) => $document->certificateState() === Document::STATE_VALID)
            ->values();
    }

    /** A completed application whose certificates do not all stand: every page and PDF must say so. */
    public function hasCertificateProblem(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && ($this->withheldCertificates()->isNotEmpty() || ! $this->hasValidCertificate());
    }

    public function hasValidCertificate(): bool
    {
        return $this->validCertificates()->isNotEmpty();
    }

    /** Current records with a code that the public check would not confirm. */
    public function withheldCertificates()
    {
        return $this->documents->whereNull('superseded_at')->whereNotNull('verification_code')
            ->diff($this->validCertificates())->values();
    }

    /**
     * True when nothing here can be shown as a certificate, so the page and the PDF
     * refuse outright. Recorded, as a refusal on a completed application can mean tampering.
     */
    public function certificatesBlocked(): bool
    {
        $this->loadMissing('documents');

        $blocked = ! $this->hasValidCertificate();
        $withheld = $this->withheldCertificates();

        // Once an hour per application, so reloading a page cannot flood the audit log.
        if (($blocked || $withheld->isNotEmpty()) && Cache::add('certificate-blocked:'.$this->id, true, now()->addHour())) {
            AuditLog::record('certificate.blocked', $this, ['documents' => $withheld->pluck('id')->all(), 'all' => $blocked]);
        }

        return $blocked;
    }

    /**
     * Pending, never decided, and nothing on it belongs to the permanent record. Removed
     * files count too: a decision on a file that has since gone is still a decision.
     */
    public function canBeWithdrawn(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->decided_at === null
            && $this->documents()->withTrashed()->withRecordFlags()->get()
                ->each(fn (Document $document) => $document->setRelation('request', $this))
                ->doesntContain(fn (Document $document) => $document->isProtectedRecord());
    }

    /** A withdrawn application has nothing left to meet about. */
    public function cancelOpenAppointments(): void
    {
        $this->appointments()->whereIn('status', ['requested', 'confirmed'])->update(['status' => 'cancelled']);
    }

    /** As recorded at submission, so a later catalogue edit cannot change what this one needed. */
    public function requiredDocumentKeys(): array
    {
        if (isset($this->service_details['required_documents'])) {
            return $this->service_details['required_documents'];
        }

        $service = $this->service_slug ? app(ServiceCatalogue::class)->find($this->service_slug) : null;

        return collect($service['documents'] ?? [])->where('required', true)->pluck('key')->all();
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'request_id');
    }

    /** Status changes for this application, oldest first. */
    public function history()
    {
        return AuditLog::where('model_type', 'NotaryRequest')->where('model_id', $this->id)
            ->where('action', 'like', 'request.%')->with('user')->oldest('id')->get();
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Move to a new status if the transition is allowed. Returns false (and
     * changes nothing) otherwise. Extra attributes are saved in the same update.
     */
    public function transitionTo(string $status, array $extra = []): bool
    {
        if (! $this->canTransitionTo($status)) {
            return false;
        }

        return $this->forceStatus($status, $extra);
    }

    /**
     * Apply a status without checking the lifecycle. Only callers that have
     * authorized the actor may use this: see ApplicationDecisionService.
     */
    public function forceStatus(string $status, array $extra = [], ?string $reason = null, bool $override = false): bool
    {
        $from = $this->getOriginal('status');

        $this->fill(array_merge($extra, ['status' => $status]));

        if (in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CORRECTION], true)) {
            $this->decided_at = now();
        }

        $saved = $this->save();

        // Every status change passes through here, whoever made it.
        if ($saved) {
            AuditLog::record('request.'.$status, $this, [
                'actor_id'        => Auth::id(),
                'actor_role'      => Auth::user()?->role,
                'application_id'  => $this->id,
                'previous_status' => $from,
                'new_status'      => $status,
                'reason'          => $reason,
                'override'        => $override,
                'notary_id'       => $this->notary_id,
            ]);
        }

        return $saved;
    }

    public function isFinal(): bool
    {
        return empty(self::TRANSITIONS[$this->status] ?? []);
    }

    /** Derived from the reference, so every copy of a receipt carries the same number. */
    public function receiptNumber(): string
    {
        return 'RCPT-'.substr((string) $this->request_number, 3);
    }

    /** Unique, human-readable reference such as NR-20261028-7K2Q9M. */
    public static function generateRequestNumber(): string
    {
        do {
            $number = 'NR-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (static::where('request_number', $number)->exists());

        return $number;
    }
}
