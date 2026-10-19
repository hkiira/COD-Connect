<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Brand;
use App\Models\Carrier;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\Taxonomy;
use App\Models\TypeAttribute;
use App\Models\Warehouse;
use App\Support\AccountContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AccountMergeService
{
    /**
     * Return all accounts with high-level data counts.
     */
    public function listAccountsWithStats(): array
    {
        return AccountContext::run(null, function () {
            $accounts = Account::orderBy('id', 'desc')->get();

            return $accounts->map(function ($account) {
                $auIds = AccountUser::where('account_id', $account->id)->pluck('id')->all();

                $ordersCount = DB::table('orders')->where('account_id', $account->id)->count();
                $customersCount = DB::table('customers')->where('account_id', $account->id)->count();
                $productsCount = DB::table('account_product')->where('account_id', $account->id)->count();
                $warehousesCount = DB::table('warehouses')
                    ->where('account_id', $account->id)
                    ->where('warehouse_type_id', 1)
                    ->count();
                $usersCount = count($auIds);

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'statut' => (int) $account->statut,
                    'orders_count' => $ordersCount,
                    'customers_count' => $customersCount,
                    'products_count' => $productsCount,
                    'warehouses_count' => $warehousesCount,
                    'users_count' => $usersCount,
                ];
            })->all();
        });
    }

    /**
     * Inspect source and target accounts to load all entities, smart auto-matching, and any saved draft.
     */
    public function inspect(int $sourceAccountId, int $targetAccountId): array
    {
        return AccountContext::run(null, function () use ($sourceAccountId, $targetAccountId) {
            $sourceAccount = Account::findOrFail($sourceAccountId);
            $targetAccount = Account::findOrFail($targetAccountId);

            $sourceData = $this->loadAccountEntities($sourceAccountId);
            $targetData = $this->loadAccountEntities($targetAccountId);

            $suggested = $this->generateSuggestedMappings($sourceData, $targetData);
            $savedDraft = $this->loadDraft($sourceAccountId, $targetAccountId);

            return [
                'source_account' => [
                    'id' => $sourceAccount->id,
                    'code' => $sourceAccount->code,
                    'name' => $sourceAccount->name,
                    'statut' => (int) $sourceAccount->statut,
                ],
                'target_account' => [
                    'id' => $targetAccount->id,
                    'code' => $targetAccount->code,
                    'name' => $targetAccount->name,
                    'statut' => (int) $targetAccount->statut,
                ],
                'source_entities' => $sourceData,
                'target_entities' => $targetData,
                'suggested_config' => $suggested,
                'saved_draft' => $savedDraft,
            ];
        });
    }

    /**
     * Load all entities for a given account.
     */
    private function loadAccountEntities(int $accountId): array
    {
        $auIds = AccountUser::where('account_id', $accountId)->pluck('id')->all();

        // 1. Warehouses (hierarchical)
        $mainWarehouses = DB::table('warehouses')
            ->where('account_id', $accountId)
            ->where('warehouse_type_id', 1)
            ->get();

        $warehousesList = $mainWarehouses->map(function ($mw) use ($accountId) {
            $partitions = DB::table('warehouses')
                ->where('account_id', $accountId)
                ->where('warehouse_type_id', 2)
                ->where('warehouse_id', $mw->id)
                ->get();

            $pIds = $partitions->pluck('id')->all();
            $rayons = !empty($pIds) ? DB::table('warehouses')
                ->where('account_id', $accountId)
                ->where('warehouse_type_id', 3)
                ->whereIn('warehouse_id', $pIds)
                ->get() : collect();

            $allWhIds = array_merge([$mw->id], $pIds, $rayons->pluck('id')->all());

            $stockSummary = DB::table('warehouse_pva')
                ->whereIn('warehouse_id', $allWhIds)
                ->selectRaw('COUNT(*) as pva_count, COALESCE(SUM(quantity), 0) as total_qty')
                ->first();

            return [
                'id' => $mw->id,
                'code' => $mw->code,
                'title' => $mw->title,
                'statut' => (int) $mw->statut,
                'partitions_count' => count($partitions),
                'rayons_count' => count($rayons),
                'pva_count' => (int) ($stockSummary->pva_count ?? 0),
                'total_qty' => (float) ($stockSummary->total_qty ?? 0),
                'partitions' => $partitions->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'rayons' => $rayons->where('warehouse_id', $p->id)->values()->map(fn ($r) => [
                        'id' => $r->id,
                        'title' => $r->title,
                    ]),
                ]),
            ];
        })->all();

        // 2. Brands
        $brandsList = DB::table('brands')
            ->where('account_id', $accountId)
            ->get()
            ->map(function ($b) use ($accountId) {
                $ordersCount = DB::table('brand_source')
                    ->where('brand_source.account_id', $accountId)
                    ->where('brand_source.brand_id', $b->id)
                    ->join('orders', 'orders.brand_source_id', '=', 'brand_source.id')
                    ->count();

                return [
                    'id' => $b->id,
                    'code' => $b->code,
                    'title' => $b->title,
                    'statut' => (int) $b->statut,
                    'orders_count' => $ordersCount,
                ];
            })->all();

        // 3. Sources
        $sourcesList = DB::table('sources')
            ->where('account_id', $accountId)
            ->get()
            ->map(function ($s) use ($accountId) {
                $ordersCount = DB::table('brand_source')
                    ->where('brand_source.account_id', $accountId)
                    ->where('brand_source.source_id', $s->id)
                    ->join('orders', 'orders.brand_source_id', '=', 'brand_source.id')
                    ->count();

                return [
                    'id' => $s->id,
                    'code' => $s->code,
                    'title' => $s->title,
                    'statut' => (int) $s->statut,
                    'orders_count' => $ordersCount,
                ];
            })->all();

        // 4. Taxonomies
        $taxonomiesList = (!empty($auIds) ? DB::table('taxonomies')->whereIn('account_user_id', $auIds)->get() : collect())
            ->map(function ($t) {
                $productsCount = DB::table('taxonomy_product')->where('taxonomy_id', $t->id)->count();

                return [
                    'id' => $t->id,
                    'code' => $t->code,
                    'title' => $t->title,
                    'products_count' => $productsCount,
                ];
            })->all();

        // 5. TypeAttributes & Attributes
        $typeAttrsList = (!empty($auIds) ? DB::table('type_attributes')->whereIn('account_user_id', $auIds)->get() : collect())
            ->map(function ($ta) {
                $attrs = DB::table('attributes')->where('types_attribute_id', $ta->id)->get();

                return [
                    'id' => $ta->id,
                    'title' => $ta->title,
                    'attributes' => $attrs->map(function ($a) {
                        $usageCount = DB::table('variation_attributes')->where('attribute_id', $a->id)->count();

                        return [
                            'id' => $a->id,
                            'title' => $a->title,
                            'usage_count' => $usageCount,
                        ];
                    })->all(),
                ];
            })->all();

        // 6. Suppliers
        $suppliersList = DB::table('suppliers')
            ->where('account_id', $accountId)
            ->get()
            ->map(function ($sup) use ($auIds) {
                $soCount = !empty($auIds) ? DB::table('supplier_orders')
                    ->where('supplier_id', $sup->id)
                    ->whereIn('account_user_id', $auIds)
                    ->count() : 0;

                return [
                    'id' => $sup->id,
                    'title' => $sup->title,
                    'orders_count' => $soCount,
                ];
            })->all();

        // 7. Carriers
        $carriersList = DB::table('account_carrier as ac')
            ->join('carriers as c', 'c.id', '=', 'ac.carrier_id')
            ->where('ac.account_id', $accountId)
            ->select('ac.id as ac_id', 'c.id as carrier_id', 'c.title')
            ->get()
            ->map(function ($c) use ($auIds) {
                $pickupsCount = !empty($auIds) ? DB::table('pickups')
                    ->where('carrier_id', $c->carrier_id)
                    ->whereIn('account_user_id', $auIds)
                    ->count() : 0;

                return [
                    'account_carrier_id' => $c->ac_id,
                    'id' => $c->carrier_id,
                    'title' => $c->title,
                    'pickups_count' => $pickupsCount,
                ];
            })->all();

        // 8. Users
        $usersList = AccountUser::where('account_id', $accountId)
            ->with('user')
            ->get()
            ->map(function ($au) {
                $ordersCount = DB::table('orders')->where('assigned_to', $au->id)->count();

                return [
                    'account_user_id' => $au->id,
                    'user_id' => $au->user_id,
                    'name' => $au->user?->name ?? 'User #' . $au->user_id,
                    'email' => $au->user?->email ?? '',
                    'statut' => (int) $au->statut,
                    'assigned_orders_count' => $ordersCount,
                ];
            })->all();

        // 9. Products & PVAs
        $productIds = DB::table('account_product')->where('account_id', $accountId)->pluck('product_id')->all();
        $productsList = [];

        if (!empty($productIds)) {
            $products = DB::table('products')->whereIn('id', $productIds)->get();

            $allPvas = DB::table('product_variation_attribute')
                ->whereIn('product_id', $productIds)
                ->get();

            $pvaIds = $allPvas->pluck('id')->all();
            $vaIds = $allPvas->pluck('variation_attribute_id')->filter()->unique()->all();

            $vaAttributesMap = [];
            if (!empty($vaIds)) {
                $rawVas = DB::table('variation_attributes as va')
                    ->join('attributes as a', 'a.id', '=', 'va.attribute_id')
                    ->join('type_attributes as ta', 'ta.id', '=', 'a.types_attribute_id')
                    ->whereIn('va.variation_attribute_id', $vaIds)
                    ->select('va.variation_attribute_id', 'a.id as attr_id', 'a.title as attr_title', 'ta.id as ta_id', 'ta.title as ta_title')
                    ->get();

                foreach ($rawVas as $rva) {
                    $vaAttributesMap[$rva->variation_attribute_id][] = [
                        'type_attribute_id' => $rva->ta_id,
                        'type_attribute_title' => $rva->ta_title,
                        'attribute_id' => $rva->attr_id,
                        'attribute_title' => $rva->attr_title,
                    ];
                }
            }

            $stockMap = [];
            $ordersMap = [];
            if (!empty($pvaIds)) {
                $stockMap = DB::table('warehouse_pva')
                    ->whereIn('product_variation_attribute_id', $pvaIds)
                    ->groupBy('product_variation_attribute_id')
                    ->selectRaw('product_variation_attribute_id, SUM(quantity) as total_qty')
                    ->pluck('total_qty', 'product_variation_attribute_id')
                    ->all();

                $ordersMap = DB::table('order_pva')
                    ->whereIn('product_variation_attribute_id', $pvaIds)
                    ->groupBy('product_variation_attribute_id')
                    ->selectRaw('product_variation_attribute_id, COUNT(*) as cnt')
                    ->pluck('cnt', 'product_variation_attribute_id')
                    ->all();
            }

            $pvasByProduct = $allPvas->groupBy('product_id');

            foreach ($products as $p) {
                $pvas = $pvasByProduct->get($p->id, collect());

                $pvaItems = $pvas->map(function ($pva) use ($vaAttributesMap, $stockMap, $ordersMap) {
                    $childVas = $vaAttributesMap[$pva->variation_attribute_id] ?? [];
                    $attrLabel = !empty($childVas)
                        ? implode(' / ', array_map(fn ($c) => "{$c['type_attribute_title']}: {$c['attribute_title']}", $childVas))
                        : 'Default';

                    $stock = (float) ($stockMap[$pva->id] ?? 0);
                    $ordersCount = (int) ($ordersMap[$pva->id] ?? 0);

                    return [
                        'id' => $pva->id,
                        'code' => $pva->code,
                        'statut' => (int) $pva->statut,
                        'variation_attribute_id' => $pva->variation_attribute_id,
                        'attribute_label' => $attrLabel,
                        'stock_quantity' => $stock,
                        'orders_count' => $ordersCount,
                        'attributes' => $childVas,
                    ];
                })->all();

                $totalStock = array_sum(array_column($pvaItems, 'stock_quantity'));
                $totalOrders = array_sum(array_column($pvaItems, 'orders_count'));

                $productsList[] = [
                    'id' => $p->id,
                    'code' => $p->code,
                    'reference' => $p->reference,
                    'title' => $p->title,
                    'product_type_id' => (int) $p->product_type_id,
                    'pva_count' => count($pvaItems),
                    'total_stock' => $totalStock,
                    'total_orders' => $totalOrders,
                    'pvas' => $pvaItems,
                ];
            }
        }

        return [
            'warehouses' => $warehousesList,
            'brands' => $brandsList,
            'sources' => $sourcesList,
            'taxonomies' => $taxonomiesList,
            'type_attributes' => $typeAttrsList,
            'suppliers' => $suppliersList,
            'carriers' => $carriersList,
            'users' => $usersList,
            'products' => $productsList,
        ];
    }

    /**
     * Generate smart auto-match suggestions comparing source and target entities.
     */
    private function generateSuggestedMappings(array $source, array $target): array
    {
        $normalize = fn ($str) => Str::slug(trim((string) $str));

        // 1. Warehouses
        $warehouses = [];
        foreach ($source['warehouses'] as $sw) {
            $matched = null;
            $sNorm = $normalize($sw['title']);
            foreach ($target['warehouses'] as $tw) {
                if ($sNorm === $normalize($tw['title']) || count($target['warehouses']) === 1) {
                    $matched = $tw['id'];
                    break;
                }
            }
            $warehouses[$sw['id']] = [
                'action' => $matched ? 'merge' : 'transfer',
                'target_id' => $matched,
            ];
        }

        // 2. Brands
        $brands = [];
        foreach ($source['brands'] as $sb) {
            $matched = null;
            $sNorm = $normalize($sb['title']);
            foreach ($target['brands'] as $tb) {
                if ($sNorm === $normalize($tb['title'])) {
                    $matched = $tb['id'];
                    break;
                }
            }
            $brands[$sb['id']] = [
                'action' => $matched ? 'merge' : 'transfer',
                'target_id' => $matched,
            ];
        }

        // 3. Sources
        $sources = [];
        foreach ($source['sources'] as $ss) {
            $matched = null;
            $sNorm = $normalize($ss['title']);
            foreach ($target['sources'] as $ts) {
                if ($sNorm === $normalize($ts['title'])) {
                    $matched = $ts['id'];
                    break;
                }
            }
            $sources[$ss['id']] = [
                'action' => $matched ? 'merge' : 'transfer',
                'target_id' => $matched,
            ];
        }

        // 4. Taxonomies
        $taxonomies = [];
        foreach ($source['taxonomies'] as $st) {
            $matched = null;
            $sNorm = $normalize($st['title']);
            foreach ($target['taxonomies'] as $tt) {
                if ($sNorm === $normalize($tt['title'])) {
                    $matched = $tt['id'];
                    break;
                }
            }
            $taxonomies[$st['id']] = [
                'action' => $matched ? 'merge' : 'transfer',
                'target_id' => $matched,
            ];
        }

        // 5. TypeAttributes & Attributes
        $typeAttrs = [];
        $attrs = [];
        foreach ($source['type_attributes'] as $sta) {
            $matchedTa = null;
            $staNorm = $normalize($sta['title']);
            // e.g. "taille" ~ "sizes", "couleur" ~ "colors"
            foreach ($target['type_attributes'] as $tta) {
                $ttaNorm = $normalize($tta['title']);
                if (
                    $staNorm === $ttaNorm ||
                    ($staNorm === 'taille' && $ttaNorm === 'sizes') ||
                    ($staNorm === 'couleur' && $ttaNorm === 'colors')
                ) {
                    $matchedTa = $tta['id'];
                    break;
                }
            }
            $typeAttrs[$sta['id']] = [
                'action' => $matchedTa ? 'merge' : 'transfer',
                'target_id' => $matchedTa,
            ];

            // Attributes under this type_attribute
            $targetAttrPool = [];
            if ($matchedTa) {
                $tTaFound = collect($target['type_attributes'])->firstWhere('id', $matchedTa);
                $targetAttrPool = $tTaFound['attributes'] ?? [];
            }
            foreach ($sta['attributes'] as $sa) {
                $matchedA = null;
                $saNorm = $normalize($sa['title']);
                foreach ($targetAttrPool as $taItem) {
                    if ($saNorm === $normalize($taItem['title'])) {
                        $matchedA = $taItem['id'];
                        break;
                    }
                }
                $attrs[$sa['id']] = [
                    'action' => $matchedA ? 'merge' : 'transfer',
                    'target_id' => $matchedA,
                ];
            }
        }

        // 6. Suppliers
        $suppliers = [];
        foreach ($source['suppliers'] as $ssup) {
            $matched = null;
            $sNorm = $normalize($ssup['title']);
            foreach ($target['suppliers'] as $tsup) {
                if ($sNorm === $normalize($tsup['title'])) {
                    $matched = $tsup['id'];
                    break;
                }
            }
            $suppliers[$ssup['id']] = [
                'action' => $matched ? 'merge' : 'transfer',
                'target_id' => $matched,
            ];
        }

        // 7. Carriers
        $carriers = [];
        foreach ($source['carriers'] as $sc) {
            $matched = null;
            $sNorm = $normalize($sc['title']);
            foreach ($target['carriers'] as $tc) {
                if ($sNorm === $normalize($tc['title'])) {
                    $matched = $tc['id'];
                    break;
                }
            }
            $carriers[$sc['id']] = [
                'action' => $matched ? 'merge' : 'transfer',
                'target_id' => $matched,
            ];
        }

        // 8. Users
        $users = [];
        foreach ($source['users'] as $su) {
            $matchedAu = null;
            $suEmail = strtolower(trim($su['email']));
            foreach ($target['users'] as $tu) {
                if ($suEmail !== '' && $suEmail === strtolower(trim($tu['email']))) {
                    $matchedAu = $tu['account_user_id'];
                    break;
                }
            }
            $users[$su['account_user_id']] = [
                'action' => $matchedAu ? 'merge' : 'transfer',
                'target_account_user_id' => $matchedAu,
            ];
        }

        // 9. Products & PVAs
        $products = [];
        foreach ($source['products'] as $sp) {
            $matchedP = null;
            $sRef = $normalize($sp['reference'] ?? $sp['code']);
            $sTitle = $normalize($sp['title']);

            foreach ($target['products'] as $tp) {
                $tRef = $normalize($tp['reference'] ?? $tp['code']);
                $tTitle = $normalize($tp['title']);

                if (
                    ($sRef !== '' && $sRef === $tRef) ||
                    ($sTitle !== '' && $sTitle === $tTitle) ||
                    // partial match e.g. "082N" / "082T" starting with "082"
                    (strlen($sRef) >= 3 && strlen($tRef) >= 3 && (str_starts_with($sRef, $tRef) || str_starts_with($tRef, $sRef)))
                ) {
                    $matchedP = $tp['id'];
                    break;
                }
            }

            $pvaConfig = [];
            $targetPvas = [];
            if ($matchedP) {
                $tPFound = collect($target['products'])->firstWhere('id', $matchedP);
                $targetPvas = $tPFound['pvas'] ?? [];
            }

            // Conservative rule: merge two variations only if their attribute VALUES are the same set
            // (type names are ignored: TAILLE=Sizes) and the target variation is still active.
            // Anything ambiguous (e.g. size-only vs size+colour) is left as "add as new" for manual review.
            $valueSet = function (array $pva) use ($normalize): string {
                $vals = array_map(fn ($a) => $normalize($a['attribute_title']), $pva['attributes'] ?? []);
                sort($vals);

                return implode('|', $vals);
            };

            $targetPvaIndex = [];
            foreach ($targetPvas as $tPva) {
                $key = $valueSet($tPva);
                if ($key !== '' && (int) ($tPva['statut'] ?? 1) === 1 && ! isset($targetPvaIndex[$key])) {
                    $targetPvaIndex[$key] = $tPva['id'];
                }
            }

            foreach ($sp['pvas'] as $sPva) {
                $sKey = $valueSet($sPva);
                $matchedPva = $sKey !== '' ? ($targetPvaIndex[$sKey] ?? null) : null;

                $pvaConfig[$sPva['id']] = [
                    'action' => $matchedPva ? 'merge' : 'add_as_new',
                    'target_pva_id' => $matchedPva,
                ];
            }

            $products[$sp['id']] = [
                'action' => $matchedP ? 'merge' : 'transfer',
                'target_product_id' => $matchedP,
                'pvas' => $pvaConfig,
            ];
        }

        return [
            'stock_policy' => 'sum',
            'warehouses' => $warehouses,
            'brands' => $brands,
            'sources' => $sources,
            'taxonomies' => $taxonomies,
            'type_attributes' => $typeAttrs,
            'attributes' => $attrs,
            'suppliers' => $suppliers,
            'carriers' => $carriers,
            'users' => $users,
            'products' => $products,
            'options' => [
                'merge_duplicate_customers' => true,
                'rename_conflicting_order_codes' => true,
                'deactivate_source_account' => true,
            ],
        ];
    }

    /**
     * Save mapping draft to storage.
     */
    public function saveDraft(int $sourceAccountId, int $targetAccountId, array $config): bool
    {
        $dir = storage_path('app/account_merge_drafts');
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $path = "{$dir}/merge_{$sourceAccountId}_{$targetAccountId}.json";
        File::put($path, json_encode([
            'source_account_id' => $sourceAccountId,
            'target_account_id' => $targetAccountId,
            'updated_at' => now()->toIso8601String(),
            'config' => $config,
        ], JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * Load mapping draft if it exists.
     */
    private function loadDraft(int $sourceAccountId, int $targetAccountId): ?array
    {
        $path = storage_path("app/account_merge_drafts/merge_{$sourceAccountId}_{$targetAccountId}.json");
        if (File::exists($path)) {
            $content = json_decode(File::get($path), true);
            return $content['config'] ?? null;
        }

        return null;
    }

    /**
     * Run dry-run calculation and return preview report.
     */
    public function preview(int $sourceAccountId, int $targetAccountId, array $config): array
    {
        return AccountContext::run(null, function () use ($sourceAccountId, $targetAccountId, $config) {
            $warnings = [];

            // 1. Conflicting order codes
            $conflictingCodes = DB::table('orders as o1')
                ->join('orders as o2', 'o1.code', '=', 'o2.code')
                ->where('o1.account_id', $sourceAccountId)
                ->where('o2.account_id', $targetAccountId)
                ->whereNotNull('o1.code')
                ->where('o1.code', '!=', '')
                ->distinct()
                ->pluck('o1.code')
                ->all();

            $conflictingCodesCount = count($conflictingCodes);
            if ($conflictingCodesCount > 0) {
                $sample = array_slice($conflictingCodes, 0, 5);
                $warnings[] = "Found {$conflictingCodesCount} overlapping order code(s) (e.g. " . implode(', ', $sample) . ") which will receive the suffix '-A{$sourceAccountId}'.";
            }

            // 2. Potential duplicate customers (by phone number)
            $sourcePhones = DB::table('phoneables')
                ->join('phones', 'phones.id', '=', 'phoneables.phone_id')
                ->join('customers', 'customers.id', '=', 'phoneables.phoneable_id')
                ->where('phoneables.phoneable_type', Customer::class)
                ->where('customers.account_id', $sourceAccountId)
                ->whereNull('customers.deleted_at')
                ->whereRaw('CHAR_LENGTH(phones.title) >= 8')
                ->pluck('phones.title')
                ->all();

            $crossPhonesCount = 0;
            if (!empty($sourcePhones)) {
                $crossPhonesCount = DB::table('phoneables')
                    ->join('phones', 'phones.id', '=', 'phoneables.phone_id')
                    ->join('customers', 'customers.id', '=', 'phoneables.phoneable_id')
                    ->where('phoneables.phoneable_type', Customer::class)
                    ->where('customers.account_id', $targetAccountId)
                    ->whereIn('phones.title', array_unique($sourcePhones))
                    ->distinct()
                    ->count('phones.title');
            }

            // 3. Count products & PVAs actions
            $productsConfig = $config['products'] ?? [];
            $productsMerged = 0;
            $productsTransferred = 0;
            $pvasMerged = 0;
            $pvasAddedAsNew = 0;

            foreach ($productsConfig as $pid => $pConf) {
                if (($pConf['action'] ?? '') === 'merge' && !empty($pConf['target_product_id'])) {
                    $productsMerged++;
                    foreach ($pConf['pvas'] ?? [] as $pvaId => $pvaConf) {
                        if (($pvaConf['action'] ?? '') === 'merge' && !empty($pvaConf['target_pva_id'])) {
                            $pvasMerged++;
                        } else {
                            $pvasAddedAsNew++;
                        }
                    }
                } else {
                    $productsTransferred++;
                }
            }

            // 4. Summaries of other entities
            $countActions = function (array $entityMap) {
                $merged = 0;
                $transferred = 0;
                foreach ($entityMap as $item) {
                    if (($item['action'] ?? '') === 'merge' && (!empty($item['target_id']) || !empty($item['target_account_user_id']))) {
                        $merged++;
                    } else {
                        $transferred++;
                    }
                }
                return ['merged' => $merged, 'transferred' => $transferred];
            };

            $warehousesSummary = $countActions($config['warehouses'] ?? []);
            $brandsSummary = $countActions($config['brands'] ?? []);
            $sourcesSummary = $countActions($config['sources'] ?? []);
            $taxonomiesSummary = $countActions($config['taxonomies'] ?? []);
            $typeAttrsSummary = $countActions($config['type_attributes'] ?? []);
            $suppliersSummary = $countActions($config['suppliers'] ?? []);
            $carriersSummary = $countActions($config['carriers'] ?? []);
            $usersSummary = $countActions($config['users'] ?? []);

            $totalOrders = DB::table('orders')->where('account_id', $sourceAccountId)->count();
            $totalCustomers = DB::table('customers')->where('account_id', $sourceAccountId)->count();

            return [
                'source_account_id' => $sourceAccountId,
                'target_account_id' => $targetAccountId,
                'orders_to_move' => $totalOrders,
                'customers_to_move' => $totalCustomers,
                'conflicting_order_codes_count' => $conflictingCodesCount,
                'conflicting_order_codes_sample' => array_slice($conflictingCodes, 0, 10),
                'shared_phone_numbers_count' => $crossPhonesCount,
                'stock_policy' => $config['stock_policy'] ?? 'sum',
                'summary' => [
                    'warehouses' => $warehousesSummary,
                    'brands' => $brandsSummary,
                    'sources' => $sourcesSummary,
                    'taxonomies' => $taxonomiesSummary,
                    'type_attributes' => $typeAttrsSummary,
                    'suppliers' => $suppliersSummary,
                    'carriers' => $carriersSummary,
                    'users' => $usersSummary,
                    'products' => [
                        'merged' => $productsMerged,
                        'transferred' => $productsTransferred,
                        'pvas_merged' => $pvasMerged,
                        'pvas_added_as_new' => $pvasAddedAsNew,
                    ],
                ],
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Execute transactional merge.
     */
    public function execute(int $sourceAccountId, int $targetAccountId, array $config): array
    {
        return AccountContext::run(null, function () use ($sourceAccountId, $targetAccountId, $config) {
            return DB::transaction(function () use ($sourceAccountId, $targetAccountId, $config) {
                $stockPolicy = $config['stock_policy'] ?? 'sum';
                $options = $config['options'] ?? [];
                $renameOrderCodes = (bool) ($options['rename_conflicting_order_codes'] ?? true);
                $mergeDuplicateCustomers = (bool) ($options['merge_duplicate_customers'] ?? true);
                $deactivateSource = (bool) ($options['deactivate_source_account'] ?? true);

                $report = [
                    'source_account_id' => $sourceAccountId,
                    'target_account_id' => $targetAccountId,
                    'steps_completed' => [],
                ];

                // =========================================================================
                // STEP 1: EMPLOYEES (account_user)
                // =========================================================================
                $usersConfig = $config['users'] ?? [];
                $userFkTables = [
                    'account_compensation', 'account_user_order_status', 'afra_sync_runs', 'attributes',
                    'compensationables', 'customer_types', 'expense_types', 'expenses', 'inventories',
                    'inventory_pva', 'invoices', 'mouvement_pva', 'mouvements', 'offerables',
                    'order_comment', 'order_pva', 'paymentables', 'payments', 'pickups',
                    'product_brand_source', 'products', 'shipments', 'stock_adjustments',
                    'subcomments', 'supplier_billings', 'supplier_order_pva', 'supplier_orders',
                    'supplier_receipts', 'taxonomies', 'transactions', 'type_attributes', 'warehouse_user',
                ];

                $mergedUsersCount = 0;
                $transferredUsersCount = 0;

                foreach ($usersConfig as $sourceAuId => $uConf) {
                    $sourceAuId = (int) $sourceAuId;
                    $action = $uConf['action'] ?? 'transfer';
                    $targetAuId = !empty($uConf['target_account_user_id']) ? (int) $uConf['target_account_user_id'] : null;

                    if ($action === 'merge' && $targetAuId && $targetAuId !== $sourceAuId) {
                        // Remap all account_user_id foreign keys
                        foreach ($userFkTables as $tbl) {
                            if (DB::getSchemaBuilder()->hasColumn($tbl, 'account_user_id')) {
                                DB::table($tbl)->where('account_user_id', $sourceAuId)->update(['account_user_id' => $targetAuId]);
                            }
                        }
                        // Orders assignment/claimed
                        DB::table('orders')->where('assigned_to', $sourceAuId)->update(['assigned_to' => $targetAuId]);
                        DB::table('orders')->where('claimed_by', $sourceAuId)->update(['claimed_by' => $targetAuId]);
                        if (DB::getSchemaBuilder()->hasTable('order_calls')) {
                            DB::table('order_calls')->where('employee_id', $sourceAuId)->update(['employee_id' => $targetAuId]);
                        }
                        if (DB::getSchemaBuilder()->hasTable('afra_city_changes')) {
                            DB::table('afra_city_changes')->where('resolved_by', $sourceAuId)->update(['resolved_by' => $targetAuId]);
                        }

                        // Polymorphic AccountUser links
                        DB::table('model_has_roles')
                            ->where('model_type', AccountUser::class)
                            ->where('model_id', $sourceAuId)
                            ->update(['model_id' => $targetAuId]);
                        DB::table('model_has_permissions')
                            ->where('model_type', AccountUser::class)
                            ->where('model_id', $sourceAuId)
                            ->update(['model_id' => $targetAuId]);

                        // Deactivate source account_user
                        DB::table('account_user')->where('id', $sourceAuId)->update(['statut' => 0]);
                        $mergedUsersCount++;
                    } else {
                        // Transfer user to target account
                        DB::table('account_user')->where('id', $sourceAuId)->update(['account_id' => $targetAccountId]);
                        $transferredUsersCount++;
                    }
                }
                $report['steps_completed'][] = "Users: {$mergedUsersCount} merged, {$transferredUsersCount} transferred.";

                // =========================================================================
                // STEP 2: WAREHOUSES & STOCK (warehouses, warehouse_pva)
                // =========================================================================
                $warehousesConfig = $config['warehouses'] ?? [];
                $warehouseFkTables = [
                    'inventories', 'orders', 'pickups', 'shipments', 'stock_adjustments',
                    'supplier_orders', 'supplier_receipts', 'warehouse_supplier', 'warehouse_user',
                ];

                $mergedWhCount = 0;
                $transferredWhCount = 0;

                foreach ($warehousesConfig as $sourceWhId => $whConf) {
                    $sourceWhId = (int) $sourceWhId;
                    $action = $whConf['action'] ?? 'transfer';
                    $targetWhId = !empty($whConf['target_id']) ? (int) $whConf['target_id'] : null;

                    if ($action === 'merge' && $targetWhId && $targetWhId !== $sourceWhId) {
                        // Re-points every reference of one source warehouse node to a target node,
                        // and moves its stock rows (resolving duplicates with the stock policy).
                        $remapWarehouseNode = function (int $fromId, int $toId) use ($warehouseFkTables, $stockPolicy) {
                            foreach ($warehouseFkTables as $tbl) {
                                if (DB::getSchemaBuilder()->hasColumn($tbl, 'warehouse_id')) {
                                    DB::table($tbl)->where('warehouse_id', $fromId)->update(['warehouse_id' => $toId]);
                                }
                            }
                            DB::table('mouvements')->where('from_warehouse', $fromId)->update(['from_warehouse' => $toId]);
                            DB::table('mouvements')->where('to_warehouse', $fromId)->update(['to_warehouse' => $toId]);
                            if (DB::getSchemaBuilder()->hasColumn('woocommerce_stores', 'default_warehouse_id')) {
                                DB::table('woocommerce_stores')->where('default_warehouse_id', $fromId)->update(['default_warehouse_id' => $toId]);
                            }

                            foreach (DB::table('warehouse_pva')->where('warehouse_id', $fromId)->get() as $swp) {
                                $targetWp = DB::table('warehouse_pva')
                                    ->where('warehouse_id', $toId)
                                    ->where('product_variation_attribute_id', $swp->product_variation_attribute_id)
                                    ->first();

                                if ($targetWp) {
                                    $newQty = match ($stockPolicy) {
                                        'take_source' => (int) $swp->quantity,
                                        'keep_target' => (int) $targetWp->quantity,
                                        default => (int) $targetWp->quantity + (int) $swp->quantity,
                                    };
                                    DB::table('warehouse_pva')->where('id', $targetWp->id)->update(['quantity' => $newQty]);
                                    DB::table('warehouse_pva')->where('id', $swp->id)->delete();
                                } else {
                                    DB::table('warehouse_pva')->where('id', $swp->id)->update(['warehouse_id' => $toId]);
                                }
                            }

                            DB::table('warehouses')->where('id', $fromId)->update(['statut' => 0]);
                        };

                        $targetDefaultPartition = DB::table('warehouses')
                            ->where('account_id', $targetAccountId)
                            ->where('warehouse_type_id', 2)
                            ->where('warehouse_id', $targetWhId)
                            ->first();

                        $targetDefaultRayon = $targetDefaultPartition ? DB::table('warehouses')
                            ->where('account_id', $targetAccountId)
                            ->where('warehouse_type_id', 3)
                            ->where('warehouse_id', $targetDefaultPartition->id)
                            ->first() : null;

                        $targetPartId = $targetDefaultPartition ? (int) $targetDefaultPartition->id : $targetWhId;
                        $targetRayId = $targetDefaultRayon ? (int) $targetDefaultRayon->id : $targetPartId;

                        $sourcePartitions = DB::table('warehouses')
                            ->where('account_id', $sourceAccountId)
                            ->where('warehouse_type_id', 2)
                            ->where('warehouse_id', $sourceWhId)
                            ->get();

                        foreach ($sourcePartitions as $sp) {
                            $sourceRayons = DB::table('warehouses')
                                ->where('account_id', $sourceAccountId)
                                ->where('warehouse_type_id', 3)
                                ->where('warehouse_id', $sp->id)
                                ->get();

                            foreach ($sourceRayons as $sr) {
                                $remapWarehouseNode((int) $sr->id, $targetRayId);
                            }
                            $remapWarehouseNode((int) $sp->id, $targetPartId);
                        }

                        $remapWarehouseNode($sourceWhId, $targetWhId);
                        $mergedWhCount++;
                    } else {
                        // Transfer warehouse + its hierarchy to target account
                        DB::table('warehouses')->where('id', $sourceWhId)->update(['account_id' => $targetAccountId]);
                        $sourcePartitions = DB::table('warehouses')->where('warehouse_id', $sourceWhId)->get();
                        foreach ($sourcePartitions as $sp) {
                            DB::table('warehouses')->where('id', $sp->id)->update(['account_id' => $targetAccountId]);
                            DB::table('warehouses')->where('warehouse_id', $sp->id)->update(['account_id' => $targetAccountId]);
                        }
                        $transferredWhCount++;
                    }
                }
                $report['steps_completed'][] = "Warehouses: {$mergedWhCount} merged, {$transferredWhCount} transferred.";

                // =========================================================================
                // STEP 3: BRANDS, SOURCES & brand_source
                // =========================================================================
                $brandsMap = [];
                foreach ($config['brands'] ?? [] as $sBid => $bConf) {
                    $sBid = (int) $sBid;
                    if (($bConf['action'] ?? '') === 'merge' && !empty($bConf['target_id'])) {
                        $brandsMap[$sBid] = (int) $bConf['target_id'];
                    } else {
                        DB::table('brands')->where('id', $sBid)->update(['account_id' => $targetAccountId]);
                        $brandsMap[$sBid] = $sBid;
                    }
                }

                $sourcesMap = [];
                foreach ($config['sources'] ?? [] as $sSid => $sConf) {
                    $sSid = (int) $sSid;
                    if (($sConf['action'] ?? '') === 'merge' && !empty($sConf['target_id'])) {
                        $sourcesMap[$sSid] = (int) $sConf['target_id'];
                    } else {
                        DB::table('sources')->where('id', $sSid)->update(['account_id' => $targetAccountId]);
                        $sourcesMap[$sSid] = $sSid;
                    }
                }

                // Resolve brand_source combinations
                $sourceBrandSources = DB::table('brand_source')->where('account_id', $sourceAccountId)->get();
                foreach ($sourceBrandSources as $sBs) {
                    $resolvedBrandId = $brandsMap[$sBs->brand_id] ?? $sBs->brand_id;
                    $resolvedSourceId = $sourcesMap[$sBs->source_id] ?? $sBs->source_id;

                    $targetBs = DB::table('brand_source')
                        ->where('account_id', $targetAccountId)
                        ->where('brand_id', $resolvedBrandId)
                        ->where('source_id', $resolvedSourceId)
                        ->first();

                    if (! $targetBs) {
                        $targetBsId = DB::table('brand_source')->insertGetId([
                            'account_id' => $targetAccountId,
                            'brand_id' => $resolvedBrandId,
                            'source_id' => $resolvedSourceId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $targetBsId = $targetBs->id;
                    }

                    DB::table('orders')->where('brand_source_id', $sBs->id)->update(['brand_source_id' => $targetBsId]);
                    DB::table('product_brand_source')->where('brand_source_id', $sBs->id)->update(['brand_source_id' => $targetBsId]);
                    if (DB::getSchemaBuilder()->hasColumn('woocommerce_stores', 'default_brand_source_id')) {
                        DB::table('woocommerce_stores')->where('default_brand_source_id', $sBs->id)->update(['default_brand_source_id' => $targetBsId]);
                    }

                    if ($sBs->id !== $targetBsId) {
                        DB::table('brand_source')->where('id', $sBs->id)->delete();
                    }
                }
                $report['steps_completed'][] = 'Brands & Sources mapped; brand_source pairs unified.';

                // =========================================================================
                // STEP 4: TAXONOMIES & ATTRIBUTES
                // =========================================================================
                foreach ($config['taxonomies'] ?? [] as $sTid => $tConf) {
                    $sTid = (int) $sTid;
                    if (($tConf['action'] ?? '') === 'merge' && !empty($tConf['target_id'])) {
                        $targetTid = (int) $tConf['target_id'];
                        DB::table('taxonomy_product')->where('taxonomy_id', $sTid)->update(['taxonomy_id' => $targetTid]);
                        DB::table('taxonomies')->where('id', $sTid)->update(['statut' => 0]);
                    }
                }

                foreach ($config['attributes'] ?? [] as $sAid => $aConf) {
                    $sAid = (int) $sAid;
                    if (($aConf['action'] ?? '') === 'merge' && !empty($aConf['target_id'])) {
                        $targetAid = (int) $aConf['target_id'];
                        DB::table('variation_attributes')->where('attribute_id', $sAid)->update(['attribute_id' => $targetAid]);
                        DB::table('attributes')->where('id', $sAid)->delete();
                    }
                }
                $report['steps_completed'][] = 'Taxonomies and Attributes reconciled.';

                // =========================================================================
                // STEP 5: SUPPLIERS & CARRIERS
                // =========================================================================
                foreach ($config['suppliers'] ?? [] as $sSupId => $supConf) {
                    $sSupId = (int) $sSupId;
                    if (($supConf['action'] ?? '') === 'merge' && !empty($supConf['target_id'])) {
                        $targetSupId = (int) $supConf['target_id'];
                        DB::table('supplier_orders')->where('supplier_id', $sSupId)->update(['supplier_id' => $targetSupId]);
                        DB::table('supplier_receipts')->where('supplier_id', $sSupId)->update(['supplier_id' => $targetSupId]);
                        DB::table('supplier_pva')->where('supplier_id', $sSupId)->update(['supplier_id' => $targetSupId]);
                        DB::table('suppliers')->where('id', $sSupId)->delete();
                    } else {
                        DB::table('suppliers')->where('id', $sSupId)->update(['account_id' => $targetAccountId]);
                    }
                }

                foreach ($config['carriers'] ?? [] as $sCId => $cConf) {
                    $sCId = (int) $sCId;
                    if (($cConf['action'] ?? '') === 'merge' && !empty($cConf['target_id'])) {
                        $targetCId = (int) $cConf['target_id'];
                        DB::table('pickups')->where('carrier_id', $sCId)->update(['carrier_id' => $targetCId]);
                        DB::table('shipments')->where('carrier_id', $sCId)->update(['carrier_id' => $targetCId]);

                        // Reassign account_carrier links
                        $targetAc = DB::table('account_carrier')->where('account_id', $targetAccountId)->where('carrier_id', $targetCId)->first();
                        $sourceAc = DB::table('account_carrier')->where('account_id', $sourceAccountId)->where('carrier_id', $sCId)->first();
                        if ($targetAc && $sourceAc) {
                            if (\Illuminate\Support\Facades\Schema::hasTable('carrier_pickups')) {
                                DB::table('carrier_pickups')->where('account_carrier_id', $sourceAc->id)->update(['account_carrier_id' => $targetAc->id]);
                            }
                            DB::table('account_carrier_city')->where('account_carrier_id', $sourceAc->id)->update(['account_carrier_id' => $targetAc->id]);
                            DB::table('account_carrier')->where('id', $sourceAc->id)->delete();
                        }
                    } else {
                        DB::table('carriers')->where('id', $sCId)->where('account_id', $sourceAccountId)->update(['account_id' => $targetAccountId]);
                        DB::table('account_carrier')->where('account_id', $sourceAccountId)->where('carrier_id', $sCId)->update(['account_id' => $targetAccountId]);
                    }
                }
                $report['steps_completed'][] = 'Suppliers and Carriers updated.';

                // =========================================================================
                // STEP 6: PRODUCTS & 2-LEVEL VARIATION (PVA) MAPPING
                // =========================================================================
                $productsConfig = $config['products'] ?? [];
                $pvaFkTables = [
                    'order_pva', 'mouvement_pva', 'inventory_pva', 'supplier_order_pva',
                    'supplier_order_receipt_allocations', 'pva_measurement', 'pva_packs',
                    'stock_adjustments', 'woocommerce_variation_links',
                ];

                foreach ($productsConfig as $sPid => $pConf) {
                    $sPid = (int) $sPid;
                    $action = $pConf['action'] ?? 'transfer';
                    $targetPid = !empty($pConf['target_product_id']) ? (int) $pConf['target_product_id'] : null;

                    if ($action === 'merge' && $targetPid && $targetPid !== $sPid) {
                        // Product level remapping
                        DB::table('product_brand_source')->where('product_id', $sPid)->update(['product_id' => $targetPid]);

                        // taxonomy_product points to account_product.id (not products.id)
                        $srcApId = DB::table('account_product')->where('account_id', $sourceAccountId)->where('product_id', $sPid)->value('id');
                        $tgtApId = DB::table('account_product')->where('account_id', $targetAccountId)->where('product_id', $targetPid)->value('id');
                        if ($srcApId && $tgtApId) {
                            $existingTaxIds = DB::table('taxonomy_product')->where('account_product_id', $tgtApId)->pluck('taxonomy_id')->all();
                            DB::table('taxonomy_product')
                                ->where('account_product_id', $srcApId)
                                ->whereIn('taxonomy_id', $existingTaxIds ?: [0])
                                ->delete();
                            DB::table('taxonomy_product')->where('account_product_id', $srcApId)->update(['account_product_id' => $tgtApId]);
                        }

                        // PVA variation mapping
                        foreach ($pConf['pvas'] ?? [] as $sPvaId => $pvaConf) {
                            $sPvaId = (int) $sPvaId;
                            $pvaAction = $pvaConf['action'] ?? 'add_as_new';
                            $targetPvaId = !empty($pvaConf['target_pva_id']) ? (int) $pvaConf['target_pva_id'] : null;

                            if ($pvaAction === 'merge' && $targetPvaId && $targetPvaId !== $sPvaId) {
                                foreach ($pvaFkTables as $tbl) {
                                    if (DB::getSchemaBuilder()->hasColumn($tbl, 'product_variation_attribute_id')) {
                                        DB::table($tbl)->where('product_variation_attribute_id', $sPvaId)->update(['product_variation_attribute_id' => $targetPvaId]);
                                    }
                                }

                                // Stock merge in warehouse_pva
                                $sourceWpvas = DB::table('warehouse_pva')->where('product_variation_attribute_id', $sPvaId)->get();
                                foreach ($sourceWpvas as $swp) {
                                    $targetWp = DB::table('warehouse_pva')
                                        ->where('warehouse_id', $swp->warehouse_id)
                                        ->where('product_variation_attribute_id', $targetPvaId)
                                        ->first();

                                    if ($targetWp) {
                                        $newQty = match ($stockPolicy) {
                                            'take_source' => (float) $swp->quantity,
                                            'keep_target' => (float) $targetWp->quantity,
                                            default => (float) $targetWp->quantity + (float) $swp->quantity,
                                        };
                                        DB::table('warehouse_pva')->where('id', $targetWp->id)->update(['quantity' => $newQty]);
                                        DB::table('warehouse_pva')->where('id', $swp->id)->delete();
                                    } else {
                                        DB::table('warehouse_pva')->where('id', $swp->id)->update(['product_variation_attribute_id' => $targetPvaId]);
                                    }
                                }

                                DB::table('supplier_pva')->where('product_variation_attribute_id', $sPvaId)->update(['product_variation_attribute_id' => $targetPvaId]);
                                DB::table('product_variation_attribute')->where('id', $sPvaId)->update(['statut' => 0]);
                            } else {
                                // Add variation under target product
                                DB::table('product_variation_attribute')->where('id', $sPvaId)->update([
                                    'product_id' => $targetPid,
                                    'account_id' => $targetAccountId,
                                ]);
                            }
                        }

                        // Remove account_product link and deactivate source product
                        DB::table('account_product')->where('account_id', $sourceAccountId)->where('product_id', $sPid)->delete();
                        DB::table('products')->where('id', $sPid)->update(['statut' => 0]);
                    } else {
                        // Transfer entire product
                        $existingAp = DB::table('account_product')->where('account_id', $targetAccountId)->where('product_id', $sPid)->first();
                        if (! $existingAp) {
                            DB::table('account_product')->where('account_id', $sourceAccountId)->where('product_id', $sPid)->update(['account_id' => $targetAccountId]);
                        } else {
                            DB::table('account_product')->where('account_id', $sourceAccountId)->where('product_id', $sPid)->delete();
                        }
                        DB::table('product_variation_attribute')->where('product_id', $sPid)->update(['account_id' => $targetAccountId]);
                    }
                }
                $report['steps_completed'][] = 'Products & PVAs merged/transferred.';

                // =========================================================================
                // STEP 7: ORDERS, CUSTOMERS & REMAINING TABLES
                // =========================================================================
                // 1. Rename conflicting order codes
                if ($renameOrderCodes) {
                    $conflictingOrders = DB::table('orders as o1')
                        ->join('orders as o2', 'o1.code', '=', 'o2.code')
                        ->where('o1.account_id', $sourceAccountId)
                        ->where('o2.account_id', $targetAccountId)
                        ->whereNotNull('o1.code')
                        ->where('o1.code', '!=', '')
                        ->select('o1.id', 'o1.code')
                        ->get();

                    $renamedCodesCount = 0;
                    foreach ($conflictingOrders as $co) {
                        DB::table('orders')->where('id', $co->id)->update([
                            'code' => $co->code . "-A{$sourceAccountId}",
                            'updated_at' => now(),
                        ]);
                        $renamedCodesCount++;
                    }
                    $report['steps_completed'][] = "Renamed {$renamedCodesCount} overlapping order codes with suffix '-A{$sourceAccountId}'.";
                }

                // 2. Bulk update all remaining tables referencing account_id
                $accountScopedTables = [
                    'orders', 'customers', 'phones', 'phone_type', 'phone_types', 'addresses',
                    'images', 'comments', 'offers', 'offerable_variations', 'cash_drawers',
                    'message_templates', 'afra_sync_runs', 'stock_adjustments', 'warehouse_natures',
                    'supplier_pva', 'variation_attributes',
                    'woocommerce_stores', 'woocommerce_order_links', 'woocommerce_variation_links', 'woocommerce_sync_logs',
                ];

                foreach ($accountScopedTables as $tbl) {
                    if (DB::getSchemaBuilder()->hasColumn($tbl, 'account_id')) {
                        DB::table($tbl)->where('account_id', $sourceAccountId)->update(['account_id' => $targetAccountId]);
                    }
                }

                // 3. Sync account_codes counters
                $sourceCodes = DB::table('account_codes')->where('account_id', $sourceAccountId)->get();
                foreach ($sourceCodes as $sc) {
                    $targetAc = DB::table('account_codes')
                        ->where('account_id', $targetAccountId)
                        ->where('default_code_id', $sc->default_code_id)
                        ->first();

                    if ($targetAc) {
                        $maxCounter = max((int) $targetAc->counter, (int) $sc->counter);
                        DB::table('account_codes')->where('id', $targetAc->id)->update(['counter' => $maxCounter]);
                        DB::table('account_codes')->where('id', $sc->id)->delete();
                    } else {
                        DB::table('account_codes')->where('id', $sc->id)->update(['account_id' => $targetAccountId]);
                    }
                }

                // 4. Update Polymorphic tables
                $polyTables = ['phoneables', 'addressables', 'imageables', 'model_has_roles'];
                foreach ($polyTables as $pt) {
                    $typeCol = $pt === 'model_has_roles' ? 'model_type' : substr($pt, 0, -1) . '_type';
                    $idCol = $pt === 'model_has_roles' ? 'model_id' : substr($pt, 0, -1) . '_id';

                    if (DB::getSchemaBuilder()->hasTable($pt) && DB::getSchemaBuilder()->hasColumn($pt, $typeCol)) {
                        if ($pt === 'model_has_roles') {
                            // PK = (role_id, model_id, model_type): skip roles the target already owns
                            $targetRoleIds = DB::table($pt)
                                ->where($typeCol, Account::class)
                                ->where($idCol, $targetAccountId)
                                ->pluck('role_id')
                                ->all();
                            if (! empty($targetRoleIds)) {
                                DB::table($pt)
                                    ->where($typeCol, Account::class)
                                    ->where($idCol, $sourceAccountId)
                                    ->whereIn('role_id', $targetRoleIds)
                                    ->delete();
                            }
                        }

                        DB::table($pt)
                            ->where($typeCol, Account::class)
                            ->where($idCol, $sourceAccountId)
                            ->update([$idCol => $targetAccountId]);
                    }
                }

                // 5. Deduplicate customers if requested
                if ($mergeDuplicateCustomers) {
                    $dedupResult = CustomerIdentityService::deduplicateAccount($targetAccountId);
                    $report['steps_completed'][] = "Customers deduplicated: {$dedupResult['merged_customers']} customer(s) merged across {$dedupResult['groups_resolved']} group(s).";
                }

                // 6. Deactivate source account
                if ($deactivateSource) {
                    DB::table('accounts')->where('id', $sourceAccountId)->update(['statut' => 0]);
                    $report['steps_completed'][] = "Source Account #{$sourceAccountId} marked as inactive (statut = 0).";
                }

                // 7. Clean up draft file
                $draftPath = storage_path("app/account_merge_drafts/merge_{$sourceAccountId}_{$targetAccountId}.json");
                if (File::exists($draftPath)) {
                    File::delete($draftPath);
                }

                $report['status'] = 'success';
                $report['message'] = "Successfully merged Account #{$sourceAccountId} into Account #{$targetAccountId}.";

                return $report;
            });
        });
    }
}
