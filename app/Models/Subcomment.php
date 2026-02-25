<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subcomment extends Model
{
    use HasFactory;
    protected $fillable = [
        'title',
        'account_user_id',
        'comment_id',
        'order_change'
    ];
    public function comments()
    {
        return $this->belongsTo(Comment::class);
    }

    public function accounts()
    {
        return $this->belongsTo(AccountUser::class);
    }

    public function order_comments(){
        return $this->hasMany(OrderComment::class);
    }

    public function account_user_order_comment(){
        return $this->belongsToMany(AccountUser::class, 'order_comments');
    }
    public function order_order_comment(){
        return $this->belongsToMany(Order::class, 'order_comments');
    }
    public function status_order_comment(){
        return $this->belongsToMany(Status::class, 'order_comments');
    }
}
