{{-- The service's own fields, in the wording the citizen saw. --}}
@foreach($application->service_details['fields'] ?? [] as $field)
    <tr><td class="k">{{ __($field['label']) }}</td><td>{{ $field['value'] }}</td></tr>
@endforeach
