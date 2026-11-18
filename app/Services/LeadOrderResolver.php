<?php

namespace App\Services;

use App\Models\AccountUser;
use App\Models\City;
use App\Models\Product;

class LeadOrderResolver
{
    /** @param array<int, array<string, mixed>> $records */
    public function resolve(
        array &$records,
        int $accountId,
        array $productMappings = [],
        array $variantMappings = [],
        array $cityMappings = [],
    ): array {
        $accountUserIds = AccountUser::query()->where('account_id', $accountId)->pluck('id');
        $products = $accountUserIds->isEmpty()
            ? collect()
            : Product::query()
                ->whereIn('account_user_id', $accountUserIds)
                ->with([
                    'productVariationAttributes.variationAttribute.childVariationAttributes.attribute',
                    'price',
                ])
                ->get(['id', 'title', 'reference', 'code', 'account_user_id'])
                ->keyBy('id');

        $cities = City::query()->get(['id', 'title']);
        $productGroups = [];
        $variantGroups = [];
        $cityGroups = [];

        foreach ($records as &$record) {
            if ($record['state'] !== 'ready') {
                continue;
            }

            $cityName = trim((string) ($record['data']['city'] ?? ''));
            $record['city_key'] = $cityName;
            $city = $this->resolveCity($cityName, $cities, $cityMappings);
            if ($city) {
                $record['city_id'] = (int) $city->id;
                $record['city_title'] = (string) $city->title;
            } else {
                $record['state'] = 'city_unresolved';
                $record['message'] = $cityName === '' ? 'missing_city' : 'unmatched_city';
                $cityGroups[$cityName] = [
                    'key' => $cityName,
                    'label' => $cityName !== '' ? $cityName : '(empty)',
                    'city_id' => null,
                    'city_title' => null,
                ];
                continue;
            }

            $productKey = trim((string) ($record['product_key'] ?? ''));
            $record['product_key'] = $productKey;
            $product = $this->resolveProduct($productKey, $products, $productMappings);
            if (! $product) {
                $record['state'] = 'product_unresolved';
                $record['message'] = $productKey === '' ? 'missing_product' : 'unmatched_product';
                $productGroups[$productKey] = [
                    'key' => $productKey,
                    'label' => $productKey !== '' ? $productKey : '(no product identifier)',
                    'product' => null,
                    'match' => $productKey === '' ? 'missing' : 'unmatched',
                ];
                continue;
            }

            $record['product_id'] = (int) $product->id;
            $record['product_title'] = (string) $product->title;
            $record['product_reference'] = (string) ($product->reference ?: $product->code ?: '');
            $record['product_price'] = (float) ($product->price->first()?->price ?? 0);

            $productGroups[$productKey] = [
                'key' => $productKey,
                'label' => $productKey !== '' ? $productKey : '(no product identifier)',
                'product' => [
                    'id' => (int) $product->id,
                    'title' => (string) $product->title,
                    'reference' => (string) ($product->reference ?: $product->code ?: ''),
                ],
                'match' => isset($productMappings[$productKey]) ? 'manual' : 'automatic',
            ];

            $variantSets = $this->variationSets($product);
            $variantKey = $this->variantKey($product->id, $record);
            $record['variant_key'] = $variantKey;
            $selectedVariant = $this->resolveVariant(
                $variantSets,
                $record,
                $variantMappings[$variantKey] ?? null,
            );

            if ($selectedVariant) {
                $record['variant_attribute_ids'] = $selectedVariant['ids'];
                $record['variant_label'] = $selectedVariant['label'];
            } else {
                $record['state'] = 'variant_unresolved';
                $record['message'] = 'unmatched_variant';
                $variantGroups[$variantKey] = [
                    'key' => $variantKey,
                    'product_id' => (int) $product->id,
                    'product_title' => (string) $product->title,
                    'requested_size' => (string) ($record['data']['metadata']['requested_size'] ?? ''),
                    'requested_color' => (string) ($record['data']['metadata']['requested_color'] ?? ''),
                    'options' => array_map(fn (array $variant) => [
                        'ids' => $variant['ids'],
                        'label' => $variant['label'],
                    ], $variantSets),
                ];
            }
        }
        unset($record);

        return [
            'product_groups' => array_values($productGroups),
            'variant_groups' => array_values($variantGroups),
            'city_groups' => array_values($cityGroups),
            'products' => $products,
        ];
    }

