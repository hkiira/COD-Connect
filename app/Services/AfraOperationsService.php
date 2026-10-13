<?php

namespace App\Services;

use App\Http\Controllers\ShipmentController;
use App\Models\AccountCarrier;
use App\Models\AfraOrderOperation;
use App\Models\AfraStatusMapping;
use App\Models\AfraSyncRun;
use App\Models\DefaultCarrier;
use App\Models\Order;
use App\Models\OrderComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The end of the Afra cycle, which Afra's API does not cover: receiving the returned parcels at the
 * warehouse (return slip FR), recording Afra's payments from their PDF (payment slip FL), and the
 * tracking page. Slips are created by ShipmentController::store, like the ASAP synchronisation.
 */
class AfraOperationsService
{
    private const SLIP_PAYMENT = 1; // shipment_types: Facture Transport Livré
    private const SLIP_RETURN = 2;  // shipment_types: Facture Transport Retour

    public function __construct(private readonly AfraShippingService $shipping)
    {
    }

    /** The account's Afra orders (in an Afra pickup), with what the lists show. */
    private function afraOrders(int $accountId)
    {
        return Order::where('orders.account_id', $accountId)
            ->whereHas('pickup', fn ($q) => $q->where('carrier_id', AfraShippingClient::carrierId()))
            ->with(['customer:id,name', 'city:id,title', 'activePvas']);
    }

    private function amount(Order $order): float
    {
        return round($order->calculateActivePvasTotalValue() - (float) $order->discount + (float) $order->carrier_price, 2);
    }

    private function row(Order $order, ?AfraOrderOperation $operation = null): array
    {
        return [
            'id'            => $order->id,
            'code'          => $order->code,
            'shipping_code' => $order->shipping_code,
            'customer'      => $order->customer?->name,
            'city'          => $order->city?->title,
            'status_id'     => (int) $order->order_status_id,
            'amount'        => $this->amount($order),
            'afra_status'   => $operation?->remote_status,
            'afra_since'    => $operation?->remote_status_at,
        ];
    }

    // region returns

    /** Afra orders whose last Afra status is marked "return", not yet on a return slip. */
    public function returnsToReceive(int $accountId): array
    {
        $names = $this->returnStatusNames($accountId);
        if (!$names) {
            return [];
        }

        $operations = AfraOrderOperation::whereIn('remote_status', $names)->get()->keyBy('order_id');

        return $this->afraOrders($accountId)
            ->whereIn('orders.id', $operations->keys())
            ->whereNull('shipment_id')
            ->whereNotIn('order_status_id', [AfraShippingService::PAID_STATUS, AfraShippingService::RETURNED_STATUS])
            ->get()
            ->map(fn ($order) => $this->row($order, $operations->get($order->id)))
            ->sortBy('afra_since')->values()->all();
    }

    /** Names of the Afra statuses marked as "return" (the operation rows store the status name). */
    private function returnStatusNames(int $accountId): array
    {
        $ids = AfraStatusMapping::where('account_id', $accountId)->where('is_return', true)->pluck('afra_status_id');
        if ($ids->isEmpty()) {
            return [];
        }

        return collect($this->shipping->client($accountId)->getStatuses())
            ->whereIn('id', $ids->all())->pluck('fr_name')->map(fn ($n) => trim((string) $n))->values()->all();
    }

    /**
     * A scanned code (order code or Afra number) at the warehouse: the order if it can go on a return
     * slip, with a warning when Afra does not show it as a return yet.
     */
    public function lookupReturn(int $accountId, string $scan): array
    {
        $scan = trim($scan);
        $order = $this->afraOrders($accountId)
            ->where(fn ($q) => $q->where('code', $scan)->orWhere('shipping_code', $scan))->first();
        if (!$order) {
            return ['found' => false, 'message' => 'Aucune commande Afra avec ce code.'];
        }
        if ($order->shipment_id || in_array((int) $order->order_status_id, [AfraShippingService::PAID_STATUS, AfraShippingService::RETURNED_STATUS], true)) {
            return ['found' => false, 'message' => 'La commande '.$order->code.' est déjà sur un bon (payée ou retournée).'];
        }

        $operation = AfraOrderOperation::where('order_id', $order->id)->first();
        $isReturn = in_array(trim((string) $operation?->remote_status), $this->returnStatusNames($accountId), true);

        return [
            'found'   => true,
            'order'   => $this->row($order, $operation),
            'warning' => $isReturn ? null : 'Afra ne montre pas encore ce colis en retour ('.($operation?->remote_status ?: 'statut inconnu').').',
        ];
    }

