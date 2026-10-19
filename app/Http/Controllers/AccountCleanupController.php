<?php

namespace App\Http\Controllers;

use App\Services\AccountCleanupService;
use App\Support\AccountContext;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountCleanupController extends Controller
{
    public function __construct(
        private readonly AccountCleanupService $cleanupService
    ) {
    }

    /**
     * List all accounts with emptiness metrics and deletion safety status.
     */
    public function index(): JsonResponse
    {
        $currentAccountId = AccountContext::id();
        $accounts = $this->cleanupService->listAccounts($currentAccountId);

        return response()->json([
            'status' => 'success',
            'data' => $accounts,
        ]);
    }

    /**
     * Inspect all child table records remaining in this account.
     */
    public function inspect(int $id): JsonResponse
    {
        try {
            $inspection = $this->cleanupService->inspectAccount($id);

            return response()->json([
                'status' => 'success',
                'data' => $inspection,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Delete a single account safely.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentAccountId = AccountContext::id();
        $force = (bool) $request->input('force', false);

        try {
            $result = $this->cleanupService->deleteAccount($id, $currentAccountId, $force);

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Bulk delete accounts safely with individual transaction checks.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer'],
            'force' => ['nullable', 'boolean'],
        ]);

        $currentAccountId = AccountContext::id();
        $force = (bool) ($data['force'] ?? false);

        $result = $this->cleanupService->bulkDelete($data['account_ids'], $currentAccountId, $force);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
