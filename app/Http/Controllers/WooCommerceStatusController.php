<?php

namespace App\Http\Controllers;

use App\Models\WooCommerce\OrderLink;
use App\Models\WooCommerce\StatusMapping;
use App\Services\WooCommerce\StatusPusher;
use App\Services\WooCommerce\WooCommerceException;
use App\Support\WooCommerce\StoreResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Which WooCommerce status each CodConnect status is pushed as (per store), and manual pushes of one or
 * several orders. The automatic push is done by the order observer and the queued job.
 */
class WooCommerceStatusController extends Controller
{
    public const WC_STATUSES = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];

    /** One row per CodConnect status, with its mapping when there is one. */
    public function index($id): JsonResponse
    {
        $store = StoreResolver::resolve($id);
        $mappings = StatusMapping::where('store_id', $store->id)->get()->keyBy('order_status_id');

        return response()->json(['statut' => 1, 'data' => DB::table('order_statuses')->orderBy('id')->get(['id', 'title'])->map(fn ($status) => [
            'order_status_id' => $status->id,
            'title' => $status->title,
            'wc_status' => $mappings[$status->id]->wc_status ?? null,
            'is_enabled' => (bool) ($mappings[$status->id]->is_enabled ?? false),
        ])]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $store = StoreResolver::resolve($id);

        $validator = Validator::make($request->all(), [
            'mappings' => 'required|array|min:1|max:50',
            'mappings.*.order_status_id' => 'required|integer|exists:order_statuses,id',
            'mappings.*.wc_status' => ['required', 'string', Rule::in(self::WC_STATUSES)],
            'mappings.*.is_enabled' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($store, $request) {
            foreach ($request->input('mappings') as $mapping) {
                StatusMapping::updateOrCreate(
                    ['store_id' => $store->id, 'order_status_id' => $mapping['order_status_id']],
                    ['wc_status' => $mapping['wc_status'], 'is_enabled' => (bool) $mapping['is_enabled']]
                );
            }
        });

        return $this->index($id);
    }

    /** Pushes one imported order now: its mapped status, or the WooCommerce status given in "status". */
    public function pushOne(Request $request, StatusPusher $pusher, $id, $wcOrderId): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string', Rule::in(self::WC_STATUSES)]]);
        $store = StoreResolver::resolve($id);

        $link = OrderLink::where('store_id', $store->id)->where('wc_order_id', $wcOrderId)->first();
        if (! $link) {
            return response()->json(['statut' => 0, 'message' => 'This WooCommerce order has not been imported.'], 404);
        }

        return $this->push($pusher, $link, $request->input('status'));
    }

    /** Pushes several imported orders (at most 50). */
    public function pushMany(Request $request, StatusPusher $pusher, $id): JsonResponse
    {
        $request->validate([
            'wc_order_ids' => 'required|array|min:1|max:50',
            'wc_order_ids.*' => 'integer|min:1',
            'status' => ['nullable', 'string', Rule::in(self::WC_STATUSES)],
        ]);
        $store = StoreResolver::resolve($id);

        $links = OrderLink::where('store_id', $store->id)->whereIn('wc_order_id', $request->input('wc_order_ids'))->get()->keyBy('wc_order_id');

        $results = collect($request->input('wc_order_ids'))->map(function ($wcOrderId) use ($links, $pusher, $request) {
            $link = $links->get((int) $wcOrderId);
            if (! $link) {
                return ['wc_order_id' => (int) $wcOrderId, 'status' => 'failed', 'message' => 'Not imported.'];
            }

            try {
                return ['wc_order_id' => (int) $wcOrderId] + $pusher->push($link, $request->input('status'));
            } catch (WooCommerceException $e) {
                return ['wc_order_id' => (int) $wcOrderId, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        })->values();

        return response()->json(['statut' => 1, 'data' => $results, 'message' => $results->where('status', 'success')->count() . ' of ' . $results->count() . ' orders updated.']);
    }

    private function push(StatusPusher $pusher, OrderLink $link, ?string $status): JsonResponse
    {
        try {
            $result = $pusher->push($link, $status);
        } catch (WooCommerceException $e) {
            return response()->json(['statut' => 0, 'message' => $e->getMessage()], 502);
        }

        return response()->json(['statut' => 1] + $result);
    }
}
