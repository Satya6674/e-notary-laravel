{{-- Accepted means the provider took the message. Confirmed means the person used it. --}}
@php($tones = ['queued' => 'en-badge-pending', 'sending' => 'en-badge-assigned', 'accepted' => 'en-badge-assigned', 'failed' => 'en-badge-rejected', 'blocked' => 'en-badge-rejected', 'simulated' => 'en-badge-pending'])
<div class="table-responsive">
    <table class="table align-middle mb-0">
        <thead><tr><th>{{ __('Date and Time') }}</th><th>{{ __('Channel') }}</th><th>{{ __('Purpose') }}</th><th>{{ __('Recipient') }}</th><th>{{ __('Status') }}</th><th>{{ __('Confirmed by Person') }}</th><th>{{ __('Error') }}</th></tr></thead>
        <tbody>
            @forelse($deliveries as $delivery)
                <tr>
                    <td class="text-nowrap">{{ $delivery->created_at?->ist() }}</td>
                    <td>{{ $delivery->channel === 'sms' ? 'SMS' : __('Email') }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $delivery->purpose)) }}</td>
                    <td class="text-nowrap">{{ $delivery->recipient }}</td>
                    <td><span class="en-badge {{ $tones[$delivery->status] ?? 'en-badge-pending' }}">{{ __(ucfirst($delivery->status)) }}</span></td>
                    <td class="text-nowrap">{{ $delivery->confirmed_at?->ist() ?? '-' }}</td>
                    <td class="small">{{ $delivery->error ?? '-' }}</td>
                </tr>
            @empty
                <x-ui.empty colspan="7" icon="fa-paper-plane">{{ __('No messages recorded yet.') }}</x-ui.empty>
            @endforelse
        </tbody>
    </table>
</div>
