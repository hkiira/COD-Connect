<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class OrderStatusComment extends Model
{
    use SoftDeletes;
    protected $table = 'order_status_comment' ;
    protected $dates = ['deleted_at'];

    protected $fillable=[
        'order_status_id',
        'comment_id',
        'statut',
        'created_at',
        'updated_at'
    ];
    public function orderStatus(){
        return $this->belongsTo(OrderStatus::class, 'order_status_id', 'id');
    }
    public function comment(){
        return $this->belongsTo(Comment::class, 'comment_id', 'id');
    }


}