    /** Creates the return slip (FR) for the parcels received: their stock goes back to the warehouse. */
    public function receiveReturns(int $accountId, int $warehouseId, array $orderIds, ?string $title = null): array
    {
        $orders = $this->afraOrders($accountId)->whereIn('orders.id', $orderIds)->whereNull('shipment_id')
            ->whereNotIn('order_status_id', [AfraShippingService::PAID_STATUS, AfraShippingService::RETURNED_STATUS])->get();
        if ($orders->isEmpty()) {
            throw new AfraException('Aucune commande à mettre sur le bon de retour.');
        }

        return $this->createSlip(self::SLIP_RETURN, $warehouseId, $title ?: 'Retour Afra '.now()->format('d/m/Y H:i'),
            // keep the delivery fee already known: the return slip must not erase it
            $orders->map(fn ($o) => ['id' => $o->id, 'carrier_price' => (float) $o->real_carrier_price])->values()->all(),
            null, $orders->count());
    }

    // endregion

    // region payments

    /**
     * Text or structured PDF items from an Afra payment invoice: finds the Afra numbers,
     * matches delivered orders, updates real_carrier_price based on PDF "Livraison", checks price differences,
     * and alerts if the PDF Montant Total differs from the system sum.
     */
    public function reconcilePayment(
        int $accountId,
        string $text = '',
        array $items = [],
        ?float $pdfTotal = null,
        ?float $pdfLivraisonTotal = null,
        ?string $invoiceNumber = null,
        ?string $invoiceDate = null
    ): array {
        $parsedItems = collect($items);

        // If items were not sent directly, but text was provided, attempt structured extraction or fallback
        if ($parsedItems->isEmpty() && !empty($text)) {
            if (preg_match_all('/CMD:\s*(\d+).*?(?:Tel:\s*([^\r\n]+))?.*?(Livré|Retour[^\r\n]*).*?(\d+)\s+(\d+)/is', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $parsedItems->push([
                        'shipping_code' => trim($m[1]),
                        'phone'         => isset($m[2]) ? trim($m[2]) : '',
                        'status'        => trim($m[3]),
                        'total'         => (float) $m[4],
                        'livraison'     => (float) $m[5],
                    ]);
                }
            }
        }

        // Only take delivered orders based on Livraison > 0
        if ($parsedItems->isNotEmpty()) {
            $validItems = $parsedItems->filter(fn ($it) => (float) ($it['livraison'] ?? 0) > 0)->keyBy('shipping_code');
            $numbers = $validItems->keys();
        } else {
            preg_match_all('/(?<!\d)\d{9,20}(?!\d)/', $text, $found);
            $numbers = collect($found[0])->unique()->reject(fn ($n) => strlen($n) === 10 && $n[0] === '0')->values();
            $validItems = collect();
        }

        $orders = $this->afraOrders($accountId)->whereIn('shipping_code', $numbers->all())->get()->keyBy('shipping_code');
        $fees = $this->tariffs($accountId, $orders->pluck('city_id')->filter()->unique()->all());

        $report = [
            'to_pay'        => [],
            'already_paid'  => [],
            'not_delivered' => [],
            'unknown'       => [],
            'invoice_meta'  => [
                'invoice_number' => $invoiceNumber,
                'invoice_date'   => $invoiceDate,
                'pdf_total'      => $pdfTotal,
                'pdf_livraison'  => $pdfLivraisonTotal,
            ],
            'total_alert'   => null,
        ];

        foreach ($numbers as $number) {
            $order = $orders->get($number);
            $item = $validItems->get($number);
            if (!$order) {
                $report['unknown'][] = $number;
                continue;
            }
            $row = $this->row($order);
            $systemPrice = (float) $row['amount'];
            $pdfPrice = $item && isset($item['total']) ? (float) $item['total'] : $systemPrice;
            $pdfDelivery = $item && isset($item['livraison']) ? (float) $item['livraison'] : null;

            // Delivery fee: prioritize PDF "Livraison", fallback to order's real_carrier_price, then tariff
            $carrierFee = $pdfDelivery !== null ? $pdfDelivery : ((float) $order->real_carrier_price > 0 ? (float) $order->real_carrier_price : ($fees[$order->city_id] ?? 0));

            $hasPriceDiff = abs($systemPrice - $pdfPrice) > 0.01;
            $needsStatusUpdate = (int) $order->order_status_id !== AfraShippingService::DELIVERED_STATUS;

            if ($order->shipment_id || (int) $order->order_status_id === AfraShippingService::PAID_STATUS) {
                $report['already_paid'][] = $row;
            } else {
                // If Livraison > 0 in PDF, accept and auto-transition to Livré then Payé
                $report['to_pay'][] = $row + [
                    'fee'            => $carrierFee,
                    'pdf_fee'        => $pdfDelivery,
                    'system_price'   => $systemPrice,
                    'pdf_price'      => $pdfPrice,
                    'has_price_diff' => $hasPriceDiff,
                    'update_price'   => false,
                    'auto_deliver'   => $needsStatusUpdate,
                    'status_title'   => $needsStatusUpdate ? 'À passer Livré' : 'Livré',
                ];
            }
        }

        $collected = collect($report['to_pay'])->sum(fn ($r) => (float) $r['amount']);
        $feesTotal = collect($report['to_pay'])->sum(fn ($r) => (float) $r['fee']);
        $report['totals'] = [
            'numbers'   => $numbers->count(),
            'collected' => round($collected, 2),
            'fees'      => round($feesTotal, 2),
            'net'       => round($collected - $feesTotal, 2),
        ];

        // Total alert comparison if PDF Montant Total is present
        if ($pdfTotal !== null && $pdfTotal > 0) {
            $diff = round($pdfTotal - $collected, 2);
            $report['total_alert'] = [
                'pdf_total'    => $pdfTotal,
                'system_total' => round($collected, 2),
                'difference'   => $diff,
                'has_mismatch' => abs($diff) > 0.01,
            ];
        }

        return $report;
    }

