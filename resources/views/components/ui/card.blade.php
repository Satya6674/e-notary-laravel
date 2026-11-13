@props(['title' => null, 'flush' => false])
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if($title)<div class="card-header"><h2>{{ $title }}</h2></div>@endif
    @if($flush){{ $slot }}@else<div class="card-body">{{ $slot }}</div>@endif
</section>
