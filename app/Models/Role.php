<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];
    protected $fillable = [
        'name', 'guard_name', 'statut', 'role_type_id'
    ];
    public function accounts()
    {
        return $this->belongsToMany(Account::class, 'model_has_roles', 'role_id', 'model_id')
            ->wherePivotIn('model_type', ['App\Models\Account']);
    }
    public function activeCompensations()
    {
        return $this->morphToMany(AccountCompensation::class, 'compensationable')
            ->withPivot('effective_date', 'start_date', 'end_date', 'amount', 'commission')
            ->wherePivot('statut', 1);
    }
    public function roleType()
    {
        return $this->belongsTo(RoleType::class);
    }
    public function accountUsers()
    {
        return $this->belongsToMany(AccountUser::class, 'model_has_roles', 'role_id', 'model_id')
            ->wherePivotIn('model_type', ['App\Models\AccountUser']);
    }
}
