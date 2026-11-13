@extends('pdf.layout')

@section('body')
@if($withheld)<div class="note">{{ __('Some certificates on this application are no longer valid and are not shown.') }}</div>@endif
@forelse($documents as $index => $document)
    @if($index)<pagebreak />@endif
    <div class="warn" style="text-align:center;">{{ __('NOT LEGALLY VALID. NOT A NOTARISED DOCUMENT.') }}</div>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:10pt;"><tr>
        <td style="vertical-align:top; padding-right:12pt;">
            <table class="fields">
                <tr><td class="k">{{ __('Record Number') }}</td><td>{{ $document->document_number }}</td></tr>
                <tr><td class="k">{{ __('Reference') }}</td><td>{{ $application->request_number }}</td></tr>
                <tr><td class="k">{{ __('Citizen') }}</td><td>{{ $application->user?->name }}</td></tr>
                <tr><td class="k">{{ __('Document') }}</td><td>{{ $document->requirement_key ? __($document->title) : $document->title }}</td></tr>
                <tr><td class="k">{{ __('Document Type') }}</td><td>{{ __($report->label($application->document_type)) }}</td></tr>
                <tr><td class="k">{{ __('Reviewed By') }}</td><td>{{ $application->notary?->name ?? __('Administrator') }}</td></tr>
                <tr><td class="k">{{ __('Issued') }}</td><td>{{ $document->certified_at?->ist('d M Y') }}</td></tr>
                <tr><td class="k">{{ __('Verification Code') }}</td><td>{{ $document->verification_code }}</td></tr>
            </table>
        </td>
        <td style="width:46mm; text-align:center; vertical-align:top;">
            <barcode code="{{ $document->verificationUrl() }}" type="QR" error="M" size="1.1" disableborder="1" />
            <div class="muted" style="font-size:8pt;">{{ __('Scan to check this record') }}</div>
        </td>
    </tr></table>

    <p class="muted" style="margin-top:8pt; font-size:8.5pt;">{{ __('Verify at') }}: {{ $document->verificationUrl() }}</p>
    @if($document->verificationUrlIsLocal())
        <div class="note">{{ __('This link uses a local test address, so it works only on the computer running this copy of E-Notary.') }}</div>
    @endif
    <p class="muted" style="font-size:8.5pt;">{{ __('This check confirms that a record exists on E-Notary. It has no legal effect.') }}</p>
    <p class="muted" style="font-size:8.5pt;">{{ __('This page was produced by E-Notary, an independent software platform. It must not be presented as proof of anything to any person, court, bank or authority.') }}</p>
@empty
    <p class="empty">{{ __('No certificate records are available for this application.') }}</p>
@endforelse
@endsection
