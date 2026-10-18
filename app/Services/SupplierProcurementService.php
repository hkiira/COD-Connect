<?php

namespace App\Services;

use App\Http\Controllers\DefaultCodeController;
use App\Models\Mouvement;
use App\Models\MouvementPva;
use App\Models\Product;
use App\Models\ProductVariationAttribute;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderPva;
use App\Models\SupplierOrderReceiptAllocation;
use App\Models\SupplierPva;
use App\Models\SupplierReceipt;
use App\Models\Warehouse;
use App\Models\WarehousePva;
use Illuminate\Support\Facades\DB;

class SupplierProcurementService
{
    /**
     * Resolve the target operational rayon (warehouse_type_id = 3) for a given warehouse,
     * auto-provisioning the partition/rayon if needed so SupplierReceipts, Pickups, and Returns
     * always hit the exact same WarehousePva record.
     */
    public function resolveTargetStockWarehouseId(int $warehouseId, int $accountId): int
    {
        return app(StockMovementService::class)->resolveOperationalWarehouseId($warehouseId, $accountId);
    }

    /**
     * Synchronize physical stock (WarehousePva + Mouvement type 5) for a SupplierReceipt.
     * - If receipt is Draft (statut == 0): rolls back any previously applied movement and deactivates it.
     * - If receipt is Validated (statut >= 1): rolls back previous lines (if any) and applies current lines.
     */
    public function syncStockForReceipt(SupplierReceipt $supplierReceipt, ?int $accountId = null, ?int $accountUserId = null): void
    {
        $accountUser = function_exists('getAccountUser') ? getAccountUser() : null;
        $accountId = $accountId ?? ($accountUser?->account_id ?? $supplierReceipt->supplier?->account_id ?? 1);
        $accountUserId = $accountUserId ?? ($accountUser?->id ?? $supplierReceipt->account_user_id);

        $targetWarehouseId = $this->resolveTargetStockWarehouseId((int) $supplierReceipt->warehouse_id, (int) $accountId);

        // 1. Roll back existing active movement from WarehousePva if present
        if ($supplierReceipt->mouvement_id) {
            $existingMovement = Mouvement::find($supplierReceipt->mouvement_id);
            if ($existingMovement && $existingMovement->to_warehouse && (int) $existingMovement->statut === 1) {
                $prevLines = MouvementPva::where('mouvement_id', $existingMovement->id)->get();
                foreach ($prevLines as $prevLine) {
                    $whPva = WarehousePva::where([
                        'warehouse_id' => $existingMovement->to_warehouse,
                        'product_variation_attribute_id' => $prevLine->product_variation_attribute_id,
                    ])->lockForUpdate()->first();

                    if ($whPva) {
                        $whPva->update([
                            'quantity' => (float) $whPva->quantity - (float) $prevLine->quantity,
                        ]);
                    }
                }
            }
        }

        // 2. If receipt is Draft (statut == 0), deactivate the movement and stop
        if ((int) $supplierReceipt->statut === SupplierReceipt::STATUS_DRAFT) {
            if ($supplierReceipt->mouvement_id) {
                Mouvement::where('id', $supplierReceipt->mouvement_id)->update(['statut' => 0]);
                MouvementPva::where('mouvement_id', $supplierReceipt->mouvement_id)->delete();
            }
            return;
        }

        // 3. Receipt is Validated: fetch its receipt lines
        $receiptLines = SupplierOrderPva::where('supplier_receipt_id', $supplierReceipt->id)
            ->whereNull('supplier_order_id')
            ->get();

        // Activate any draft products included in this validated receipt
        $pvaIds = $receiptLines->pluck('product_variation_attribute_id')->filter()->unique()->values()->all();
        if (!empty($pvaIds)) {
            $productIds = ProductVariationAttribute::whereIn('id', $pvaIds)->pluck('product_id')->unique()->all();
            if (!empty($productIds)) {
                Product::whereIn('id', $productIds)
                    ->where('statut', Product::STATUS_DRAFT)
                    ->update(['statut' => Product::STATUS_ACTIVE, 'updated_at' => now()]);
            }
        }

        // 4. Create or update Mouvement (mouvement_type_id = 5: Réception fournisseur)
        $movement = null;
        if ($supplierReceipt->mouvement_id) {
            $movement = Mouvement::find($supplierReceipt->mouvement_id);
            if ($movement) {
                $movement->update([
                    'to_warehouse' => $targetWarehouseId,
                    'statut' => 1,
                ]);
                MouvementPva::where('mouvement_id', $movement->id)->delete();
            }
        }

        if (!$movement) {
            $movement = Mouvement::create([
                'code' => DefaultCodeController::getAccountCode('Receipt', $accountId),
                'mouvement_type_id' => 5,
                'to_warehouse' => $targetWarehouseId,
                'statut' => 1,
                'account_user_id' => $accountUserId,
            ]);
            $supplierReceipt->update(['mouvement_id' => $movement->id]);
        }

        // 5. Create MouvementPva lines & increment WarehousePva stock
        foreach ($receiptLines as $line) {
            $pvaId = (int) $line->product_variation_attribute_id;
            $qty = (float) $line->quantity;
            $price = (float) $line->price;

            if ($qty <= 0) {
                continue;
            }

            MouvementPva::create([
                'product_variation_attribute_id' => $pvaId,
                'mouvement_id' => $movement->id,
                'quantity' => $qty,
                'price' => $price,
                'account_user_id' => $accountUserId,
                'created_at' => now(),
                'updated_at' => now(),
                'sop_type_id' => 1,
                'statut' => 1,
            ]);

            $whPva = WarehousePva::where([
                'warehouse_id' => $targetWarehouseId,
                'product_variation_attribute_id' => $pvaId,
            ])->lockForUpdate()->first();

            if (!$whPva) {
                WarehousePva::create([
                    'warehouse_id' => $targetWarehouseId,
                    'product_variation_attribute_id' => $pvaId,
                    'quantity' => $qty,
                    'statut' => 1,
                ]);
            } else {
                $whPva->update([
                    'quantity' => (float) $whPva->quantity + $qty,
                    'statut' => 1,
                ]);
            }
        }
    }

