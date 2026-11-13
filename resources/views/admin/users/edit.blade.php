@extends('layouts.app')

@section('title', __('Edit User'))

@section('content')
<x-ui.page-head :title="__('Edit User')">
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>{{ __('Back to Users') }}</a>
</x-ui.page-head>

<div class="row">
    <div class="col-lg-8">
        <x-ui.card>
            <form method="POST" action="{{ route('admin.users.update', $user) }}">
                @csrf
                @method('PUT')

                <x-ui.field name="name" :label="__('Name')" :value="$user->name" required />
                <x-ui.field name="email" type="email" :label="__('Email')" :value="$user->email" />
                <x-ui.field name="mobile" :label="__('Mobile')" :value="$user->mobile" />

                <div class="mb-3">
                    <label class="form-label" for="f-role">{{ __('Role') }} <span class="en-required" aria-hidden="true">*</span></label>
                    <select id="f-role" name="role" class="form-select @error('role') is-invalid @enderror" required>
                        <option value="public" @selected(old('role', $user->role) === 'public')>{{ __('Citizen') }}</option>
                        <option value="notary" @selected(old('role', $user->role) === 'notary')>{{ __('Notary') }}</option>
                        <option value="admin" @selected(old('role', $user->role) === 'admin')>{{ __('Admin') }}</option>
                    </select>
                    @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <hr>

                <x-ui.password-field name="password" :label="__('New Password')" :hint="__('Leave blank to keep the current password.')" requirements autocomplete="new-password" />
                <x-ui.password-field name="password_confirmation" :label="__('Confirm New Password')" autocomplete="new-password" />

                <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
