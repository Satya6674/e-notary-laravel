@extends('layouts.app')

@section('title', __('Services'))

@section('content')
<x-ui.page-head :title="__('Services')" :subtitle="__('Changes here update the public site, the citizen portal and the footer.')" />

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr><th>{{ __('Order') }}</th><th>{{ __('Service') }}</th><th>{{ __('Category') }}</th><th>{{ __('Status') }}</th><th>{{ __('Visible') }}</th><th>{{ __('Requirements') }}</th><th></th></tr>
            </thead>
            <tbody>
                @foreach($services as $service)
                    <tr>
                        <td>{{ $service['order'] }}</td>
                        <td><strong>{{ __($service['name']) }}</strong></td>
                        <td>{{ __($catalogue->categories()[$service['category']]['name']) }}</td>
                        <td>@include('partials.service-status', ['service' => $service])</td>
                        <td>{{ $service['visible'] ? __('Yes') : __('No') }}</td>
                        <td>{{ $service['kind'] === 'application' ? ($service['requirements_verified'] ? __('Verified') : __('Subject to confirmation')) : __('Not applicable') }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.services.edit', $service['slug']) }}">{{ __('Edit') }}<span class="visually-hidden">: {{ __($service['name']) }}</span></a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-ui.card>
@endsection
