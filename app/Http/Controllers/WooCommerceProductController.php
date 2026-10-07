<?php

namespace App\Http\Controllers;

use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\SyncLog;
use App\Models\WooCommerce\VariationLink;
use App\Services\WooCommerce\VariationMatcher;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use App\Support\Orders\OrderOwnership;
use App\Support\WooCommerce\StoreResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Products of a WooCommerce store and their match with CodConnect product variations:
 * the catalog with its match state, the variations of one product, manual and bulk links,
 * unlinking, and auto-match proposals (never saved until the user applies them).
 */
class WooCommerceProductController extends Controller
{
    /** "Unmatched only" has to look at WooCommerce pages one by one: stop after this many products. */
    private const UNMATCHED_SCAN_LIMIT = 1000;

    public function index(Request $request, $id): JsonResponse
    {
        $store = StoreResolver::resolve($id);
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));
        $unmatchedOnly = filter_var($request->query('unmatched'), FILTER_VALIDATE_BOOLEAN);
        $client = WooCommerceClient::fromStore($store);

        try {
            if (! $unmatchedOnly) {
                $result = $client->products(array_filter(['page' => $page, 'per_page' => $perPage, 'search' => $search ?: null]));
                $items = $this->withState($store, $result['data']);

                return response()->json(['statut' => 1, 'data' => $items, 'meta' => ['count' => $result['total'], 'page' => $page, 'per_page' => $perPage, 'truncated' => false]]);
            }

            // unmatched only: WooCommerce cannot filter by our links, so read pages until enough are found
            $found = collect();
            $scanned = 0;
            $truncated = false;
            for ($wcPage = 1; $found->count() < $page * $perPage; $wcPage++) {
                $result = $client->products(array_filter(['page' => $wcPage, 'per_page' => 100, 'search' => $search ?: null]));
                $scanned += count($result['data']);
                $found = $found->merge(collect($this->withState($store, $result['data']))->where('state', '!=', 'matched'));

                if ($wcPage >= $result['total_pages'] || ! $result['data']) {
                    break;
                }
                if ($scanned >= self::UNMATCHED_SCAN_LIMIT) {
                    $truncated = true;
                    break;
                }
            }

            return response()->json([
                'statut' => 1,
                'data' => $found->slice(($page - 1) * $perPage, $perPage)->values(),
                'meta' => ['count' => $found->count(), 'page' => $page, 'per_page' => $perPage, 'truncated' => $truncated, 'scanned' => $scanned],
            ]);
        } catch (WooCommerceException $e) {
            return $this->failed($e);
        }
    }

    /** The variations of one WooCommerce product, each with the CodConnect variation it is linked to. */
    public function variations($id, $productId): JsonResponse
    {
        $store = StoreResolver::resolve($id);

        try {
            $client = WooCommerceClient::fromStore($store);
            $product = $client->product((int) $productId);
            $variations = $product['type'] === 'variable' ? $client->variations((int) $productId)['data'] : [];
        } catch (WooCommerceException $e) {
            return $this->failed($e);
        }

        $rows = $variations ?: [['id' => 0, 'sku' => $product['sku'] ?? '', 'attributes' => [], 'price' => $product['price'] ?? null, 'stock_quantity' => $product['stock_quantity'] ?? null]];
        $links = $this->linksFor($store, (int) $productId, collect($rows)->pluck('id')->all());
        $local = $this->describePvas($links->pluck('product_variation_attribute_id')->all());

        return response()->json(['statut' => 1, 'data' => collect($rows)->map(function ($variation) use ($links, $local, $productId) {
            $link = $links->get((int) $variation['id']);

            return [
                'wc_product_id' => (int) $productId,
                'wc_variation_id' => (int) $variation['id'],
                'sku' => $variation['sku'] ?? '',
                'options' => collect($variation['attributes'] ?? [])->pluck('option')->values(),
                'price' => $variation['price'] ?? null,
                'stock_quantity' => $variation['stock_quantity'] ?? null,
                'link_id' => $link?->id,
                'local' => $link ? ($local[$link->product_variation_attribute_id] ?? null) : null,
            ];
        })->values()]);
    }

    /** Creates (or moves) links. Accepts one link or a "links" list; every variation must belong to the account. */
    public function link(Request $request, $id): JsonResponse
    {
        $store = StoreResolver::resolve($id);
        $payload = $request->has('links') ? $request->input('links') : [$request->all()];

        $validator = Validator::make(['links' => $payload], [
            'links' => 'required|array|min:1|max:500',
            'links.*.wc_product_id' => 'required|integer|min:1',
            'links.*.wc_variation_id' => 'required|integer|min:0',
            'links.*.product_variation_attribute_id' => ['required', 'integer', fn ($attribute, $value, $fail) => OrderOwnership::ownsProductVariation($value) ?: $fail('not exist')],
            'links.*.source' => 'sometimes|in:manual,auto',
        ]);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($store, $payload) {
            foreach ($payload as $link) {
                VariationLink::updateOrCreate(
                    ['store_id' => $store->id, 'wc_product_id' => $link['wc_product_id'], 'wc_variation_id' => $link['wc_variation_id']],
                    ['account_id' => $store->account_id, 'product_variation_attribute_id' => $link['product_variation_attribute_id'], 'source' => $link['source'] ?? 'manual']
                );
                SyncLog::record($store, 'mapping', 'variation', 'success', 'Variation linked', [
                    'entity_id' => $link['product_variation_attribute_id'],
                    'wc_id' => $link['wc_variation_id'] ?: $link['wc_product_id'],
                ]);
            }
        });

        return response()->json(['statut' => 1, 'message' => count($payload) . ' link(s) saved.']);
    }

    public function unlink($id, $linkId): JsonResponse
    {
        $store = StoreResolver::resolve($id);
        $link = VariationLink::where('store_id', $store->id)->findOrFail($linkId);

        SyncLog::record($store, 'mapping', 'variation', 'success', 'Variation unlinked', [
            'entity_id' => $link->product_variation_attribute_id,
            'wc_id' => $link->wc_variation_id ?: $link->wc_product_id,
        ]);
        $link->delete();

        return response()->json(['statut' => 1]);
    }

    /**
     * Proposals for the unlinked variations of some WooCommerce products ("product_ids", at most 10: each one costs two calls to the store).
     * Nothing is written: the screen shows them and posts the accepted ones to link().
     */
    public function autoMatch(Request $request, $id): JsonResponse
    {
        $store = StoreResolver::resolve($id);
        $validator = Validator::make($request->all(), ['product_ids' => 'required|array|min:1|max:10', 'product_ids.*' => 'integer|min:1']);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $client = WooCommerceClient::fromStore($store);
        $matcher = new VariationMatcher();
        $proposals = [];

        try {
            foreach ($request->input('product_ids') as $productId) {
                $product = $client->product((int) $productId);
                $variations = $product['type'] === 'variable' ? $client->variations((int) $productId)['data'] : [];
                $ids = $variations ? collect($variations)->pluck('id')->all() : [0];
                $linked = $this->linksFor($store, (int) $productId, $ids);

                // only the variations that are not linked yet
                $open = collect($variations)->reject(fn ($variation) => $linked->has((int) $variation['id']))->values()->all();
                if ($variations && ! $open) {
                    continue;
                }
                if (! $variations && $linked->has(0)) {
                    continue;
                }

                foreach ($matcher->propose($product, $open, (int) $store->account_id) as $proposal) {
                    $proposals[] = $proposal + ['wc_product_name' => $product['name']];
                }
            }
        } catch (WooCommerceException $e) {
            return $this->failed($e);
        }

        $local = $this->describePvas(collect($proposals)->pluck('product_variation_attribute_id')->all());

        return response()->json(['statut' => 1, 'data' => collect($proposals)->map(fn ($p) => $p + ['local' => $local[$p['product_variation_attribute_id']] ?? null])->values()]);
    }

    // -------------------------------------------------------------------------

    /** Adds the match state to a page of WooCommerce products. */
    private function withState(Store $store, array $products): array
    {
        $variationIds = collect($products)->flatMap(fn ($p) => $p['variations'] ?? [])->all();
        $productIds = collect($products)->pluck('id')->all();

        $links = VariationLink::where('store_id', $store->id)
            ->where(fn ($q) => $q->whereIn('wc_variation_id', array_merge($variationIds, $productIds))->orWhereIn('wc_product_id', $productIds))
            ->get();
        $linkedVariations = $links->pluck('wc_variation_id')->flip();
        $linkedSimple = $links->where('wc_variation_id', 0)->pluck('wc_product_id')->flip();

        return array_map(function ($product) use ($linkedVariations, $linkedSimple) {
            $variationIds = $product['variations'] ?? [];
            $total = max(1, count($variationIds));
            $linked = $variationIds
                ? collect($variationIds)->filter(fn ($vid) => $linkedVariations->has($vid))->count()
                : (int) ($linkedSimple->has($product['id']) || $linkedVariations->has($product['id']));

            return [
                'wc_product_id' => $product['id'],
                'name' => $product['name'],
                'sku' => $product['sku'] ?? '',
                'type' => $product['type'],
                'status' => $product['status'] ?? null,
                'price' => $product['price'] ?? null,
                'stock_status' => $product['stock_status'] ?? null,
                'image' => $product['images'][0]['src'] ?? null,
                'permalink' => $product['permalink'] ?? null,
                'variations_total' => $total,
                'variations_linked' => $linked,
                'state' => $linked === 0 ? 'unmatched' : ($linked >= $total ? 'matched' : 'partial'),
            ];
        }, $products);
    }

    /**
     * Links of a product's variations keyed by WooCommerce variation id (0 for a simple product). Rows copied
     * from the old single-store data only know the variation id, so they are found by it as well.
     *
     * @return Collection<int, VariationLink>
     */
    private function linksFor(Store $store, int $productId, array $variationIds): Collection
    {
        $ids = array_map('intval', $variationIds);

        return VariationLink::where('store_id', $store->id)
            ->where(fn ($q) => $q->where('wc_product_id', $productId)->orWhereIn('wc_variation_id', array_merge($ids, [$productId])))
            ->get()
            ->keyBy(fn ($link) => (int) ($link->wc_variation_id ?: 0))
            ->mapWithKeys(function ($link, $key) use ($productId) {
                // a legacy simple product was stored with the product id in the variation column
                return [($key === $productId ? 0 : $key) => $link];
            });
    }

    /** @return array<int, array{product_variation_attribute_id:int, product_id:int, title:string, options:array<int,string>}> */
    private function describePvas(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $rows = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->leftJoin('variation_attributes as va', 'va.variation_attribute_id', '=', 'pva.variation_attribute_id')
            ->leftJoin('attributes as a', 'a.id', '=', 'va.attribute_id')
            ->whereIn('pva.id', array_unique($ids))
            ->select('pva.id', 'pva.product_id', 'p.title', 'p.reference', 'a.title as option')
            ->get();

        return $rows->groupBy('id')->map(fn ($group, $id) => [
            'product_variation_attribute_id' => (int) $id,
            'product_id' => (int) $group[0]->product_id,
            'title' => $group[0]->title,
            'reference' => $group[0]->reference,
            'options' => $group->pluck('option')->filter()->values()->all(),
        ])->all();
    }

    private function failed(WooCommerceException $e): JsonResponse
    {
        return response()->json(['statut' => 0, 'message' => $e->getMessage()], 502);
    }
}
