@extends('layouts.app')

@section('title', __('Manage Users'))

@section('content')
@php
    $roleLabels = ['public' => __('Citizen'), 'notary' => __('Notary'), 'admin' => __('Admin')];
@endphp

<x-ui.page-head :title="__('Manage Users')" :subtitle="__('View and manage all registered users.')" />

<form method="GET" class="en-toolbar mb-3">
    <input type="text" name="search" class="form-control" placeholder="{{ __('Search by name, email, mobile') }}" aria-label="{{ __('Search by name, email, mobile') }}" value="{{ $search }}">
    <select name="role" class="form-select" aria-label="{{ __('Role') }}">
        <option value="all" @selected($role === 'all')>{{ __('All Roles') }}</option>
        @foreach($roleLabels as $value => $label)
            <option value="{{ $value }}" @selected($role === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="state" class="form-select" aria-label="{{ __('Status') }}">
        <option value="all" @selected($state === 'all')>{{ __('All Status') }}</option>
        <option value="active" @selected($state === 'active')>{{ __('Active') }}</option>
        <option value="deactivated" @selected($state === 'deactivated')>{{ __('Deactivated') }}</option>
    </select>
    <div class="en-toolbar-action"><button type="submit" class="btn btn-primary">{{ __('Filter') }}</button></div>
    @if(\App\Models\User::softDeleteSupported())
        <div class="en-toolbar-action"><a href="{{ route('admin.users.deleted') }}" class="btn btn-outline-secondary">{{ __('Deleted Users') }}</a></div>
    @endif
</form>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('ID') }}</th>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Email') }}</th>
                    <th>{{ __('Mobile') }}</th>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Created') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>{{ $user->id }}</td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td class="text-nowrap">{{ $user->mobile }}</td>
                        <td>{{ $roleLabels[$user->role] ?? $user->role }}</td>
                        <td>
                            @if($user->deactivated_at)
                                <span class="en-badge en-badge-rejected">{{ __('Deactivated') }}</span>
                            @else
                                <span class="en-badge en-badge-approved">{{ __('Active') }}</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $user->created_at?->ist('d M Y') }}</td>
                        <td>
                            <div class="en-actions">
                                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                @unless($user->isProtectedAccount() && $user->id !== auth()->id())<a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit') }}</a>@endunless
                                @if($user->deactivated_at)
                                    <form action="{{ route('admin.users.reactivate', $user) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Reactivate') }}</button>
                                    </form>
                                @elseif($user->id !== auth()->id() && ! $user->isProtectedAccount())
                                    @php
                                        $deactivateText = __('Deactivate this account? The person will not be able to sign in until it is reactivated.');
                                        if ($user->role === 'notary') {
                                            $deactivateText .= ' '.__('This Notary has :applications open applications and :appointments open appointments. They stay assigned until you reassign them.', ['applications' => $user->open_applications_count, 'appointments' => $user->open_appointments_count]);
                                        }
                                    @endphp
                                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST" data-confirm="{{ $deactivateText }}" data-reason>
                                        @csrf
                                        @method('DELETE')
                                        @if($user->role === 'notary')
                                            {{-- The counts in the confirmation above. The server refuses if the work has changed since. --}}
                                            <input type="hidden" name="open_applications" value="{{ $user->open_applications_count }}">
                                            <input type="hidden" name="open_appointments" value="{{ $user->open_appointments_count }}">
                                        @endif
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Deactivate') }}</button>
                                    </form>
                                @endif
                                @can('delete', $user)
                                    <button type="button" class="btn btn-sm btn-danger" data-delete-user data-action="{{ route('admin.users.delete', $user) }}" data-name="{{ $user->name }}" data-email="{{ $user->email }}" data-role="{{ $roleLabels[$user->role] ?? $user->role }}">{{ __('Delete') }}</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="8" icon="fa-users">{{ __('No users found.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@if($users instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $users->links() }}</div>
@endif

@include('admin.users.partials.delete-modal')
@endsection
