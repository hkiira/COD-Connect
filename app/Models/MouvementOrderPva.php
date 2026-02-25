<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MouvementOrderPva extends Model
{
    use HasFactory;
    protected $table = 'mouvement_order_pva';
    protected $fillable = [

        'order_pva_id',
        'mouvement_pva_id',
        'statut',
    ];
    public function orderPva(){
        return $this->belongsTo(OrderPva::class);
    }
    public function mouvementPva(){
        return $this->belongsTo(MouvementPva::class);
    }

}
