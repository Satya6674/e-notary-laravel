<?php

namespace App\Http\Controllers;

use App\Services\ServiceCatalogue;
use Illuminate\Http\Request;

/** Public service pages. Nothing here reads any citizen's data. */
class ServiceController extends Controller
{
    public function index(Request $request, ServiceCatalogue $catalogue)
    {
        // A query string can carry an array (?category[]=x); only a known category key is used.
        $category = $request->query('category');
        $category = is_string($category) && isset($catalogue->categories()[$category]) ? $category : null;

        return view('services.index', [
            'catalogue'  => $catalogue,
            'category'   => $category,
            'services'   => $catalogue->inCategory($category),
        ]);
    }

    public function show(string $slug, ServiceCatalogue $catalogue)
    {
        $service = $catalogue->find($slug);

        abort_unless($service && $service['visible'], 404);

        return view('services.show', ['service' => $service, 'canApply' => $catalogue->canApply($service)]);
    }
}
