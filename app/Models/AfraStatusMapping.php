<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfraStatusMapping extends Model
{
    protected $fillable = ['account_id', 'afra_status_id', 'comment_id', 'is_return', 'order_status_id'];

    protected $casts = ['is_return' => 'boolean'];

    public function comment()
    {
        return $this->belongsTo(Comment::class);
    }
}
