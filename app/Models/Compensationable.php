<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Compensationable extends Model
{
    use SoftDeletes;
    protected $table = 'compensationables';
    protected $dates = ['deleted_at', 'effective_date', 'start_date', 'end_date'];

    protected $fillable = [
        'account_compensation_id',
        "compensationable_id",
        "compensationable_type",
        "comparison_operator_id",
        "account_user_id",
        "amount",
        "commission",
        "effective_date",
        "start_date",
        "end_date",
        "statut",
        'deleted_at'
    ];

    public function accountCompensation()
    {
        return $this->belongsTo(AccountCompensation::class);
    }


    public function comparisonOperator()
    {
        return $this->belongsTo(ComparisonOperator::class);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }

    // Define the morphTo relationship
    public function compensationable()
    {
        return $this->morphTo();
    }
}
