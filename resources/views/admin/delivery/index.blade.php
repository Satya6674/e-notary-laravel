@extends('layouts.app')

@section('title', __('Email Delivery'))

@section('content')
<x-ui.page-head :title="__('Email Delivery')" :subtitle="__('Emails waiting to be handed to the mail server, and any that failed.')" />

<div class="en-notice mb-3">{{ __('Waiting means an email is in the queue. It is not delivered. Emails leave only while a queue worker is running, and this page cannot confirm that a message reached an inbox.') }}</div>

@if($held)<div class="en-notice mb-3">{{ __('Held on Other Queues') }}: {{ $held }}</div>@endif

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-4"><x-ui.stat :label="__('Waiting in Queue')" :value="$waiting" icon="fa-hourglass-half" /></div>
    <div class="col-6 col-lg-4"><x-ui.stat :label="__('Failed')" :value="$total" icon="fa-triangle-exclamation" :alert="$total > 0" /></div>
    <div class="col-12 col-lg-4"><x-ui.stat :label="__('Oldest Waiting')" :value="$oldest" icon="fa-clock" /></div>
</div>

<x-ui.card :title="__('Email and SMS History')" class="mb-3" flush>
    @include('admin.security.deliveries', ['deliveries' => $history])
</x-ui.card>

<x-ui.card :title="__('Failed Emails')" flush>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>{{ __('Type') }}</th><th>{{ __('Failed At') }}</th><th>{{ __('Error') }}</th><th></th></tr></thead>
            <tbody>
                @forelse($failed as $job)
                    <tr>
                        <td>{{ $job->type }}</td>
                        <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($job->failed_at)->ist() }}</td>
                        <td class="small">{{ $job->error }}</td>
                        <td class="text-end"><form method="POST" action="{{ route('admin.delivery.retry', $job->uuid) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">{{ __('Retry') }}</button></form></td>
                    </tr>
                @empty
                    <x-ui.empty colspan="4" icon="fa-circle-check">{{ __('No failed emails.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
<div class="mt-3">{{ $failed->links() }}</div>
@endsection
