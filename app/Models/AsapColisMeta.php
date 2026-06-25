<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsapColisMeta extends Model
{
    protected $guarded = [];

    public function histories()
    {
        return $this->hasMany(AsapColisHistory::class, 'asap_colis_meta_id');
    }

    public function addressHistories()
    {
        return $this->hasMany(AsapColisAddressHistory::class, 'asap_colis_meta_id');
    }

    public function callHistories()
    {
        return $this->hasMany(AsapColisCallHistory::class, 'asap_colis_meta_id');
    }
}
