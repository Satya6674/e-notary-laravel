{{-- What the citizen entered for a catalogue service, in the wording they saw. --}}
@if($application->service_details)
    <dt class="col-sm-4">{{ __('Service') }}</dt><dd class="col-sm-8">{{ __($application->service_details['service'] ?? '') }}</dd>
    @foreach($application->service_details['fields'] ?? [] as $field)
        <dt class="col-sm-4">{{ __($field['label']) }}</dt><dd class="col-sm-8 text-break">{{ $field['value'] }}</dd>
    @endforeach
@endif
