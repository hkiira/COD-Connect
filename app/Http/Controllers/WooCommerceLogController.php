<?php

namespace App\Http\Controllers;

use App\Models\WooCommerce\OrderLink;
use App\Models\WooCommerce\SyncLog;
use App\Services\WooCommerce\OrderService;
use App\Services\WooCommerce\StatusPusher;
use App\Services\WooCommerce\WooCommerceException;
use App\Support\WooCommerce\StoreResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The WooCommerce activity log of a store, and "Retry" for the failed lines. */
class WooCommerceLogController extends Controller
{
    public function index(Request $request, $id): JsonResponse
    {
        $request->validate([
            'direction' => 'nullable|in:import,push,mapping',
            'status' => 'nullable|in:success,failed,skipped',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $store = StoreResolver::resolve($id);

        $page = SyncLog::where('store_id', $store->id)
            ->when($request->query('direction'), fn ($q, $direction) => $q->where('direction', $direction))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 25), ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'statut' => 1,
            'data' => $page->items(),
            'meta' => ['count' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ]);
    }

    /** Repeats a failed import or push. A success makes the line stop being offered for retry. */
    public function retry(StatusPusher $pusher, $id, $logId): JsonResponse
    {
        $store = StoreResolver::resolve($id);
        $log = SyncLog::where('store_id', $store->id)->findOrFail($logId);

        if ($log->status !== 'failed' || ! $log->wc_id || ! in_array($log->direction, ['push', 'import'], true)) {
            return response()->json(['statut' => 0, 'message' => 'Only a failed import or push can be retried.'], 422);
        }

        try {
            if ($log->direction === 'push') {
                $link = OrderLink::where('store_id', $store->id)->where('wc_order_id', $log->wc_id)->first();
                if (! $link) {
                    return response()->json(['statut' => 0, 'message' => 'This WooCommerce order has not been imported.'], 404);
                }
                $result = $pusher->push($link);
            } else {
                $result = (new OrderService($store))->import([['wc_order_id' => $log->wc_id]])[0];
                if (! $result['success'] && ! ($result['skipped'] ?? false)) {
                    return response()->json(['statut' => 0, 'message' => $result['message']], 422);
                }
            }
        } catch (WooCommerceException $e) {
            return response()->json(['statut' => 0, 'message' => $e->getMessage()], 502);
        }

        // the retry left its own line in the log; the failed one is closed so it is not offered again
        $log->update(['status' => 'skipped', 'message' => $log->message . ' (retried)']);

        return response()->json(['statut' => 1, 'message' => $result['message']]);
    }
}
