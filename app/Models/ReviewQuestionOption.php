<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class ReviewQuestionOption extends Model {
    use HasFactory;
    protected $fillable = ['review_question_id', 'label', 'value'];
    public function question() { return $this->belongsTo(ReviewQuestion::class); }
}
