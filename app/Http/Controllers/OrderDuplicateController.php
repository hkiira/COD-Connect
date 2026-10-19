<?php

namespace App\Http\Controllers;

use App\Services\OrderDuplicateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderDuplicateController extends Controller
{
    protected OrderDuplicateService $duplicateService;

    public function __construct(OrderDuplicateService $duplicateService)
    {
        $this->duplicateService = $duplicateService;
    }

    /**
     * GET api/orders/duplicates
     * List paginated duplicate order groups.
     */
    public function index(Request $request)
    {
        $accountUser = getAccountUser();
        if (!$accountUser) {
            return response()->json(['statut' => 0, 'message' => 'Non autorisé.'], 401);
        }

        $filters = [
            'window_hours' => $request->query('window_hours', 48),
            'start_date'   => $request->query('start_date'),
            'end_date'     => $request->query('end_date'),
            'search'       => $request->query('search', ''),
            'page'         => (int) $request->query('page', 1),
            'per_page'     => (int) $request->query('per_page', 10),
        ];

        $data = $this->duplicateService->getDuplicates($accountUser->account_id, $filters);

        return response()->json([
            'statut' => 1,
            'data'   => $data,
        ]);
    }

    /**
     * POST api/orders/duplicates/bulk-delete
     * Soft-delete selected duplicate orders.
     */
    public function bulkDelete(Request $request)
    {
        $accountUser = getAccountUser();
        if (!$accountUser) {
            return response()->json(['statut' => 0, 'message' => 'Non autorisé.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|integer',
        ], [
            'order_ids.required' => 'La liste des commandes à supprimer est requise.',
            'order_ids.array'    => 'Format invalide pour les commandes.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut'  => 0,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $result = $this->duplicateService->bulkDelete($accountUser->account_id, $request->input('order_ids'));

        return response()->json([
            'statut'  => 1,
            'message' => "{$result['deleted_count']} commande(s) doublon supprimée(s) avec succès.",
            'data'    => $result,
        ]);
    }
}
