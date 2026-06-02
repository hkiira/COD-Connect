<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['name'];
    protected $hidden = ['pivot', 'created_at', 'updated_at'];

    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'taggable');
    }
}
