<?php

namespace App\Services;

use App\Models\AfraOrderOperation;
use App\Models\AfraStatusMapping;
use App\Models\AfraSyncRun;
use App\Models\DefaultCarrier;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\Pickup;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class AfraShippingService
{
    public function client(int $accountId): AfraShippingClient
    {
        return new AfraShippingClient($accountId);
    }

    public function payload(Order $order): array
    {
        $order->load(['customer.activePhones', 'customer.activeAddresses', 'activePhones', 'activeAddresses', 'activePvas.product']);
        $address = $order->activeAddresses->first() ?: $order->customer?->activeAddresses->first();
        $phone = $order->activePhones->first() ?: $order->customer?->activePhones->first();
        $cityId = $order->city_id ?: $address?->city_id;
        $city = DefaultCarrier::where('carrier_id', 26)->where('city_id', $cityId)->first();
        if (!$city || !$city->city_id_carrier) {
            throw new RuntimeException('Ville locale non associée à une ville Afra.');
        }
        if (!$order->customer?->name || !$phone?->title || !$address?->title) {
            throw new RuntimeException('Client, téléphone ou adresse manquant pour Afra.');
        }

        $lines = $order->activePvas->filter(fn ($pva) => (int) $pva->pivot->quantity > 0)->values();
        $gross = $lines->sum(fn ($pva) => (int) round((float) $pva->pivot->price * 100) * (int) $pva->pivot->quantity);
        $target = $gross - (int) round((float) $order->discount * 100) + (int) round((float) $order->carrier_price * 100);
        if ($target < 0 || $lines->isEmpty()) {
            throw new RuntimeException('Total Afra invalide ou produits actifs absents.');
        }

        // Allocate adjusted cents across lines, then split any remainder across units.
        $weightSum = $gross > 0 ? $gross : $lines->sum(fn ($pva) => (int) $pva->pivot->quantity);
        $allocated = 0;
        $products = [];
        foreach ($lines as $index => $pva) {
            $quantity = (int) $pva->pivot->quantity;
            $weight = $gross > 0 ? (int) round((float) $pva->pivot->price * 100) * $quantity : $quantity;
            $lineCents = $index === $lines->count() - 1 ? $target - $allocated : intdiv($target * $weight, $weightSum);
            $allocated += $lineCents;
            $unit = intdiv($lineCents, $quantity);
            $remainder = $lineCents % $quantity;
            $name = $pva->product?->title ?: 'Produit';
            if ($quantity > $remainder) {
                $products[] = ['product_name' => $name, 'quantity' => $quantity - $remainder, 'unit_price' => round($unit / 100, 2)];
            }
            if ($remainder) {
                $products[] = ['product_name' => $name, 'quantity' => $remainder, 'unit_price' => round(($unit + 1) / 100, 2)];
            }
        }

        return [
            'client_name' => $order->customer->name,
            'client_phone_number' => $phone->title,
            'city_id' => (int) $city->city_id_carrier,
            'client_address' => $address->title,
            'order_type' => 'normal',
            'test_product' => 'no',
            'products' => $products,
            'note' => (string) ($order->note ?? ''),
        ];
    }

    public function fingerprint(Order $order): string
    {
        // These are the fields Afra receives. Status and other local-only changes are omitted.
        $order->load(['customer.activePhones', 'customer.activeAddresses', 'activePhones', 'activeAddresses', 'activePvas.product']);
        return hash('sha256', json_encode([
            $order->customer?->name,
            $order->activePhones->first()?->title ?: $order->customer?->activePhones->first()?->title,
            $order->activeAddresses->first()?->title ?: $order->customer?->activeAddresses->first()?->title,
            $order->city_id,
            $order->discount,
            $order->carrier_price,
            $order->note,
            $order->activePvas->map(fn ($pva) => [$pva->id, $pva->pivot->quantity, $pva->pivot->price])->all(),
        ]));
    }

    public function create(Order $order, int $actorId, AfraShippingClient $client): string
    {
        if ($order->shipping_code) {
            return 'skipped';
        }
        AfraOrderOperation::firstOrCreate(['order_id' => $order->id]);
        $claimed = AfraOrderOperation::where('order_id', $order->id)
            ->where(function ($query) { $query->whereNull('create_state')->orWhere('create_state', 'failed'); })
            ->update(['create_state' => 'pending']);
        if (!$claimed) {
            return 'skipped';
        }
        $remoteCreated = false;
        try {
            $response = $client->createOrder($this->payload($order));
            if (($response['status'] ?? null) !== 'success' || empty($response['order_number'])) {
                throw new RuntimeException((string) ($response['message'] ?? 'Création refusée par Afra.'));
            }
            $remoteCreated = true;
            $updated = Order::whereKey($order->id)->whereNull('shipping_code')->update(['shipping_code' => (string) $response['order_number']]);
            if (!$updated) {
                throw new AfraUncertainException('Commande créée chez Afra mais code local non enregistré.');
            }
            AfraOrderOperation::where('order_id', $order->id)->update(['create_state' => 'sent', 'last_error' => null]);
            return 'synchronized';
        } catch (\Throwable $e) {
            $uncertain = $remoteCreated || $e instanceof ConnectionException || $e instanceof AfraUncertainException;
            AfraOrderOperation::where('order_id', $order->id)->update(['create_state' => $uncertain ? 'uncertain' : 'failed', 'last_error' => $e->getMessage()]);
            $this->comment($order, $actorId, 'Échec de synchronisation Afra: '.$e->getMessage());
            return 'failed';
        }
    }

    public function update(Order $order, int $actorId): array
    {
        try {
            $payload = $this->payload($order);
            $payload['order_number'] = (string) $order->shipping_code;
            $response = $this->client((int) $order->account_id)->updateOrder($payload);
            if (($response['status'] ?? null) !== 'success') {
                throw new RuntimeException((string) ($response['message'] ?? 'Modification refusée par Afra.'));
            }
            return ['success' => true, 'message' => 'Changement enregistré et synchronisation effectuée.'];
        } catch (\Throwable $e) {
            $this->comment($order, $actorId, 'Échec de synchronisation Afra après modification: '.$e->getMessage());
            return ['success' => false, 'message' => 'Changement enregistré localement, mais synchronisation Afra échouée: '.$e->getMessage()];
        }
    }

    public function requestReturn(Order $order, int $actorId, bool $manualRetry = false): array
    {
        if (!$order->shipping_code || !$order->pickup || (int) $order->pickup->carrier_id !== 26) {
            return ['success' => false, 'message' => 'Commande Afra non éligible.'];
        }
        AfraOrderOperation::firstOrCreate(['order_id' => $order->id]);
        $claim = AfraOrderOperation::where('order_id', $order->id);
        $manualRetry ? $claim->where('return_state', 'failed') : $claim->whereNull('return_state');
        if (!$claim->update(['return_state' => 'pending'])) {
            return ['success' => false, 'message' => 'Demande de retour déjà envoyée ou réponse à vérifier.'];
        }
        $remoteSucceeded = false;
        try {
            $response = $this->client((int) $order->account_id)->returnRequest((string) $order->shipping_code);
            if (($response['status'] ?? null) !== 'success') {
                throw new RuntimeException((string) ($response['message'] ?? 'Retour refusé par Afra.'));
            }
            $remoteSucceeded = true;
            AfraOrderOperation::where('order_id', $order->id)->update(['return_state' => 'sent', 'last_error' => null]);
            return ['success' => true, 'message' => 'Demande de retour envoyée à Afra.'];
        } catch (\Throwable $e) {
            $uncertain = $remoteSucceeded || $e instanceof ConnectionException || $e instanceof AfraUncertainException;
            AfraOrderOperation::where('order_id', $order->id)->update(['return_state' => $uncertain ? 'uncertain' : 'failed', 'last_error' => $e->getMessage()]);
            $this->comment($order, $actorId, 'Échec de la demande de retour Afra: '.$e->getMessage());
            return ['success' => false, 'message' => $uncertain ? 'Retour Afra à vérifier avant tout nouvel envoi.' : 'Demande de retour Afra échouée: '.$e->getMessage()];
        }
    }

    public function syncPickup(AfraSyncRun $run): void
    {
        $pickup = Pickup::with('orders')->findOrFail($run->pickup_id);
        if ((int) $pickup->carrier_id !== 26 || (int) $pickup->accountUser->account_id !== (int) $run->account_id) {
            throw new RuntimeException('Pickup Afra hors compte.');
        }
        $client = $this->client((int) $run->account_id);
        foreach ($pickup->orders as $order) {
            $outcome = $order->shipping_code || (int) $order->account_id !== (int) $run->account_id
                ? 'skipped'
                : $this->create($order, (int) $run->account_user_id, $client);
            $run->increment('processed');
            $run->increment($outcome);
        }
    }

    public function syncStatuses(AfraSyncRun $run): void
    {
        $client = $this->client((int) $run->account_id);
        $rawStatuses = $client->getStatuses();
        $statuses = $rawStatuses['statuses'] ?? $rawStatuses['data'] ?? $rawStatuses;
        $idsByName = collect($statuses)->filter(fn ($s) => isset($s['id'], $s['fr_name']))
            ->mapWithKeys(fn ($s) => [$this->normalize($s['fr_name']) => (int) $s['id']]);
        $mappings = AfraStatusMapping::where('account_id', $run->account_id)->pluck('order_status_id', 'afra_status_id');
        $unmapped = [];
        for ($page = 1; ; $page++) {
            $data = $client->getOrders($page, 100);
            $orders = $data['orders'] ?? [];
            foreach ($orders as $remote) {
                $run->increment('processed');
                $number = $remote['number'] ?? null;
                $statusName = $remote['status'] ?? '';
                $remoteId = $idsByName->get($this->normalize($statusName));
                $localId = $remoteId ? $mappings->get($remoteId) : null;
                if (!$number || !$localId) {
                    $unmapped[$statusName ?: '(inconnu)'] = true;
                    $run->increment('skipped');
                    continue;
                }
                $order = Order::where('account_id', $run->account_id)->where('shipping_code', (string) $number)
                    ->whereHas('pickup', fn ($q) => $q->where('carrier_id', 26))->first();
                if (!$order || (int) $order->order_status_id === (int) $localId) {
                    $run->increment('skipped');
                    continue;
                }
                // Incoming state changes never go through OrderController's outbound update/return flow.
                $order->update(['order_status_id' => $localId]);
                $order->activeOrderPvas()->update(['order_status_id' => $localId]);
                $run->increment('synchronized');
            }
            $lastPage = (int) ($data['pagination']['last_page'] ?? $data['last_page'] ?? 0);
            if (($lastPage && $page >= $lastPage) || (!$lastPage && count($orders) < 100)) {
                break;
            }
            if ($page >= 10000) {
                throw new RuntimeException('Pagination Afra anormalement longue.');
            }
        }
        if ($unmapped) {
            $run->update(['message' => 'Statuts absents ou non associés: '.implode(', ', array_keys($unmapped))]);
        }
    }

    public function comment(Order $order, int $actorId, string $message): void
    {
        OrderComment::create([
            'order_id' => $order->id,
            'account_user_id' => $actorId,
            'order_status_id' => $order->order_status_id,
            'title' => mb_substr($message, 0, 255),
        ]);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
