<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AsapDeliveryStatusUpdated;
use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Support\Facades\Log;

class HandleAsapDeliveryStatusUpdated
{
    public function handle(AsapDeliveryStatusUpdated $event): void
    {
        $order = Order::where('code', $event->code)
            ->orWhere('shipping_code', $event->code)
            ->first();

        if (! $order) {
            Log::warning('ASAP Delivery webhook order not found.', [
                'code' => $event->code,
                'state' => $event->state,
            ]);
            return;
        }

        $statusMap = [
            'En attente de ramassage' => 'En Attente',
            'Livré' => 'Livrée',
            'Annulé' => 'Annulée',
            'Refusé' => 'Abandonnée',
            'Reporté' => 'En souffrance',
            'Programmé' => 'En préparation',
        ];

        $state = trim($event->state);
        $mappedTitle = $statusMap[$state] ?? $state;

        $orderStatus = OrderStatus::where('title', $mappedTitle)->first();

        if ($orderStatus) {
            $order->order_status_id = $orderStatus->id;
        }

        $order->note = $this->appendDeliveryNote($order->note, $event);

        if (! $orderStatus) {
            $order->meta = $this->mergeMeta($order->meta, [
                'asap_delivery_state' => $state,
                'asap_delivery_note' => $event->note,
                'asap_delivery_reported_at' => $event->timestamp,
            ]);
        }

        $order->save();
    }

    private function appendDeliveryNote(?string $existingNote, AsapDeliveryStatusUpdated $event): string
    {
        $noteLines = [];

        if (filled($existingNote)) {
            $noteLines[] = trim($existingNote);
        }

        $deliveryNote = sprintf(
            'ASAP Delivery update: %s at %s',
            $event->state,
            date('Y-m-d H:i:s', $event->timestamp)
        );

        if ($event->note) {
            $deliveryNote .= sprintf(' (%s)', $event->note);
        }

        $noteLines[] = $deliveryNote;

        return implode("\n", $noteLines);
    }

    private function mergeMeta($meta, array $data): string
    {
        $decoded = [];

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = [];
            }
        } elseif (is_array($meta)) {
            $decoded = $meta;
        }

        return json_encode(array_merge($decoded, $data), JSON_UNESCAPED_UNICODE);
    }
}
