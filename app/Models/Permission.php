<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];
    protected $fillable = [
        'name', 'guard_name', 'statut','permission_type_id'
    ];
    public function permissionType(){
        return $this->belongsTo(PermissionType::class);
    }
    
    public function accountUsers()
    {
        return $this->belongsToMany(AccountUser::class, 'model_has_permissions', 'permission_id', 'model_id')
            ->wherePivotIn('model_type', ['App\Models\AccountUser']);
    }
}