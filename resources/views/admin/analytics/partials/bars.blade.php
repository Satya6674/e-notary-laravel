{{--
    Stacked bar chart drawn with CSS, so it needs no script and prints as shown.
    $series: list of ['label' => ..., key => count, ...]; $keys: key => [legend text, CSS colour].
    The same numbers are in the table below the chart for screen readers and for copying.
--}}
@php
    $totals = array_map(fn ($bucket) => array_sum(array_map(fn ($k) => $bucket[$k] ?? 0, array_keys($keys))), $series);
    $peak = max([1, ...$totals]);
@endphp
<div class="en-chart" role="img" aria-label="{{ $caption }}">
    <div class="en-chart-bars" style="--en-bars: {{ max(1, count($series)) }};">
        @foreach($series as $i => $bucket)
            <div class="en-chart-col" title="{{ $bucket['label'] }}: {{ $totals[$i] }}">
                <div class="en-chart-stack" style="height: {{ round($totals[$i] / $peak * 100, 2) }}%;">
                    @foreach($keys as $key => [$legend, $colour])
                        @if(($bucket[$key] ?? 0) > 0)
                            <span style="flex: {{ $bucket[$key] }}; background: {{ $colour }};"></span>
                        @endif
                    @endforeach
                </div>
                @if(count($series) <= 16)<small class="en-chart-label">{{ $bucket['label'] }}</small>@endif
            </div>
        @endforeach
    </div>
    <div class="en-chart-legend">
        @foreach($keys as [$legend, $colour])
            <span><i style="background: {{ $colour }};" aria-hidden="true"></i>{{ $legend }}</span>
        @endforeach
        <span class="text-muted">{{ __('Highest') }}: {{ $totals ? max($totals) : 0 }}</span>
    </div>
</div>
<details class="mt-2">
    <summary class="small">{{ __('Show figures') }}</summary>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <caption class="visually-hidden">{{ $caption }}</caption>
            <thead><tr><th scope="col">{{ __('Period') }}</th>@foreach($keys as [$legend])<th scope="col" class="text-end">{{ $legend }}</th>@endforeach<th scope="col" class="text-end">{{ __('Total') }}</th></tr></thead>
            <tbody>
                @foreach($series as $i => $bucket)
                    <tr><td>{{ $bucket['label'] }}</td>@foreach(array_keys($keys) as $key)<td class="text-end">{{ $bucket[$key] ?? 0 }}</td>@endforeach<td class="text-end fw-semibold">{{ $totals[$i] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</details>
