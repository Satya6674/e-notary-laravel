@extends('pdf.layout')

@section('body')
<table class="fields">
    <tr><td class="k">{{ __('Search') }}</td><td>{{ $filters['search'] ?: '-' }}</td></tr>
    <tr><td class="k">{{ __('From') }}</td><td>{{ $filters['from'] ?: '-' }}</td></tr>
    <tr><td class="k">{{ __('To') }}</td><td>{{ $filters['to'] ?: '-' }}</td></tr>
</table>

@if($total > $entries->count())
    <div class="note">{{ __('Showing the latest :shown of :total records. Narrow the filters to see the rest.', ['shown' => $entries->count(), 'total' => $total]) }}</div>
@endif

<h2>{{ __('Audit Entries') }}</h2>
<table class="grid" repeat_header="1">
    <thead><tr>
        <th style="width:13%;">{{ __('Date and Time') }} (IST)</th><th style="width:15%;">{{ __('User') }}</th><th style="width:10%;">{{ __('Role') }}</th>
        <th style="width:15%;">{{ __('Action') }}</th><th style="width:13%;">{{ __('Subject') }}</th><th>{{ __('Details') }}</th><th style="width:10%;">{{ __('IP Address') }}</th>
    </tr></thead>
    <tbody>
        @forelse($entries as $i => $entry)
            <tr class="{{ $i % 2 ? 'alt' : '' }}">
                <td>{{ $entry->created_at?->ist() }}</td>
                <td>{{ $entry->user?->name ?? __('System') }}</td>
                <td>{{ __($report->role($entry->meta['actor_role'] ?? $entry->user?->role)) }}</td>
                <td>{{ $entry->action }}</td>
                <td>{{ $entry->model_type ? "{$entry->model_type} #{$entry->model_id}" : '-' }}</td>
                <td>{{ collect([$report->auditDetails($entry), $entry->meta['reason'] ?? null])->filter()->implode(' | ') ?: '-' }}</td>
                <td>{{ $entry->ip_address ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">{{ __('No audit entries.') }}</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
