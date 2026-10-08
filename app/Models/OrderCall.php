<?php
// OrderCall.php - Eloquent model for order_calls table
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCall extends Model
{
    protected $table = 'order_calls';
    protected $fillable = [
        'order_id',
        'employee_id',
        'called_at',
        'call_number',
        'result',
        'note',
        'call_duration',
    ];
    // Relationships
    public function order() {
        return $this->belongsTo(Order::class);
    }
    public function employee() {
        return $this->belongsTo(AccountUser::class, 'employee_id');
    }
}
