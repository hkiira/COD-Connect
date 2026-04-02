<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Standardised JSON response helpers.
 *
 * List  response shape: { statut: 1, data: [...], meta: { total, per_page, current_page } }
 * Item  response shape: { statut: 1, data: {...} }
 * Error response shape: { statut: 0, message: '...' }
 */
trait ApiResponse
{
    /**
     * Successful list / paginated response.
     *
     * @param  mixed  $data        The collection of items (array or Collection).
     * @param  int    $total       Total number of rows before pagination.
     * @param  int    $perPage     Items per page.
     * @param  int    $currentPage Current page (1-based).
     * @return JsonResponse
     */
    protected function apiList($data, int $total, int $perPage, int $currentPage): JsonResponse
    {
        return response()->json([
            'statut'      => 1,
            'data'        => $data,
            'meta'        => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $currentPage,
            ],
        ]);
    }

    /**
     * Successful single-item response.
     *
     * @param  mixed $data The resource object/array.
     * @return JsonResponse
     */
    protected function apiItem($data): JsonResponse
    {
        return response()->json([
            'statut' => 1,
            'data'   => $data,
        ]);
    }

    /**
     * Error response.
     *
     * @param  string $message    Human-readable error message.
     * @param  int    $httpStatus HTTP status code (default 422).
     * @return JsonResponse
     */
    protected function apiError(string $message, int $httpStatus = 422): JsonResponse
    {
        return response()->json([
            'statut'  => 0,
            'message' => $message,
        ], $httpStatus);
    }
}
