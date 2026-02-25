<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait
class User extends Authenticatable
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'email',
        'password',
        'firstname',
        'lastname',
        'statut',
        'cin',
        'birthday',
        'photo',
        'created_at',
        'updated_at',
        'photo_dir'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
    public function toArray()
    {
        $data = parent::toArray();

        // Add additional parameters to the array
        $data['account_user'] = $this->computeCustomParameter();;

        return $data;
    }
    public function computeCustomParameter()
    {
        // Your dynamic logic to compute the custom parameter
        $identifier = ["account" => $this->accountUsers->first()->account_id, "id" => $this->accountUsers->first()->id];

        return $identifier;
    }

    public function accountUsers()
    {
        return $this->hasMany(AccountUser::class);
    }
    public function accounts()
    {
        return $this->belongsToMany(Account::class)
            ->wherePivot('statut', 1);
    }

    public function phones()
    {
        return $this->morphToMany(Phone::class, 'phoneable');
    }
    public function compensations()
    {
        return $this->morphToMany(AccountCompensation::class, 'compensationable');
    }
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }

    public function addresses()
    {
        return $this->morphToMany(Address::class, 'addressable');
    }

    public function supplier_receipts()
    {
        return $this->belongsToMany(SupplierReceipt::class, 'supplier_order_product_size');
    }
}
