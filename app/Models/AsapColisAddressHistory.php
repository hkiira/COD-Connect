<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsapColisAddressHistory extends Model
{
    protected $guarded = [];

    public function meta()
    {
        return $this->belongsTo(AsapColisMeta::class, 'asap_colis_meta_id');
    }
}
