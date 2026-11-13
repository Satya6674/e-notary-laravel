<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ServiceDraft;
use App\Services\ServiceApplication;
use App\Services\ServiceCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Citizen journey: choose a service, enter details and documents, review, submit. */
class ServiceController extends Controller
{
    public function __construct(private ServiceCatalogue $catalogue, private ServiceApplication $applications)
    {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $category = $request->query('category');

        $services = $this->catalogue->inCategory($category)->when($search !== '', fn ($list) => $list->filter(
            fn (array $service) => Str::contains(__($service['name']).' '.$service['name'].' '.__($service['summary']), $search, true)
        ))->values();

        $selected = $services->firstWhere('slug', $request->query('service'));

        return view('user.services.index', [
            'catalogue' => $this->catalogue,
            'services'  => $services,
            'selected'  => $selected,
            'search'    => $search,
            'category'  => isset($this->catalogue->categories()[$category]) ? $category : null,
            'draft'     => $this->draft($request),
        ]);
    }

    public function apply(Request $request, string $slug)
    {
        $service = $this->service($slug);
        $draft = $this->draft($request);

        return view('user.services.apply', [
            'service' => $service,
            'draft'   => $draft?->service_slug === $slug ? $draft : null,
            'other'   => $draft && $draft->service_slug !== $slug ? $this->catalogue->find($draft->service_slug) : null,
        ]);
    }

    public function save(Request $request, string $slug)
    {
        $service = $this->service($slug);

        $data = $request->validate(
            $this->applications->fieldRules($service) + $this->applications->fileRules($service),
            [],
            $this->applications->fieldNames($service)
        );

        $this->applications->saveDraft($request->user(), $service, $data, $request->file('files', []));

        return redirect()->route('user.services.review', $slug);
    }

    public function removeFile(Request $request, string $slug, string $key)
    {
        $this->service($slug);
        $draft = $this->draft($request);

        abort_unless($draft && $draft->service_slug === $slug, 404);

        $draft->removeFile($key);

        return back()->with('status', 'Document removed.');
    }

    public function review(Request $request, string $slug)
    {
        $service = $this->service($slug);
        $draft = $this->draft($request);

        if (! $draft || $draft->service_slug !== $slug) {
            return redirect()->route('user.services.apply', $slug);
        }

        return view('user.services.review', [
            'service'       => $service,
            'draft'         => $draft,
            'missing'       => $this->applications->missingDocuments($service, $draft),
            'missingFields' => $this->applications->missingFields($service, $draft),
        ]);
    }

    public function submit(Request $request, string $slug)
    {
        $service = $this->service($slug);
        $draft = $this->draft($request);

        if (! $draft || $draft->service_slug !== $slug) {
            return redirect()->route('user.services.apply', $slug);
        }

        $request->validate(['declaration' => 'accepted'], ['declaration.accepted' => __('Please confirm the declaration before submitting.')]);

        // Checked here as well as on the page: requirements can change while a draft waits.
        if ($this->applications->missingDocuments($service, $draft) || $this->applications->missingFields($service, $draft)) {
            return redirect()->route('user.services.review', $slug)->with('error', 'Required information or documents are missing.');
        }

        $application = $this->applications->submit($request->user(), $service, $draft);

        // A second click: the first already created the application.
        if (! $application) {
            return redirect()->route('user.requests')->with('status', 'Your application has already been submitted.');
        }

        return redirect()->route('user.requests.show', $application)->with('status', 'Your application has been submitted.');
    }

    public function discard(Request $request)
    {
        $this->draft($request)?->discard();

        return redirect()->route('user.services.index')->with('status', 'Your draft has been discarded.');
    }

    /** The service, only while citizens may apply for it. */
    private function service(string $slug): array
    {
        $service = $this->catalogue->find($slug);

        abort_unless($service && $this->catalogue->canApply($service), 404);

        return $service;
    }

    private function draft(Request $request): ?ServiceDraft
    {
        return ServiceDraft::where('user_id', $request->user()->id)->first();
    }
}
