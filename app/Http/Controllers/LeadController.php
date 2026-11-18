<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadImportService;
use App\Support\AccountContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accountId = AccountContext::id();
        if (! $accountId) {
            return response()->json(['message' => 'Account context was not found.'], 422);
        }

        $query = Lead::query()->where('account_id', $accountId);
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        $status = (string) $request->input('status', 'all');
        if ($status !== 'all' && in_array($status, Lead::STATUSES, true)) {
            $query->where('status', $status);
        }
        $source = trim((string) $request->input('source', ''));
        if ($source !== '') {
            $query->where('source', $source);
        }

        $counts = Lead::query()
            ->where('account_id', $accountId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $perPage = max(10, min(100, (int) $request->input('per_page', $request->input('pagination.per_page', 20))));
        $page = max(1, (int) $request->input('page', $request->input('pagination.page', 1)));
        $paginator = $query
            ->orderByRaw('lead_date IS NULL')
            ->orderByDesc('lead_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'statut' => 1,
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'counts' => $counts,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $accountId = AccountContext::id();
        if (! $accountId) {
            return response()->json(['message' => 'Account context was not found.'], 422);
        }

        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::in(Lead::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        $lead = Lead::query()->where('account_id', $accountId)->findOrFail($id);
        $lead->update($data);

        return response()->json(['statut' => 1, 'data' => $lead->fresh(), 'message' => 'Lead updated.']);
    }

    public function previewImport(Request $request, LeadImportService $imports): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
            'source' => ['required', 'string', Rule::in(['tiktok', 'facebook', 'instagram', 'other'])],
            'mapping' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->runImportAction($request, $validated, $imports, false);
    }

    public function import(Request $request, LeadImportService $imports): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
            'source' => ['required', 'string', Rule::in(['tiktok', 'facebook', 'instagram', 'other'])],
            'mapping' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->runImportAction($request, $validated, $imports, true);
    }

    /** @param array<string, mixed> $validated */
    private function runImportAction(Request $request, array $validated, LeadImportService $imports, bool $commit): JsonResponse
    {
        $accountId = AccountContext::id();
        if (! $accountId) {
            return response()->json(['message' => 'Account context was not found.'], 422);
        }

        $mapping = null;
        if (! empty($validated['mapping'])) {
            $mapping = json_decode($validated['mapping'], true);
            if (! is_array($mapping)) {
                return response()->json(['message' => 'Column mapping is invalid.'], 422);
            }
        }

        try {
            if ($commit) {
                return response()->json([
                    'statut' => 1,
                    'data' => $imports->import($request->file('file'), $accountId, $validated['source'], $mapping),
                    'message' => 'Leads imported.',
                ]);
            }

            $preview = $imports->preview($request->file('file'), $accountId, $validated['source'], $mapping);
            unset($preview['_headers'], $preview['_fingerprint'], $preview['_records']);

            return response()->json(['statut' => 1, 'data' => $preview]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The file could not be processed. Check its format and try again.'], 422);
        }
    }
}
