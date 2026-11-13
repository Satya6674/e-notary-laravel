<div class="en-admin-row" data-row>
    <input type="hidden" name="fields[{{ $i }}][key]" value="{{ $row['key'] ?? '' }}">
    <div class="row g-2">
        <div class="col-md-5">
            <label class="form-label" for="field-label-{{ $i }}">{{ __('Field Label') }}</label>
            <input id="field-label-{{ $i }}" class="form-control" name="fields[{{ $i }}][label]" value="{{ $row['label'] ?? '' }}" maxlength="120" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="field-type-{{ $i }}">{{ __('Type') }}</label>
            <select id="field-type-{{ $i }}" class="form-select" name="fields[{{ $i }}][type]">
                @foreach(['text' => 'Short Text', 'textarea' => 'Long Text', 'date' => 'Date'] as $type => $label)
                    <option value="{{ $type }}" @selected(($row['type'] ?? 'text') === $type)>{{ __($label) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="field-max-{{ $i }}">{{ __('Maximum Length') }}</label>
            <input id="field-max-{{ $i }}" class="form-control" type="number" min="10" max="2000" name="fields[{{ $i }}][max]" value="{{ $row['max'] ?? 200 }}">
        </div>
        <div class="col-md-3 d-flex align-items-end justify-content-between gap-2">
            <div class="form-check mb-2">
                <input id="field-req-{{ $i }}" class="form-check-input" type="checkbox" name="fields[{{ $i }}][required]" value="1" @checked($row['required'] ?? false)>
                <label class="form-check-label" for="field-req-{{ $i }}">{{ __('Required') }}</label>
            </div>
            <button class="btn btn-sm btn-outline-secondary mb-1" type="button" data-remove-row>{{ __('Remove') }}</button>
        </div>
    </div>
</div>
