@extends('layouts.app')

@section('title', __('Permissions'))

@section('content')
<x-ui.page-head :title="__('Permissions')" :subtitle="__('What each role can do. Roles are fixed in the application and enforced by its policies.')">
    <a class="btn btn-outline-primary" href="{{ route('admin.security.index') }}">{{ __('Back to Security') }}</a>
</x-ui.page-head>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4"><x-ui.stat :label="__('Citizens')" :value="$counts['public'] ?? 0" icon="fa-user" /></div>
    <div class="col-12 col-md-4"><x-ui.stat :label="__('Notaries')" :value="$counts['notary'] ?? 0" icon="fa-user-tie" /></div>
    <div class="col-12 col-md-4"><x-ui.stat :label="__('Administrators')" :value="$counts['admin'] ?? 0" icon="fa-user-gear" /></div>
</div>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('Action') }}</th><th>{{ __('Citizen') }}</th><th>{{ __('Notary') }}</th><th>{{ __('Administrator') }}</th></tr></thead>
            <tbody>
                @foreach($permissions as $action => $roles)
                    <tr><td>{{ __($action) }}</td><td>{{ __($roles['citizen']) }}</td><td>{{ __($roles['notary']) }}</td><td>{{ __($roles['admin']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-ui.card>
@endsection
