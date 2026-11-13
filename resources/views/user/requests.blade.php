@extends('layouts.app')

@section('title', __('My Applications'))

@section('content')
<div class="en-page-head">
    <div><h1>{{ __('My Applications') }}</h1></div>
    <a class="btn btn-primary" href="{{ route('user.services.index') }}"><i class="fas fa-plus me-2"></i>{{ __('New Application') }}</a>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET">
            <select name="status" class="form-select form-select-sm" style="max-width:240px;" aria-label="{{ __('Status') }}" onchange="this.form.submit()">
                <option value="all">{{ __('All') }}</option>
                @foreach(array_keys(\App\Models\NotaryRequest::TRANSITIONS) as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ __(ucwords(str_replace('_', ' ', $option))) }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Title') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Submitted') }}</th><th></th></tr></thead>
            <tbody>
                @forelse($requests as $application)
                    <tr>
                        <td class="text-nowrap">{{ $application->request_number }}</td>
                        <td>{{ $application->documents->first()?->title ?? '-' }}</td>
                        <td>{{ __(ucwords(str_replace('_', ' ', $application->document_type))) }}</td>
                        <td>@include('partials.status-badge', ['status' => $application->status])</td>
                        <td class="text-nowrap">{{ $application->created_at?->ist('d M Y') }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('user.requests.show', $application) }}">{{ __('View') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No applications yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $requests->links() }}</div>
@endsection