    /**
     * Allocate a validated receipt's lines against open SupplierOrders of the same supplier using FIFO.
     * Priority:
     *  1. Open orders (`statut IN (1, 2)`) for the same supplier and same warehouse (`created_at ASC, id ASC`)
     *  2. Open orders (`statut IN (1, 2)`) for the same supplier in any other warehouse (`created_at ASC, id ASC`)
     * Any remaining quantity not covered by open orders is recorded as `hors_commande_quantity` on the receipt line.
     */
    public function allocateReceiptFifo(SupplierReceipt $supplierReceipt, bool $updateSupplierPrices = true): void
    {
        // First clear any previous allocations for this receipt so it's idempotent
        $this->deallocateReceiptFifo($supplierReceipt);

        if ((int) $supplierReceipt->statut === SupplierReceipt::STATUS_DRAFT) {
            return;
        }

        $accountUser = function_exists('getAccountUser') ? getAccountUser() : null;
        $accountId = $accountUser?->account_id ?? $supplierReceipt->supplier?->account_id ?? 1;

        $receiptLines = SupplierOrderPva::where('supplier_receipt_id', $supplierReceipt->id)
            ->whereNull('supplier_order_id')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $affectedOrderIds = [];

        foreach ($receiptLines as $receiptLine) {
            $pvaId = (int) $receiptLine->product_variation_attribute_id;
            $remainingToAllocate = max(0, (int) $receiptLine->quantity);
            $price = (float) $receiptLine->price;

            // Update or create default SupplierPva price if requested
            if ($updateSupplierPrices && $supplierReceipt->supplier_id) {
                $supPva = SupplierPva::firstOrCreate(
                    [
                        'supplier_id' => $supplierReceipt->supplier_id,
                        'product_variation_attribute_id' => $pvaId,
                    ],
                    [
                        'price' => $price,
                        'statut' => 1,
                        'account_id' => $accountId,
                    ]
                );
                if ($price > 0 && (float) $supPva->price !== $price) {
                    $supPva->update(['price' => $price]);
                }
            }

            if ($remainingToAllocate <= 0) {
                continue;
            }

            // Find open SupplierOrder lines for this supplier & variation
            // Same warehouse prioritized first, then oldest order first
            $openOrderLines = SupplierOrderPva::query()
                ->select('supplier_order_pva.*')
                ->join('supplier_orders as so', 'so.id', '=', 'supplier_order_pva.supplier_order_id')
                ->whereNull('so.deleted_at')
                ->whereNull('supplier_order_pva.deleted_at')
                ->whereNull('supplier_order_pva.supplier_receipt_id')
                ->where('so.supplier_id', $supplierReceipt->supplier_id)
                ->whereIn('so.statut', [SupplierOrder::STATUS_PENDING, SupplierOrder::STATUS_PARTIAL])
                ->where('supplier_order_pva.product_variation_attribute_id', $pvaId)
                ->whereRaw('(supplier_order_pva.quantity - supplier_order_pva.received_quantity - supplier_order_pva.cancelled_quantity) > 0')
                ->orderByRaw('CASE WHEN so.warehouse_id = ? THEN 0 ELSE 1 END ASC', [(int) $supplierReceipt->warehouse_id])
                ->orderBy('so.created_at', 'asc')
                ->orderBy('so.id', 'asc')
                ->orderBy('supplier_order_pva.id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($openOrderLines as $orderLine) {
                if ($remainingToAllocate <= 0) {
                    break;
                }

                $orderLineRemaining = max(
                    0,
                    (int) $orderLine->quantity - (int) $orderLine->received_quantity - (int) $orderLine->cancelled_quantity
                );

                if ($orderLineRemaining <= 0) {
                    continue;
                }

                $allocQty = min($remainingToAllocate, $orderLineRemaining);

                SupplierOrderReceiptAllocation::create([
                    'supplier_order_id' => $orderLine->supplier_order_id,
                    'supplier_order_pva_id' => $orderLine->id,
                    'supplier_receipt_id' => $supplierReceipt->id,
                    'supplier_receipt_pva_id' => $receiptLine->id,
                    'product_variation_attribute_id' => $pvaId,
                    'allocated_quantity' => $allocQty,
                ]);

                $newReceived = (int) $orderLine->received_quantity + $allocQty;
                $isLineDone = ($newReceived + (int) $orderLine->cancelled_quantity) >= (int) $orderLine->quantity;

                $orderLine->update([
                    'received_quantity' => $newReceived,
                    'statut' => $isLineDone ? 2 : 1,
                ]);

                $remainingToAllocate -= $allocQty;
                $affectedOrderIds[$orderLine->supplier_order_id] = true;
            }

            // Record any leftover quantity as Hors Commande on the receipt line
            $receiptLine->update([
                'hors_commande_quantity' => $remainingToAllocate,
                'statut' => 2,
            ]);
        }

        // Recalculate status for all affected SupplierOrders
        foreach (array_keys($affectedOrderIds) as $orderId) {
            $order = SupplierOrder::find($orderId);
            if ($order) {
                $this->recalculateOrderStatus($order);
            }
        }
    }

