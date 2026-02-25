<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;

class AccountUser extends Model
{
    use HasRoles;
    protected $guard_name = "api";
    protected $table = 'account_user';
    protected $fillable = [
        'code',
        'account_id',
        'user_id',
        'statut'
    ];

    public function deplacements()
    {
        return $this->hasMany(Mouvement::class)
            ->where('mouvement_type_id', 1);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
    public function chargements()
    {
        return $this->hasMany(Mouvement::class)
            ->where('mouvement_type_id', 2);
    }
    public function tranferts()
    {
        return $this->hasMany(Mouvement::class)
            ->where('mouvement_type_id', 4);
    }
    public function returns()
    {
        return $this->hasMany(Mouvement::class)
            ->where('mouvement_type_id', 3);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function types_attributes()
    {
        return $this->hasMany(TypeAttribute::class);
    }

    public function attributes()
    {
        return $this->hasMany(Attribute::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function payment_commissions()
    {
        return $this->hasMany(PaymentCommission::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function comment_subcomments()
    {
        return $this->belongsToMany(Comment::class, 'subcomments')
            ->withPivot('title');
    }

    public function subcomments()
    {
        return $this->hasMany(Subcomment::class);
    }

    public function carrierPickups()
    {
        return $this->hasMany(CarrierPickup::class);
    }

    public function accountCarriers()
    {
        return $this->belongsToMany(AccountCarrier::class, 'carrier_pickups')
            ->withPivot('code');
    }


    public function warehouseUsers()
    {
        return $this->hasMany(WarehouseUser::class);
    }

    public function collectors()
    {
        return $this->belongsToMany(Collector::class, 'pickups')
            ->withPivot('code');
    }


    public function carrierInvoices()
    {
        $this->hasMany(CarrierInvoice::class);
    }

    public function accountCarrierInvoices()
    {
        $this->belongsToMany(AccountCarrier::class, 'carrier_invoices');
    }

    public function supplier_billings()
    {
        return $this->hasMany(SupplierBilling::class);
    }
    public function suppliers_supplier_billing()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_billings')
            ->withPivot('code', 'montant', 'statut');
    }

    public function supplierOrders()
    {
        return $this->hasMany(SupplierOrder::class, 'account_user_id', 'id');
    }
    public function modelRoles()
    {
        return $this->belongsToMany(Role::class, 'model_has_roles', 'model_id', 'role_id');
    }

    public function suppliers_supplier_order()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_orders')
            ->withPivot('code', 'shipping_date', 'statut');
    }

    public function supplierReceipts()
    {
        return $this->hasMany(SupplierReceipt::class);
    }
    public function categories()
    {
        return $this->hasMany(Category::class);
    }
    public function suppliers_supplier_receipt()
    {
        return $this->belongsToMany(AccountUser::class, 'supplier_receipts')
            ->withPivot('code', 'statut');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function customer_order()
    {
        return $this->belongsToMany(Customer::class, 'orders');
    }
    public function account_city_order()
    {
        return $this->belongsToMany(AccountCity::class, 'orders');
    }
    public function payment_type_order()
    {
        return $this->belongsToMany(PaymentType::class, 'orders');
    }
    public function payment_method_order()
    {
        return $this->belongsToMany(PaymentMethod::class, 'orders');
    }
    public function brand_source_order()
    {
        return $this->belongsToMany(BrandSource::class, 'orders');
    }
    public function pickup_order()
    {
        return $this->belongsToMany(Pickup::class, 'orders');
    }
    public function statuses_order()
    {
        return $this->belongsToMany(Status::class, 'orders');
    }

    public function payment_commision_order()
    {
        return $this->belongsToMany(PaymentCommission::class, 'orders');
    }

    public function invoice_order()
    {
        return $this->belongsToMany(Invoice::class, 'orders');
    }

    public function order_products()
    {
        return $this->hasMany(OrderProduct::class);
    }

    public function order_order_product()
    {
        return $this->belongsToMany(Order::class, 'order_products');
    }
    public function product_variationAttribute_order_product()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'order_products');
    }
    public function offer_order_product()
    {
        return $this->belongsToMany(Offer::class, 'order_products');
    }

    public function order_comments()
    {
        return $this->hasMany(OrderComment::class);
    }

    public function order_order_comment()
    {
        return $this->belongsToMany(Order::class, 'order_comments');
    }
    public function subcomment_order_comment()
    {
        return $this->belongsToMany(Subcomment::class, 'order_comments');
    }
    public function status_order_comment()
    {
        return $this->belongsToMany(OrderStatus::class, 'order_comments');
    }
    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user')
            ->withPivot('statut');
    }
    public function activeWarehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user')
            ->wherePivotIn('statut', [1]);
    }
}
