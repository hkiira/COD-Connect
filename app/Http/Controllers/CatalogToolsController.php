<?php

namespace App\Http\Controllers;

use App\Models\SupplierOrder;
use App\Models\SupplierOrderPva;
use App\Models\Warehouse;
use App\Http\Controllers\DefaultCodeController;
use App\Services\CatalogImportExportService;
use App\Services\CatalogStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock monitoring (alerts, reconciliation, adjustment) and catalog CSV tools
 * (export, import preview/commit, bulk edit). Everything is limited to the
 * authenticated user's account.
 */
class CatalogToolsController extends Controller
{
    public function __construct(
        private readonly CatalogStockService $stock,
        private readonly CatalogImportExportService $files,
    ) {
    }

    private function accountId(): int
    {
        return (int) getAccountUser()->account_id;
    }

    // region stock

    public function stockAlerts(Request $request): JsonResponse
    {
        $window = $request->filled('window') ? (int) $request->input('window') : null;
        $coverDays = $request->filled('cover_days') ? (int) $request->input('cover_days') : null;
        $limit = (int) $request->input('limit', 200);

        return response()->json([
            'status' => 'success',
            'data' => $this->stock->alerts($this->accountId(), $limit, $window, $coverDays),
        ]);
    }

    public function generateSupplierOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'merge_open_order' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.pva_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:1'],
            'items.*.price' => ['nullable', 'numeric'],
        ]);

        $accountId = $this->accountId();
        $accountUserId = getAccountUser()->id;
        $warehouseId = $data['warehouse_id'] ?? null;
        if ($warehouseId && !Warehouse::where(['id' => $warehouseId, 'account_id' => $accountId])->exists()) {
            $warehouseId = null;
        }
        $warehouseId = $warehouseId ?? Warehouse::where(['account_id' => $accountId, 'warehouse_type_id' => 1, 'statut' => 1])->value('id');

        $order = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $accountId, $accountUserId, $warehouseId) {
            $order = null;
            if (!empty($data['merge_open_order'])) {
                $order = SupplierOrder::where('supplier_id', $data['supplier_id'])
                    ->where('warehouse_id', $warehouseId)
                    ->where('statut', SupplierOrder::STATUS_PENDING)
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->first();
            }

            if (!$order) {
                $order = SupplierOrder::create([
                    'code' => DefaultCodeController::getAccountCode('SupplierOrder', $accountId),
                    'supplier_id' => $data['supplier_id'],
                    'account_user_id' => $accountUserId,
                    'warehouse_id' => $warehouseId,
                    'statut' => SupplierOrder::STATUS_PENDING,
                ]);
            }

            foreach ($data['items'] as $item) {
                $existingLine = SupplierOrderPva::where('supplier_order_id', $order->id)
                    ->where('product_variation_attribute_id', $item['pva_id'])
                    ->whereNull('supplier_receipt_id')
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingLine) {
                    $existingLine->update([
                        'quantity' => (float) $existingLine->quantity + (float) $item['quantity'],
                        'price' => isset($item['price']) && (float) $item['price'] > 0 ? $item['price'] : $existingLine->price,
                    ]);
                } else {
                    SupplierOrderPva::create([
                        'supplier_order_id' => $order->id,
                        'supplier_receipt_id' => null,
                        'product_variation_attribute_id' => $item['pva_id'],
                        'quantity' => $item['quantity'],
                        'received_quantity' => 0,
                        'cancelled_quantity' => 0,
                        'hors_commande_quantity' => 0,
                        'price' => $item['price'] ?? 0,
                        'sop_type_id' => 1,
                        'statut' => 1,
                        'account_user_id' => $accountUserId,
                    ]);
                }
            }

            return $order;
        });

        return response()->json([
            'status' => 'success',
            'data' => $order->load('supplier'),
            'message' => 'Supplier order generated successfully',
        ]);
    }

    public function stockReconciliation(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->stock->reconciliation($this->accountId())]);
    }

    public function stockAdjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_pva_id' => ['required', 'integer'],
            'quantity'         => ['required', 'numeric', 'min:0'],
            'reason'           => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $result = $this->stock->adjust(
            $this->accountId(),
            (int) $data['warehouse_pva_id'],
            (float) $data['quantity'],
            $data['reason'],
            getAccountUser()->id
        );

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    // endregion

    // region files

    public function export(): StreamedResponse
    {
        $accountId = $this->accountId();
        $filename = 'catalog-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($accountId) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
            foreach ($this->files->export($accountId) as $row) {
                fputcsv($out, $row, CatalogImportExportService::DELIMITER);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Dry run by default; send commit=1 to write the changes. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);

        $result = $this->files->preview($this->accountId(), $request->file('file')->getRealPath());

        return $this->respond($request, $result);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'                      => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'                    => ['integer'],
            'fields'                   => ['required', 'array', 'min:1'],
            'fields.status'            => ['sometimes'],
            'fields.cost_price'        => ['sometimes'],
            'fields.low_stock_threshold' => ['sometimes'],
        ]);

        $result = $this->files->previewBulk($this->accountId(), $data['ids'], $data['fields']);

        return $this->respond($request, $result);
    }

    private function respond(Request $request, array $result): JsonResponse
    {
        $committed = false;
        $updated = 0;

        if ($request->boolean('commit')) {
            if ($result['errors'] !== []) {
                return response()->json(['status' => 'error', 'message' => 'Fix the errors before confirming.', 'data' => $result + ['committed' => false]], 422);
            }
            $updated = $this->files->apply($this->accountId(), $result['changes']);
            $committed = true;
        }

        return response()->json(['status' => 'success', 'data' => $result + ['committed' => $committed, 'updated_products' => $updated]]);
    }

    // endregion
}
