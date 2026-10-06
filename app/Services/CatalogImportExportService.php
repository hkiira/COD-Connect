<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * CSV export of the catalog and a safe, update-only import / bulk edit:
 * only the account's own products, only status, cost price and low-stock threshold.
 */
class CatalogImportExportService
{
    public const DELIMITER = ';';

    public const EXPORT_COLUMNS = [
        'product_id', 'product_code', 'product_title', 'status', 'brand', 'categories',
        'cost_price', 'low_stock_threshold', 'pva_id', 'pva_code', 'variation', 'stock', 'price',
    ];

    /** CSV column => products column */
    private const UPDATABLE = [
        'status'              => 'statut',
        'cost_price'          => 'cost_price',
        'low_stock_threshold' => 'low_stock_threshold',
    ];

    // region export

    /** @return \Generator<int, array<int, mixed>> header first, then one row per variation. */
    public function export(int $accountId): \Generator
    {
        yield self::EXPORT_COLUMNS;

        $label = "(SELECT GROUP_CONCAT(CONCAT(ta.title, ': ', a.title) ORDER BY ta.title SEPARATOR ' / ')
            FROM variation_attributes va JOIN attributes a ON a.id = va.attribute_id
            JOIN type_attributes ta ON ta.id = a.types_attribute_id
            WHERE va.variation_attribute_id = pva.variation_attribute_id)";

        $query = DB::table('products as p')
            ->leftJoin('product_variation_attribute as pva', function ($j) {
                $j->on('pva.product_id', '=', 'p.id')->whereNull('pva.deleted_at');
            })
            ->whereNull('p.deleted_at')
            ->whereIn('p.id', fn ($q) => $q->select('product_id')->from('account_product')->where('account_id', $accountId))
            ->orderBy('p.id')->orderBy('pva.id')
            ->selectRaw("p.id as product_id, p.code as product_code, p.title as product_title, p.statut, p.cost_price, p.low_stock_threshold,
                pva.id as pva_id, pva.code as pva_code, $label as variation,
                (SELECT b.title FROM product_brand_source pbs JOIN brand_source bs ON bs.id = pbs.brand_source_id JOIN brands b ON b.id = bs.brand_id
                    WHERE pbs.product_id = p.id AND pbs.deleted_at IS NULL LIMIT 1) as brand,
                (SELECT GROUP_CONCAT(DISTINCT t.title SEPARATOR ' / ') FROM taxonomy_product tp
                    JOIN account_product ap ON ap.id = tp.account_product_id JOIN taxonomies t ON t.id = tp.taxonomy_id
                    WHERE ap.product_id = p.id AND ap.account_id = ? AND tp.deleted_at IS NULL) as categories,
                (SELECT COALESCE(SUM(w.quantity), 0) FROM warehouse_pva w WHERE w.product_variation_attribute_id = pva.id) as stock,
                (SELECT MAX(o.price) FROM offerables ofr JOIN offers o ON o.id = ofr.offer_id
                    WHERE ofr.offerable_type = 'App\\\\Models\\\\Product' AND ofr.offerable_id = p.id AND ofr.deleted_at IS NULL
                    AND o.deleted_at IS NULL AND o.offer_type_id = 1 AND o.statut = 1) as price", [$accountId]);

        foreach ($query->cursor() as $r) {
            yield [
                $r->product_id, $r->product_code, $r->product_title, $r->statut, $r->brand, $r->categories,
                $r->cost_price, $r->low_stock_threshold, $r->pva_id, $r->pva_code, $r->variation, $r->stock, $r->price,
            ];
        }
    }

    // endregion

    // region import

    /**
     * Reads a CSV and works out the changes. Nothing is written here.
     *
     * @return array{changes: array<int, array<string,mixed>>, errors: array<int, array<string,mixed>>, products: int, rows: int}
     */
    public function preview(int $accountId, string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return ['changes' => [], 'errors' => [['line' => 0, 'message' => 'Unreadable file.']], 'products' => 0, 'rows' => 0];
        }

        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = substr_count((string) $firstLine, ';') >= substr_count((string) $firstLine, ',') ? ';' : ',';

        $header = $this->normalizeHeader(fgetcsv($handle, 0, $delimiter) ?: []);
        $errors = [];
        $perProduct = [];
        $rows = 0;

        if (! in_array('product_id', $header, true)) {
            fclose($handle);

            return ['changes' => [], 'errors' => [['line' => 1, 'message' => 'Missing column "product_id".']], 'products' => 0, 'rows' => 0];
        }

        $line = 1;
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if ($cells === [null] || count(array_filter($cells, fn ($c) => $c !== null && $c !== '')) === 0) {
                continue;
            }
            $rows++;
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $productId = (int) ($row['product_id'] ?? 0);

            if ($productId <= 0) {
                $errors[] = ['line' => $line, 'message' => 'Invalid product_id.'];
                continue;
            }

