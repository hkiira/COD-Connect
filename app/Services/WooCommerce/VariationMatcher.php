<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\DB;

/**
 * Proposes which CodConnect product variation a WooCommerce variation corresponds to. It only proposes:
 * nothing is saved until the user applies the proposals, and it prefers staying silent over guessing.
 *
 * Rules, strongest first:
 *  1. SKU: the WooCommerce SKU equals the barcode or code of exactly one of the account's variations.
 *  2. Known product: the WooCommerce product id is already attached to a CodConnect product (an earlier sync keeps
 *     it in products.meta), or the product SKU equals the reference of exactly one CodConnect product. Among that product's variations, the one whose attributes contain every WooCommerce
 *     option the product knows (a size "44" is known, a colour written in another language is not), if exactly one does.
 */
class VariationMatcher
{
    public const BY_SKU = 'sku';
    public const BY_ATTRIBUTES = 'attributes';

    /**
     * @param array $wcProduct    WooCommerce product (id, sku, type, attributes)
     * @param array $wcVariations WooCommerce variations of it (id, sku, attributes[{option}]); empty for a simple product
     * @return array<int, array{wc_product_id:int, wc_variation_id:int, product_variation_attribute_id:int, reason:string}>
     */
    public function propose(array $wcProduct, array $wcVariations, int $accountId): array
    {
        $productId = (int) $wcProduct['id'];
        $items = $wcVariations ?: [['id' => 0, 'sku' => $wcProduct['sku'] ?? '', 'attributes' => []]];

        $localProduct = $this->localProductFor($productId, (string) ($wcProduct['sku'] ?? ''), $accountId);
        $variants = $localProduct ? $this->variantsOf($localProduct, $accountId) : [];
        $vocabulary = collect($variants)->flatMap(fn ($v) => $v['options'])->unique()->all();

        $proposals = [];
        foreach ($items as $item) {
            $match = $this->bySku((string) ($item['sku'] ?? ''), $accountId);
            $reason = self::BY_SKU;

            if ($match === null && $variants) {
                $options = collect($item['attributes'] ?? [])->pluck('option')->all();
                $match = self::uniqueByOptions($options, $variants, $vocabulary);
                $reason = self::BY_ATTRIBUTES;
            }

            if ($match !== null) {
                $proposals[] = [
                    'wc_product_id' => $productId,
                    'wc_variation_id' => (int) $item['id'],
                    'product_variation_attribute_id' => $match,
                    'reason' => $reason,
                ];
            }
        }

        return $this->withoutDuplicateTargets($proposals);
    }

    /**
     * The single variation that carries every given option the product knows about, or null when none or
     * several do (e.g. only the size is known and the product has the same size in two colours).
     *
     * @param array<int, string|null> $options  options of the WooCommerce variation
     * @param array<int, array{id:int, options:array<int,string>}> $variants local variations with their normalized option titles
     * @param array<int, string> $vocabulary    every option title the local product has
     */
    public static function uniqueByOptions(array $options, array $variants, array $vocabulary): ?int
    {
        $known = array_values(array_filter(
            array_map([self::class, 'normalize'], $options),
            fn ($option) => $option !== '' && in_array($option, $vocabulary, true)
        ));

        if (! $known) {
            return null;
        }

        $matching = array_filter($variants, fn ($variant) => ! array_diff($known, $variant['options']));

        return count($matching) === 1 ? (int) array_values($matching)[0]['id'] : null;
    }

    public static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /** One CodConnect variation cannot be the target of two WooCommerce variations of the same proposal list. */
    private function withoutDuplicateTargets(array $proposals): array
    {
        $count = collect($proposals)->countBy('product_variation_attribute_id');

        return array_values(array_filter($proposals, fn ($p) => $count[$p['product_variation_attribute_id']] === 1));
    }

    private function bySku(string $sku, int $accountId): ?int
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $ids = DB::table('product_variation_attribute')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('barcode', $sku)->orWhere('code', $sku))
            ->limit(2)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids[0] : null;
    }

    /** The account's product that an earlier sync attached this WooCommerce product to, or whose reference is its SKU. */
    private function localProductFor(int $wcProductId, string $sku, int $accountId): ?int
    {
        $ids = DB::table('products as p')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', $accountId)
            ->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->where('p.meta', 'like', '%"id":' . $wcProductId . ',%')->orWhere('p.meta', 'like', '%"id": ' . $wcProductId . ',%'))
            ->limit(2)
            ->pluck('p.id');

        if ($ids->count() === 1) {
            return (int) $ids[0];
        }

        $sku = trim($sku);
        if ($ids->isEmpty() && $sku !== '') {
            $byReference = DB::table('products as p')
                ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
                ->where('au.account_id', $accountId)
                ->whereNull('p.deleted_at')
                ->where('p.reference', $sku)
                ->limit(2)
                ->pluck('p.id');

            return $byReference->count() === 1 ? (int) $byReference[0] : null;
        }

        return null;
    }

    /** @return array<int, array{id:int, options:array<int,string>}> */
    private function variantsOf(int $productId, int $accountId): array
    {
        $rows = DB::table('product_variation_attribute as pva')
            ->join('variation_attributes as va', 'va.variation_attribute_id', '=', 'pva.variation_attribute_id')
            ->join('attributes as a', 'a.id', '=', 'va.attribute_id')
            ->where('pva.product_id', $productId)
            ->where('pva.account_id', $accountId)
            ->whereNull('pva.deleted_at')
            ->select('pva.id', 'a.title')
            ->get();

        return $rows->groupBy('id')
            ->map(fn ($group, $id) => ['id' => (int) $id, 'options' => $group->pluck('title')->map(fn ($t) => self::normalize($t))->all()])
            ->values()
            ->all();
    }
}
