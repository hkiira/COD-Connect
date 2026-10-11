<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\WooCommerce\OrderLink;
use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\SyncLog;
use App\Notifications\AppAlert;
use App\Services\WooCommerce\OrderService;
use App\Services\WooCommerce\VariationLinkResolver;
use App\Services\WooCommerce\WooCommerceClient;
use App\Support\AccountContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Handles incoming webhooks from WordPress/WooCommerce.
 *
 * When a customer places an order on WordPress, the store's snippet/plugin immediately calls:
 * POST /api/wc/stores/{id}/webhook
 * Headers: X-WC-Secret: <consumer_secret or webhook_secret>
 * Body: { "wc_order_id": 1234 }
 */
class WooCommerceWebhookController extends Controller
{
    /**
     * Lists active stores matching the provided secret (and optional site_url).
     * Used by the WordPress plugin to automatically discover and list stores in the settings dropdown.
     */
    public function discover(Request $request): JsonResponse
    {
        $providedSecret = $request->header('X-WC-Secret') ?: $request->bearerToken() ?: $request->input('secret');
        if (! $providedSecret) {
            return response()->json(['statut' => 0, 'message' => 'Missing X-WC-Secret header or secret.'], 401);
        }

        $siteUrl = $request->input('site_url');
        $siteHost = $siteUrl ? parse_url($siteUrl, PHP_URL_HOST) : null;
        $globalSecret = config('services.woocommerce.webhook_secret');

        $stores = Store::withoutGlobalScopes()
            ->where('is_active', true)
            ->get()
            ->filter(function (Store $store) use ($providedSecret, $globalSecret, $siteHost) {
                $secretMatches = hash_equals((string) $store->consumer_secret, (string) $providedSecret)
                    || ($globalSecret && hash_equals((string) $globalSecret, (string) $providedSecret));

                if (! $secretMatches) {
                    return false;
                }

                if ($siteHost) {
                    $storeHost = parse_url($store->base_url, PHP_URL_HOST);
                    if ($storeHost && strcasecmp($storeHost, $siteHost) !== 0) {
                        return false;
                    }
                }

                return true;
            })
            ->map(fn (Store $s) => [
                'id'       => $s->id,
                'name'     => $s->name,
                'base_url' => $s->base_url,
            ])
            ->values();

        return response()->json([
            'statut' => 1,
            'data'   => $stores,
        ]);
    }

