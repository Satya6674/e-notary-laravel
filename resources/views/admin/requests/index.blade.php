@extends('layouts.app')

@section('title', __('Applications'))

@section('content')
<x-ui.page-head :title="__('Applications')" :subtitle="__('Assign requests to notaries and track decisions.')" />

<form method="GET" class="en-toolbar mb-3">
    <input type="text" name="search" class="form-control" placeholder="{{ __('Search by reference, user name or mobile') }}" aria-label="{{ __('Search by reference, user name or mobile') }}" value="{{ $search }}">
    <select name="status" class="form-select" aria-label="{{ __('Status') }}">
        <option value="all" @selected($status === 'all')>{{ __('All Status') }}</option>
        @foreach(array_keys(\App\Models\NotaryRequest::TRANSITIONS) as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ __(ucwords(str_replace('_', ' ', $s))) }}</option>
        @endforeach
    </select>
    <div class="en-toolbar-action"><button type="submit" class="btn btn-primary">{{ __('Filter') }}</button></div>
</form>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('Reference') }}</th>
                    <th>{{ __('User') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Notary') }}</th>
                    <th>{{ __('Submitted') }}</th>
                    <th class="text-end">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $request)
                    <tr>
                        <td class="text-nowrap">{{ $request->request_number }}</td>
                        <td>{{ optional($request->user)->name ?? __('N/A') }}</td>
                        <td>{{ __(ucwords(str_replace('_', ' ', $request->document_type))) }}</td>
                        <td>@include('partials.status-badge', ['status' => $request->status])</td>
                        <td>{{ optional($request->notary)->name ?? '-' }}</td>
                        <td class="text-nowrap">{{ $request->created_at?->ist('d M Y') }}</td>
                        <td>
                            <div class="en-actions">
                                <a href="{{ route('admin.requests.show', $request) }}" class="btn btn-sm btn-outline-primary">
                                    {{ $request->isFinal() ? __('View') : __('Review') }}
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="7" icon="fa-file-signature">{{ __('No requests found.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@if($requests instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $requests->links() }}</div>
@endif
@endsection
