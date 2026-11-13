<?php

namespace App\Services;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\EmailReplacement;
use App\Models\User;
use App\Support\Platform;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Figures for the Admin analytics page and its exports. Every number is counted from the
 * database at the moment it is asked for; nothing here is stored or estimated.
 *
 * Dates are grouped by the Indian calendar day (Platform::DISPLAY_TIMEZONE), because that is
 * the day people see on every page. The database keeps UTC, so the grouping is done here in
 * PHP rather than with a database date function, which would group by the UTC day.
 *
 * A figure whose table does not exist yet (a migration not applied) is null, never 0, so the
 * page can say "not available" instead of reporting a false zero.
 */
class Analytics
{
    /** A notary decision is the latest of these audit actions for that account. */
    public const NOTARY_APPROVED = 'admin.notary_approved';

    public const NOTARY_REJECTED = 'admin.notary_unapproved';

    /** Longest range the page accepts, so a request cannot ask for unbounded work. */
    public const MAX_RANGE_DAYS = 731;

    /** Daily figures are listed only for ranges up to this length. */
    public const MAX_DAILY_DAYS = 92;

    /** Default range when no dates are given: the last 90 days including today. */
    public const DEFAULT_DAYS = 90;

    public function range(?string $from, ?string $to): array
    {
        $zone = Platform::DISPLAY_TIMEZONE;
        $end = $to ? CarbonImmutable::parse($to, $zone)->endOfDay() : CarbonImmutable::now($zone)->endOfDay();
        $start = $from ? CarbonImmutable::parse($from, $zone)->startOfDay() : $end->subDays(self::DEFAULT_DAYS - 1)->startOfDay();

        return [$start, $end];
    }

