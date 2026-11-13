@props(['icon' => 'fa-inbox', 'colspan' => null])
@if($colspan)
    <tr><td colspan="{{ $colspan }}"><div class="en-empty"><i class="fas {{ $icon }}" aria-hidden="true"></i>{{ $slot }}</div></td></tr>
@else
    <div class="en-empty"><i class="fas {{ $icon }}" aria-hidden="true"></i>{{ $slot }}</div>
@endif
