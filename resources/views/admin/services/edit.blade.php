@extends('layouts.app')

@section('title', __($service['name']))

@section('content')
<x-ui.page-head :title="__($service['name'])" :subtitle="$service['slug']">
    <a class="btn btn-outline-primary" href="{{ route('admin.services.index') }}">{{ __('Back to Services') }}</a>
</x-ui.page-head>

<form method="POST" action="{{ route('admin.services.update', $service['slug']) }}">
    @csrf
    @method('PUT')

    <x-ui.card :title="__('Service Details')" class="mb-3">
        <div class="row">
            <div class="col-md-6"><x-ui.field name="name" :label="__('Service Title')" :value="$service['name']" required maxlength="120" /></div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="f-category">{{ __('Category') }}</label>
                <select id="f-category" name="category" class="form-select">
                    @foreach($catalogue->categories() as $key => $item)
                        <option value="{{ $key }}" @selected(old('category', $service['category']) === $key)>{{ __($item['name']) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="f-status">{{ __('Status') }}</label>
                <select id="f-status" name="status" class="form-select">
                    @foreach($catalogue->statuses() as $key => $item)
                        <option value="{{ $key }}" @selected(old('status', $service['status']) === $key)>{{ __($item['label']) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4"><x-ui.field name="icon" :label="__('Icon')" :value="$service['icon']" required maxlength="60" :hint="__('A Font Awesome solid icon name, such as fa-file-lines.')" /></div>
            <div class="col-md-4"><x-ui.field name="order" type="number" :label="__('Display Order')" :value="$service['order']" required min="0" /></div>
            <div class="col-12"><x-ui.field name="summary" :label="__('Short Description')" :value="$service['summary']" required maxlength="200" /></div>
            <div class="col-12 mb-3">
                <label class="form-label" for="f-description">{{ __('Full Description') }}</label>
                <textarea id="f-description" name="description" rows="3" maxlength="1000" class="form-control @error('description') is-invalid @enderror" required>{{ old('description', $service['description']) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 mb-3">
                <label class="form-label" for="f-instructions">{{ __('Requirement Instructions') }}</label>
                <textarea id="f-instructions" name="instructions" rows="2" maxlength="1000" class="form-control">{{ old('instructions', $service['instructions']) }}</textarea>
            </div>
        </div>
        <div class="form-check">
            <input type="hidden" name="visible" value="0">
            <input id="f-visible" class="form-check-input" type="checkbox" name="visible" value="1" @checked(old('visible', $service['visible']))>
            <label class="form-check-label" for="f-visible">{{ __('Show this service') }}</label>
        </div>
        @if($service['kind'] === 'application')
            <div class="form-check mt-2">
                <input type="hidden" name="requirements_verified" value="0">
                <input id="f-verified" class="form-check-input" type="checkbox" name="requirements_verified" value="1" @checked(old('requirements_verified', $service['requirements_verified']))>
                <label class="form-check-label" for="f-verified">{{ __('A legal professional has verified these requirements') }}</label>
                <div class="form-text">{{ __('Only tick this when it is true. It removes the "subject to confirmation" notice.') }}</div>
            </div>
        @endif
    </x-ui.card>

    @if($service['kind'] === 'application')
        @foreach([['documents', 'Document Requirements', 'Add Document', 'admin.services.document-row'], ['fields', 'Application Fields', 'Add Field', 'admin.services.field-row']] as [$list, $title, $add, $partial])
            <x-ui.card :title="__($title)" class="mb-3">
                <div data-rows="{{ $list }}" data-next="{{ count($service[$list]) }}">
                    @foreach($service[$list] as $i => $row)
                        @include($partial, ['i' => $i, 'row' => $row])
                    @endforeach
                </div>
                <template data-template="{{ $list }}">@include($partial, ['i' => '__i__', 'row' => []])</template>
                <button class="btn btn-sm btn-outline-primary" type="button" data-add-row="{{ $list }}">{{ __($add) }}</button>
            </x-ui.card>
        @endforeach
    @endif

    <button class="btn btn-primary" type="submit">{{ __('Save Service') }}</button>
</form>

@if($edited)
    <form method="POST" action="{{ route('admin.services.reset', $service['slug']) }}" class="mt-3">
        @csrf
        <button class="btn btn-outline-secondary" type="submit">{{ __('Restore Defaults') }}</button>
    </form>
@endif
@endsection

@push('scripts')
<script>
    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-add-row]');
        if (add) {
            var list = document.querySelector('[data-rows="' + add.dataset.addRow + '"]');
            var html = document.querySelector('[data-template="' + add.dataset.addRow + '"]').innerHTML;
            list.insertAdjacentHTML('beforeend', html.replace(/__i__/g, list.dataset.next));
            list.dataset.next = Number(list.dataset.next) + 1;
        }
        var remove = event.target.closest('[data-remove-row]');
        if (remove) { remove.closest('[data-row]').remove(); }
    });
</script>
@endpush
