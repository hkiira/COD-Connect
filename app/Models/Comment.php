<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Comment extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'title',
        'statut',
        'current_statut',
        'new_statut',
        'comment_id',
        'postponed',
        'is_change',
        'account_id'
    ];
    public function accounts()
    {
        return $this->belongsTo(Account::class);
    }
    
    // Relation avec l'entrepôt parent (si applicable)
    public function parentComment()
    {
        return $this->belongsTo(Comment::class, 'comment_id');
    }
    // Relation avec les entrepôts enfants
    public function childComments()
    {
        return $this->hasMany(Comment::class, 'comment_id');
    }
    public function orderStatuses(){
        return $this->belongsToMany(OrderStatus::class, 'order_status_comment');
    }
    public function orders(){
        return $this->belongsToMany(Order::class, 'order_comment');
    }
 
}
