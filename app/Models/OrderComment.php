<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderComment extends Model
{
    use SoftDeletes;
    use HasFactory;
    protected $dates = ['deleted_at'];
    protected $table = 'order_comment';
    protected $fillable = [
        'order_id',
        'title',
        'postpone',
        'order_status_id',
        'account_user_id',
        'comment_id',
        'created_at',
        'sync',
        'updated_at'
    ];
    public function order(){
        return $this->belongsTo(Order::class);
    }
    public function comment(){
        return $this->belongsTo(Comment::class);
    }
    public function orderStatus(){
        return $this->belongsTo(OrderStatus::class,'order_status_id');
    }
    public function accountUser(){
        return $this->belongsTo(AccountUser::class);
    }
}
