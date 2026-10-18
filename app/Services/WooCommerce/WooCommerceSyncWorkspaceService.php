<?php

namespace App\Services\WooCommerce;

use App\Http\Controllers\DefaultCodeController;
use App\Models\AccountProduct;
use App\Models\AccountUser;
use App\Models\Attribute;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariationAttribute;
use App\Models\PvaMeasurement;
use App\Models\TypeAttribute;
use App\Models\VariationAttribute;
use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\SyncLog;
use App\Models\WooCommerce\VariationLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WooCommerceSyncWorkspaceService
{
    /**
     * Scans the WooCommerce store catalog and categorizes items into:
     * - already matched
     * - auto-match proposals (existing local PVA matches by SKU or variation attributes)
     * - missing variations (local product exists, but specific variation is missing)
     * - missing products (neither product nor variations exist in CodConnect)
     */
    public function analyze(Store $store): array
    {
        $client = WooCommerceClient::fromStore($store);
        $matcher = new VariationMatcher();
        $accountId = (int) $store->account_id;

        // Fetch products from WooCommerce (up to 100 products per analyze run)
        $wcProductsResult = $client->products(['per_page' => 100, 'page' => 1]);
        $wcProducts = $wcProductsResult['data'] ?? [];

        $proposals = [];
        $missingProducts = [];
        $missingVariations = [];
        $matchedCount = 0;
        $totalVariationsCount = 0;

        foreach ($wcProducts as $wcProduct) {
            $productId = (int) $wcProduct['id'];
            $isVariable = ($wcProduct['type'] ?? '') === 'variable';

            // Fetch variations for variable products
            $variations = $isVariable ? ($client->variations($productId)['data'] ?? []) : [];
            $items = $variations ?: [['id' => 0, 'sku' => $wcProduct['sku'] ?? '', 'attributes' => [], 'price' => $wcProduct['price'] ?? null]];

            $totalVariationsCount += count($items);

            // Fetch existing links for this product
            $linkedMap = VariationLink::where('store_id', $store->id)
                ->where('wc_product_id', $productId)
                ->get()
                ->keyBy('wc_variation_id');

            // Find if a local product already exists in CodConnect
            $localProduct = $this->findLocalProduct($productId, (string) ($wcProduct['sku'] ?? ''), (string) ($wcProduct['name'] ?? ''), $accountId);

            $productHasUnlinked = false;
            $unlinkedVariations = [];

            foreach ($items as $item) {
                $varId = (int) $item['id'];
                if ($linkedMap->has($varId)) {
                    $matchedCount++;
                    continue;
                }

                $productHasUnlinked = true;
                $unlinkedVariations[] = $item;
            }

            if (! $productHasUnlinked) {
                continue;
            }

            // Run proposals for unlinked variations
            $detectedProposals = $matcher->propose($wcProduct, $unlinkedVariations, $accountId);
            $proposedVarIds = [];

            foreach ($detectedProposals as $prop) {
                $proposedVarIds[] = $prop['wc_variation_id'];
                $proposals[] = $prop + [
                    'wc_product_name' => $wcProduct['name'],
                    'sku' => $this->itemSku($unlinkedVariations, $prop['wc_variation_id']),
                    'options' => $this->itemOptions($unlinkedVariations, $prop['wc_variation_id']),
                ];
            }

            // For variations that could NOT be proposed:
            $remaining = array_filter($unlinkedVariations, fn ($v) => ! in_array((int) $v['id'], $proposedVarIds, true));

            if ($remaining) {
                if ($localProduct) {
                    // Local product exists, so these are missing variations
                    foreach ($remaining as $rem) {
                        $missingVariations[] = [
                            'wc_product_id' => $productId,
                            'wc_product_name' => $wcProduct['name'],
                            'wc_variation_id' => (int) $rem['id'],
                            'sku' => $rem['sku'] ?? '',
                            'options' => collect($rem['attributes'] ?? [])->pluck('option')->values()->all(),
                            'price' => $rem['price'] ?? $wcProduct['price'] ?? null,
                            'local_product_id' => $localProduct->id,
                            'local_product_title' => $localProduct->title,
                        ];
                    }
                } else {
                    // Local product does not exist in CodConnect at all
                    $missingProducts[] = [
                        'wc_product_id' => $productId,
                        'name' => $wcProduct['name'],
                        'sku' => $wcProduct['sku'] ?? '',
                        'type' => $wcProduct['type'] ?? 'simple',
                        'price' => $wcProduct['price'] ?? null,
                        'image' => $wcProduct['images'][0]['src'] ?? null,
                        'variations' => array_map(fn ($v) => [
                            'wc_variation_id' => (int) $v['id'],
                            'sku' => $v['sku'] ?? '',
                            'price' => $v['price'] ?? null,
                            'options' => collect($v['attributes'] ?? [])->pluck('option')->values()->all(),
                        ], $remaining),
                    ];
                }
            }
        }

        // Add local descriptors to proposals
        $localDesc = $this->describePvas(collect($proposals)->pluck('product_variation_attribute_id')->all());
        $enrichedProposals = array_map(fn ($p) => $p + ['local' => $localDesc[$p['product_variation_attribute_id']] ?? null], $proposals);

        return [
            'statut' => 1,
            'summary' => [
                'total_products' => count($wcProducts),
                'total_variations' => $totalVariationsCount,
                'matched' => $matchedCount,
                'proposed' => count($enrichedProposals),
                'missing_variations' => count($missingVariations),
                'missing_products' => count($missingProducts),
            ],
            'proposals' => $enrichedProposals,
            'missing_variations' => $missingVariations,
            'missing_products' => $missingProducts,
        ];
    }

    /**
     * Generates a new product and all its variations in CodConnect from WooCommerce, and links them.
     */
    public function generateProduct(Store $store, int $wcProductId): array
    {
        $client = WooCommerceClient::fromStore($store);
        $productData = $client->product($wcProductId);
        $isVariable = ($productData['type'] ?? '') === 'variable';
        $variationsData = $isVariable ? ($client->variations($wcProductId)['data'] ?? []) : [];

        $accountId = (int) $store->account_id;
        $accountUser = AccountUser::where('account_id', $accountId)->where('statut', 1)->first();
        if (! $accountUser) {
            throw new \RuntimeException("Aucun utilisateur actif trouvé pour le compte #{$accountId}");
        }

        return DB::transaction(function () use ($store, $productData, $variationsData, $isVariable, $accountId, $accountUser) {
            $reference = trim((string) ($productData['sku'] ?: 'WC-' . $productData['id']));
            
            // Generate product code
            $code = DefaultCodeController::getAccountCode('Product', $accountId) ?: ('PRD-' . $productData['id']);

            $product = Product::create([
                'title' => $productData['name'],
                'reference' => $reference,
                'code' => $code,
                'statut' => 1,
                'account_user_id' => $accountUser->id,
                'product_type_id' => 1, // Standard product
                'meta' => [['id' => (int) $productData['id']]],
            ]);

            AccountProduct::create([
                'product_id' => $product->id,
                'account_id' => $accountId,
                'statut' => 1,
            ]);

            // Default Offer
            $offerCode = DefaultCodeController::getAccountCode('Offer', $accountId) ?: ('OFF-' . $product->id);
            $offer = Offer::create([
                'code' => $offerCode,
                'title' => $product->title,
                'price' => (float) ($productData['price'] ?: 0),
                'account_id' => $accountId,
                'offer_type_id' => 1,
            ]);
            $offer->products()->syncWithoutDetaching([
                $product->id => ['account_user_id' => $accountUser->id, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $linksCount = 0;

            if ($isVariable && $variationsData) {
                // Ensure attributes and type attributes exist
                $attributeLookup = $this->ensureAttributes($productData['attributes'] ?? [], $accountUser);

                foreach ($variationsData as $var) {
                    $attrIds = [];
                    foreach ($var['attributes'] ?? [] as $attr) {
                        $attrName = trim((string) ($attr['name'] ?? ''));
                        $attrOption = trim((string) ($attr['option'] ?? ''));
                        if (isset($attributeLookup[$attrName][$attrOption])) {
                            $attrIds[] = $attributeLookup[$attrName][$attrOption];
                        }
                    }

                    $variationAttributeId = $this->ensureVariationAttributeCombination($attrIds, $accountId);

                    $pvaCode = 'REF:' . $product->reference . '-' . $var['id'];
                    $pva = ProductVariationAttribute::create([
                        'account_id' => $accountId,
                        'product_id' => $product->id,
                        'variation_attribute_id' => $variationAttributeId,
                        'code' => $pvaCode,
                        'barcode' => $var['sku'] ?: null,
                        'price' => (float) ($var['price'] ?: $productData['price'] ?: 0),
                        'statut' => 1,
                    ]);

                    PvaMeasurement::create([
                        'product_variation_attribute_id' => $pva->id,
                        'measurement_id' => 1,
                        'quantity' => 1,
                    ]);

                    VariationLink::updateOrCreate(
                        ['store_id' => $store->id, 'wc_product_id' => $productData['id'], 'wc_variation_id' => $var['id']],
                        ['account_id' => $accountId, 'product_variation_attribute_id' => $pva->id, 'source' => 'auto']
                    );
                    $linksCount++;
                }
            } else {
                // Simple product: create single default PVA
                $pva = ProductVariationAttribute::create([
                    'account_id' => $accountId,
                    'product_id' => $product->id,
                    'code' => 'REF:' . $product->reference,
                    'barcode' => $productData['sku'] ?: null,
                    'price' => (float) ($productData['price'] ?: 0),
                    'statut' => 1,
                ]);

                PvaMeasurement::create([
                    'product_variation_attribute_id' => $pva->id,
                    'measurement_id' => 1,
                    'quantity' => 1,
                ]);

                VariationLink::updateOrCreate(
                    ['store_id' => $store->id, 'wc_product_id' => $productData['id'], 'wc_variation_id' => 0],
                    ['account_id' => $accountId, 'product_variation_attribute_id' => $pva->id, 'source' => 'auto']
                );
                $linksCount++;
            }

            SyncLog::record($store, 'mapping', 'variation', 'success', "Produit #{$product->id} et {$linksCount} variation(s) générés depuis WooCommerce", [
                'entity_id' => $product->id,
                'wc_id' => $productData['id'],
            ]);

            return [
                'statut' => 1,
                'product_id' => $product->id,
                'links_count' => $linksCount,
                'message' => "Produit « {$product->title} » et {$linksCount} variation(s) générés et liés avec succès.",
            ];
        });
    }

    /**
     * Generates a single missing variation under an existing local CodConnect product, and links it.
     */
    public function generateVariation(Store $store, int $wcProductId, int $wcVariationId, int $localProductId): array
    {
        $client = WooCommerceClient::fromStore($store);
        $productData = $client->product($wcProductId);
        $variationData = $client->variation($wcProductId, $wcVariationId);

        $accountId = (int) $store->account_id;
        $localProduct = Product::where('id', $localProductId)->firstOrFail();
        $accountUser = AccountUser::where('account_id', $accountId)->where('statut', 1)->first();
        if (! $accountUser) {
            throw new \RuntimeException("Aucun utilisateur actif trouvé pour le compte #{$accountId}");
        }

        return DB::transaction(function () use ($store, $productData, $variationData, $localProduct, $accountId, $accountUser, $wcProductId, $wcVariationId) {
            $attributeLookup = $this->ensureAttributes($variationData['attributes'] ?? [], $accountUser);

            $attrIds = [];
            foreach ($variationData['attributes'] ?? [] as $attr) {
                $attrName = trim((string) ($attr['name'] ?? ''));
                $attrOption = trim((string) ($attr['option'] ?? ''));
                if (isset($attributeLookup[$attrName][$attrOption])) {
                    $attrIds[] = $attributeLookup[$attrName][$attrOption];
                }
            }

            $variationAttributeId = $this->ensureVariationAttributeCombination($attrIds, $accountId);

            $pvaCode = 'REF:' . $localProduct->reference . '-' . $wcVariationId;
            $pva = ProductVariationAttribute::create([
                'account_id' => $accountId,
                'product_id' => $localProduct->id,
                'variation_attribute_id' => $variationAttributeId,
                'code' => $pvaCode,
                'barcode' => $variationData['sku'] ?: null,
                'price' => (float) ($variationData['price'] ?: $productData['price'] ?: 0),
                'statut' => 1,
            ]);

            PvaMeasurement::create([
                'product_variation_attribute_id' => $pva->id,
                'measurement_id' => 1,
                'quantity' => 1,
            ]);

            VariationLink::updateOrCreate(
                ['store_id' => $store->id, 'wc_product_id' => $wcProductId, 'wc_variation_id' => $wcVariationId],
                ['account_id' => $accountId, 'product_variation_attribute_id' => $pva->id, 'source' => 'auto']
            );

            SyncLog::record($store, 'mapping', 'variation', 'success', "Variation #{$pva->id} générée sous le produit #{$localProduct->id}", [
                'entity_id' => $pva->id,
                'wc_id' => $wcVariationId,
            ]);

            return [
                'statut' => 1,
                'product_variation_attribute_id' => $pva->id,
                'message' => "Variation liée avec succès au produit « {$localProduct->title} ».",
            ];
        });
    }

    /**
     * Bulk generate selected missing products and/or variations.
     */
    public function bulkGenerate(Store $store, array $items): array
    {
        $createdProducts = 0;
        $createdVariations = 0;
        $errors = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? 'product';
            try {
                if ($type === 'product') {
                    $res = $this->generateProduct($store, (int) $item['wc_product_id']);
                    $createdProducts++;
                    $createdVariations += ($res['links_count'] ?? 1);
                } elseif ($type === 'variation') {
                    $this->generateVariation($store, (int) $item['wc_product_id'], (int) $item['wc_variation_id'], (int) $item['local_product_id']);
                    $createdVariations++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Erreur pour l'élément #{$item['wc_product_id']}: " . $e->getMessage();
            }
        }

        return [
            'statut' => 1,
            'created_products' => $createdProducts,
            'created_variations' => $createdVariations,
            'errors' => $errors,
            'message' => "Génération terminée : {$createdProducts} produit(s) et {$createdVariations} variation(s) créés.",
        ];
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    private function findLocalProduct(int $wcProductId, string $sku, string $title, int $accountId): ?Product
    {
        $sku = trim($sku);
        $title = trim($title);

        // 1. By meta WC ID
        $byMeta = Product::where('statut', 1)
            ->where(fn ($q) => $q->where('meta', 'like', '%"id":' . $wcProductId . ',%')
                ->orWhere('meta', 'like', '%"id": ' . $wcProductId . ',%')
                ->orWhere('meta', 'like', '%"id":' . $wcProductId . '}%'))
            ->first();
        if ($byMeta) {
            return $byMeta;
        }

        // 2. By SKU / Reference
        if ($sku !== '') {
            $byRef = Product::where('statut', 1)->where('reference', $sku)->first();
            if ($byRef) {
                return $byRef;
            }
        }

        // 3. By exact title
        if ($title !== '') {
            $byTitle = Product::where('statut', 1)->where('title', $title)->first();
            if ($byTitle) {
                return $byTitle;
            }
        }

        return null;
    }

    /**
     * Ensures TypeAttribute and Attribute exist for all given WooCommerce attributes.
     * Returns: [ 'Taille' => [ '42' => attribute_id, '44' => attribute_id ] ]
     */
    private function ensureAttributes(array $attributesList, AccountUser $accountUser): array
    {
        $lookup = [];

        foreach ($attributesList as $rawAttr) {
            $typeName = trim((string) ($rawAttr['name'] ?? 'Attribut'));
            if ($typeName === '') {
                continue;
            }

            // Find or create TypeAttribute
            $typeAttribute = TypeAttribute::firstOrCreate(
                ['title' => $typeName, 'account_user_id' => $accountUser->id],
                [
                    'code' => 'TATT-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $typeName), 0, 4)),
                    'statut' => 1,
                ]
            );

            $options = $rawAttr['options'] ?? (isset($rawAttr['option']) ? [$rawAttr['option']] : []);
            foreach ($options as $opt) {
                $optTitle = trim((string) $opt);
                if ($optTitle === '') {
                    continue;
                }

                $attr = Attribute::firstOrCreate(
                    [
                        'title' => $optTitle,
                        'types_attribute_id' => $typeAttribute->id,
                        'account_user_id' => $accountUser->id,
                    ],
                    [
                        'code' => 'ATT-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $optTitle), 0, 4)),
                        'statut' => 1,
                    ]
                );

                $lookup[$typeName][$optTitle] = $attr->id;
            }
        }

        return $lookup;
    }

    /**
     * Finds or creates a parent VariationAttribute and its child rows for a combination of Attribute IDs.
     */
    private function ensureVariationAttributeCombination(array $attributeIds, int $accountId): ?int
    {
        $attributeIds = array_values(array_unique(array_filter($attributeIds)));
        if (! $attributeIds) {
            return null;
        }

        sort($attributeIds);

        // Find existing parent variation_attributes having exact same children
        $parents = VariationAttribute::whereNull('attribute_id')
            ->whereNull('variation_attribute_id')
            ->where('account_id', $accountId)
            ->with('childVariationAttributes')
            ->get();

        foreach ($parents as $parent) {
            $childAttrIds = $parent->childVariationAttributes->pluck('attribute_id')->map('intval')->all();
            sort($childAttrIds);
            if ($childAttrIds === $attributeIds) {
                return (int) $parent->id;
            }
        }

        // Create new parent
        $newParent = VariationAttribute::create([
            'code' => 'codev',
            'account_id' => $accountId,
            'statut' => 1,
        ]);

        foreach ($attributeIds as $attrId) {
            VariationAttribute::create([
                'code' => 'codev' . $newParent->id,
                'account_id' => $accountId,
                'variation_attribute_id' => $newParent->id,
                'attribute_id' => (int) $attrId,
                'statut' => 1,
            ]);
        }

        return (int) $newParent->id;
    }

    private function itemSku(array $items, int $varId): string
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $varId) {
                return (string) ($item['sku'] ?? '');
            }
        }
        return '';
    }

    private function itemOptions(array $items, int $varId): array
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $varId) {
                return collect($item['attributes'] ?? [])->pluck('option')->values()->all();
            }
        }
        return [];
    }

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
}
