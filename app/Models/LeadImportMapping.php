<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class LeadImportMapping extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'source', 'fingerprint', 'headers', 'mapping',
        'product_mapping', 'variant_mapping', 'city_mapping',
    ];

    protected $casts = [
        'headers' => 'array',
        'mapping' => 'array',
        'product_mapping' => 'array',
        'variant_mapping' => 'array',
        'city_mapping' => 'array',
    ];
}
