<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/** A document is visible to whoever may view its application. */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $document->request !== null && $user->can('view', $document->request);
    }
}
