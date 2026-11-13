{{-- Recorded status changes only. $staff adds who acted; citizens see the role. --}}
<table class="grid" repeat_header="1">
    <thead><tr>
        <th style="width:21%;">{{ __('Date and Time') }} (IST)</th>
        <th style="width:21%;">{{ __('Status') }}</th>
        <th style="width:{{ $staff ? 18 : 16 }}%;">{{ __($staff ? 'Reviewed By' : 'Role') }}</th>
        <th>{{ __('Reason') }}</th>
    </tr></thead>
    <tbody>
        @forelse($history as $i => $entry)
            <tr class="{{ $i % 2 ? 'alt' : '' }}">
                <td>{{ $entry['when'] }}</td>
                <td>{{ __($entry['status']) }}@if($entry['override']) <span class="tag">({{ __('Admin override') }})</span>@endif</td>
                <td>{{ $staff ? $entry['name'] : __($report->role($entry['role'])) }}</td>
                <td>{{ $entry['reason'] ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">{{ __('No status changes yet.') }}</td></tr>
        @endforelse
    </tbody>
</table>
