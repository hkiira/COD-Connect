<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Offerable extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'offer_id',
        'offerable_type',
        'offerable_id',
        'gift',
        'product_id',
        'account_user_id',
        'statut',
        'deleted_at'
    ];
    public function offer()
    {
        return $this->belongsTo(Offer::class, 'offer_id');
    }
}