    /**
     * Reverse FIFO allocations of a SupplierReceipt and recalculate affected SupplierOrders.
     */
    public function deallocateReceiptFifo(SupplierReceipt $supplierReceipt): void
    {
        $allocations = SupplierOrderReceiptAllocation::where('supplier_receipt_id', $supplierReceipt->id)
            ->lockForUpdate()
            ->get();

        $affectedOrderIds = [];

        foreach ($allocations as $alloc) {
            $orderLine = SupplierOrderPva::where('id', $alloc->supplier_order_pva_id)->lockForUpdate()->first();
            if ($orderLine) {
                $newReceived = max(0, (int) $orderLine->received_quantity - (int) $alloc->allocated_quantity);
                $isLineDone = ($newReceived + (int) $orderLine->cancelled_quantity) >= (int) $orderLine->quantity;
                $orderLine->update([
                    'received_quantity' => $newReceived,
                    'statut' => $isLineDone ? 2 : 1,
                ]);
                $affectedOrderIds[$orderLine->supplier_order_id] = true;
            }
            $alloc->delete();
        }

        // Reset hors_commande_quantity on receipt lines
        SupplierOrderPva::where('supplier_receipt_id', $supplierReceipt->id)
            ->whereNull('supplier_order_id')
            ->update([
                'hors_commande_quantity' => 0,
                'statut' => 1,
            ]);

        foreach (array_keys($affectedOrderIds) as $orderId) {
            $order = SupplierOrder::find($orderId);
            if ($order) {
                $this->recalculateOrderStatus($order);
            }
        }
    }

    /**
     * Validate a SupplierReceipt:
     * - Sets statut = 1 (Validated)
     * - Syncs stock (WarehousePva + Mouvement)
     * - Allocates FIFO against open SupplierOrders
     */
    public function validateReceipt(SupplierReceipt $supplierReceipt): SupplierReceipt
    {
        return DB::transaction(function () use ($supplierReceipt) {
            $supplierReceipt->update(['statut' => SupplierReceipt::STATUS_VALIDATED]);
            $this->syncStockForReceipt($supplierReceipt);
            $this->allocateReceiptFifo($supplierReceipt, true);
            return $supplierReceipt->fresh();
        });
    }

