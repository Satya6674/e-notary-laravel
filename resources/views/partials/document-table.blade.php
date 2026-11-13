{{-- $documents, plus $download: fn (Document) => URL. Preview is one route for every role. --}}
<div class="table-responsive">
    <table class="table align-middle">
        <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Size') }}</th><th>{{ __('Uploaded') }}</th><th></th></tr></thead>
        <tbody>
            @forelse($documents as $doc)
                <tr>
                    <td>
                        {{ $doc->requirement_key ? __($doc->title) : ($doc->title ?? __('Untitled')) }}
                        @include('partials.document-version', ['document' => $doc, 'checkFile' => true])
                        @if($doc->file_hash)<small class="d-block text-muted" title="SHA-256">{{ substr($doc->file_hash, 0, 16) }}…</small>@endif
                    </td>
                    <td class="text-nowrap">{{ $doc->file_size ? number_format($doc->file_size / 1024, 1).' KB' : '-' }}</td>
                    <td class="text-nowrap">{{ $doc->created_at?->ist('d M Y') }}</td>
                    <td class="text-end text-nowrap">
                        @if($doc->isPreviewable())
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('documents.preview', $doc) }}" target="_blank" rel="noopener">{{ __('Preview') }}</a>
                        @endif
                        <a class="btn btn-sm btn-primary" href="{{ $download($doc) }}">{{ __('Download') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-3">{{ __('No documents attached.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
