@props(['label', 'value', 'icon', 'href' => null, 'alert' => false])
<{{ $href ? 'a' : 'div' }} @if($href) href="{{ $href }}" @endif class="card en-stat {{ $alert ? 'is-alert' : '' }}">
    <span class="en-stat-icon" aria-hidden="true"><i class="fas {{ $icon }}"></i></span>
    <span><span class="en-stat-value d-block">{{ $value }}</span><span class="en-stat-label">{{ $label }}</span></span>
</{{ $href ? 'a' : 'div' }}>