    /**
     * Unvalidate (Dévalider) a SupplierReceipt back to Draft (statut = 0):
     * - Reverses FIFO allocations on SupplierOrders and updates their statuses
     * - Reverses stock movement (WarehousePva + Mouvement)
     */
    public function unvalidateReceipt(SupplierReceipt $supplierReceipt): SupplierReceipt
    {
        return DB::transaction(function () use ($supplierReceipt) {
            $this->deallocateReceiptFifo($supplierReceipt);
            $supplierReceipt->update(['statut' => SupplierReceipt::STATUS_DRAFT]);
            $this->syncStockForReceipt($supplierReceipt);
            return $supplierReceipt->fresh();
        });
    }

    /**
     * Recalculate a SupplierOrder's status based on its lines:
     * - Draft (0) and Cancelled (4) stay untouched unless forced.
     * - If total received == 0 and total cancelled == 0 -> STATUS_PENDING (1)
     * - If total remaining == 0 (all received or closed) -> STATUS_COMPLETED (3)
     * - If total received > 0 and total remaining > 0 -> STATUS_PARTIAL (2)
     */
    public function recalculateOrderStatus(SupplierOrder $supplierOrder, bool $forceFromClosed = true): SupplierOrder
    {
        if (in_array((int) $supplierOrder->statut, [SupplierOrder::STATUS_DRAFT, SupplierOrder::STATUS_CANCELLED], true)) {
            return $supplierOrder;
        }

        $lines = SupplierOrderPva::where('supplier_order_id', $supplierOrder->id)
            ->whereNull('supplier_receipt_id')
            ->get();

        if ($lines->isEmpty()) {
            return $supplierOrder;
        }

        $totalOrdered = 0;
        $totalReceived = 0;
        $totalCancelled = 0;

        foreach ($lines as $line) {
            $qty = max(0, (int) $line->quantity);
            $rec = min($qty, max(0, (int) $line->received_quantity));
            $canc = min(max(0, $qty - $rec), max(0, (int) $line->cancelled_quantity));

            $totalOrdered += $qty;
            $totalReceived += $rec;
            $totalCancelled += $canc;
        }

        $totalRemaining = max(0, $totalOrdered - $totalReceived - $totalCancelled);

        if ($totalOrdered > 0 && $totalRemaining === 0) {
            $newStatus = SupplierOrder::STATUS_COMPLETED;
        } elseif ($totalReceived > 0) {
            $newStatus = SupplierOrder::STATUS_PARTIAL;
        } else {
            $newStatus = SupplierOrder::STATUS_PENDING;
        }

        if ((int) $supplierOrder->statut !== $newStatus) {
            $supplierOrder->update(['statut' => $newStatus]);
        }

        return $supplierOrder;
    }

    /**
     * Force-close remaining quantities on a SupplierOrder (or a specific variation line within it)
     * when the supplier will never deliver the rest ("Clôturer le reste").
     */
    public function closeOrderRemaining(SupplierOrder $supplierOrder, ?int $pvaId = null): SupplierOrder
    {
        return DB::transaction(function () use ($supplierOrder, $pvaId) {
            if ((int) $supplierOrder->statut === SupplierOrder::STATUS_DRAFT) {
                $supplierOrder->update(['statut' => SupplierOrder::STATUS_CANCELLED]);
                return $supplierOrder->fresh();
            }

            $query = SupplierOrderPva::where('supplier_order_id', $supplierOrder->id)
                ->whereNull('supplier_receipt_id')
                ->lockForUpdate();

            if ($pvaId !== null) {
                $query->where('product_variation_attribute_id', $pvaId);
            }

            $lines = $query->get();

            foreach ($lines as $line) {
                $remaining = max(0, (int) $line->quantity - (int) $line->received_quantity - (int) $line->cancelled_quantity);
                if ($remaining > 0) {
                    $line->update([
                        'cancelled_quantity' => (int) $line->cancelled_quantity + $remaining,
                        'statut' => 2,
                    ]);
                }
            }

            return $this->recalculateOrderStatus($supplierOrder->fresh());
        });
    }