    /** Creates the payment slip (FL): orders become Paid, the fees are recorded, plus the amount received. */
    public function createPayment(int $accountId, int $warehouseId, array $lines, float $received, ?string $title = null): array
    {
        $lineMap = collect($lines)->keyBy('id');
        $fees = $lineMap->mapWithKeys(fn ($l) => [(int) $l['id'] => round((float) ($l['fee'] ?? 0), 2)]);

        $orders = $this->afraOrders($accountId)->whereIn('orders.id', $fees->keys())->whereNull('shipment_id')->get();
        if ($orders->count() !== $fees->count()) {
            throw new AfraException('Certaines commandes ne sont plus à payer (déjà payées ou introuvables). Recommencez le rapprochement.');
        }

        $actorId = getAccountUser()->id;

        foreach ($orders as $order) {
            $line = $lineMap->get($order->id);

            // 1. If user opted to update order price to the PDF Total
            if (!empty($line['update_price']) && isset($line['new_price'])) {
                $newPrice = (float) $line['new_price'];
                $oldAmount = $this->amount($order);
                if (abs($oldAmount - $newPrice) > 0.01) {
                    $pvas = $order->activePvas;
                    $pvaTotal = $order->calculateActivePvasTotalValue();
                    if ($pvas->isNotEmpty()) {
                        if ($pvaTotal > 0) {
                            $scale = $newPrice / $pvaTotal;
                            foreach ($pvas as $pva) {
                                $pva->pivot->price = round($pva->pivot->price * $scale, 2);
                                $pva->pivot->save();
                            }
                        } else {
                            $first = $pvas->first();
                            $first->pivot->price = $newPrice;
                            $first->pivot->save();
                        }
                    }
                    $order->update(['discount' => 0]);

                    OrderComment::create([
                        'order_id'        => $order->id,
                        'account_user_id' => $actorId,
                        'order_status_id' => $order->order_status_id,
                        'title'           => "Prix actualisé via Facture Afra ({$oldAmount} DH -> {$newPrice} DH)",
                    ]);
                }
            }

            // 2. If order is not yet Livré, auto transition to DELIVERED_STATUS so createSlip can mark it Paid
            if ((int) $order->order_status_id !== AfraShippingService::DELIVERED_STATUS) {
                $oldStatus = $order->order_status_id;
                $order->update(['order_status_id' => AfraShippingService::DELIVERED_STATUS]);
                $order->orderPvas()->where('order_status_id', $oldStatus)->update(['order_status_id' => AfraShippingService::DELIVERED_STATUS]);

                OrderComment::create([
                    'order_id'        => $order->id,
                    'account_user_id' => $actorId,
                    'order_status_id' => AfraShippingService::DELIVERED_STATUS,
                    'title'           => 'Marqué Livré via Facture Afra (Livraison > 0)',
                ]);
            }
        }

        return $this->createSlip(self::SLIP_PAYMENT, $warehouseId, $title ?: 'Paiement Afra '.now()->format('d/m/Y'),
            $orders->map(fn ($o) => ['id' => $o->id, 'carrier_price' => $fees[$o->id]])->values()->all(),
            $received, $orders->count());
    }

