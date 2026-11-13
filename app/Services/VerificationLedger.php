<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Append-only history of verifications (verification_events). A row is never updated, so a
 * future SMS verification adds its own row and the earlier email fallback stays on record.
 * Stores a masked target and a hash, never the address or number itself, and never a code.
 */
class VerificationLedger
{
    public const PURPOSE_EMAIL = 'email';

    public const PURPOSE_MOBILE = 'mobile';

    private static ?bool $supported = null;

    public static function supported(): bool
    {
        return self::$supported ??= Schema::hasTable('verification_events');
    }

    private static ?bool $manualSupported = null;

    /** Has 000030 added who acted, why, and the status before and after? */
    public static function manualSupported(): bool
    {
        return self::$manualSupported ??= self::supported() && Schema::hasColumn('verification_events', 'actor_id');
    }

    public static function forgetSchemaState(): void
    {
        self::$supported = self::$manualSupported = null;
    }

    /**
     * An Admin's manual verification. Unlike record(), a failure is thrown, so the caller's transaction
     * rolls the verification back instead of keeping a change with no history row.
     *
     * @param  array{actor_id: int, reason: string, evidence_method: string, previous_status: string, new_status: string}  $admin
     */
    public function recordManual(User $user, string $purpose, array $admin): void
    {
        $target = (string) ($purpose === self::PURPOSE_EMAIL ? $user->email : $user->mobile);

        DB::table('verification_events')->insert([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'method' => User::METHOD_ADMIN_MANUAL,
            'target_masked' => $purpose === self::PURPOSE_EMAIL ? DeliveryLog::maskEmail($target) : SmsService::mask($target),
            'target_hash' => self::targetHash($purpose, $target),
            // Nothing was delivered: the Admin confirmed ownership by other means.
            'delivered_via' => 'admin',
            'sms_status' => $purpose === self::PURPOSE_MOBILE ? User::SMS_NOT_SENT : null,
            'actor_id' => $admin['actor_id'],
            'reason' => $admin['reason'],
            'evidence_method' => $admin['evidence_method'],
            'previous_status' => $admin['previous_status'],
            'new_status' => $admin['new_status'],
            'verified_at' => now(),
            'created_at' => now(),
        ]);
    }

    public static function targetHash(string $purpose, string $target): string
    {
        return hash('sha256', $purpose.':'.strtolower(trim($target)));
    }

    /**
     * One account's history, newest first, for the read-only Admin view. Masked targets only: the
     * hash column is never read. Empty when the table does not exist yet.
     *
     * @return Collection<int, object>
     */
    public function history(User $user, int $limit = 50): Collection
    {
        if (! self::supported()) {
            return collect();
        }

        $columns = ['id', 'purpose', 'method', 'target_masked', 'delivered_via', 'sms_status', 'verified_at', 'created_at'];

        // Who acted and why, once 000030 has run. The acting Admin is shown by name, never by contact.
        if (self::manualSupported()) {
            array_push($columns, 'actor_id', 'reason', 'evidence_method', 'previous_status', 'new_status');
        }

        $rows = DB::table('verification_events')->where('user_id', $user->id)
            ->orderByDesc('id')->limit($limit)->get($columns);

        $names = User::whereIn('id', $rows->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return $rows->each(fn ($row) => $row->actor_name = isset($row->actor_id) ? ($names[$row->actor_id] ?? null) : null);
    }

    /**
     * The account holder's own verification history, for the privacy export. Masked targets only.
     * Never the hash, never a code, and never which administrator acted or the reason they wrote:
     * a manual check reads "an administrator", with how ownership was confirmed. Empty before 000029.
     *
     * @return list<array<string, mixed>>
     */
    public function exportFor(User $user): array
    {
        if (! self::supported()) {
            return [];
        }

        $columns = ['purpose', 'method', 'target_masked', 'delivered_via', 'sms_status', 'verified_at'];
        if (self::manualSupported()) {
            array_push($columns, 'actor_id', 'evidence_method', 'previous_status', 'new_status');
        }

        return DB::table('verification_events')->where('user_id', $user->id)->orderBy('id')->get($columns)
            ->map(fn ($row) => [
                'purpose' => $row->purpose,
                'method' => $row->method,
                'address_or_number' => $row->target_masked,
                'delivered_via' => $row->delivered_via,
                'sms_status' => $row->sms_status,
                'verified_at' => $row->verified_at ? \Illuminate\Support\Carbon::parse($row->verified_at)->toIso8601String() : null,
            ] + (isset($row->actor_id) ? [
                'verified_by' => 'administrator',
                'ownership_confirmed_by' => $row->evidence_method,
                'status_change' => $row->previous_status.' -> '.$row->new_status,
            ] : []))->values()->all();
    }

    public function record(User $user, string $purpose, string $method, string $deliveredVia, ?string $smsStatus = null): void
    {
        if (! self::supported()) {
            return;
        }

        $target = (string) ($purpose === self::PURPOSE_EMAIL ? $user->email : $user->mobile);

        try {
            DB::table('verification_events')->insert([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'method' => $method,
                'target_masked' => $purpose === self::PURPOSE_EMAIL ? DeliveryLog::maskEmail($target) : SmsService::mask($target),
                'target_hash' => self::targetHash($purpose, $target),
                'delivered_via' => $deliveredVia,
                'sms_status' => $smsStatus,
                'verified_at' => now(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The verification itself stands; the history row is a record, not a gate.
            report($e);
        }
    }
}
