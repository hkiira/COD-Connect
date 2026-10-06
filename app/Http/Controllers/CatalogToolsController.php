<?php

namespace App\Http\Controllers;

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

    public function stockAlerts(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->stock->alerts($this->accountId())]);
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
