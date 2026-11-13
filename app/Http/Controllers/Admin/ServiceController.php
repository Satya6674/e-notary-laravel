<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\ServiceCatalogue;
use App\Support\DemoClaims;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Service settings. Slug, kind and destination stay fixed, as code and links depend on them. */
class ServiceController extends Controller
{
    public function __construct(private ServiceCatalogue $catalogue)
    {
    }

    public function index()
    {
        return view('admin.services.index', ['catalogue' => $this->catalogue, 'services' => $this->catalogue->all()]);
    }

    public function edit(string $slug)
    {
        return view('admin.services.edit', [
            'catalogue' => $this->catalogue,
            'service'   => $this->service($slug),
            'edited'    => $this->catalogue->isEdited($slug),
        ]);
    }

    public function update(Request $request, string $slug)
    {
        $service = $this->service($slug);

        $rules = [
            'name'         => ['required', 'string', 'max:120', DemoClaims::rule()],
            'category'     => ['required', Rule::in(array_keys($this->catalogue->categories()))],
            'summary'      => ['required', 'string', 'max:200', DemoClaims::rule()],
            'description'  => ['required', 'string', 'max:1000', DemoClaims::rule()],
            'icon'         => ['required', 'regex:/^fa-[a-z0-9-]+$/', 'max:60'],
            'status'       => ['required', Rule::in(array_keys($this->catalogue->statuses()))],
            'order'        => 'required|integer|min:0|max:100000',
            'instructions' => ['nullable', 'string', 'max:1000', DemoClaims::rule()],
        ];

        if ($service['kind'] === 'application') {
            $rules += [
                'documents'             => 'array|max:12',
                'documents.*.name'      => ['required', 'string', 'max:120', DemoClaims::rule()],
                'documents.*.formats'   => 'required|array|min:1',
                'documents.*.formats.*' => [Rule::in(config('service_catalogue.formats'))],
                'documents.*.max_mb'    => 'required|numeric|min:0.1|max:'.(config('service_catalogue.max_kb') / 1024),
                'fields'                => 'array|max:12',
                'fields.*.label'        => ['required', 'string', 'max:120', DemoClaims::rule()],
                'fields.*.type'         => ['required', Rule::in(config('service_catalogue.field_types'))],
                'fields.*.max'          => 'nullable|integer|min:10|max:2000',
            ];
        }

        $validator = validator($request->all(), $rules);

        $validator->after(function (Validator $validator) use ($request, $service) {
            $open = config("service_catalogue.statuses.{$request->input('status')}.open");

            if ($open && $service['kind'] === 'application' && ! collect($request->input('documents', []))->contains(fn ($row) => ! empty($row['required']))) {
                $validator->errors()->add('documents', __('A service that accepts applications needs at least one required document.'));
            }
        });

        $data = $validator->validate();

        $values = [
            'name'                  => $data['name'],
            'category'              => $data['category'],
            'summary'               => $data['summary'],
            'description'           => $data['description'],
            'icon'                  => $data['icon'],
            'status'                => $data['status'],
            'order'                 => (int) $data['order'],
            'instructions'          => (string) ($data['instructions'] ?? ''),
            'visible'               => $request->boolean('visible'),
            'requirements_verified' => $request->boolean('requirements_verified'),
        ];

        if ($service['kind'] === 'application') {
            $values['documents'] = $this->rows($data['documents'] ?? [], $request->input('documents', []), 'name', fn (array $row, array $raw) => [
                'name'     => $row['name'],
                'required' => ! empty($raw['required']),
                'formats'  => array_values($row['formats']),
                'max_kb'   => (int) round($row['max_mb'] * 1024),
            ]);
            $values['fields'] = $this->rows($data['fields'] ?? [], $request->input('fields', []), 'label', fn (array $row, array $raw) => [
                'label'    => $row['label'],
                'type'     => $row['type'],
                'required' => ! empty($raw['required']),
                'max'      => (int) ($row['max'] ?? 500),
            ]);
        }

        $changed = $this->catalogue->update($slug, $values);

        AuditLog::record('admin.service_updated', null, ['service' => $slug, 'changed' => $changed]);

        return redirect()->route('admin.services.index')->with('status', 'Service saved.');
    }

    public function reset(string $slug)
    {
        $this->service($slug);
        $this->catalogue->reset($slug);

        AuditLog::record('admin.service_reset', null, ['service' => $slug]);

        return redirect()->route('admin.services.index')->with('status', 'Service restored to its defaults.');
    }

    /** Rows keep their key across edits, so drafts stay matched. New rows get one from their text. */
    private function rows(array $rows, array $raw, string $source, callable $map): array
    {
        $result = [];

        foreach ($rows as $index => $row) {
            $submitted = $raw[$index] ?? [];
            $key = preg_match('/^[a-z0-9_]{1,60}$/', (string) ($submitted['key'] ?? ''))
                ? $submitted['key']
                : (Str::slug($row[$source], '_') ?: 'item');

            while (array_key_exists($key, $result)) {
                $key .= '_2';
            }

            $result[$key] = ['key' => $key] + $map($row, $submitted);
        }

        return array_values($result);
    }

    private function service(string $slug): array
    {
        return $this->catalogue->find($slug) ?? abort(404);
    }
}
