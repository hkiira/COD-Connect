<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Review extends Model {
    use HasFactory;
    protected $fillable = ['order_id', 'user_id'];
    public function answers() { return $this->hasMany(ReviewAnswer::class); }
}