    /** Current totals, whatever the selected dates. */
    public function snapshot(): array
    {
        $notaries = User::where('role', 'notary')->notDeleted();
        $decisions = $this->latestNotaryDecisions();
        $unapproved = (clone $notaries)->whereNull('notary_approved_at')->pluck('id');
        $rejected = $unapproved->filter(fn (int $id) => ($decisions[$id] ?? null) === self::NOTARY_REJECTED)->count();
        $appointments = Appointment::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'citizens'          => User::where('role', 'public')->notDeleted()->count(),
            'notaries'          => (clone $notaries)->count(),
            'approved_notaries' => (clone $notaries)->whereNotNull('notary_approved_at')->count(),
            'pending_notaries'  => $unapproved->count() - $rejected,
            'rejected_notaries' => $rejected,
            'deactivated'       => User::whereIn('role', ['public', 'notary'])->notDeleted()->whereNotNull('deactivated_at')->count(),
            'appointments'      => [
                'total'     => (int) $appointments->sum(),
                'pending'   => (int) (($appointments['requested'] ?? 0) + ($appointments['confirmed'] ?? 0)),
                'requested' => (int) ($appointments['requested'] ?? 0),
                'confirmed' => (int) ($appointments['confirmed'] ?? 0),
                'completed' => (int) ($appointments['completed'] ?? 0),
                'cancelled' => (int) ($appointments['cancelled'] ?? 0),
            ],
            'deleted_accounts'  => User::softDeleteSupported() ? User::whereNotNull('deleted_at')->count() : null,
            'deletions_total'   => $this->has('account_deletions') ? AccountDeletion::count() : null,
            'restored_accounts' => $this->has('account_deletions') ? AccountDeletion::whereNotNull('restored_at')->count() : null,
            'recovery'          => User::accountRecoverySupported() ? $this->statusCounts(AccountRecoveryRequest::query()) : null,
            'replacements'      => EmailReplacement::supported() ? $this->statusCounts(EmailReplacement::query()) : null,
        ];
    }

    /** Figures for the selected dates. */
    public function period(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $users = User::whereIn('role', ['public', 'notary'])
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->get(['id', 'role', 'created_at']);

        $decisions = AuditLog::whereIn('action', [self::NOTARY_APPROVED, self::NOTARY_REJECTED])
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->get(['action', 'created_at']);

        $appointments = Appointment::whereBetween('scheduled_at', [$start->utc(), $end->utc()])
            ->get(['id', 'notary_id', 'status', 'scheduled_at']);

        $days = (int) $start->diffInDays($end) + 1;

        return [
            'days'          => $days,
            'registrations' => [
                'total'    => $users->count(),
                'citizens' => $users->where('role', 'public')->count(),
                'notaries' => $users->where('role', 'notary')->count(),
                'daily'    => $days <= self::MAX_DAILY_DAYS ? $this->series($users, 'created_at', $start, $end, 'day') : null,
                'weekly'   => $this->series($users, 'created_at', $start, $end, 'week'),
                'monthly'  => $this->series($users, 'created_at', $start, $end, 'month'),
            ],
            'decisions'     => $this->decisionSeries($decisions, $start, $end),
            'appointments'  => [
                'total'      => $appointments->count(),
                'byStatus'   => collect(Appointment::STATUSES)->mapWithKeys(fn ($s) => [$s => $appointments->where('status', $s)->count()])->all(),
                'monthly'    => $this->appointmentMonths($appointments, $start, $end),
                'byNotary'   => $this->byNotary($appointments),
                'completion' => $this->completionRate($appointments),
            ],
        ];
    }

    /**
     * Completed as a share of appointments that reached an outcome (completed or cancelled).
     * Open appointments are left out, as they have no outcome yet. Null when there is none.
     */
    public function completionRate(Collection $appointments): ?float
    {
        $done = $appointments->whereIn('status', ['completed', 'cancelled'])->count();

        return $done ? round($appointments->where('status', 'completed')->count() / $done * 100, 1) : null;
    }

    /** Each bucket is keyed by its label; values are counts of citizens and notaries. */
    private function series(Collection $rows, string $column, CarbonImmutable $start, CarbonImmutable $end, string $unit): array
    {
        $buckets = [];

        foreach ($this->buckets($start, $end, $unit) as $key => $label) {
            $buckets[$key] = ['label' => $label, 'citizens' => 0, 'notaries' => 0];
        }

        foreach ($rows as $row) {
            $key = $this->bucketKey($row->{$column}, $unit);

            if (isset($buckets[$key])) {
                $buckets[$key][$row->role === 'notary' ? 'notaries' : 'citizens']++;
            }
        }

        return array_values($buckets);
    }

    private function decisionSeries(Collection $decisions, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = [];

        foreach ($this->buckets($start, $end, 'month') as $key => $label) {
            $buckets[$key] = ['label' => $label, 'approved' => 0, 'rejected' => 0];
        }

        foreach ($decisions as $entry) {
            $key = $this->bucketKey($entry->created_at, 'month');

            if (isset($buckets[$key])) {
                $buckets[$key][$entry->action === self::NOTARY_APPROVED ? 'approved' : 'rejected']++;
            }
        }

        return [
            'approved' => $decisions->where('action', self::NOTARY_APPROVED)->count(),
            'rejected' => $decisions->where('action', self::NOTARY_REJECTED)->count(),
            'monthly'  => array_values($buckets),
        ];
    }

    private function appointmentMonths(Collection $appointments, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = [];

        foreach ($this->buckets($start, $end, 'month') as $key => $label) {
            $buckets[$key] = ['label' => $label] + array_fill_keys(Appointment::STATUSES, 0);
        }

        foreach ($appointments as $appointment) {
            $key = $this->bucketKey($appointment->scheduled_at, 'month');

            if (isset($buckets[$key]) && in_array($appointment->status, Appointment::STATUSES, true)) {
                $buckets[$key][$appointment->status]++;
            }
        }

        return array_values($buckets);
    }

    private function byNotary(Collection $appointments): array
    {
        $names = User::whereIn('id', $appointments->pluck('notary_id')->filter()->unique())->pluck('name', 'id');

        return $appointments->groupBy(fn ($a) => (int) $a->notary_id)->map(function (Collection $group, int $id) use ($names) {
            return [
                'name'       => $id === 0 ? __('Not assigned') : ($names[$id] ?? __('Deleted account')),
                'total'      => $group->count(),
                'open'       => $group->whereIn('status', ['requested', 'confirmed'])->count(),
                'completed'  => $group->where('status', 'completed')->count(),
                'cancelled'  => $group->where('status', 'cancelled')->count(),
                'completion' => $this->completionRate($group),
            ];
        })->sortByDesc('total')->values()->all();
    }

    /** @return array<string, string> bucket key => readable label, in order */
    private function buckets(CarbonImmutable $start, CarbonImmutable $end, string $unit): array
    {
        $first = match ($unit) {
            'day'   => $start->startOfDay(),
            'week'  => $start->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $start->startOfMonth(),
        };

        $labels = [];

        foreach (CarbonPeriod::create($first, '1 '.$unit, $end) as $moment) {
            $moment = CarbonImmutable::instance($moment)->locale(app()->getLocale());
            // translatedFormat gives Hindi and Punjabi month names; English stays "Oct".
            $labels[$this->bucketKey($moment, $unit)] = match ($unit) {
                'day'   => $moment->translatedFormat('d M'),
                'week'  => __('Week of').' '.$moment->translatedFormat('d M Y'),
                'month' => $moment->translatedFormat('M Y'),
            };
        }

        return $labels;
    }

    private function bucketKey($moment, string $unit): string
    {
        $local = CarbonImmutable::instance($moment)->setTimezone(Platform::DISPLAY_TIMEZONE);

        return match ($unit) {
            'day'   => $local->format('Y-m-d'),
            'week'  => $local->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
            'month' => $local->format('Y-m'),
        };
    }

    /** @return array<int, string> notary id => latest decision action */
    private function latestNotaryDecisions(): array
    {
        return AuditLog::whereIn('action', [self::NOTARY_APPROVED, self::NOTARY_REJECTED])
            ->where('model_type', 'User')
            ->orderBy('id')
            ->get(['model_id', 'action'])
            ->mapWithKeys(fn ($entry) => [(int) $entry->model_id => $entry->action])
            ->all();
    }

    private function statusCounts($query): array
    {
        $counts = $query->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);

        return ['total' => (int) $counts->sum()] + $counts->all();
    }

    private function has(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
