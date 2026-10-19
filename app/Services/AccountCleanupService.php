<?php

namespace App\Services;

use App\Models\Account;
use App\Support\AccountContext;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountCleanupService
{
    /**
     * List all accounts with key metadata, counts, and deletion eligibility.
     */
    public function listAccounts(?int $currentAccountId = null): array
    {
        return AccountContext::run(null, function () use ($currentAccountId) {
            $accounts = Account::orderBy('id', 'desc')->get();

            return $accounts->map(function ($account) use ($currentAccountId) {
                $id = (int) $account->id;
                $ordersCount = DB::table('orders')->where('account_id', $id)->count();
                $customersCount = DB::table('customers')->where('account_id', $id)->count();
                $productsCount = DB::table('account_product')->where('account_id', $id)->count();
                $warehousesCount = Schema::hasTable('warehouses')
                    ? DB::table('warehouses')->where('account_id', $id)->where('warehouse_type_id', 1)->count()
                    : 0;
                $usersCount = DB::table('account_user')->where('account_id', $id)->count();

                $isEmpty = ($ordersCount === 0 && $customersCount === 0 && $productsCount === 0);
                $isSystemRoot = ($id === 1);
                $isCurrentSession = ($currentAccountId !== null && $id === (int) $currentAccountId);

                $isProtected = $isSystemRoot || $isCurrentSession || ($ordersCount > 0);
                $canDelete = !$isProtected && ($ordersCount === 0);

                $blockReason = null;
                if ($isSystemRoot) {
                    $blockReason = 'Root system account (#1) cannot be deleted.';
                } elseif ($isCurrentSession) {
                    $blockReason = 'Currently active account in this session.';
                } elseif ($ordersCount > 0) {
                    $blockReason = "Account contains {$ordersCount} order(s). Merge account first.";
                }

                return [
                    'id' => $id,
                    'code' => $account->code ?? "CMPT{$id}",
                    'name' => $account->name ?? "Account #{$id}",
                    'statut' => (int) $account->statut,
                    'created_at' => $account->created_at?->toIso8601String() ?? null,
                    'orders_count' => $ordersCount,
                    'customers_count' => $customersCount,
                    'products_count' => $productsCount,
                    'warehouses_count' => $warehousesCount,
                    'users_count' => $usersCount,
                    'is_empty' => $isEmpty,
                    'is_protected' => $isProtected,
                    'can_delete' => $canDelete,
                    'block_reason' => $blockReason,
                ];
            })->all();
        });
    }

    /**
     * Inspect all child table records remaining in this account.
     */
    public function inspectAccount(int $accountId): array
    {
        return AccountContext::run(null, function () use ($accountId) {
            $account = Account::find($accountId);
            if (!$account) {
                throw new Exception("Account #{$accountId} not found.");
            }

            $auIds = DB::table('account_user')->where('account_id', $accountId)->pluck('id')->all();

            return [
                'account' => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'statut' => (int) $account->statut,
                    'created_at' => $account->created_at?->toIso8601String(),
                ],
                'orders_count' => DB::table('orders')->where('account_id', $accountId)->count(),
                'customers_count' => DB::table('customers')->where('account_id', $accountId)->count(),
                'products_count' => DB::table('account_product')->where('account_id', $accountId)->count(),
                'warehouses_count' => Schema::hasTable('warehouses') ? DB::table('warehouses')->where('account_id', $accountId)->count() : 0,
                'users_count' => count($auIds),
                'brands_count' => Schema::hasTable('brands') ? DB::table('brands')->where('account_id', $accountId)->count() : 0,
                'sources_count' => Schema::hasTable('sources') ? DB::table('sources')->where('account_id', $accountId)->count() : 0,
                'carriers_count' => Schema::hasTable('account_carrier') ? DB::table('account_carrier')->where('account_id', $accountId)->count() : 0,
                'suppliers_count' => Schema::hasTable('suppliers') ? DB::table('suppliers')->where('account_id', $accountId)->count() : 0,
                'taxonomies_count' => (!empty($auIds) && Schema::hasTable('taxonomies')) ? DB::table('taxonomies')->whereIn('account_user_id', $auIds)->count() : 0,
                'attributes_count' => (!empty($auIds) && Schema::hasTable('attributes')) ? DB::table('attributes')->whereIn('account_user_id', $auIds)->count() : 0,
                'codes_count' => Schema::hasTable('account_codes') ? DB::table('account_codes')->where('account_id', $accountId)->count() : 0,
                'locations_count' => Schema::hasTable('account_locations') ? DB::table('account_locations')->where('account_id', $accountId)->count() : 0,
                'compensations_count' => Schema::hasTable('account_compensations') ? DB::table('account_compensations')->where('account_id', $accountId)->count() : 0,
                'roles_count' => DB::table('model_has_roles')->where('model_type', 'App\\Models\\Account')->where('model_id', $accountId)->count(),
            ];
        });
    }

    /**
     * Atomically delete an empty or cleanable account and all its child associations.
     */
    public function deleteAccount(int $accountId, ?int $currentAccountId = null, bool $force = false): array
    {
        return AccountContext::run(null, function () use ($accountId, $currentAccountId, $force) {
            if ($accountId === 1) {
                throw new Exception("Account #1 is the primary system account and cannot be deleted.");
            }

            if ($currentAccountId !== null && $accountId === (int) $currentAccountId) {
                throw new Exception("Cannot delete the currently active session account.");
            }

            $account = Account::find($accountId);
            if (!$account) {
                throw new Exception("Account #{$accountId} not found.");
            }

            $ordersCount = DB::table('orders')->where('account_id', $accountId)->count();
            if ($ordersCount > 0) {
                throw new Exception("Account #{$accountId} contains {$ordersCount} orders and cannot be deleted. Please merge it into another account first.");
            }

            $customersCount = DB::table('customers')->where('account_id', $accountId)->count();
            $productsCount = DB::table('account_product')->where('account_id', $accountId)->count();

            if (($customersCount > 0 || $productsCount > 0) && !$force) {
                throw new Exception("Account #{$accountId} has {$customersCount} customer(s) or {$productsCount} product(s). Confirm force delete to proceed.");
            }

            return DB::transaction(function () use ($account, $accountId) {
                $deletedStats = [];

                // 1. Account Users and user-scoped entities (Taxonomies, Attributes, Statuses, Roles)
                $auIds = DB::table('account_user')->where('account_id', $accountId)->pluck('id')->all();
                if (!empty($auIds)) {
                    if (Schema::hasTable('taxonomies')) {
                        $taxIds = DB::table('taxonomies')->whereIn('account_user_id', $auIds)->pluck('id')->all();
                        if (!empty($taxIds) && Schema::hasTable('taxonomy_product')) {
                            DB::table('taxonomy_product')->whereIn('taxonomy_id', $taxIds)->delete();
                        }
                        DB::table('taxonomies')->whereIn('account_user_id', $auIds)->delete();
                    }
                    if (Schema::hasTable('attributes')) {
                        DB::table('attributes')->whereIn('account_user_id', $auIds)->delete();
                    }
                    if (Schema::hasTable('type_attributes')) {
                        DB::table('type_attributes')->whereIn('account_user_id', $auIds)->delete();
                    }
                    if (Schema::hasTable('account_user_order_statuses')) {
                        DB::table('account_user_order_statuses')->whereIn('account_user_id', $auIds)->delete();
                    }
                    DB::table('model_has_roles')
                        ->where('model_type', 'App\\Models\\AccountUser')
                        ->whereIn('model_id', $auIds)
                        ->delete();
                    $deletedStats['account_users'] = DB::table('account_user')->where('account_id', $accountId)->delete();
                }

                // 2. Model Roles for Account
                $deletedStats['account_roles'] = DB::table('model_has_roles')
                    ->where('model_type', 'App\\Models\\Account')
                    ->where('model_id', $accountId)
                    ->delete();

                // 3. Products Pivot
                if (Schema::hasTable('account_product')) {
                    $deletedStats['account_products'] = DB::table('account_product')->where('account_id', $accountId)->delete();
                }

                // 4. Warehouses & Stock (PVAs)
                if (Schema::hasTable('warehouses')) {
                    $whIds = DB::table('warehouses')->where('account_id', $accountId)->pluck('id')->all();
                    if (!empty($whIds)) {
                        if (Schema::hasTable('warehouse_pva')) {
                            DB::table('warehouse_pva')->whereIn('warehouse_id', $whIds)->delete();
                        }
                        $deletedStats['warehouses'] = DB::table('warehouses')->where('account_id', $accountId)->delete();
                    }
                }

                // 5. Carriers & Cities
                if (Schema::hasTable('account_carrier')) {
                    $acIds = DB::table('account_carrier')->where('account_id', $accountId)->pluck('id')->all();
                    if (!empty($acIds) && Schema::hasTable('account_carrier_city')) {
                        DB::table('account_carrier_city')->whereIn('account_carrier_id', $acIds)->delete();
                    }
                    $deletedStats['account_carriers'] = DB::table('account_carrier')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('account_city')) {
                    DB::table('account_city')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('carriers')) {
                    DB::table('carriers')->where('account_id', $accountId)->delete();
                }

                // 6. Codes & Compensations & Locations
                if (Schema::hasTable('account_codes')) {
                    DB::table('account_codes')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('account_compensations')) {
                    DB::table('account_compensations')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('account_locations')) {
                    DB::table('account_locations')->where('account_id', $accountId)->delete();
                }

                // 7. Brands & Sources
                if (Schema::hasTable('brand_sources')) {
                    $bIds = Schema::hasTable('brands') ? DB::table('brands')->where('account_id', $accountId)->pluck('id')->all() : [];
                    $sIds = Schema::hasTable('sources') ? DB::table('sources')->where('account_id', $accountId)->pluck('id')->all() : [];
                    if (!empty($bIds)) {
                        DB::table('brand_sources')->whereIn('brand_id', $bIds)->delete();
                    }
                    if (!empty($sIds)) {
                        DB::table('brand_sources')->whereIn('source_id', $sIds)->delete();
                    }
                }
                if (Schema::hasTable('brands')) {
                    DB::table('brands')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('sources')) {
                    DB::table('sources')->where('account_id', $accountId)->delete();
                }

                // 8. Suppliers, Offers, Comments, Images, Phone Types, Customers
                if (Schema::hasTable('suppliers')) {
                    DB::table('suppliers')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('offers')) {
                    DB::table('offers')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('comments')) {
                    DB::table('comments')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('images')) {
                    DB::table('images')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('phone_types')) {
                    DB::table('phone_types')->where('account_id', $accountId)->delete();
                }
                if (Schema::hasTable('customers')) {
                    DB::table('customers')->where('account_id', $accountId)->delete();
                }

                // 10. Delete the account record
                $accountName = $account->name;
                $accountCode = $account->code;
                $account->delete();

                return [
                    'success' => true,
                    'account_id' => $accountId,
                    'account_name' => $accountName,
                    'account_code' => $accountCode,
                    'cleaned_stats' => $deletedStats,
                    'message' => "Account #{$accountId} ({$accountName}) successfully deleted.",
                ];
            });
        });
    }

    /**
     * Bulk delete accounts with individual safety checks.
     */
    public function bulkDelete(array $accountIds, ?int $currentAccountId = null, bool $force = false): array
    {
        $deleted = [];
        $failed = [];

        foreach ($accountIds as $id) {
            $id = (int) $id;
            try {
                $res = $this->deleteAccount($id, $currentAccountId, $force);
                $deleted[] = $res;
            } catch (Exception $e) {
                $failed[] = [
                    'account_id' => $id,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'total_requested' => count($accountIds),
            'total_deleted' => count($deleted),
            'total_failed' => count($failed),
            'deleted' => $deleted,
            'failed' => $failed,
        ];
    }
}
