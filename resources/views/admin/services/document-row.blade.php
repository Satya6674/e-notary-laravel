<div class="en-admin-row" data-row>
    <input type="hidden" name="documents[{{ $i }}][key]" value="{{ $row['key'] ?? '' }}">
    <div class="row g-2">
        <div class="col-md-5">
            <label class="form-label" for="doc-name-{{ $i }}">{{ __('Document Name') }}</label>
            <input id="doc-name-{{ $i }}" class="form-control" name="documents[{{ $i }}][name]" value="{{ $row['name'] ?? '' }}" maxlength="120" required>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="doc-size-{{ $i }}">{{ __('Maximum Size (MB)') }}</label>
            <input id="doc-size-{{ $i }}" class="form-control" type="number" step="0.1" min="0.1" max="{{ config('service_catalogue.max_kb') / 1024 }}" name="documents[{{ $i }}][max_mb]" value="{{ round(($row['max_kb'] ?? config('service_catalogue.max_kb')) / 1024, 1) }}" required>
        </div>
        <div class="col-md-4 d-flex align-items-end justify-content-between gap-2">
            <div class="form-check mb-2">
                <input id="doc-req-{{ $i }}" class="form-check-input" type="checkbox" name="documents[{{ $i }}][required]" value="1" @checked($row['required'] ?? false)>
                <label class="form-check-label" for="doc-req-{{ $i }}">{{ __('Required') }}</label>
            </div>
            <button class="btn btn-sm btn-outline-secondary mb-1" type="button" data-remove-row>{{ __('Remove') }}</button>
        </div>
        <fieldset class="col-12">
            <legend class="form-label mb-1">{{ __('Accepted Formats') }}</legend>
            @foreach(config('service_catalogue.formats') as $format)
                <div class="form-check form-check-inline">
                    <input id="doc-{{ $i }}-{{ $format }}" class="form-check-input" type="checkbox" name="documents[{{ $i }}][formats][]" value="{{ $format }}" @checked(in_array($format, $row['formats'] ?? config('service_catalogue.formats'), true))>
                    <label class="form-check-label" for="doc-{{ $i }}-{{ $format }}">{{ strtoupper($format) }}</label>
                </div>
            @endforeach
        </fieldset>
    </div>
</div>
