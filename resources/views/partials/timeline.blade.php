{{-- $history: audit entries for an application. $staff shows who acted. --}}
<ol class="en-timeline">
    @forelse($history as $entry)
        @php($meta = $entry->meta ?? [])
        <li>
            <strong>{{ __(ucwords(str_replace('_', ' ', $meta['new_status'] ?? str_replace('request.', '', $entry->action)))) }}</strong>
            @if(! empty($meta['override']))<span class="en-override">{{ __('Admin override') }}</span>@endif
            <small>
                {{ $entry->created_at?->ist() }} IST
                @if($staff ?? false)
                    &middot; {{ $entry->user?->name ?? __('Deleted account') }}
                    @if(! empty($meta['actor_role']))({{ $meta['actor_role'] }})@endif
                @endif
            </small>
            @if(! empty($meta['reason']))
                <div class="small mt-1">{{ $meta['reason'] }}</div>
            @endif
        </li>
    @empty
        <li>{{ __('No status changes yet.') }}</li>
    @endforelse
</ol>
