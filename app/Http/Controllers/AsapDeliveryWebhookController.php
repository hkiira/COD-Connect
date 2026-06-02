<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\AsapDeliveryStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AsapDeliveryWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'datereported' => ['required', 'integer'],
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            Log::warning('ASAP Delivery Webhook validation failed.', [
                'errors' => $validator->errors(),
                'payload' => $request->all(),
            ]);
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        try {
            AsapDeliveryStatusUpdated::dispatch(
                $validated['code'],
                $validated['state'],
                (int) $validated['datereported'],
                $validated['note'] ?? null
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch AsapDeliveryStatusUpdated event.', [
                'exception' => $e->getMessage(),
                'payload' => $validated,
            ]);
            return response()->json(['success' => false], 500);
        }

        return response()->json(['success' => true]);
    }
}
