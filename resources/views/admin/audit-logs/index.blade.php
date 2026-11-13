@extends('layouts.app')

@section('title', __('Audit Logs'))

@section('content')
<x-ui.page-head :title="__('Audit Logs')" :subtitle="__('Track important actions performed in the system.')">
    <a href="{{ route('admin.audit-logs.pdf', array_filter(['search' => $search, 'from' => request('from'), 'to' => request('to')])) }}" class="btn btn-outline-primary"><i class="fas fa-file-pdf me-2" aria-hidden="true"></i>{{ __('Export PDF') }}</a>
</x-ui.page-head>

<form method="GET" class="en-toolbar mb-3">
    <input type="text" name="search" class="form-control" placeholder="{{ __('Search by user, action, IP') }}" aria-label="{{ __('Search by user, action, IP') }}" value="{{ $search }}">
    <input type="date" name="from" class="form-control" aria-label="{{ __('From') }}" value="{{ request('from') }}">
    <input type="date" name="to" class="form-control" aria-label="{{ __('To') }}" value="{{ request('to') }}">
    <div class="en-toolbar-action"><button type="submit" class="btn btn-primary">{{ __('Search') }}</button></div>
</form>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('User') }}</th>
                    <th>{{ __('Action') }}</th>
                    <th>{{ __('Subject') }}</th>
                    <th>{{ __('Reason') }}</th>
                    <th>{{ __('IP Address') }}</th>
                    <th>{{ __('Date') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td>{{ optional($log->user)->name ?? __('System') }}</td>
                        <td>
                            {{ $log->action ?? '-' }}
                            @if(! empty($log->meta['override']))
                                <span class="en-override">{{ __('Admin override') }}</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $log->model_type ? $log->model_type.' #'.$log->model_id : '-' }}</td>
                        <td>{{ $log->meta['reason'] ?? '-' }}</td>
                        <td class="text-nowrap">{{ $log->ip_address ?? '-' }}</td>
                        <td class="text-nowrap">{{ $log->created_at?->ist('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <x-ui.empty colspan="6" icon="fa-clipboard-list">{{ __('No audit logs found.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@if($logs instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $logs->links() }}</div>
@endif
@endsection
