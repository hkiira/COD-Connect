<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductBrandSource extends Model
{
    use SoftDeletes;

    protected $table = 'product_brand_source';

    protected $fillable = [
        'product_id',
        'brand_source_id',
        'account_user_id',
        'statut',
    ];

    // Relationships
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function brandSource()
    {
        return $this->belongsTo(BrandSource::class);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
}