    /**
     * Reconcile historical SupplierOrders and SupplierReceipts in the database:
     * 1. Splits legacy 2024 shared rows (where both supplier_order_id and supplier_receipt_id are set on the same row)
     *    into clean separate order lines + receipt lines + allocation records.
     * 2. Normalizes legacy statuses and fixes 2026 orders created with accidental draft status.
     * 3. Runs FIFO allocation for unallocated validated receipts (without duplicating stock movements).
     */
    public function reconcileHistoricalData(): array
    {
        return DB::transaction(function () {
            $splitCount = 0;
            $reconciledReceipts = 0;

            // 1. Split legacy rows that have BOTH supplier_order_id and supplier_receipt_id
            $sharedRows = SupplierOrderPva::whereNotNull('supplier_order_id')
                ->whereNotNull('supplier_receipt_id')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $legacyOrderIds = [];

            foreach ($sharedRows as $shared) {
                $orderId = (int) $shared->supplier_order_id;
                $receiptId = (int) $shared->supplier_receipt_id;
                $qty = max(0, (int) $shared->quantity);

                // Create dedicated receipt line
                $receiptLine = SupplierOrderPva::create([
                    'supplier_order_id' => null,
                    'supplier_receipt_id' => $receiptId,
                    'product_variation_attribute_id' => $shared->product_variation_attribute_id,
                    'quantity' => $qty,
                    'received_quantity' => 0,
                    'cancelled_quantity' => 0,
                    'hors_commande_quantity' => 0,
                    'price' => $shared->price,
                    'account_user_id' => $shared->account_user_id,
                    'sop_type_id' => 1,
                    'statut' => 2,
                    'created_at' => $shared->created_at,
                    'updated_at' => $shared->updated_at,
                ]);

                // Convert existing shared row into a pure order line
                $shared->update([
                    'supplier_receipt_id' => null,
                    'received_quantity' => $qty,
                    'cancelled_quantity' => 0,
                    'hors_commande_quantity' => 0,
                    'statut' => 2,
                ]);

                // Create allocation link
                SupplierOrderReceiptAllocation::create([
                    'supplier_order_id' => $orderId,
                    'supplier_order_pva_id' => $shared->id,
                    'supplier_receipt_id' => $receiptId,
                    'supplier_receipt_pva_id' => $receiptLine->id,
                    'product_variation_attribute_id' => $shared->product_variation_attribute_id,
                    'allocated_quantity' => $qty,
                ]);

                $legacyOrderIds[$orderId] = true;
                $splitCount++;
            }

            // Recalculate status for those split legacy orders (they will become STATUS_COMPLETED = 3)
            foreach (array_keys($legacyOrderIds) as $orderId) {
                $order = SupplierOrder::find($orderId);
                if ($order) {
                    if ((int) $order->statut === 0) {
                        $order->update(['statut' => SupplierOrder::STATUS_PENDING]);
                    }
                    $this->recalculateOrderStatus($order);
                }
            }

            // 2. Fix 2026 orders (id >= 145) that were saved with statut = 0 due to form default switch
            SupplierOrder::where('id', '>=', 145)
                ->where('statut', 0)
                ->update(['statut' => SupplierOrder::STATUS_PENDING]);

            // Normalize SupplierReceipt statuses (1, 2, 3 -> 1 Validated)
            SupplierReceipt::whereIn('statut', [2, 3])
                ->update(['statut' => SupplierReceipt::STATUS_VALIDATED]);

            // 3. Run FIFO allocation on validated receipts that don't have allocations yet (e.g. 2026 receipts)
            $allocatedReceiptIds = SupplierOrderReceiptAllocation::distinct()->pluck('supplier_receipt_id')->toArray();

            $unallocatedReceipts = SupplierReceipt::where('statut', SupplierReceipt::STATUS_VALIDATED)
                ->whereNotIn('id', $allocatedReceiptIds)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            foreach ($unallocatedReceipts as $receipt) {
                $this->allocateReceiptFifo($receipt, false);
                $reconciledReceipts++;
            }

            // 4. Recalculate status on all non-draft, non-cancelled orders
            $allOrders = SupplierOrder::whereNotIn('statut', [SupplierOrder::STATUS_DRAFT, SupplierOrder::STATUS_CANCELLED])->get();
            foreach ($allOrders as $order) {
                $this->recalculateOrderStatus($order);
            }

            return [
                'split_legacy_lines' => $splitCount,
                'reconciled_receipts' => $reconciledReceipts,
                'order_status_summary' => SupplierOrder::selectRaw('statut, count(*) as total')->groupBy('statut')->pluck('total', 'statut')->toArray(),
            ];
        });
    }
}

