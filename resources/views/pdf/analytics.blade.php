@extends('pdf.layout')

@section('body')
<div class="note">{{ __('Counted from the live database when this file was generated. Dates are Indian Standard Time.') }}</div>

@foreach($tables as $section => $rows)
    <h2>{{ $section }}</h2>
    <table class="grid" repeat_header="1">
        <thead><tr>
            @foreach($rows[0] ?? [] as $heading)<th>{{ $heading }}</th>@endforeach
        </tr></thead>
        <tbody>
            @forelse(array_slice($rows, 1) as $i => $row)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    @foreach($row as $cell)<td>{{ $cell }}</td>@endforeach
                </tr>
            @empty
                <tr><td colspan="{{ max(1, count($rows[0] ?? [])) }}" class="empty">{{ __('No records in this period.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endforeach
@endsection
