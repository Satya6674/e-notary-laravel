{{-- The document checklist for one service. $draft is the citizen's draft for this service, if any. --}}
@php($draft = $draft ?? null)
<div class="en-req">
    <div class="en-req-head">
        <span class="en-svc-icon" aria-hidden="true"><i class="fas {{ $service['icon'] }}"></i></span>
        <div>
            <h2>{{ __($service['name']) }}</h2>
            @include('partials.service-status', ['service' => $service])
        </div>
    </div>
    <p class="en-req-text">{{ __($service['description']) }}</p>

    @if($service['documents'])
        @foreach(['Mandatory Documents' => true, 'Optional Documents' => false] as $heading => $required)
            @php($rows = array_filter($service['documents'], fn ($document) => $document['required'] === $required))
            @if($rows)
                <h3>{{ __($heading) }}</h3>
                <ul class="en-req-list">
                    @foreach($rows as $document)
                        <li>
                            <span>{{ __($document['name']) }}</span>
                            @if($draft?->file($document['key']))
                                <span class="en-doc-tag is-uploaded">{{ __('File uploaded') }}</span>
                            @elseif($required)
                                <span class="en-doc-tag is-required">{{ __('Required') }}</span>
                            @else
                                <span class="en-doc-tag is-optional">{{ __('Optional') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach

        @php($first = $service['documents'][0])
        <dl class="en-req-meta">
            <div><dt>{{ __('Accepted Formats') }}</dt><dd>{{ strtoupper(implode(', ', $first['formats'])) }}</dd></div>
            <div><dt>{{ __('Maximum File Size') }}</dt><dd>{{ round($first['max_kb'] / 1024, 1) }} MB</dd></div>
        </dl>

        @if($service['instructions'])
            <p class="en-req-note"><i class="fas fa-circle-info me-2" aria-hidden="true"></i>{{ __($service['instructions']) }}</p>
        @endif
        @unless($service['requirements_verified'])
            <p class="en-req-note is-warn"><i class="fas fa-triangle-exclamation me-2" aria-hidden="true"></i>{{ __('These requirements are subject to confirmation. They have not been verified by a legal professional.') }}</p>
        @endunless
    @elseif(! $service['open'])
        <p class="en-req-note">{{ __('This service is not open yet, so no document requirements are published.') }}</p>
    @endif

    {{ $slot ?? '' }}
</div>
