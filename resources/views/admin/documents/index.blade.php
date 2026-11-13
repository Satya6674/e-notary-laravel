@extends('layouts.app')

@section('title', __('Documents'))

@section('content')
<x-ui.page-head :title="__('Documents')" :subtitle="__('View and manage uploaded documents.')" />

<form method="GET" class="en-toolbar mb-3">
    <input type="text" name="search" class="form-control" placeholder="{{ __('Search by title or type') }}" aria-label="{{ __('Search by title or type') }}" value="{{ $search }}">
    <select name="status" class="form-select" aria-label="{{ __('Status') }}">
        <option value="all" @selected($status === 'all')>{{ __('All Status') }}</option>
        <option value="pending" @selected($status === 'pending')>{{ __('Pending') }}</option>
        <option value="approved" @selected($status === 'approved')>{{ __('Approved') }}</option>
        <option value="rejected" @selected($status === 'rejected')>{{ __('Rejected') }}</option>
    </select>
    <div class="en-toolbar-action"><button type="submit" class="btn btn-primary">{{ __('Filter') }}</button></div>
</form>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('Title') }}</th>
                    <th>{{ __('Uploaded By') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Uploaded') }}</th>
                    <th class="text-end">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td>{{ $document->title ?? __('Untitled') }} @include('partials.document-version')</td>
                        <td>{{ optional(optional($document->request)->user)->name ?? __('N/A') }}</td>
                        <td>{{ __(ucwords(str_replace('_', ' ', $document->request?->document_type ?? 'other'))) }}</td>
                        <td>@include('partials.status-badge', ['status' => $document->request?->status ?? 'pending'])</td>
                        <td class="text-nowrap">{{ $document->created_at?->ist('d M Y') }}</td>
                        <td>
                            <div class="en-actions">
                                <a href="{{ route('admin.documents.show', $document) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                @if($document->isPreviewable())<a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">{{ __('Preview') }}</a>@endif
                                <a href="{{ route('admin.documents.download', $document) }}" class="btn btn-sm btn-outline-success">{{ __('Download') }}</a>
                                @unless(in_array($document->request?->status, ['approved', 'completed'], true) || $document->isProtectedRecord())
                                <form action="{{ route('admin.documents.destroy', $document) }}" method="POST" data-confirm="{{ __('Remove this document?') }}" data-reason>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button>
                                </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="6" icon="fa-folder-open">{{ __('No documents found.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@if($documents instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $documents->links() }}</div>
@endif
@endsection
