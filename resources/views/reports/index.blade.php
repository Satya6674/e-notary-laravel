@extends('layouts.app')

@section('title', __('Reports'))

@section('content')
@php($query = array_filter($filters))
<div class="en-page-head">
    <div><h1>{{ __('Reports') }}</h1><p>{{ __('Live figures from the database.') }}</p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('reports.csv', $query) }}"><i class="fas fa-file-csv me-1"></i>CSV</a>
        <a class="btn btn-sm btn-primary" href="{{ route('reports.pdf', $query) }}"><i class="fas fa-file-pdf me-1"></i>PDF</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="status">{{ __('Status') }}</label>
                <select id="status" name="status" class="form-select">
                    <option value="">{{ __('All') }}</option>
                    @foreach(array_keys(\App\Models\NotaryRequest::TRANSITIONS) as $option)
                        <option value="{{ $option }}" @selected($filters['status'] === $option)>{{ __(ucwords(str_replace('_', ' ', $option))) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3"><label class="form-label" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" class="form-control" value="{{ $filters['from'] }}"></div>
            <div class="col-md-3"><label class="form-label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" class="form-control" value="{{ $filters['to'] }}"></div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">{{ __('Apply') }}</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card en-stat"><div><div class="en-stat-value">{{ $total }}</div><div class="en-stat-label">{{ __('Total') }}</div></div></div></div>
    @foreach($byStatus as $name => $count)
        <div class="col-6 col-lg-3"><div class="card en-stat"><div><div class="en-stat-value">{{ $count }}</div><div class="en-stat-label">{{ __(ucwords(str_replace('_', ' ', $name))) }}</div></div></div></div>
    @endforeach
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Citizen') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Notary') }}</th><th>{{ __('Submitted') }}</th></tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td class="text-nowrap">{{ $row->request_number }}</td>
                        <td>{{ $row->user?->name }}</td>
                        <td>{{ __(ucwords(str_replace('_', ' ', $row->document_type))) }}</td>
                        <td>@include('partials.status-badge', ['status' => $row->status])</td>
                        <td>{{ $row->notary?->name ?? '-' }}</td>
                        <td class="text-nowrap">{{ $row->created_at?->ist('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No applications match these filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
