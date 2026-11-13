@extends('layouts.app')

@section('title', __('My Documents'))

@section('content')
<x-ui.page-head :title="__('My Documents')" />

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('Document Title') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Uploaded Date') }}</th>
                    <th class="text-end">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td>{{ $document->title ?? optional($document->request)->purpose ?? __('Document') }} @include('partials.document-version')</td>
                        <td>{{ $document->request ? __(ucwords(str_replace('_', ' ', $document->request->document_type))) : '-' }}</td>
                        <td>@include('partials.status-badge', ['status' => $document->request?->status ?? 'pending'])</td>
                        <td class="text-nowrap">{{ $document->created_at?->ist('d M Y') }}</td>
                        <td>
                            <div class="en-actions">
                                <a href="{{ route('user.documents.show', $document) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                @if($document->isPreviewable())<a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">{{ __('Preview') }}</a>@endif
                                <a href="{{ route('user.documents.download', $document) }}" class="btn btn-sm btn-outline-success">{{ __('Download') }}</a>
                                @unless($document->isProtectedRecord())
                                    <form action="{{ route('user.documents.destroy', $document) }}" method="POST" data-confirm="{{ __('Delete this document?') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="5" icon="fa-folder-open">{{ __('No documents found.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@if($documents instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $documents->links() }}</div>
@endif
@endsection
