{{ $greeting ?? 'Hello,' }}

@foreach($introLines as $line)
{{ $line }}

@endforeach
@if(!empty($reference))
Reference: {{ $reference }}

@endif
@if(!empty($actionUrl))
{{ $actionText }}: {{ $actionUrl }}

@endif
@foreach($outroLines as $line)
{{ $line }}

@endforeach
--
{{ config('app.name') }} - {{ config('app.url') }}
E-Notary is an independent software platform. It is not a government service and provides no legally recognised notarisation.
