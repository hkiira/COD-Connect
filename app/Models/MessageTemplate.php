<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/** A WhatsApp message of the order workspaces; `{client}`, `{code}`... are filled by the screens. */
class MessageTemplate extends Model
{
    use BelongsToAccount;

    public const STAGES = ['confirmation', 'tracking'];

    public const LANGUAGES = ['darija', 'fr'];

    protected $fillable = ['account_id', 'stage', 'title', 'language', 'body', 'position'];

    protected $casts = ['position' => 'integer'];
}
