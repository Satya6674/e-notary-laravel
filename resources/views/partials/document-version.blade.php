{{-- Version label for $document. Every file in a history says what became of it. --}}
@php($lifecycle = $document->lifecycleStatus($checkFile ?? false))
@if($lifecycle)
    <span class="en-badge {{ ['superseded' => 'en-badge-pending', 'certified' => 'en-badge-approved'][$lifecycle] ?? 'en-badge-rejected' }}">{{ __(['superseded' => 'Superseded', 'rejected' => 'Rejected', 'revoked' => 'Revoked', 'certified' => 'Certified', 'integrity_failed' => 'Integrity check failed'][$lifecycle]) }}</span>
@endif
@if($document->version > 1 || ! $document->isCurrent())
    <small class="d-block text-muted">{{ __('Version :number', ['number' => $document->version]) }}</small>
@endif