            foreach (array_keys(self::UPDATABLE) as $column) {
                if (! array_key_exists($column, $row) || $row[$column] === null || trim((string) $row[$column]) === '') {
                    continue;
                }
                [$ok, $value] = $this->parseValue($column, (string) $row[$column]);
                if (! $ok) {
                    $errors[] = ['line' => $line, 'message' => "Invalid value for \"$column\": {$row[$column]}."];
                    continue;
                }
                if (isset($perProduct[$productId][$column]) && $perProduct[$productId][$column] !== $value) {
                    $errors[] = ['line' => $line, 'message' => "Conflicting values for \"$column\" on product $productId."];
                    continue;
                }
                $perProduct[$productId][$column] = $value;
            }
            $perProduct[$productId] ??= [];
        }
        fclose($handle);

        return $this->diff($accountId, $perProduct, $errors, $rows);
    }

    /**
     * Same diff for a bulk edit of explicit product ids.
     *
     * @param int[] $ids
     * @param array<string, mixed> $fields status | cost_price | low_stock_threshold
     */
    public function previewBulk(int $accountId, array $ids, array $fields): array
    {
        $errors = [];
        $values = [];
        foreach ($fields as $column => $raw) {
            if (! array_key_exists($column, self::UPDATABLE)) {
                $errors[] = ['line' => 0, 'message' => "Field cannot be edited: $column."];
                continue;
            }
            [$ok, $value] = $this->parseValue($column, (string) $raw);
            $ok ? $values[$column] = $value : $errors[] = ['line' => 0, 'message' => "Invalid value for \"$column\"."];
        }

        $perProduct = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $perProduct[$id] = $values;
        }

        return $this->diff($accountId, $perProduct, $errors, count($perProduct));
    }

    /** Writes the changes of a preview; only changed, owned products. */
    public function apply(int $accountId, array $changes): int
    {
        $byProduct = collect($changes)->groupBy('product_id');

        return DB::transaction(function () use ($accountId, $byProduct) {
            $owned = $this->ownedProductIds($accountId, $byProduct->keys()->all());
            $updated = 0;

            foreach ($byProduct as $productId => $fields) {
                if (! in_array((int) $productId, $owned, true)) {
                    continue;
                }
                $update = ['updated_at' => now()];
                foreach ($fields as $change) {
                    $update[self::UPDATABLE[$change['field']]] = $change['new'];
                }
                $updated += DB::table('products')->where('id', $productId)->update($update) ? 1 : 0;
            }

            return $updated;
        });
    }

    // endregion

    // region helpers

    /** @param array<int, array<string, mixed>> $perProduct product id => column => value */
    private function diff(int $accountId, array $perProduct, array $errors, int $rows): array
    {
        $owned = $this->ownedProductIds($accountId, array_keys($perProduct));
        $current = DB::table('products')->whereIn('id', $owned)->get(['id', 'title', 'statut', 'cost_price', 'low_stock_threshold'])->keyBy('id');
        $changes = [];

        foreach ($perProduct as $productId => $columns) {
            if (! in_array((int) $productId, $owned, true)) {
                $errors[] = ['line' => 0, 'message' => "Product $productId not found for this account."];
                continue;
            }
            $product = $current[$productId];

            foreach ($columns as $column => $value) {
                $old = $product->{self::UPDATABLE[$column]};
                if ((string) $old === (string) $value || (is_numeric($old) && is_numeric($value) && (float) $old === (float) $value)) {
                    continue;
                }
                $changes[] = [
                    'product_id' => (int) $productId,
                    'title'      => $product->title,
                    'field'      => $column,
                    'old'        => $old,
                    'new'        => $value,
                ];
            }
        }

        return ['changes' => $changes, 'errors' => $errors, 'products' => count($perProduct), 'rows' => $rows];
    }

    /** @param int[] $ids @return int[] */
    private function ownedProductIds(int $accountId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('account_product')->where('account_id', $accountId)
            ->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return array{0: bool, 1: mixed} */
    private function parseValue(string $column, string $raw): array
    {
        $raw = trim($raw);

        return match ($column) {
            'status' => match (mb_strtolower($raw)) {
                '1', 'actif', 'active', 'oui', 'yes' => [true, 1],
                '0', 'inactif', 'inactive', 'non', 'no' => [true, 0],
                default => [false, null],
            },
            'cost_price' => is_numeric($n = str_replace(',', '.', $raw)) && (float) $n >= 0 ? [true, round((float) $n, 2)] : [false, null],
            'low_stock_threshold' => ctype_digit($raw) ? [true, (int) $raw] : [false, null],
            default => [false, null],
        };
    }

    /** @param array<int, string|null> $header @return string[] */
    private function normalizeHeader(array $header): array
    {
        return array_map(
            fn ($h) => trim(mb_strtolower(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header
        );
    }

    // endregion
}
