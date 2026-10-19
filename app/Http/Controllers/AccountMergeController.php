<?php

namespace App\Http\Controllers;

use App\Services\AccountMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountMergeController extends Controller
{
    public function __construct(
        private readonly AccountMergeService $mergeService
    ) {
    }

    /**
     * List all accounts with key data metrics.
     */
    public function accounts(): JsonResponse
    {
        $accounts = $this->mergeService->listAccountsWithStats();

        return response()->json([
            'status' => 'success',
            'data' => $accounts,
        ]);
    }

    /**
     * Inspect source & target accounts: load all entities, smart auto-matching, and saved draft.
     */
    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer'],
            'target_id' => ['required', 'integer', 'different:source_id'],
        ]);

        $result = $this->mergeService->inspect((int) $data['source_id'], (int) $data['target_id']);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Save draft mapping configuration.
     */
    public function saveDraft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer'],
            'target_id' => ['required', 'integer', 'different:source_id'],
            'config' => ['required', 'array'],
        ]);

        $saved = $this->mergeService->saveDraft(
            (int) $data['source_id'],
            (int) $data['target_id'],
            $data['config']
        );

        return response()->json([
            'status' => 'success',
            'saved' => $saved,
            'message' => 'Mapping draft saved successfully.',
        ]);
    }

    /**
     * Run dry-run calculation and return preview report.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer'],
            'target_id' => ['required', 'integer', 'different:source_id'],
            'config' => ['required', 'array'],
        ]);

        $preview = $this->mergeService->preview(
            (int) $data['source_id'],
            (int) $data['target_id'],
            $data['config']
        );

        return response()->json([
            'status' => 'success',
            'data' => $preview,
        ]);
    }

    /**
     * Execute transactional merge.
     */
    public function execute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer'],
            'target_id' => ['required', 'integer', 'different:source_id'],
            'config' => ['required', 'array'],
        ]);

        $result = $this->mergeService->execute(
            (int) $data['source_id'],
            (int) $data['target_id'],
            $data['config']
        );

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
