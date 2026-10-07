<?php

namespace App\Http\Controllers;

use App\Models\ProductVariationAttribute;
use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\SyncLog;
use App\Models\WooCommerce\VariationLink;
use App\Services\WooCommerce\OrderService;
use App\Services\WooCommerce\WooCommerceException;
use App\Support\Orders\OrderOwnership;
use App\Support\WooCommerce\StoreResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WooCommerce orders: list, details and import.
 *
 * Two sets of routes reach it. `wc/stores/{id}/orders...` names the store. The older `wc-orders...` routes (still
 * used by screens that predate multi-store) take an optional `store_id` and otherwise use the account's first
 * active store. The work itself is in App\Services\WooCommerce\OrderService.
 */
class WooCommerceOrderController extends Controller
{
    // ---- store-scoped routes ------------------------------------------------

    public function listForStore(Request $request, $id): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|string|max:40',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:100',
        ]);

        $store = StoreResolver::resolve($id);
        $status = (string) $request->query('status', ($store->import_statuses[0] ?? 'processing'));
        $perPage = (int) $request->query('per_page', 10);
        $page = (int) $request->query('page', 1);

        try {
            $result = (new OrderService($store))->list($status, $perPage, $page, $request->query('search'));
        } catch (WooCommerceException $e) {
            return $this->failed($e, 'Failed to fetch orders from WooCommerce.');
        }

        return response()->json([
            'statut' => 1,
            'data' => $result['data'],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'status' => $status, 'count' => $result['total']],
        ]);
    }

    public function showForStore($id, $wcOrderId): JsonResponse
    {
        $store = StoreResolver::resolve($id);

        try {
            return response()->json(['statut' => 1, 'data' => (new OrderService($store))->show((int) $wcOrderId)]);
        } catch (WooCommerceException $e) {
            return response()->json(['statut' => 0, 'message' => 'Order not found.'], 404);
        }
    }

    public function importForStore(Request $request, $id): JsonResponse
    {
        $request->validate([
            'orders' => 'required|array|min:1|max:100',
            'orders.*.wc_order_id' => 'required|integer|min:1',
            'orders.*.warehouse_id' => 'nullable|integer',
            'orders.*.brand_source_id' => 'nullable|integer',
            'orders.*.payment_type_id' => 'nullable|integer',
            'orders.*.payment_method_id' => 'nullable|integer',
            'wc_update_status' => 'nullable|string|max:40',
        ]);

        return $this->runImport(StoreResolver::resolve($id), $request);
    }

    // ---- routes that predate multi-store -----------------------------------

    public function getOrdersByStatus(Request $request): JsonResponse
    {
        return $this->listForStore($request, $this->legacyStoreId($request));
    }

    public function showOrder(Request $request, $id): JsonResponse
    {
        return $this->showForStore($this->legacyStoreId($request), $id);
    }

    public function importOrders(Request $request): JsonResponse
    {
        $request->validate([
            'orders' => 'required|array|min:1|max:100',
            'orders.*.wc_order_id' => 'required|integer|min:1',
            'orders.*.warehouse_id' => 'nullable|integer',
            'orders.*.brand_source_id' => 'nullable|integer',
            'orders.*.payment_type_id' => 'nullable|integer',
            'orders.*.payment_method_id' => 'nullable|integer',
            'wc_update_status' => 'nullable|string|max:40',
        ]);

        return $this->runImport(StoreResolver::resolve($this->legacyStoreId($request)), $request);
    }

    /** Links a WooCommerce variation to a CodConnect one (the order screens' "Sync" button). */
    public function syncProduct(Request $request): JsonResponse
    {
        $request->validate([
            'wc_variation_id' => 'required|integer|min:1',
            'wc_product_id' => 'nullable|integer|min:0',
            'system_pva_id' => 'required|integer',
        ]);

        if (! OrderOwnership::ownsProductVariation($request->system_pva_id)) {
            return response()->json(['statut' => 0, 'message' => 'System product variation not found.'], 404);
        }

        $store = StoreResolver::resolve($this->legacyStoreId($request));
        $pva = ProductVariationAttribute::find($request->system_pva_id);

        VariationLink::updateOrCreate(
            ['store_id' => $store->id, 'wc_product_id' => (int) $request->input('wc_product_id', 0), 'wc_variation_id' => (int) $request->wc_variation_id],
            ['account_id' => $store->account_id, 'product_variation_attribute_id' => $pva->id, 'source' => 'manual']
        );
        SyncLog::record($store, 'mapping', 'variation', 'success', 'Variation linked', ['entity_id' => $pva->id, 'wc_id' => (int) $request->wc_variation_id]);

        // the older screens still read the id from the variation's meta
        $meta = is_array($pva->meta) ? $pva->meta : [];
        if (! collect($meta)->contains(fn ($item) => isset($item['id']) && (int) $item['id'] === (int) $request->wc_variation_id)) {
            $meta[] = ['id' => (int) $request->wc_variation_id];
            $pva->meta = $meta;
            $pva->save();
        }

        return response()->json(['statut' => 1, 'message' => 'Product synced successfully.', 'pva' => $pva]);
    }

    // ---- helpers ------------------------------------------------------------

    private function legacyStoreId(Request $request): ?int
    {
        $id = $request->query('store_id', $request->input('store_id'));

        return $id === null || $id === '' ? null : (int) $id;
    }

    private function runImport(Store $store, Request $request): JsonResponse
    {
        try {
            $results = (new OrderService($store))->import($request->input('orders'), $request->input('wc_update_status'));
        } catch (\Throwable $e) {
            Log::error('WooCommerce import: ' . $e->getMessage());

            return response()->json(['statut' => 0, 'message' => 'An error occurred during import.'], 500);
        }

        $ok = collect($results)->where('success', true)->count();

        return response()->json([
            'statut' => $ok > 0 ? 1 : 0,
            'message' => "{$ok} of " . count($results) . ' orders imported successfully.',
            'results' => $results,
        ]);
    }

    private function failed(WooCommerceException $e, string $message): JsonResponse
    {
        return response()->json(['statut' => 0, 'message' => $message, 'error' => $e->getMessage()], 502);
    }
}
