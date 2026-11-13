@props(['title', 'subtitle' => null])
<div class="en-page-head">
    <div>
        <h1>{{ $title }}</h1>
        @if($subtitle)<p>{{ $subtitle }}</p>@endif
    </div>
    @if($slot->isNotEmpty())<div class="en-actions">{{ $slot }}</div>@endif
</div>
