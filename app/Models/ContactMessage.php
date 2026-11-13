<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A message sent through the contact form. "is_read" tracks whether an admin has seen it.
 */
class ContactMessage extends Model
{
    use HasFactory;

    protected $table = 'contact_messages';

    // Empty guarded list = every column can be mass-assigned with create()/update().
    protected $guarded = [];
}