    private function resolveCity(string $cityName, $cities, array $cityMappings): ?City
    {
        if (array_key_exists($cityName, $cityMappings) && is_numeric($cityMappings[$cityName])) {
            return $cities->first(fn (City $city) => (int) $city->id === (int) $cityMappings[$cityName]);
        }

        if ($cityName === '') {
            return null;
        }

        $normalized = $this->normalizeText($cityName);
        $matches = $cities->filter(fn (City $city) => $this->normalizeText((string) $city->title) === $normalized);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveProduct(string $identifier, $products, array $productMappings): ?Product
    {
        if (array_key_exists($identifier, $productMappings) && is_numeric($productMappings[$identifier])) {
            return $products->get((int) $productMappings[$identifier]);
        }

        if ($identifier === '') {
            return null;
        }

        $normalized = $this->normalizeText($identifier);
        $identifierTokens = array_filter(explode(' ', $normalized));
        $ranked = [];

        foreach ($products as $product) {
            $reference = $this->normalizeText((string) $product->reference);
            $code = $this->normalizeText((string) $product->code);
            $title = $this->normalizeText((string) $product->title);
            $rank = 0;

            if ($reference !== '' && in_array($reference, $identifierTokens, true)) {
                $rank = max($rank, 4);
            }
            if ($code !== '' && in_array($code, $identifierTokens, true)) {
                $rank = max($rank, 4);
            }
            if ($title !== '' && $title === $normalized) {
                $rank = max($rank, 3);
            } elseif ($title !== '' && mb_strlen($title) >= 4 && str_contains(' ' . $normalized . ' ', ' ' . $title . ' ')) {
                $rank = max($rank, 2);
            }

            if ($rank > 0) {
                $ranked[$product->id] = ['product' => $product, 'rank' => $rank];
            }
        }

        if ($ranked === []) {
            return null;
        }

        $bestRank = max(array_column($ranked, 'rank'));
        $best = array_values(array_filter($ranked, fn (array $match) => $match['rank'] === $bestRank));

        return count($best) === 1 ? $best[0]['product'] : null;
    }

    /** @return array<int, array{ids: array<int, int>, attributes: array<int, array{id: int, title: string}>, label: string}> */
    private function variationSets(Product $product): array
    {
        $sets = [];
        foreach ($product->productVariationAttributes as $productVariation) {
            $variation = $productVariation->variationAttribute;
            if (! $variation) {
                continue;
            }

            $attributes = [];
            foreach ($variation->childVariationAttributes as $child) {
                $attribute = $child->attribute;
                if (! $attribute) {
                    continue;
                }
                $attributes[(int) $attribute->id] = [
                    'id' => (int) $attribute->id,
                    'title' => (string) $attribute->title,
                ];
            }

            if ($attributes === []) {
                continue;
            }

            ksort($attributes);
            $ids = array_map('intval', array_keys($attributes));
            $key = implode(',', $ids);
            $sets[$key] = [
                'ids' => $ids,
                'attributes' => array_values($attributes),
                'label' => implode(' · ', array_column(array_values($attributes), 'title')),
            ];
        }

        return array_values($sets);
    }

    private function resolveVariant(array $variantSets, array $record, mixed $override): ?array
    {
        if (is_array($override)) {
            $ids = array_values(array_unique(array_map('intval', array_filter($override, 'is_numeric'))));
            sort($ids);
            foreach ($variantSets as $variant) {
                if ($variant['ids'] === $ids) {
                    return $variant;
                }
            }
            return null;
        }

        if (count($variantSets) === 1 && trim((string) ($record['data']['metadata']['requested_size'] ?? '')) === '' && trim((string) ($record['data']['metadata']['requested_color'] ?? '')) === '') {
            return $variantSets[0];
        }

        $size = $this->normalizeText((string) ($record['data']['metadata']['requested_size'] ?? ''));
        $color = $this->normalizeText((string) ($record['data']['metadata']['requested_color'] ?? ''));
        if ($size === '' && $color === '') {
            return null;
        }

        $matches = array_values(array_filter($variantSets, function (array $variant) use ($size, $color) {
            $matchedIds = [];
            foreach ([$size, $color] as $wanted) {
                if ($wanted === '') {
                    continue;
                }
                $found = null;
                foreach ($variant['attributes'] as $attribute) {
                    if ($this->normalizeText($attribute['title']) === $wanted) {
                        $found = $attribute['id'];
                        break;
                    }
                }
                if ($found === null || in_array($found, $matchedIds, true)) {
                    return false;
                }
                $matchedIds[] = $found;
            }
            return true;
        }));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function variantKey(int $productId, array $record): string
    {
        return hash('sha256', json_encode([
            $productId,
            $this->normalizeText((string) ($record['data']['metadata']['requested_size'] ?? '')),
            $this->normalizeText((string) ($record['data']['metadata']['requested_color'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim(str_replace("\u{FEFF}", '', $value)));
        $value = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value) ?? $value;
        $value = strtr($value, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c',
        ]);

        return trim(preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value);
    }
}
