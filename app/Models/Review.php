<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Review extends Model {
    use HasFactory;
    protected $fillable = ['order_id', 'user_id'];
    public function answers() { return $this->hasMany(ReviewAnswer::class); }
    public function accountUser() { return $this->belongsTo(AccountUser::class, 'user_id'); }
    public function user() { return $this->belongsTo(User::class, 'user_id'); }
}
