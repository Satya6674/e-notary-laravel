@extends('layouts.app')

@section('title', __('Support Tickets'))

@section('content')
@php
    $admin = auth()->user()->isAdmin();
    $badge = ['open' => 'pending', 'answered' => 'approved', 'closed' => 'completed'];
    $priorityBadge = ['low' => 'completed', 'normal' => 'pending', 'high' => 'rejected', 'urgent' => 'rejected'];
@endphp
<div class="en-page-head"><div><h1>{{ __('Support Tickets') }}</h1><p>{{ $admin ? __('Every ticket, newest activity first.') : __('Ask a question and follow the replies here.') }}</p></div></div>

@if($admin)
    <div class="row g-3 mb-3">
        @foreach(\App\Models\SupportTicket::STATUSES as $state)
            <div class="col-4"><a class="card en-stat" href="{{ route('support.index', ['status' => $state]) }}"><span><span class="en-stat-value d-block">{{ (int) ($counts[$state] ?? 0) }}</span><span class="en-stat-label">{{ __(ucfirst($state)) }}</span></span></a></div>
        @endforeach
    </div>
@else
    <div class="card mb-3">
        <div class="card-header"><h2>{{ __('New Ticket') }}</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('support.store') }}" class="row g-3" enctype="multipart/form-data">
                @csrf
                <div class="col-md-6">
                    <label class="form-label" for="subject">{{ __('Subject') }}</label>
                    <input id="subject" name="subject" class="form-control @error('subject') is-invalid @enderror" required minlength="3" maxlength="200" value="{{ old('subject') }}">
                    @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-{{ $triage ? 2 : 3 }}">
                    <label class="form-label" for="category">{{ __('Category') }}</label>
                    <select id="category" name="category" class="form-select">
                        @foreach(\App\Models\SupportTicket::CATEGORIES as $category)
                            <option value="{{ $category }}" @selected(old('category') === $category)>{{ __(ucfirst($category)) }}</option>
                        @endforeach
                    </select>
                </div>
                @if($triage)
                    <div class="col-md-2">
                        <label class="form-label" for="priority">{{ __('Priority') }}</label>
                        <select id="priority" name="priority" class="form-select">
                            @foreach(\App\Models\SupportTicket::PRIORITIES as $option)
                                <option value="{{ $option }}" @selected(old('priority', 'normal') === $option)>{{ __(ucfirst($option)) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-md-{{ $triage ? 2 : 3 }}">
                    <label class="form-label" for="request_id">{{ __('Application (optional)') }}</label>
                    <select id="request_id" name="request_id" class="form-select">
                        <option value="">{{ __('None') }}</option>
                        @foreach($applications as $application)
                            <option value="{{ $application->id }}" @selected((string) old('request_id') === (string) $application->id)>{{ $application->request_number }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="body">{{ __('Message') }}</label>
                    <textarea id="body" name="body" class="form-control @error('body') is-invalid @enderror" rows="3" required minlength="5" maxlength="2000">{{ old('body') }}</textarea>
                    @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">{{ __('Lost access to your email address? Choose "Account" and say so. An administrator will check your identity before anything changes.') }}</div>
                    @if($attachments)
                    <div class="mt-2">
                        <label class="form-label small" for="attachments">{{ __('Attachments (optional)') }}</label>
                        <input id="attachments" type="file" name="attachments[]" class="form-control form-control-sm @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="attachments-help">
                        <div id="attachments-help" class="form-text">{{ __('Up to 3 files: PDF, JPG or PNG, 5 MB each. Do not attach real identity documents.') }}</div>
                        @error('attachments')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @foreach($errors->get('attachments.*') as $messages)<div class="invalid-feedback d-block">{{ $messages[0] }}</div>@endforeach
                    </div>
                    @endif
                </div>
                <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('Open Ticket') }}</button></div>
            </form>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-header"><h2>{{ __('Tickets') }}</h2></div>
    <div class="card-body border-bottom">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small" for="q">{{ __('Search') }}</label>
                <input id="q" type="search" name="q" class="form-control form-control-sm" maxlength="100" value="{{ $filters['q'] }}" placeholder="{{ $admin ? __('Ticket, subject, name or email') : __('Ticket or subject') }}">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small" for="status">{{ __('Status') }}</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    @foreach(['all', ...\App\Models\SupportTicket::STATUSES] as $option)
                        <option value="{{ $option }}" @selected($filters['status'] === $option)>{{ __(ucfirst($option)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small" for="filter-category">{{ __('Category') }}</label>
                <select id="filter-category" name="category" class="form-select form-select-sm">
                    @foreach(['all', ...\App\Models\SupportTicket::CATEGORIES] as $option)
                        <option value="{{ $option }}" @selected($filters['category'] === $option)>{{ __(ucfirst($option)) }}</option>
                    @endforeach
                </select>
            </div>
            @if($triage)
                <div class="col-6 col-lg-2">
                    <label class="form-label small" for="filter-priority">{{ __('Priority') }}</label>
                    <select id="filter-priority" name="priority" class="form-select form-select-sm">
                        @foreach(['all', ...\App\Models\SupportTicket::PRIORITIES] as $option)
                            <option value="{{ $option }}" @selected($filters['priority'] === $option)>{{ __(ucfirst($option)) }}</option>
                        @endforeach
                    </select>
                </div>
                @if($admin)
                    <div class="col-6 col-lg-2">
                        <label class="form-label small" for="assigned">{{ __('Assigned') }}</label>
                        <select id="assigned" name="assigned" class="form-select form-select-sm">
                            @foreach(['all' => 'Anyone', 'me' => 'Me', 'none' => 'Nobody'] as $option => $label)
                                <option value="{{ $option }}" @selected($filters['assigned'] === $option)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @endif
            <div class="col-6 col-lg-1"><button class="btn btn-sm btn-primary w-100" type="submit">{{ __('Filter') }}</button></div>
        </form>
        @if($admin && ! $triage)
            <p class="small text-muted mb-0 mt-2">{{ __('Priority and assignment become available after a pending database update.') }}</p>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th scope="col">{{ __('Ticket') }}</th><th scope="col">{{ __('Subject') }}</th><th scope="col">{{ __('Category') }}</th>
                @if($admin)<th scope="col">{{ __('From') }}</th>@endif
                @if($triage)<th scope="col">{{ __('Priority') }}</th>@endif
                @if($admin && $triage)<th scope="col">{{ __('Assigned') }}</th>@endif
                <th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Updated') }}</th>
            </tr></thead>
            <tbody>
                @forelse($tickets as $ticket)
                    <tr>
                        <td class="text-nowrap"><a href="{{ route('support.show', $ticket) }}">{{ $ticket->ticket_number }}</a></td>
                        <td>{{ $ticket->subject }}</td>
                        <td>{{ __(ucfirst($ticket->category)) }}</td>
                        @if($admin)<td>{{ $ticket->user?->name ?? __('Deleted account') }}</td>@endif
                        @if($triage)<td><span class="en-badge en-badge-{{ $priorityBadge[$ticket->priority] ?? 'pending' }}">{{ __(ucfirst($ticket->priority)) }}</span></td>@endif
                        @if($admin && $triage)<td>{{ $ticket->assignee?->name ?? '–' }}</td>@endif
                        <td><span class="en-badge en-badge-{{ $badge[$ticket->status] ?? 'pending' }}">{{ __(ucfirst($ticket->status)) }}</span></td>
                        <td class="text-nowrap">{{ $ticket->updated_at?->ist('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">{{ __('No tickets match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $tickets->links() }}</div>
@endsection
