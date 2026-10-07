<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\WooCommerce\OrderLink;
use App\Models\WooCommerce\SyncLog;
use App\Models\WooCommerce\VariationLink;
use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\StatusMapping;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use App\Support\Orders\OrderOwnership;
use App\Support\Orders\OrderStatus;
use App\Support\WooCommerce\StoreResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The WooCommerce stores of the account: list, create, edit, delete and "test connection".
 * Keys are write-only: they are never returned, an empty value on update keeps the stored one.
 */
class WooCommerceStoreController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'statut' => 1,
            'data' => Store::orderBy('name')->get()->map->toPanelArray()->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = $this->validator($request, creating: true);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $store = Store::create($this->payload($request));

        $store->seedDefaultMappings();

        return response()->json(['statut' => 1, 'data' => $store->toPanelArray()], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $store = StoreResolver::resolve($id);

        $validator = $this->validator($request, creating: false, store: $store);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $store->update($this->payload($request));

        return response()->json(['statut' => 1, 'data' => $store->fresh()->toPanelArray()]);
    }

    public function destroy($id): JsonResponse
    {
        StoreResolver::resolve($id)->delete();

        return response()->json(['statut' => 1]);
    }

    /** Calls the store with the saved keys and remembers whether it worked. */
    public function test($id): JsonResponse
    {
        $store = StoreResolver::resolve($id);

        try {
            $counts = WooCommerceClient::fromStore($store)->ping();
            $store->update(['last_checked_at' => now(), 'last_error' => null]);

            return response()->json(['statut' => 1, 'message' => 'Connection works.', 'data' => $counts]);
        } catch (WooCommerceException $e) {
            $store->update(['last_checked_at' => now(), 'last_error' => $e->getMessage()]);

            return response()->json(['statut' => 0, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Figures for the control panel home. What only the store knows (orders waiting, products) is read live but
     * cached for two minutes, so opening the page repeatedly does not hammer the website; a store that cannot be
     * reached still gives the local figures, plus the reason.
     */
    public function overview($id): JsonResponse
    {
        $store = StoreResolver::resolve($id);
        $today = now()->startOfDay();
        $week = now()->subDays(7);

        $remote = Cache::remember("wc-overview:{$store->id}", 120, function () use ($store) {
            try {
                $client = WooCommerceClient::fromStore($store);
                $status = $store->import_statuses[0] ?? 'processing';

                return [
                    'waiting_orders' => $client->orders(['status' => $status, 'per_page' => 1])['total'],
                    'products_total' => $client->products(['per_page' => 1])['total'],
                    'error' => null,
                ];
            } catch (WooCommerceException $e) {
                return ['waiting_orders' => null, 'products_total' => null, 'error' => $e->getMessage()];
            }
        });

        $logs = SyncLog::where('store_id', $store->id);
        $links = OrderLink::where('store_id', $store->id);
        $metric = fn ($value) => ['value' => $value];

        return response()->json(['statut' => 1, 'data' => [
            'store' => $store->toPanelArray(),
            'wc_error' => $remote['error'],
            'summary' => [
                'waiting_orders' => $metric($remote['waiting_orders']),
                'imported_today' => $metric((clone $links)->where('imported_at', '>=', $today)->count()),
                'imported_total' => $metric((clone $links)->count()),
                'products_total' => $metric($remote['products_total']),
                'linked_variations' => $metric(VariationLink::where('store_id', $store->id)->count()),
                'pushed_today' => $metric((clone $logs)->where('direction', 'push')->where('status', 'success')->where('created_at', '>=', $today)->count()),
                'failed_week' => $metric((clone $logs)->where('status', 'failed')->where('created_at', '>=', $week)->count()),
            ],
            'last_activity_at' => (clone $logs)->latest('id')->value('created_at'),
        ]]);
    }

    private function validator(Request $request, bool $creating, ?Store $store = null)
    {
        $accountId = (int) getAccountUser()->account_id;

        return Validator::make($request->all(), [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'base_url' => [$creating ? 'required' : 'sometimes', 'url', 'max:255'],
            // required to create; on update an empty value keeps the saved one
            'consumer_key' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'consumer_secret' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'verify_ssl' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'auto_import' => 'sometimes|boolean',
            'import_statuses' => 'sometimes|array',
            'import_statuses.*' => ['string', Rule::in(['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'])],
            'wc_status_after_import' => ['sometimes', 'string', 'max:40'],
            'default_warehouse_id' => ['nullable', fn ($attribute, $value, $fail) => $value === null || Warehouse::where(['id' => $value, 'account_id' => $accountId])->exists() ?: $fail('not exist')],
            'default_brand_source_id' => ['nullable', fn ($attribute, $value, $fail) => $value === null || OrderOwnership::ownsBrandSource($value) ?: $fail('not exist')],
        ]);
    }

    /** Only the editable columns; empty keys are dropped so an update keeps the saved ones. */
    private function payload(Request $request): array
    {
        $data = $request->only([
            'name', 'base_url', 'verify_ssl', 'is_active', 'auto_import', 'import_statuses',
            'wc_status_after_import', 'default_warehouse_id', 'default_brand_source_id',
        ]);

        foreach (['consumer_key', 'consumer_secret'] as $secret) {
            if (filled($request->input($secret))) {
                $data[$secret] = $request->input($secret);
            }
        }

        if (isset($data['base_url'])) {
            $data['base_url'] = rtrim($data['base_url'], '/');
        }

        return $data;
    }
}