    public function handle(Request $request, $id): JsonResponse
    {
        $store = Store::withoutGlobalScopes()->where('is_active', true)->find($id);
        if (! $store) {
            return response()->json(['statut' => 0, 'message' => 'Store not found or inactive.'], 404);
        }

        $providedSecret = $request->header('X-WC-Secret') ?: $request->bearerToken() ?: $request->input('secret');
        if (! $providedSecret) {
            return response()->json(['statut' => 0, 'message' => 'Missing X-WC-Secret header.'], 401);
        }

        $globalSecret = config('services.woocommerce.webhook_secret');
        $valid = hash_equals((string) $store->consumer_secret, (string) $providedSecret)
            || ($globalSecret && hash_equals((string) $globalSecret, (string) $providedSecret));

        if (! $valid) {
            return response()->json(['statut' => 0, 'message' => 'Unauthorized: invalid secret.'], 403);
        }

        // Test mode: verify credentials and connectivity without creating an order
        if ($request->boolean('test') || $request->input('wc_order_id') === 'test') {
            return response()->json([
                'statut'  => 1,
                'message' => "Connexion réussie avec CodConnect pour la boutique : {$store->name}",
                'store'   => [
                    'id'   => $store->id,
                    'name' => $store->name,
                ],
            ]);
        }

        $wcOrderId = (int) ($request->input('wc_order_id') ?: $request->input('id'));
        if (! $wcOrderId) {
            return response()->json(['statut' => 0, 'message' => 'Missing order ID (wc_order_id or id).'], 422);
        }

        // Check if already imported
        $existing = OrderLink::where('store_id', $store->id)->where('wc_order_id', $wcOrderId)->first();
        if ($existing) {
            return response()->json([
                'statut' => 1,
                'message' => 'Order already imported.',
                'order_id' => $existing->order_id,
                'skipped' => true,
            ]);
        }

        $actor = $this->actorFor((int) $store->account_id);
        if (! $actor) {
            return response()->json(['statut' => 0, 'message' => 'No active user found for this account.'], 500);
        }
        Auth::setUser($actor);

        return AccountContext::run((int) $store->account_id, function () use ($store, $wcOrderId, $request) {
            try {
                $client = WooCommerceClient::fromStore($store);
                $wcOrder = $request->input('order') ?: $client->order($wcOrderId);
            } catch (\Throwable $e) {
                Log::error("WooCommerce webhook: failed to fetch order #{$wcOrderId}: " . $e->getMessage());
                return response()->json([
                    'statut' => 0,
                    'message' => 'Failed to retrieve order from WooCommerce: ' . $e->getMessage(),
                ], 502);
            }

            // Verify products mapping
            $resolver = new VariationLinkResolver($store);
            $lineItems = $wcOrder['line_items'] ?? [];
            $unmatchedCount = 0;

            foreach ($lineItems as $item) {
                $pva = $resolver->resolve((int) ($item['product_id'] ?? 0), (int) ($item['variation_id'] ?? 0));
                if (! $pva?->product) {
                    $unmatchedCount++;
                }
            }

            if ($unmatchedCount > 0 || empty($lineItems)) {
                // Block automatic import & notify user as decided in grill-me
                $this->notifyUnmatched($store, $wcOrderId, $unmatchedCount);

                SyncLog::record(
                    $store,
                    'import',
                    'order',
                    'failed',
                    "Webhook order #{$wcOrderId} blocked: {$unmatchedCount} unmatched product(s).",
                    ['wc_id' => $wcOrderId]
                );

                return response()->json([
                    'statut' => 0,
                    'message' => "Order #{$wcOrderId} blocked: contains {$unmatchedCount} unmatched product(s). Notification sent.",
                    'unmatched_items' => $unmatchedCount,
                ], 422);
            }

            // All items matched -> import the order
            $service = new OrderService($store);
            $results = $service->import([['wc_order_id' => $wcOrderId]]);
            $result = $results[0] ?? null;

            if ($result && ($result['success'] ?? false)) {
                $customerName = trim(($wcOrder['billing']['first_name'] ?? '') . ' ' . ($wcOrder['billing']['last_name'] ?? '')) ?: 'Client';
                $total = ($wcOrder['total'] ?? '0') . ' ' . ($wcOrder['currency'] ?? 'MAD');
                $systemOrderId = $result['system_order_id'] ?? null;

                $this->notifySuccess($store, $wcOrderId, $customerName, $total, $systemOrderId);

                return response()->json([
                    'statut' => 1,
                    'message' => "Order #{$wcOrderId} imported successfully.",
                    'system_order_id' => $systemOrderId,
                ]);
            }

            $errorMsg = $result['message'] ?? 'Import failed.';
            return response()->json(['statut' => 0, 'message' => $errorMsg], 422);
        });
    }

    private function notifySuccess(Store $store, int $wcOrderId, string $customerName, string $total, ?int $systemOrderId): void
    {
        $users = AccountUser::where('account_id', $store->account_id)->where('statut', 1)->get();
        $title = "Nouvelle commande #{$wcOrderId} - {$store->name}";
        $message = "Commande de {$customerName} ({$total}) importée avec succès.";
        $link = "/dashboard/wc-orders/{$wcOrderId}";

        Notification::send($users, new AppAlert($title, $message, $link, 'WooCommerce', 'order'));
    }

    private function notifyUnmatched(Store $store, int $wcOrderId, int $unmatchedCount): void
    {
        $users = AccountUser::where('account_id', $store->account_id)->where('statut', 1)->get();
        $title = "⚠️ Commande #{$wcOrderId} sur {$store->name}";
        $message = "En attente de liaison: {$unmatchedCount} article(s) non associé(s) dans CodConnect.";
        $link = "/dashboard/woocommerce/products?store={$store->id}";

        Notification::send($users, new AppAlert($title, $message, $link, 'WooCommerce', 'order'));
    }

    private function actorFor(int $accountId)
    {
        return AccountUser::where('account_id', $accountId)->with('user.accountUsers')->get()
            ->map->user
            ->filter()
            ->first(fn ($user) => (int) $user->accountUsers->sortBy('id')->first()?->account_id === $accountId);
    }
}
