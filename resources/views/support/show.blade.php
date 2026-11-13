@extends('layouts.app')

@section('title', $ticket->ticket_number)

@section('content')
@php($admin = auth()->user()->isAdmin())
<div class="en-page-head">
    <div>
        <h1>{{ $ticket->subject }}</h1>
        <p>{{ $ticket->ticket_number }} &middot; {{ __(ucfirst($ticket->category)) }}
            @if($triage) &middot; {{ __('Priority') }}: {{ __(ucfirst($ticket->priority)) }}@endif
            &middot; {{ __(ucfirst($ticket->status)) }}
            @if($ticket->request) &middot; {{ $ticket->request->request_number }}@endif</p>
    </div>
    <a class="btn btn-sm btn-outline-primary" href="{{ route('support.index') }}">{{ __('Back to Tickets') }}</a>
</div>

<div class="row g-3">
    <div class="{{ $admin ? 'col-lg-8' : 'col-12' }}">
        @foreach($ticket->messages as $message)
            <div class="en-msg {{ $message->user?->isAdmin() ? 'is-staff' : '' }}">
                <div class="en-msg-head"><strong>{{ $message->user?->name ?? __('Deleted account') }}</strong>@if($message->user?->isAdmin()) <span class="en-badge en-badge-approved">{{ __('Support team') }}</span>@endif &middot; {{ $message->created_at?->ist('d M Y H:i') }}</div>
                <div style="white-space:pre-line;">{{ $message->body }}</div>
                @if($attachments && $message->attachments->isNotEmpty())
                    <ul class="list-unstyled small mt-2 mb-0">
                        @foreach($message->attachments as $file)
                            <li><i class="fas fa-paperclip me-1" aria-hidden="true"></i><a href="{{ route('support.attachment', [$ticket, $file->id]) }}">{{ $file->original_name }}</a> <span class="text-muted">({{ $file->sizeLabel() }})</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach

        @if($ticket->status === 'closed')
            <div class="alert alert-secondary">{{ __('This ticket is closed.') }}@if($triage && $ticket->closed_at) {{ $ticket->closed_at->ist('d M Y H:i') }}@endif</div>
        @else
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('support.reply', $ticket) }}" enctype="multipart/form-data">
                        @csrf
                        <label class="form-label" for="body">{{ __('Reply') }}</label>
                        <textarea id="body" name="body" class="form-control mb-2 @error('body') is-invalid @enderror" rows="3" required minlength="2" maxlength="2000"></textarea>
                        @error('body')<div class="invalid-feedback mb-2">{{ $message }}</div>@enderror
                        @if($attachments)
                        <div class="mt-2">
                            <label class="form-label small" for="attachments">{{ __('Attachments (optional)') }}</label>
                            <input id="attachments" type="file" name="attachments[]" class="form-control form-control-sm @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="attachments-help">
                            <div id="attachments-help" class="form-text">{{ __('Up to 3 files: PDF, JPG or PNG, 5 MB each. Do not attach real identity documents.') }}</div>
                            @error('attachments')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @foreach($errors->get('attachments.*') as $messages)<div class="invalid-feedback d-block">{{ $messages[0] }}</div>@endforeach
                        </div>
                        @endif
                        <button class="btn btn-primary" type="submit">{{ __('Send Reply') }}</button>
                    </form>
                    <form method="POST" action="{{ route('support.close', $ticket) }}" class="mt-2" data-confirm="{{ __('Close this ticket? No more replies can be added.') }}">
                        @csrf
                        <button class="btn btn-sm btn-link p-0 text-danger" type="submit">{{ __('Close Ticket') }}</button>
                    </form>
                </div>
            </div>
        @endif
    </div>

    @if($admin)
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h2>{{ __('Requester') }}</h2></div>
                <div class="card-body small">
                    @if($ticket->user)
                        <p class="mb-1"><strong>{{ $ticket->user->name }}</strong> &middot; {{ __(['public' => 'Citizen', 'notary' => 'Notary', 'admin' => 'Administrator'][$ticket->user->role] ?? $ticket->user->role) }}</p>
                        <p class="mb-2">{{ $ticket->user->email }}</p>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.show', $ticket->user) }}">{{ __('View account') }}</a>
                    @else
                        <p class="mb-0 text-muted">{{ __('Deleted account') }}</p>
                    @endif
                </div>
            </div>

            @if($triage)
                <div class="card mb-3">
                    <div class="card-header"><h2>{{ __('Triage') }}</h2></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('support.triage', $ticket) }}">
                            @csrf
                            @method('PATCH')
                            <label class="form-label small" for="priority">{{ __('Priority') }}</label>
                            <select id="priority" name="priority" class="form-select form-select-sm mb-2">
                                @foreach(\App\Models\SupportTicket::PRIORITIES as $option)
                                    <option value="{{ $option }}" @selected($ticket->priority === $option)>{{ __(ucfirst($option)) }}</option>
                                @endforeach
                            </select>
                            <label class="form-label small" for="assigned_to">{{ __('Assigned to') }}</label>
                            <select id="assigned_to" name="assigned_to" class="form-select form-select-sm mb-2">
                                <option value="">{{ __('Nobody') }}</option>
                                @foreach($admins as $person)
                                    <option value="{{ $person->id }}" @selected((int) $ticket->assigned_to === $person->id)>{{ $person->name }}@if($person->id === auth()->id()) ({{ __('you') }})@endif</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Save') }}</button>
                        </form>
                    </div>
                </div>
            @endif

            @if($replacement)
                <div class="card mb-3">
                    <div class="card-header"><h2>{{ __('Lost email address') }}</h2></div>
                    <div class="card-body small">
                        <p>{{ __('If this person can no longer open their email, check who they are outside the system first, for example by video call. The replacement step records that check and asks the new address to confirm.') }}</p>
                        <a class="btn btn-sm btn-outline-primary" href="{{ $replacement }}">{{ __('Start lost mailbox replacement') }}</a>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