    /** City id → our Afra fee: the account's special price, else the carrier's default price. */
    private function tariffs(int $accountId, array $cityIds): array
    {
        if (!$cityIds) {
            return [];
        }
        $carrierId = AfraShippingClient::carrierId();
        $defaults = DefaultCarrier::where('carrier_id', $carrierId)->whereIn('city_id', $cityIds)->pluck('price', 'city_id');
        $accountCarrierId = AccountCarrier::where('account_id', $accountId)->where('carrier_id', $carrierId)->value('id');
        $special = $accountCarrierId
            ? DB::table('account_carrier_city')->where('account_carrier_id', $accountCarrierId)->whereIn('city_id', $cityIds)->pluck('price', 'city_id')
            : collect();

        return collect($cityIds)->mapWithKeys(fn ($id) => [$id => (float) ($special[$id] ?? $defaults[$id] ?? 0)])->all();
    }

    // endregion

    /** Same request as the ASAP synchronisation (AsapDeliveryController::runSyncInvoices / runSyncReturns). */
    private function createSlip(int $type, int $warehouseId, string $title, array $orders, ?float $received, int $count): array
    {
        $slip = [
            'carrier_id'       => AfraShippingClient::carrierId(),
            'shipment_type_id' => $type,
            'warehouse_id'     => $warehouseId,
            'statut'           => 1,
            'title'            => $title,
            'orders'           => $orders,
        ];
        if ($received !== null) {
            $slip['given_amount'] = $received;
        }

        $response = $this->storeSlip($slip);
        if ((int) ($response['statut'] ?? 0) !== 1) {
            throw new AfraException('Bon non créé: '.json_encode($response['data'] ?? $response));
        }
        $shipment = $response['data'][0] ?? [];

        return ['success' => true, 'shipment_id' => $shipment['id'] ?? null, 'code' => $shipment['code'] ?? null, 'orders' => $count];
    }

    /** The slip creation itself (separate so tests can check the request without the warehouse setup). */
    protected function storeSlip(array $slip): array
    {
        return ShipmentController::store(new Request([$slip]))->getData(true);
    }

    // region tracking

    /** Everything the "Suivi" tab shows. */
    public function tracking(int $accountId): array
    {
        $problemStates = ['failed', 'uncertain', 'accepted'];
        $operations = AfraOrderOperation::whereHas('order', fn ($q) => $q->where('account_id', $accountId))
            ->where(fn ($q) => $q->whereIn('create_state', $problemStates)->orWhereIn('return_state', $problemStates)
                ->orWhereIn('delete_state', $problemStates)->orWhereIn('exchange_state', $problemStates)
                ->orWhereNotNull('missing_since'))
            ->with('order.customer:id,name')->latest('updated_at')->limit(200)->get();

        $withoutCode = $this->afraOrders($accountId)->whereNull('shipping_code')
            ->whereNotIn('order_status_id', [2, 3, AfraShippingService::CANCELLED_STATUS])->count();

        $lastStatusRun = AfraSyncRun::where('account_id', $accountId)->where('kind', 'statuses')
            ->where('status', 'completed')->latest('id')->first();

        return [
            'runs' => AfraSyncRun::where('account_id', $accountId)->latest('id')->limit(20)
                ->get(['id', 'kind', 'pickup_id', 'status', 'processed', 'synchronized', 'skipped', 'failed', 'message', 'created_at', 'updated_at']),
            'problems' => $operations->filter(fn ($op) => $op->order)->map(fn ($op) => [
                'order_id'       => $op->order_id,
                'code'           => $op->order->code,
                'shipping_code'  => $op->order->shipping_code,
                'customer'       => $op->order->customer?->name,
                'create_state'   => $op->create_state,
                'return_state'   => $op->return_state,
                'delete_state'   => $op->delete_state,
                'exchange_state' => $op->exchange_state,
                'missing_since'  => $op->missing_since,
                'afra_status'    => $op->remote_status,
                'last_error'     => $op->last_error,
            ])->values(),
            'without_code'      => $withoutCode,
            'last_status_sync'  => $lastStatusRun?->updated_at,
            'last_sync_message' => $lastStatusRun?->message,
        ];
    }

    // endregion
}
