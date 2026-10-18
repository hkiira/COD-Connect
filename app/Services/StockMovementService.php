<?php

namespace App\Services;

use App\Http\Controllers\DefaultCodeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\WarehouseController;
use App\Models\Mouvement;
use App\Models\MouvementPva;
use App\Models\Order;
use App\Models\Pickup;
use App\Models\Shipment;
use App\Models\Warehouse;
use App\Models\WarehousePva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    /**
     * Resolve the operational sub-warehouse (Rayon Normale, warehouse_type_id = 3)
     * for any given warehouse ID. If the warehouse is a root warehouse (type 1) or
     * partition (type 2) without a child rayon yet, it auto-provisions the partition/rayon
     * so Pickups, Returns, Exitslips, and SupplierReceipts always operate on the exact
     * same stock location and never crash on null references.
     */
    public function resolveOperationalWarehouseId(int $warehouseId, ?int $accountId = null): int
    {
        $accountUser = function_exists('getAccountUser') ? getAccountUser() : null;
        $accountId = $accountId ?? ($accountUser?->account_id ?? 1);

        $warehouse = Warehouse::where('id', $warehouseId)->first();
        if (!$warehouse) {
            return $warehouseId;
        }

        $accountId = (int) ($warehouse->account_id ?: $accountId);

        // Already a level-3 rayon
        if ((int) $warehouse->warehouse_type_id === 3) {
            return (int) $warehouse->id;
        }

        // Level-2 partition -> find or create level-3 rayon
        if ((int) $warehouse->warehouse_type_id === 2) {
            $rayon = Warehouse::where([
                'warehouse_id' => $warehouse->id,
                'warehouse_type_id' => 3,
            ])->orderByRaw('CASE WHEN warehouse_nature_id = 1 THEN 0 ELSE 1 END ASC')->first();

            if (!$rayon) {
                $rayon = Warehouse::create([
                    'code' => DefaultCodeController::getAccountCode('Warehouse', $accountId),
                    'title' => 'Rayon Normale (' . $warehouse->title . ')',
                    'statut' => 1,
                    'warehouse_type_id' => 3,
                    'warehouse_nature_id' => 1,
                    'account_id' => $accountId,
                    'warehouse_id' => $warehouse->id,
                ]);
            }

            return (int) $rayon->id;
        }

        // Level-1 main warehouse -> find or create level-2 partition, then level-3 rayon
        $partition = Warehouse::where([
            'warehouse_id' => $warehouse->id,
            'warehouse_type_id' => 2,
        ])->first();

        if (!$partition) {
            $partition = Warehouse::create([
                'code' => DefaultCodeController::getAccountCode('Partition', $accountId),
                'title' => 'partition ' . $warehouse->title,
                'statut' => 1,
                'warehouse_type_id' => 2,
                'account_id' => $accountId,
                'warehouse_id' => $warehouse->id,
            ]);
        }

        $rayon = Warehouse::where([
            'warehouse_id' => $partition->id,
            'warehouse_type_id' => 3,
        ])->orderByRaw('CASE WHEN warehouse_nature_id = 1 THEN 0 ELSE 1 END ASC')->first();

        if (!$rayon) {
            $rayon = Warehouse::create([
                'code' => DefaultCodeController::getAccountCode('Warehouse', $accountId),
                'title' => 'Rayon Normale (' . $partition->title . ')',
                'statut' => 1,
                'warehouse_type_id' => 3,
                'warehouse_nature_id' => 1,
                'account_id' => $accountId,
                'warehouse_id' => $partition->id,
            ]);
        }

        return (int) $rayon->id;
    }

    /**
     * Roll back the physical stock effect of an active Mouvement in WarehousePva.
     * - If `from_warehouse` is set: adds back the deducted quantities.
     * - If `to_warehouse` is set: subtracts the added quantities.
     */
    public function rollbackMovementStock(Mouvement $mouvement): void
    {
        if ((int) $mouvement->statut !== 1) {
            return;
        }

        $lines = MouvementPva::where('mouvement_id', $mouvement->id)
            ->where('statut', 1)
            ->lockForUpdate()
            ->get();

        foreach ($lines as $line) {
            $pvaId = (int) $line->product_variation_attribute_id;
            $qty = (float) $line->quantity;

            if ($qty == 0) {
                continue;
            }

            if ($mouvement->from_warehouse) {
                $fromWhPva = WarehousePva::firstOrCreate(
                    [
                        'warehouse_id' => (int) $mouvement->from_warehouse,
                        'product_variation_attribute_id' => $pvaId,
                    ],
                    [
                        'quantity' => 0,
                        'statut' => 1,
                    ]
                );
                $fromWhPva->update([
                    'quantity' => (float) $fromWhPva->quantity + $qty,
                ]);
            }

            if ($mouvement->to_warehouse) {
                $toWhPva = WarehousePva::firstOrCreate(
                    [
                        'warehouse_id' => (int) $mouvement->to_warehouse,
                        'product_variation_attribute_id' => $pvaId,
                    ],
                    [
                        'quantity' => 0,
                        'statut' => 1,
                    ]
                );
                $toWhPva->update([
                    'quantity' => (float) $toWhPva->quantity - $qty,
                ]);
            }
        }
    }

    /**
     * Reverse a movement's stock impact and delete/deactivate the movement and its lines.
     */
    public function reverseAndDeleteMovement(int|Mouvement|null $mouvement): void
    {
        if (!$mouvement) {
            return;
        }

        DB::transaction(function () use ($mouvement) {
            $mvt = $mouvement instanceof Mouvement
                ? Mouvement::where('id', $mouvement->id)->lockForUpdate()->first()
                : Mouvement::where('id', (int) $mouvement)->lockForUpdate()->first();

            if (!$mvt) {
                return;
            }

            $this->rollbackMovementStock($mvt);

            MouvementPva::where('mouvement_id', $mvt->id)->update(['statut' => 0]);
            MouvementPva::where('mouvement_id', $mvt->id)->delete();

            $mvt->update(['statut' => 0]);
            $mvt->delete();
        });
    }

    /**
     * Synchronize the Exit Movement (Bon de sortie, mouvement_type_id = 6) and WarehousePva stock
     * for a Pickup (Bon de ramassage) based on its current active orders and order lines.
     */
    public function syncPickupExitMovement(Pickup $pickup): ?Mouvement
    {
        return DB::transaction(function () use ($pickup) {
            $pickup = Pickup::with('orders.activeOrderPvas')->find($pickup->id);
            if (!$pickup || !$pickup->warehouse_id) {
                return null;
            }

            $accountUser = function_exists('getAccountUser') ? getAccountUser() : null;
            $accountUserId = $accountUser?->id ?? $pickup->account_user_id;
            $accountId = $accountUser?->account_id ?? Warehouse::where('id', $pickup->warehouse_id)->value('account_id') ?? 1;

            $fromWarehouseId = $this->resolveOperationalWarehouseId((int) $pickup->warehouse_id, (int) $accountId);

            // Aggregate quantities per variation across all active orders of this pickup
            $aggregatedPvas = [];
            foreach ($pickup->orders as $order) {
                foreach ($order->activeOrderPvas as $orderPva) {
                    $pvaId = (int) $orderPva->product_variation_attribute_id;
                    $qty = (float) $orderPva->quantity;
                    if ($qty <= 0) {
                        continue;
                    }
                    if (!isset($aggregatedPvas[$pvaId])) {
                        $aggregatedPvas[$pvaId] = [
                            'id' => $pvaId,
                            'quantity' => 0.0,
                            'price' => (float) ($orderPva->price ?? 0),
                            'orders' => [],
                        ];
                    }
                    $aggregatedPvas[$pvaId]['quantity'] += $qty;
                    $aggregatedPvas[$pvaId]['orders'][] = $orderPva->id;
                }
            }

            $existingMovement = $pickup->mouvement_id
                ? Mouvement::where('id', $pickup->mouvement_id)->lockForUpdate()->first()
                : null;

            // Step 1: Roll back any previously applied stock deduction on the existing movement
            if ($existingMovement) {
                $this->rollbackMovementStock($existingMovement);
            }

            // Step 2: If pickup now has no active order lines, deactivate its movement and return
            if (empty($aggregatedPvas)) {
                if ($existingMovement) {
                    MouvementPva::where('mouvement_id', $existingMovement->id)->delete();
                    $existingMovement->update(['statut' => 0]);
                }
                return $existingMovement;
            }

            // Step 3: Create or update the Exitslip Mouvement (mouvement_type_id = 6)
            if (!$existingMovement) {
                $existingMovement = Mouvement::create([
                    'code' => DefaultCodeController::getAccountCode('Exitslip', (int) $accountId),
                    'mouvement_type_id' => 6,
                    'from_warehouse' => $fromWarehouseId,
                    'description' => 'Bon de ramassage ' . ($pickup->code ?: ('#' . $pickup->id)),
                    'statut' => 1,
                    'account_user_id' => $accountUserId,
                ]);
                $pickup->update(['mouvement_id' => $existingMovement->id]);
            } else {
                $existingMovement->update([
                    'from_warehouse' => $fromWarehouseId,
                    'statut' => 1,
                ]);
                MouvementPva::where('mouvement_id', $existingMovement->id)->delete();
            }

            // Step 4: Create fresh MouvementPva lines and deduct stock from WarehousePva
            foreach ($aggregatedPvas as $pvaData) {
                $newSop = MouvementPva::create([
                    'product_variation_attribute_id' => $pvaData['id'],
                    'mouvement_id' => $existingMovement->id,
                    'quantity' => $pvaData['quantity'],
                    'price' => $pvaData['price'],
                    'account_user_id' => $accountUserId,
                    'statut' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Deduct stock safely using firstOrCreate
                WarehouseController::mouve($newSop, $existingMovement);

                if (!empty($pvaData['orders'])) {
                    $orderPivot = [];
                    foreach ($pvaData['orders'] as $orderPvaId) {
                        $orderPivot[$orderPvaId] = [
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    $newSop->orderPvas()->syncWithoutDetaching($orderPivot);
                }
            }

            return $existingMovement->fresh();
        });
    }

    /**
     * Synchronize the Return Movement (Retour livreur, mouvement_type_id = 1) and WarehousePva stock
     * for a Return Shipment child based on its current returned orders.
     */
    public function syncReturnShipmentMovement(Shipment $shipmentChild, int $warehouseId): ?Mouvement
    {
        return DB::transaction(function () use ($shipmentChild, $warehouseId) {
            $shipmentChild = Shipment::with('orders.activeOrderPvas')->find($shipmentChild->id);
            if (!$shipmentChild) {
                return null;
            }

            $accountUser = function_exists('getAccountUser') ? getAccountUser() : null;
            $accountUserId = $accountUser?->id ?? $shipmentChild->account_user_id;
            $accountId = $accountUser?->account_id ?? Warehouse::where('id', $warehouseId)->value('account_id') ?? 1;

            $toWarehouseId = $this->resolveOperationalWarehouseId($warehouseId, (int) $accountId);

            // Aggregate quantities per variation across all returned orders
            $aggregatedPvas = [];
            foreach ($shipmentChild->orders as $order) {
                foreach ($order->activeOrderPvas as $orderPva) {
                    $pvaId = (int) $orderPva->product_variation_attribute_id;
                    $qty = (float) $orderPva->quantity;
                    if ($qty <= 0) {
                        continue;
                    }
                    if (!isset($aggregatedPvas[$pvaId])) {
                        $aggregatedPvas[$pvaId] = [
                            'id' => $pvaId,
                            'quantity' => 0.0,
                            'price' => (float) ($orderPva->price ?? 0),
                            'orders' => [],
                        ];
                    }
                    $aggregatedPvas[$pvaId]['quantity'] += $qty;
                    $aggregatedPvas[$pvaId]['orders'][] = $orderPva->id;
                }
            }

            $existingMovement = $shipmentChild->mouvement_id
                ? Mouvement::where('id', $shipmentChild->mouvement_id)->lockForUpdate()->first()
                : null;

            // Step 1: Roll back previously added return stock
            if ($existingMovement) {
                $this->rollbackMovementStock($existingMovement);
            }

            // Step 2: If no returned items remain, deactivate the movement
            if (empty($aggregatedPvas)) {
                if ($existingMovement) {
                    MouvementPva::where('mouvement_id', $existingMovement->id)->delete();
                    $existingMovement->update(['statut' => 0]);
                }
                return $existingMovement;
            }

            // Step 3: Create or update the Return Mouvement (mouvement_type_id = 1)
            if (!$existingMovement) {
                $existingMovement = Mouvement::create([
                    'code' => DefaultCodeController::getAccountCode('return', (int) $accountId),
                    'mouvement_type_id' => 1,
                    'to_warehouse' => $toWarehouseId,
                    'description' => 'Bon de retour ' . ($shipmentChild->code ?: ('#' . $shipmentChild->id)),
                    'statut' => 1,
                    'account_user_id' => $accountUserId,
                ]);
                $shipmentChild->update(['mouvement_id' => $existingMovement->id]);
            } else {
                $existingMovement->update([
                    'to_warehouse' => $toWarehouseId,
                    'statut' => 1,
                ]);
                MouvementPva::where('mouvement_id', $existingMovement->id)->delete();
            }

            // Step 4: Create fresh MouvementPva lines and add returned stock to WarehousePva
            foreach ($aggregatedPvas as $pvaData) {
                $newSop = MouvementPva::create([
                    'product_variation_attribute_id' => $pvaData['id'],
                    'mouvement_id' => $existingMovement->id,
                    'quantity' => $pvaData['quantity'],
                    'price' => $pvaData['price'],
                    'account_user_id' => $accountUserId,
                    'sop_type_id' => 1,
                    'statut' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                WarehouseController::mouve($newSop, $existingMovement);

                if (!empty($pvaData['orders'])) {
                    $orderPivot = [];
                    foreach ($pvaData['orders'] as $orderPvaId) {
                        $orderPivot[$orderPvaId] = [
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    $newSop->orderPvas()->syncWithoutDetaching($orderPivot);
                }
            }

            return $existingMovement->fresh();
        });
    }
}
