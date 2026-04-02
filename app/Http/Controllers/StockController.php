<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\AccountUser;
use App\Http\Resources\ProductInventoryResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with([
            'accountProducts.taxonomies',
            'images',
            'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute',
            'productVariationAttributes.activeWarehouses',
            'productVariationAttributes.orderPvas.order.orderStatus',
            'productVariationAttributes.supplierOrderPvas.supplierOrder'
        ]);

        // When authenticated, scope to the caller's account only.
        // This prevents cross-account data exposure and enables
        // account-specific pricing / stock data in the response.
        if (Auth::check()) {
            $accountUserIds = AccountUser::where('account_id', getAccountUser()->account_id)
                ->pluck('id')
                ->toArray();
            $query->whereIn('account_user_id', $accountUserIds)
                  ->where('statut', 1);
        }

        // Search Filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('code', 'LIKE', "%{$search}%")
                  ->orWhere('reference', 'LIKE', "%{$search}%");
            });
        }

        // Status Filter
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $status = $request->input('status');
            
            $query->whereHas('productVariationAttributes', function ($q) use ($status) {
                $sumQuery = DB::raw('(SELECT COALESCE(SUM(quantity), 0) FROM warehouse_pva WHERE warehouse_pva.product_variation_attribute_id = product_variation_attribute.id AND warehouse_pva.statut = 1)');
                
                if ($status === 'Out of Stock') {
                    $q->where($sumQuery, '<=', 0);
                } elseif ($status === 'Low Stock') {
                    $q->where($sumQuery, '>', 0)->where($sumQuery, '<', 5);
                } elseif ($status === 'In Stock') {
                    $q->where($sumQuery, '>=', 5);
                }
            });
        }

        $products = $query->paginate(15);

        return ProductInventoryResource::collection($products);
    }
}
