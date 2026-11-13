<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Profile photos, from a file or the browser camera (which submits a JPEG file
 * through the same field). Stored on the private disk and served after a check.
 */
class ProfilePhotoController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png|max:2048|dimensions:min_width=100,min_height=100,max_width=4000,max_height=4000',
        ]);

        $user = $request->user();
        $old = $user->photo_path;
        $path = $data['photo']->store('photos', Document::PRIVATE_DISK);

        $user->forceFill(['photo_path' => $path])->save();

        if ($old) {
            Storage::disk(Document::PRIVATE_DISK)->delete($old);
        }

        AuditLog::record('user.photo_updated', $user);

        return back()->with('status', 'Photo updated.');
    }

    public function destroy(Request $request)
    {
        $user = $request->user();

        if ($user->photo_path) {
            Storage::disk(Document::PRIVATE_DISK)->delete($user->photo_path);
            $user->forceFill(['photo_path' => null])->save();
            AuditLog::record('user.photo_removed', $user);
        }

        return back()->with('status', 'Photo removed.');
    }

    public function show(Request $request, User $user)
    {
        $viewer = $request->user();

        $allowed = $viewer->id === $user->id || $viewer->isAdmin()
            || ($viewer->isApprovedNotary() && NotaryRequest::where('user_id', $user->id)->where('notary_id', $viewer->id)->exists());

        abort_unless($allowed && $user->photo_path, 404);

        return Storage::disk(Document::PRIVATE_DISK)->response($user->photo_path, 'photo', [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
