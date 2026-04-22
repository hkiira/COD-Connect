<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class ReviewAnswer extends Model {
    use HasFactory;
    protected $fillable = ['review_id', 'review_question_id', 'answer_value'];
    public function review() { return $this->belongsTo(Review::class); }
    public function question() { return $this->belongsTo(ReviewQuestion::class, 'review_question_id'); }
}
