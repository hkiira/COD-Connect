<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class OfferableVariation extends Model
{
    use SoftDeletes;
    protected $table = 'offerable_variations';
    protected $dates = ['deleted_at'];
    protected $fillable = ['offerable_variation_id', 'offerable_id', 'account_id', 'statut'];

    // Define relationships or additional methods as needed

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function parentCOfferableVariation()
    {
        return $this->belongsTo(OfferableVariation::class, 'offerable_variation_id');
    }
    

    public function childOfferableVariations()
    {
        return $this->hasMany(OfferableVariation::class, 'offerable_variation_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'offerable_variation_id');
    }
    public function statuses()
    {
        return $this->hasMany(OrderStatus::class, 'offerable_variation_id');
    }
    public function orderPvas()
    {
        return $this->hasMany(OrderPva::class, 'offerable_variation_id');
    }

    public function offerable()
    {
        return $this->belongsTo(Offerable::class, 'offerable_id');
    }
}
