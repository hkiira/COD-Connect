<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrandSource extends Model
{
    use HasFactory;
    protected $table = 'brand_source';
    protected $fillable = [

        'account_id',
        'source_id',
        'brand_id',
        'statut'
    ];
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }
    public function brandSources()
    {
        return $this->belongsToMany(Product::class, 'products');
    }
    public function commissions()
    {
        return $this->morphToMany(AccountCompensation::class, 'compensationable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id', 1);
    }
    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    public function accounts()
    {
        return $this->belongsTo(Account::class);
    }
}
