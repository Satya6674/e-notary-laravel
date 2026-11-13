<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\DemoClaims;
use App\Support\Platform;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Language choice for anyone, personal settings, and the admin's site settings. */
class SettingsController extends Controller
{
    /** Works for guests too; a logged-in user's choice is also saved to their account. */
    public function locale(Request $request)
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(Platform::LOCALES))]]);

        $request->session()->put('locale', $data['locale']);

        if (Platform::schemaReady() && $request->user()) {
            $request->user()->forceFill(['locale' => $data['locale']])->save();
        }

        return back();
    }

    public function edit(Request $request)
    {
        return view('settings.index', [
            'user'     => $request->user(),
            'activity' => AuditLog::where('user_id', $request->user()->id)->where('action', 'like', 'auth.%')->latest('id')->limit(6)->get(),
            'site' => $request->user()->isAdmin()
                ? collect(Setting::DEFAULTS)->map(fn ($default, $key) => Setting::get($key))
                : null,
        ]);
    }

    public function updateSite(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'portal_notice'  => ['nullable', 'string', 'max:300', DemoClaims::rule()],
            'support_email'  => 'nullable|email:rfc|max:255',
            'default_locale' => ['required', Rule::in(array_keys(Platform::LOCALES))],
        ]);

        $data['appointments_enabled'] = $request->boolean('appointments_enabled') ? '1' : '0';

        foreach ($data as $key => $value) {
            Setting::put($key, $value);
        }

        AuditLog::record('admin.settings_updated', null, ['changed' => array_keys($data)]);

        return back()->with('status', 'Settings saved.');
    }
}
